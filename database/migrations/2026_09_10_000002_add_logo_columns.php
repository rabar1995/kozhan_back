<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Logos for agents and wallets: manually pasted image URLs
 * (no upload handling).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlFile = database_path('sql/logos.sql');

        if (is_file($sqlFile)) {
            DB::unprepared(file_get_contents($sqlFile));
        }
    }

    public function down(): void
    {
        foreach (['agents', 'accounts'] as $table) {
            foreach (['logo_url'] as $column) {
                if (DB::getSchemaBuilder()->hasColumn($table, $column)) {
                    DB::getSchemaBuilder()->table($table, function ($t) use ($column) {
                        $t->dropColumn($column);
                    });
                }
            }
        }
    }
};
