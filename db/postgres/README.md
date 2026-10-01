# Postgres

The application runs on SQLite by default and on Postgres when `.env` sets
`PACELAB_DRIVER=pgsql`. Nothing changes until that line changes.

## Standing it up

Run as the superuser, from the application root:

    psql -U postgres -h 127.0.0.1 -f db/postgres/00_create_role_and_db.sql -v approle_password="'choose-one'"
    psql -U postgres -h 127.0.0.1 -d pacelab -f db/postgres/01_schema.sql
    psql -U postgres -h 127.0.0.1 -d pacelab -f db/postgres/02_grants.sql

Then copy `.env.example` to `.env` and fill in `PGPASSWORD`. `.env` is
ignored by git and must never be committed.

## Moving the data across

    tools/php/php.exe -c tools/php/php.ini -d extension_dir=tools/php/ext tools/migrate-to-postgres.php
    psql -U postgres -h 127.0.0.1 -d pacelab -f db/postgres/03_reset_sequences.sql

The loader copies every row and then proves it: row counts per table, then
every row compared field by field against the SQLite source. It opens the
SQLite database read-only and is safe to re-run.

The sequence reset is separate and runs as the superuser on purpose. Rows
are loaded with their original ids so the foreign keys still point where
they did, and Postgres does not advance an identity sequence when the id is
supplied. `setval()` needs `UPDATE` on the sequence, while the running
application only ever calls `nextval()`, which needs `USAGE`. Granting the
application `UPDATE` to save this step would widen what a defect in it could
do, for nothing.

For the same reason the loader uses `DELETE` rather than `TRUNCATE`:
`TRUNCATE` is its own privilege and the application does not have it.

## The test suite is SQLite only

`tests/run-tests.php` builds a throwaway SQLite file and points
`LAB_DB_PATH` at it. A Postgres connection ignores `LAB_DB_PATH`, so without
a guard the tests would run against the real database and write to it. The
harness therefore forces `PACELAB_DRIVER=sqlite` for its own run, whatever
`.env` says.

Exercising the Postgres path properly needs a throwaway Postgres database of
its own — create `pacelab_test`, apply the schema, and point the harness at
it. That is worth doing before the Radiant cutover and is not done yet.

## Two things left faithful rather than correct

The translation preserves behaviour so that any difference after the move is
attributable to one change, not two. Both of these are worth fixing
afterwards, separately and with the tests watching:

- **Money is `double precision`**, because it was SQLite `REAL`. The columns
  are `equipment.rate`, `equipment_costs.amount`, `usage_records.rate_charged`,
  `usage_records.total_charge` and `export_batches.total_amount`. Floating
  point is the wrong type for currency; `numeric(12,2)` is right.
- **Booleans are `integer`**, because SQLite has no boolean. `active`,
  `voided`, `exported`, `succeeded`, `protected` and `may_edit_code` would
  all read better as `boolean`.

## Backups

    pg_dump -Fc -U postgres -h HOST -d pacelab > backups/pacelab-YYYYMMDD.dump

Restore one into a scratch database and check the row counts before trusting
it. A backup that has never been restored is unproven.
