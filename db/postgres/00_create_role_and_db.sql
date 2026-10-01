-- Run this ONCE as the postgres superuser, on the local development machine
-- and again later on Radiant.
--
--   psql -U postgres -h 127.0.0.1 -f db/postgres/00_create_role_and_db.sql \
--        -v approle_password="'choose-something'"
--
-- Replace the password. It is passed on the command line rather than written
-- here because this file is committed and a password never is.
--
-- The application connects as pacelab_app, never as postgres. That is PRI's
-- rule and it is the difference between a bug dropping a table and a bug
-- failing with "permission denied".

\set ON_ERROR_STOP on

CREATE ROLE pacelab_app LOGIN PASSWORD :approle_password;

CREATE DATABASE pacelab OWNER pacelab_app ENCODING 'UTF8';

\connect pacelab

-- Nothing the application does needs to create or drop a table, so it is not
-- granted that. Schema changes are applied deliberately, as the superuser,
-- from the numbered files beside this one.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO pacelab_app;

-- Applied after 01_schema.sql has created the tables:
--   GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO pacelab_app;
--   GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO pacelab_app;
-- 02_grants.sql does exactly that, so run it after the schema.
