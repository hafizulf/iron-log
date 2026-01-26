<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    public $incrementing = false;
    protected $keyType = 'string';

    // append-only (no updated_at)
    public $timestamps = false;

    protected $fillable = [
        'id',
        'request_id',
        'actor_id',
        'action',
        'resource_type',
        'resource_id',
        'payload',
        'checksum',
    ];

    protected $casts = [
        'payload' => 'array',    // jsonb
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Audit logs are append-only.'));
        static::deleting(fn () => throw new \RuntimeException('Audit logs are append-only.'));
    }
}
