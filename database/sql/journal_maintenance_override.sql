-- Run this once on the kozhanfx database (pgAdmin "Query Tool" on
-- the kozhanfx DB, or psql -h 127.0.0.1 -p 5433 -U postgres -d kozhanfx).
-- It is idempotent — safe to run repeatedly.
--
-- Makes the journal immutability trigger maintenance-aware: journal
-- entries stay immutable by default, but a transaction-local
-- maintenance window (app.journal_maintenance = 'on') allows the
-- controlled force-delete of a wallet with zero balance.

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
