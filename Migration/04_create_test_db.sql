-- Create pacelab_test as an exact duplicate of pacelab.
--
--   psql -U postgres -h 127.0.0.1 -f Migration/04_create_test_db.sql
--
-- TEMPLATE copies the whole database - schema, data, indexes, sequence
-- positions - in one step, which is both faster and more faithful than
-- replaying the schema and reloading the rows.
--
-- Postgres refuses to copy a database that has an open connection, so close
-- anything pointed at pacelab first. The terminate below does that for you;
-- it is harmless when there is nothing to terminate.
--
-- Re-runnable: the existing copy is dropped first. Never point this at
-- anything but the test database.
\set ON_ERROR_STOP on

SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = 'pacelab' AND pid <> pg_backend_pid();

DROP DATABASE IF EXISTS pacelab_test;

CREATE DATABASE pacelab_test TEMPLATE pacelab OWNER pacelab_app;

COMMENT ON DATABASE pacelab_test IS
  'Throwaway duplicate of pacelab. Tests write here. Never the live database.';

-- Hand the whole test database to pacelab_app.
--
-- The tables and sequences were copied from pacelab, where postgres owns
-- them, so the application role could read and write rows but not reset a
-- sequence. The test harness needs to: it empties every table between runs
-- and the tests assume ids start at 1, which is what a fresh SQLite file
-- gives them.
--
-- This is the TEST database only. In pacelab the application role keeps the
-- narrow grants from 03_grants.sql and cannot alter anything.
\connect pacelab_test

DO $$
DECLARE r record;
BEGIN
    FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
        EXECUTE format('ALTER TABLE public.%I OWNER TO pacelab_app', r.tablename);
    END LOOP;
    FOR r IN SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' LOOP
        EXECUTE format('ALTER SEQUENCE public.%I OWNER TO pacelab_app', r.sequencename);
    END LOOP;
END $$;
