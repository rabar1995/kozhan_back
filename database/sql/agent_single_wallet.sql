-- Single net-position agent wallet.
-- 1) Seeds the agent_wallet account type (asset, debit-normal).
-- 2) Rewrites fn_update_agent_balance: agents.net_balance becomes the
--    signed current_balance of the agent's own accounts (sum across all
--    its accounts, sign per account type normal_balance), instead of
--    receivable - payable subtraction of the two legacy columns.
--
-- Run once on the kozhanfx database as the function's owner
-- (sudo -u postgres psql -d kozhanfx -f /tmp/agent_single_wallet.sql).
-- Idempotent: safe to run repeatedly.

INSERT INTO account_types (id, code, name, category, normal_balance, is_system)
VALUES (gen_random_uuid(), 'agent_wallet', 'Agent Wallet', 'asset', 'debit', true)
ON CONFLICT (code) DO NOTHING;

-- Single-wallet model: legacy pair columns become optional.
ALTER TABLE agent_currency_accounts ALTER COLUMN receivable_account_id DROP NOT NULL;
ALTER TABLE agent_currency_accounts ALTER COLUMN payable_account_id DROP NOT NULL;

CREATE OR REPLACE FUNCTION public.fn_update_agent_balance() RETURNS trigger
    LANGUAGE plpgsql
    AS $agent$
DECLARE
    v_agent_id uuid;
BEGIN
    -- Single-wallet model: only the agent_wallet account sheet counts.
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
