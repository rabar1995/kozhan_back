-- One "Commission" card per currency.
-- Replaces the separate commission_revenue / commission_expense account
-- types with a single credit-normal type in the revenue category:
--   revenue credits add to the card, expense debits subtract — so the
--   balance is the net commission earned (positive) or the net
--   commission cost (negative).
-- Existing P&L SQL keeps working because category stays 'revenue'.
--
-- Run once by the DB owner if the app role cannot insert the type
-- (idempotent; safe to re-run).
--
-- psql -h 127.0.0.1 -p 5433 -U postgres -d kozhanfx -f /tmp/single_commission_card.sql

INSERT INTO account_types (id, code, name, category, normal_balance, is_system)
VALUES (gen_random_uuid(), 'commission', 'Commission', 'revenue', 'credit', true)
ON CONFLICT (code) DO NOTHING;

-- Dormant legacy 'Commission Expense' cards from the earlier seed have no
-- ledger history in a fresh database; remove them so the Shared Wallets
-- list shows only the single Commission card per currency.
DELETE FROM accounts a
USING account_types t
WHERE t.id = a.account_type_id
  AND t.code = 'commission_expense'
  AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.account_id = a.id);
