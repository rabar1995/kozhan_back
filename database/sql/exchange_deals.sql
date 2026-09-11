-- Exchange Deals tab
-- Idempotent. Safe to run multiple times.
-- Requires the core Kozhan FX tables: offices, currencies, account_types,
-- accounts, transactions, users (they already exist).

-- ============================================================
-- 0. Migration from the earlier crypto implementation (if applied).
-- ============================================================
DROP TABLE IF EXISTS crypto_transactions CASCADE;
DROP TABLE IF EXISTS crypto_platforms CASCADE;

-- Allow the new transaction type in the accounting engine.
-- Idempotent: re-adds the check only if exchange_deal is missing.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'transactions_tx_type_check'
            AND pg_get_constraintdef(oid) LIKE '%exchange_deal%'
    ) THEN
        ALTER TABLE transactions DROP CONSTRAINT transactions_tx_type_check;
        ALTER TABLE transactions ADD CONSTRAINT transactions_tx_type_check
            CHECK (((tx_type)::text = ANY ((ARRAY['remittance'::character varying,
                'commission'::character varying, 'expense'::character varying,
                'transfer'::character varying, 'adjustment'::character varying,
                'reversal'::character varying, 'exchange_deal'::character varying])::text[])));
    END IF;
END $$;

-- ============================================================
-- 1. exchange_deals — two-leg money deals between the owner and a
--    person, moving value between the owner's private wallets.
--
--    direction:
--      deal_send     owner sent an amount from one of his wallets
--                    to a person; the person owes it back
--                    (a settlement leg closes this deal later)
--      deal_receive  a person delivered an amount into one of the
--                    owner's wallets; the office owes it back
--      deal_settle   closing leg: the money came back / was paid out
--                    in another wallet; the numeric spread between
--                    the two legs is booked as profit/loss
--
--    deal_parent_id (deal_settle only) links the settle leg to the
--    deal_send / deal_receive record it closes.
-- ============================================================
CREATE TABLE IF NOT EXISTS exchange_deals (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    office_id uuid NOT NULL REFERENCES offices(id) ON DELETE CASCADE,
    tx_number varchar(50) NOT NULL,
    direction varchar(20) NOT NULL
        CHECK (direction IN ('deal_send','deal_receive','deal_settle')),
    counterparty_name varchar(255) NOT NULL,
    account_id uuid NOT NULL REFERENCES accounts(id),
    amount numeric(18,4) NOT NULL CHECK (amount > 0),
    currency_id uuid NOT NULL REFERENCES currencies(id),
    settle_account_id uuid NULL REFERENCES accounts(id),
    settle_currency_id uuid NULL REFERENCES currencies(id),
    settle_amount numeric(18,4) NULL,
    deal_parent_id uuid NULL REFERENCES exchange_deals(id),
    notes text NULL,
    booking_tx_id uuid NULL REFERENCES transactions(id),
    status varchar(20) NOT NULL DEFAULT 'completed'
        CHECK (status IN ('completed','cancelled')),
    created_by uuid NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    cancelled_at timestamptz NULL,
    cancelled_by uuid NULL REFERENCES users(id),
    cancel_reason text NULL,
    UNIQUE (office_id, tx_number)
);

CREATE INDEX IF NOT EXISTS idx_exchange_deals_office_created
    ON exchange_deals(office_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_exchange_deals_direction
    ON exchange_deals(office_id, direction);
CREATE INDEX IF NOT EXISTS idx_exchange_deals_status
    ON exchange_deals(office_id, status);
CREATE INDEX IF NOT EXISTS idx_exchange_deals_deal_parent
    ON exchange_deals(deal_parent_id) WHERE deal_parent_id IS NOT NULL;
