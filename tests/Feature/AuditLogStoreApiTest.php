<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogStoreApiTest extends TestCase
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

    public function test_it_rejects_missing_request_header(): void
    {
        $res = $this->postJson('/api/audit-logs', [
            'actor_id' => Str::uuid(),
            'action' => 'user.login',
            'resource_type' => 'user',
            'resource_id' => '123',
            'payload' => 'not-an-object',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['payload']);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_idempotency_for_same_request_id_and_same_body(): void
    {
        $requestId = (string) Str::uuid();
        $body = [
            'action' => 'user.login',
            'resource_type' => 'user',
            'resource_id' => '123',
            'payload' => ['ip' => '127.0.0.1'],
        ];

        $firstReq = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/audit-logs', $body);

        $firstReq->assertCreated();
        $firstId = data_get($firstReq->json(), 'data.id');

        $secondReq = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/audit-logs', $body);

        $secondReq->assertOk();
        $secondId = data_get($secondReq->json(), 'data.id');

        $this->assertSame($firstId, $secondId);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_it_returns_conflict_for_same_request_id_but_different_body(): void
    {
        $requestId = (string) Str::uuid();
        $actorId = (string) Str::uuid();

        $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/audit-logs', [
                'actor_id' => $actorId,
                'action' => 'user.login',
                'resource_type' => 'user',
                'resource_id' => '123',
                'payload' => ['ip' => '127.0.0.1'],
            ])
            ->assertCreated();

        $res = $this->withHeader('X-Request-Id', $requestId)
            ->postJson('/api/audit-logs', [
                'actor_id' => $actorId,
                'action' => 'user.login',
                'resource_type' => 'user',
                'resource_id' => '123',
                'payload' => ['ip' => '10.0.0.1'], // different
            ]);

        $res->assertStatus(409);
        $this->assertDatabaseCount('audit_logs', 1);
    }
}
