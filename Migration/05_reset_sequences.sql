-- Run as the superuser AFTER loading data with Migration/load-data.php.
--
--   psql -U postgres -h 127.0.0.1 -d pacelab -f Migration/05_reset_sequences.sql
--
-- Rows are loaded with their original ids so that every foreign key still
-- points where it did. Identity sequences do not advance when an id is
-- supplied explicitly, so without this the next insert collides with an
-- existing row and fails on the primary key.
--
-- This is superuser work on purpose. setval() needs UPDATE on the sequence,
-- while the running application only ever calls nextval(), which needs just
-- USAGE. Granting the application UPDATE to save this step would widen what
-- a defect in it could do, for no benefit.
--
-- Safe to run repeatedly.
\set ON_ERROR_STOP on

DO $$
DECLARE
    r      record;
    seq    text;
    newval bigint;
BEGIN
    FOR r IN
        SELECT c.relname AS table_name, a.attname AS pk
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_index i     ON i.indrelid = c.oid AND i.indisprimary
        JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY (i.indkey)
        WHERE n.nspname = 'public' AND c.relkind = 'r'
    LOOP
        seq := pg_get_serial_sequence('public.' || quote_ident(r.table_name), r.pk);
        CONTINUE WHEN seq IS NULL;          -- settings has a text primary key
        EXECUTE format(
            'SELECT COALESCE(MAX(%I), 0) + 1 FROM %I',
            r.pk, r.table_name
        ) INTO newval;
        PERFORM setval(seq, newval, false);
        RAISE NOTICE '% -> next id %', r.table_name, newval;
    END LOOP;
END $$;
