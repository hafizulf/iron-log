<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'id'            => (string) Str::uuid(),
            'actor_id'      => null,
            'action'        => 'user.login',
            'resource_type' => 'user',
            'resource_id'   => (string) $this->faker->numberBetween(1, 1000),
            'payload'       => ['ip' => '127.0.0.1'],
            'checksum'      => Str::random(64), // temporary; overrided later
            'request_id'    => (string) Str::uuid(),
        ];
    }
}
