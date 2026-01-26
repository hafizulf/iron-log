<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // for cursor pagination
            $table->index(
                ['created_at', 'id'],
                'audit_logs_created_at_id_idx'
            );
        });

        // JSONB expression
        DB::statement(
            "CREATE INDEX audit_logs_payload_ip_idx
             ON audit_logs ((payload->>'ip'))"
        );
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_created_at_id_idx');
        });

        DB::statement(
            "DROP INDEX IF EXISTS audit_logs_payload_ip_idx"
        );
    }
};
