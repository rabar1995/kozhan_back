-- Logos (Agents + Wallets): manually pasted image URLs
-- Idempotent. Safe to run multiple times.

ALTER TABLE agents
    ADD COLUMN IF NOT EXISTS logo_url varchar(255) NULL,
    DROP COLUMN IF EXISTS logo_path;

ALTER TABLE accounts
    ADD COLUMN IF NOT EXISTS logo_url varchar(255) NULL,
    DROP COLUMN IF EXISTS logo_path;
