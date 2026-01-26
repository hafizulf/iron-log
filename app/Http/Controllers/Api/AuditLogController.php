<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\AuditLog;

class AuditLogController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'actor_id'       => ['nullable', 'uuid'],
            'action'         => ['required', 'string', 'max:255'],
            'resource_type'  => ['required', 'string', 'max:255'],
            'resource_id'    => ['required', 'string', 'max:255'],
            'payload'        => ['nullable', 'array'],
        ]);

        $requestId = $request->header('X-Request-Id');
        if (!$requestId || !Str::isUuid($requestId)) {
            return response()->json([
                'message' => 'X-Request-Id must be a valid UUID',
                'errors'  => ['request_id' => ['X-Request-Id must be a valid UUID']],
            ], 422);
        }

        $actorId = $data['actor_id'] ?? null;
        $payload = $data['payload'] ?? [];
        $payloadSorted = $this->deepKsort($payload);

        $fingerprint = hash('sha256', $this->canonicalJson([
            ...$data,
            'actor_id'      => $actorId,
            'payload'       => $payloadSorted,
        ]));

        // checksum: untuk row yang disimpan
        $id = (string) Str::uuid();
        $checksum = hash('sha256', $this->canonicalJson([
            ...$data,
            'id'            => $id,
            'request_id'    => $requestId,
            'actor_id'      => $actorId,
            'payload'       => $payloadSorted,
        ]));

        try {
            $log = AuditLog::create([
                ...$data,
                'id'         => $id,
                'request_id' => $requestId,
                'actor_id'   => $actorId,
                'payload'    => $payloadSorted,
                'checksum'   => $checksum,
            ])->refresh();

            return response()->json(['data' => $log], 201);

        } catch (\Illuminate\Database\QueryException $e) {
            $existing = AuditLog::where('request_id', $requestId)->first();

            if (!$existing) {
                throw $e;
            }

            $existingFingerprint = hash('sha256', $this->canonicalJson([
                'actor_id'      => $existing->actor_id,
                'action'        => $existing->action,
                'resource_type' => $existing->resource_type,
                'resource_id'   => $existing->resource_id,
                'payload'       => $this->deepKsort($existing->payload ?? []),
            ]));

            if (!hash_equals($existingFingerprint, $fingerprint)) {
                return response()->json([
                    'message' => 'X-Request-Id was already used with a different request body.',
                    'errors'  => [
                        'request_id' => ['Duplicate X-Request-Id with different body.'],
                    ],
                ], 409);
            }

            return response()->json(['data' => $existing], 200);
        }
    }

    private function canonicalJson(array $value): string
    {
        $sorted = $this->deepKsort($value);

        return json_encode(
            $sorted,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '{}';
    }

    private function deepKsort(mixed $value): mixed
    {
        if (!\is_array($value)) return $value;

        // If associative array, sort keys; if list, keep order
        if ($this->isAssoc($value)) {
            ksort($value);
        }

        // Sort recursively if value is an array
        foreach ($value as $k => $v) {
            $value[$k] = $this->deepKsort($v);
        }

        return $value;
    }

    private function isAssoc(array $arr): bool
    {
        $keys = array_keys($arr);
        return $keys !== range(0, \count($arr) - 1);
    }

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date'],
            'actor_id'  => ['nullable', 'uuid'],
            'action'    => ['nullable', 'string', 'max:255'],
            'limit'     => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor'    => ['nullable', 'string'], // format: "<created_at>|<id>"
            'ip'        => ['nullable', 'ip'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'message' => 'Invalid query parameters.',
                'errors'  => $v->errors(),
            ], 422);
        }

        $limit = (int) ($request->query('limit', 25));

        $q = AuditLog::query();

        // filters
        if ($request->filled('from')) {
            $from = Carbon::parse($request->query('from'))->startOfSecond();
            $q->where('created_at', '>=', $from);
        }

        if ($request->filled('to')) {
            $to = Carbon::parse($request->query('to'))->endOfSecond();
            $q->where('created_at', '<=', $to);
        }

        if ($request->filled('actor_id')) {
            $q->where('actor_id', $request->query('actor_id'));
        }

        if ($request->filled('action')) {
            // $q->where('action', 'ilike', '%'.$request->query('action').'%');

            // run for sqlite too
            $term = mb_strtolower($request->query('action'));
            $q->whereRaw('LOWER(action) LIKE ?', ['%' . $term . '%']);
        }

        // JSONB query - filter logs where payload.ip == query ip
        if ($request->filled('ip')) {
            $q->where('payload->ip', $request->query('ip'));
        }

        // cursor pagination (created_at DESC, id DESC)
        if ($request->filled('cursor')) {
            // cursor: "2026-01-26T04:01:17.000000Z|76c250a9-1138-4746-a6d3-b6afd27bdeda"
            $cursor = $request->query('cursor');
            [$cursorCreatedAt, $cursorId] = array_pad(explode('|', $cursor, 2), 2, null);

            if ($cursorCreatedAt && $cursorId) {
                $cursorCreatedAt = Carbon::parse($cursorCreatedAt);

                $q->where(function ($w) use ($cursorCreatedAt, $cursorId) {
                    $w->where('created_at', '<', $cursorCreatedAt)
                        ->orWhere(function ($w2) use ($cursorCreatedAt, $cursorId) {
                            $w2->where('created_at', '=', $cursorCreatedAt)
                                ->where('id', '<', $cursorId);
                    });
                });
            }
        }

        $items = $q->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1) // fetch one extra to know has_more for next page
            ->get();

        $hasMore = $items->count() > $limit;
        if ($hasMore) {
            $items = $items->take($limit);
        }

        $nextCursor = null;
        if ($hasMore && $items->isNotEmpty()) {
            $last = $items->last();
            $nextCursor = $last->created_at->toISOString() . '|' . $last->id;
        }

        Log::debug('AuditLogController::index', [
            response()->json([
                'data' => $items,
                'meta' => [
                    'limit' => $limit,
                    'has_more' => $hasMore,
                    'next_cursor' => $nextCursor,
                ],
            ])
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
        ]);
    }

    public function verify(string $id): JsonResponse
    {
        if(!Str::isUuid($id)) {
            return response()->json([
                'message' => 'Id must be a valid UUID',
                'errors'  => ['id' => ['Id must be a valid UUID']],
            ], 422);
        }

        $log = AuditLog::find($id);

        if (!$log) {
            return response()->json([
                'message' => 'Log not found',
            ], 404);
        }

        $expected = $this->computeChecksumForLog($log);
        $stored = (string) $log->checksum;

        $valid = hash_equals($stored, $expected);

        return response()->json([
            'data' => [
                'id' => $log->id,
                'valid' => $valid,
                'stored_checksum' => $stored,
                'expected_checksum' => $expected,
            ],
        ], 200);
    }

    /**
     * IMPORTANT: must match the exact checksum formula used in store()
     */
    private function computeChecksumForLog(AuditLog $log): string
    {
        $payloadSorted = $this->deepKsort($log->payload ?? []);

        $input = $this->canonicalJson([
            'id'            => $log->id,
            'request_id'    => $log->request_id,
            'actor_id'      => $log->actor_id ?? null,
            'action'        => $log->action,
            'resource_type' => $log->resource_type,
            'resource_id'   => $log->resource_id,
            'payload'       => $payloadSorted,
        ]);

        return hash('sha256', $input);
    }
}
