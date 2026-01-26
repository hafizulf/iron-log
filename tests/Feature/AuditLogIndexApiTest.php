<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogIndexApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_422_for_invalid_query_params(): void
    {
        $res = $this->getJson('/api/audit-logs?limit=999&ip=not-an-ip&actor_id=not-uuid');

        $res->assertStatus(422);
        $res->assertJsonFragment(['message' => 'Invalid query parameters.']);
        $res->assertJsonStructure(['errors' => ['limit', 'ip', 'actor_id']]);
    }

    public function test_it_returns_empty_list_with_default_meta_when_no_data(): void
    {
        $res = $this->getJson('/api/audit-logs');

        $res->assertOk();
        $res->assertJson([
            'data' => [],
            'meta' => [
                'limit' => 25,
                'has_more' => false,
                'next_cursor' => null,
            ],
        ]);
    }

    public function test_it_respects_limit_and_returns_meta(): void
    {
        AuditLog::factory()->create(['created_at' => Carbon::parse('2026-01-26T10:00:00Z')]);
        AuditLog::factory()->create(['created_at' => Carbon::parse('2026-01-26T09:00:00Z')]);

        $res = $this->getJson('/api/audit-logs?limit=1');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $res->assertJsonPath('meta.limit', 1);
        $res->assertJsonPath('meta.has_more', true);
        $this->assertNotEmpty($res->json('meta.next_cursor'));
    }

    public function test_it_orders_by_created_at_desc_then_id_desc(): void
    {
        // created_at sama, id berbeda -> pastikan urutan id DESC berlaku
        $t = Carbon::parse('2026-01-26T10:00:00Z');

        $lowId  = '00000000-0000-0000-0000-000000000001';
        $highId = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

        AuditLog::factory()->create(['id' => $lowId,  'created_at' => $t, 'action' => 'a']);
        AuditLog::factory()->create(['id' => $highId, 'created_at' => $t, 'action' => 'b']);

        $res = $this->getJson('/api/audit-logs?limit=10');

        $res->assertOk();
        $ids = array_map(fn ($row) => $row['id'], $res->json('data'));

        $this->assertSame([$highId, $lowId], $ids);
    }

    public function test_it_filters_by_actor_id(): void
    {
        $actorA = (string) Str::uuid();
        $actorB = (string) Str::uuid();

        AuditLog::factory()->create(['actor_id' => $actorA, 'action' => 'user.login']);
        AuditLog::factory()->create(['actor_id' => $actorB, 'action' => 'user.logout']);

        $res = $this->getJson('/api/audit-logs?actor_id=' . $actorA);

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $res->assertJsonPath('data.0.actor_id', $actorA);
    }

    public function test_it_filters_action_by_ilike_contains(): void
    {
        AuditLog::factory()->create(['action' => 'user.login']);
        AuditLog::factory()->create(['action' => 'order.created']);

        $res = $this->getJson('/api/audit-logs?action=LOGIN');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $res->assertJsonPath('data.0.action', 'user.login');
    }

    public function test_it_filters_by_ip_in_jsonb_payload(): void
    {
        AuditLog::factory()->create(['payload' => ['ip' => '127.0.0.1']]);
        AuditLog::factory()->create(['payload' => ['ip' => '10.0.0.1']]);

        $res = $this->getJson('/api/audit-logs?ip=127.0.0.1');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('127.0.0.1', data_get($res->json(), 'data.0.payload.ip'));
    }

    public function test_it_filters_by_from_and_to_inclusive_using_seconds_boundary(): void
    {
        // pakai startOfSecond() dan endOfSecond()
        AuditLog::factory()->create(['created_at' => Carbon::parse('2026-01-26T10:00:00Z')]); // in
        AuditLog::factory()->create(['created_at' => Carbon::parse('2026-01-26T10:00:01Z')]); // in
        AuditLog::factory()->create(['created_at' => Carbon::parse('2026-01-26T09:59:59Z')]); // out

        $from = '2026-01-26T10:00:00Z';
        $to   = '2026-01-26T10:00:01Z';

        $res = $this->getJson("/api/audit-logs?from={$from}&to={$to}&limit=50");

        $res->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function test_it_paginates_with_cursor_keyset_correctly(): void
    {
        AuditLog::factory()->state([
            'created_at' => Carbon::parse('2026-01-26T10:00:00Z'),
        ])->create();

        AuditLog::factory()->state([
            'created_at' => Carbon::parse('2026-01-26T09:00:00Z'),
        ])->create();

        AuditLog::factory()->state([
            'created_at' => Carbon::parse('2026-01-26T08:00:00Z'),
        ])->create();

        $page1 = $this->getJson('/api/audit-logs?limit=2');
        $page1->assertOk();
        $this->assertCount(2, $page1->json('data'));
        $this->assertTrue($page1->json('meta.has_more'));

        $cursor = $page1->json('meta.next_cursor');
        $this->assertNotEmpty($cursor);

        $page2 = $this->getJson('/api/audit-logs?limit=2&cursor=' . urlencode($cursor));
        $page2->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertFalse($page2->json('meta.has_more'));

        $ids1 = array_column($page1->json('data'), 'id');
        $ids2 = array_column($page2->json('data'), 'id');
        $this->assertEmpty(array_intersect($ids1, $ids2));
    }


    public function test_cursor_tie_breaker_when_same_created_at_uses_id(): void
    {
        // created_at sama, 3 item, limit 2
        $t = Carbon::parse('2026-01-26T10:00:00Z');

        $id1 = 'ffffffff-ffff-ffff-ffff-fffffffffff1';
        $id2 = 'ffffffff-ffff-ffff-ffff-fffffffffff0';
        $id3 = '00000000-0000-0000-0000-000000000001';

        AuditLog::factory()->create(['id' => $id3, 'created_at' => $t, 'action' => 'c']); // paling kecil
        AuditLog::factory()->create(['id' => $id2, 'created_at' => $t, 'action' => 'b']);
        AuditLog::factory()->create(['id' => $id1, 'created_at' => $t, 'action' => 'a']); // paling besar

        $page1 = $this->getJson('/api/audit-logs?limit=2');
        $page1->assertOk();
        $ids1 = array_column($page1->json('data'), 'id');
        $this->assertSame([$id1, $id2], $ids1);

        $cursor = $page1->json('meta.next_cursor');

        $page2 = $this->getJson('/api/audit-logs?limit=2&cursor=' . urlencode($cursor));
        $page2->assertOk();
        $ids2 = array_column($page2->json('data'), 'id');
        $this->assertSame([$id3], $ids2);
    }
}
