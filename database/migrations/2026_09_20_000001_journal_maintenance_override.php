<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the ledger immutability trigger maintenance-aware.
 *
 * By default journal entries stay immutable (UPDATE and DELETE raise an
 * exception). A maintenance window can be opened explicitly by setting
 * the transaction-local GUC app.journal_maintenance to 'on'
 * (SELECT set_config('app.journal_maintenance', 'on', true)), which
 * allows controlled cleanup — e.g. the owner force-deleting a wallet
 * with zero balance — while every other code path keeps failing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $current = DB::selectOne(
            'SELECT pg_get_functiondef(\'public.fn_prevent_journal_modify()\'::regprocedure) AS def'
        )?->def ?? '';

        if (str_contains($current, 'journal_maintenance')) {
            return; // already applied (e.g. by the DB admin directly)
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.fn_prevent_journal_modify() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
            BEGIN
                IF coalesce(current_setting('app.journal_maintenance', true), 'off') = 'on' THEN
                    RETURN NULL;
                END IF;
                RAISE EXCEPTION 'Journal entries are immutable. Use void/reversal instead.';
                RETURN NULL;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.fn_prevent_journal_modify() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
            BEGIN
                RAISE EXCEPTION 'Journal entries are immutable. Use void/reversal instead.';
                RETURN NULL;
            END;
            $$;
        SQL);
    }
};
