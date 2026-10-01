-- Run as the superuser AFTER 01_schema.sql.
--
--   psql -U postgres -h 127.0.0.1 -d pacelab -f db/postgres/02_grants.sql
--
-- Least privilege: the application reads and writes rows and nothing else.
-- It cannot create, alter or drop a table, so a defect cannot cost you the
-- schema.
\set ON_ERROR_STOP on

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO pacelab_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO pacelab_app;

-- So a table added by a later migration is reachable without repeating this.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO pacelab_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO pacelab_app;
