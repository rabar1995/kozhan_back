<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Optimization pass:
 *  - Drop unused Laravel default tables (sessions / password reset / queue).
 *  - Drop the unused exchange_rates feature table.
 *  - Drop dead views and a dead function (no model or code references).
 *  - Drop never-read columns (audit old_values, office contact fields,
 *    accounts.metadata, decorative remittances.exchange_rate and
 *    agents.commission_rate).
 *  - Add composite indexes for the real query patterns.
 *  - Rewrite fn_update_agent_balance as an O(1) delta update keyed on
 *    the agent wallet account only (the legacy receivable/payable
 *    3-way OR scan is retired).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Unused default tables (SESSION_DRIVER=file, no queue usage,
        //    no password reset flow).
        DB::statement('DROP TABLE IF EXISTS sessions CASCADE');
        DB::statement('DROP TABLE IF EXISTS password_reset_tokens CASCADE');
        DB::statement('DROP TABLE IF EXISTS failed_jobs CASCADE');
        DB::statement('DROP TABLE IF EXISTS job_batches CASCADE');
        DB::statement('DROP TABLE IF EXISTS jobs CASCADE');

        // 2. Exchange Rates feature is being removed end-to-end.
        DB::statement('DROP TABLE IF EXISTS exchange_rates CASCADE');

        // 3. Dead views + dead function.
        DB::statement('DROP VIEW IF EXISTS v_account_balances CASCADE');
        DB::statement('DROP VIEW IF EXISTS v_expenses CASCADE');
        DB::statement('DROP VIEW IF EXISTS v_remittances CASCADE');
        DB::statement('DROP VIEW IF EXISTS v_agent_balances CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS fn_validate_transaction(uuid)');

        // 4. Dead columns.
        DB::statement('ALTER TABLE audit_logs DROP COLUMN IF EXISTS old_values');
        DB::statement('ALTER TABLE offices DROP COLUMN IF EXISTS address');
        DB::statement('ALTER TABLE offices DROP COLUMN IF EXISTS phone');
        DB::statement('ALTER TABLE accounts DROP COLUMN IF EXISTS metadata');
        DB::statement('ALTER TABLE remittances DROP COLUMN IF EXISTS exchange_rate');
        DB::statement('ALTER TABLE agents DROP COLUMN IF EXISTS commission_rate');

        // 5. Indexes for the real query patterns.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_aca_account ON agent_currency_accounts(account_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_accounts_office_currency ON accounts(office_id, currency_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_remittances_office_status ON remittances(office_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_exchange_deals_office_dir_status ON exchange_deals(office_id, direction, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(user_id)');

        // 6. O(1) agent balance trigger (keyed on the agent wallet only).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.fn_update_agent_balance() RETURNS trigger
                LANGUAGE plpgsql
                AS $agent$
            DECLARE
                v_agent_id uuid;
            BEGIN
                SELECT aca.agent_id INTO v_agent_id
                FROM agent_currency_accounts aca
                WHERE aca.account_id = NEW.account_id
                LIMIT 1;

                IF v_agent_id IS NULL THEN
                    RETURN NEW;
                END IF;

                -- O(1) signed delta, identical math to fn_update_account_balance.
                UPDATE agents a
                SET net_balance = a.net_balance + CASE
                        WHEN at.normal_balance = 'debit'
                            THEN CASE WHEN NEW.entry_type = 'debit' THEN NEW.amount ELSE -NEW.amount END
                        ELSE CASE WHEN NEW.entry_type = 'credit' THEN NEW.amount ELSE -NEW.amount END
                    END,
                    updated_at = NOW()
                FROM accounts ac
                JOIN account_types at ON at.id = ac.account_type_id
                WHERE ac.id = NEW.account_id
                  AND a.id = v_agent_id;

                RETURN NEW;
            END;
            $agent$;
        SQL);
    }

    public function down(): void
    {
        // Non-destructive restore of structure only (dropped data is gone).
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sessions (
                id varchar NOT NULL PRIMARY KEY,
                user_id bigint,
                ip_address varchar(45),
                user_agent text,
                payload text NOT NULL,
                last_activity bigint NOT NULL
            );
        SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS sessions_last_activity_index ON sessions(last_activity)');
        DB::statement('CREATE INDEX IF NOT EXISTS sessions_user_id_index ON sessions(user_id)');

        DB::statement('ALTER TABLE offices ADD COLUMN IF NOT EXISTS address text');
        DB::statement('ALTER TABLE offices ADD COLUMN IF NOT EXISTS phone varchar');
        DB::statement('ALTER TABLE accounts ADD COLUMN IF NOT EXISTS metadata jsonb');
        DB::statement('ALTER TABLE remittances ADD COLUMN IF NOT EXISTS exchange_rate numeric(20,6) DEFAULT 0');
        DB::statement('ALTER TABLE agents ADD COLUMN IF NOT EXISTS commission_rate numeric(8,4) DEFAULT 0');
    }
};
