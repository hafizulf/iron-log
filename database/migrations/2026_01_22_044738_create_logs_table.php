<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->string('action');
            $table->string('resource_type');
            $table->string('resource_id');
            $table->jsonb('payload');
            $table->char('checksum', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('actor_id');
            $table->index(['resource_type', 'resource_id']);
            $table->index('created_at');
            $table->index('payload', null, 'gin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
