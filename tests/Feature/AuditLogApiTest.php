<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_audit_log(): void
    {
        $requestId = (string) Str::uuid();
        $actorId = (string) Str::uuid();

        $res = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/audit-logs', [
                'actor_id' => $actorId,
                'action' => 'user.login',
                'resource_type' => 'user',
                'resource_id' => '123',
                'payload' => ['ip' => '127.0.0.1'],
            ]);

        $res->assertCreated();

        // Response assertions (keeps API contract honest)
        $res->assertJsonStructure([
            'data' => [
                'id',
                'request_id',
                'actor_id',
                'action',
                'resource_type',
                'resource_id',
                'payload',
                'checksum',
                'created_at',
            ],
        ]);

        $checksum = data_get($res->json(), 'data.checksum');
        $this->assertNotEmpty($checksum);

        // DB assertions (ensures persistence correctness)
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'request_id' => $requestId,
            'actor_id' => $actorId,
            'action' => 'user.login',
            'resource_type' => 'user',
            'resource_id' => '123',
            'checksum' => $checksum,
        ]);
    }
}
