<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit middleware records "METHOD path/{uuid}" in the action column,
 * which easily exceeds the original varchar(50). Widen it so mutations
 * with UUIDs in the path do not fail after the response commits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 50)->change();
        });
    }
};
