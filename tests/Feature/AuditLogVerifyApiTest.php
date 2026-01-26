<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogVerifyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_log_is_valid(): void
    {
        // Create log through API (so checksum is consistent with store())
        $requestId = (string) Str::uuid();

        $create = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/audit-logs', [
                'action' => 'user.login',
                'resource_type' => 'user',
                'resource_id' => '123',
                'payload' => ['ip' => '127.0.0.1'],
            ]);

        $create->assertCreated();
        $id = data_get($create->json(), 'data.id');
        $this->assertNotEmpty($id);

        $verify = $this->getJson("/api/audit-logs/{$id}/verify");
        $verify->assertOk();

        $this->assertTrue((bool) data_get($verify->json(), 'data.valid'));
    }

    public function test_it_detects_tampering(): void
    {
        $requestId = (string) Str::uuid();

        $create = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/audit-logs', [
                'action' => 'user.login',
                'resource_type' => 'user',
                'resource_id' => '123',
                'payload' => ['ip' => '127.0.0.1'],
            ]);

        $create->assertCreated();
        $id = data_get($create->json(), 'data.id');

        // Tamper the record directly via DB (bypass Eloquent append-only guards)
        DB::table('audit_logs')
            ->where('id', $id)
            ->update([
                'action' => 'user.logout', // change something
            ]);

        $verify = $this->getJson("/api/audit-logs/{$id}/verify");
        $verify->assertOk();

        $this->assertFalse((bool) data_get($verify->json(), 'data.valid'));
    }
}
