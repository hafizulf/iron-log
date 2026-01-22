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

        $id = (string) Str::uuid();
        $actorId = $data['actor_id'] ?? null;
        $payload = $data['payload'] ?? [];
        $payloadSorted = $this->deepKsort($payload);

        $checksumInput = $this->canonicalJson([
            'id'            => $id,
            'actor_id'      => $actorId,
            'action'        => $data['action'],
            'resource_type' => $data['resource_type'],
            'resource_id'   => $data['resource_id'],
            'payload'       => $payloadSorted,
        ]);

        $checksum = hash('sha256', $checksumInput);

        $log = AuditLog::create([
            'id'            => $id,
            'actor_id'      => $actorId,
            'action'        => $data['action'],
            'resource_type' => $data['resource_type'],
            'resource_id'   => $data['resource_id'],
            'payload'       => $payloadSorted,
            'checksum'      => $checksum,
        ]);

        return response()->json([
            'ok' => true,
            'id' => $log->getAttribute('id'),
        ], 201);
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
        return $keys !== range(0, count($arr) - 1);
    }
}
