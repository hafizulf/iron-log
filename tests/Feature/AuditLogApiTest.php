<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_audit_log(): void
    {
        $res = $this->postJson('/api/audit-logs', [
            'action' => 'user.login',
            'resource_type' => 'user',
            'resource_id' => '123',
            'payload' => ['ip' => '127.0.0.1'],
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseCount('audit_logs', 1);
    }
}
