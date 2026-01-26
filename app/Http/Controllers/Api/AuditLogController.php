<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

            Log::info('Audit log created', ['data' => $log->toArray()]);
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
}
