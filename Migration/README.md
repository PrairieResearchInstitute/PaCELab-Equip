# Migration

Moving PaCELab Equip from SQLite to Postgres, and the procedure for doing it
again on Radiant.

The application runs on SQLite by default and on Postgres when `.env` sets
`PACELAB_DRIVER=pgsql`. Nothing changes until that line changes.

**All 140 checks pass on both drivers.**

## Files, in run order

| | |
|---|---|
| `01_create_role_and_db.sql` | the `pacelab_app` role and the `pacelab` database |
| `02_schema.sql` | 14 tables, 15 indexes |
| `03_grants.sql` | least privilege for the application role |
| `04_create_test_db.sql` | `pacelab_test`, a `TEMPLATE` copy |
| `05_reset_sequences.sql` | after a data load |
| `load-data.php` | SQLite to Postgres, with verification |

## Standing it up

As the superuser, from the application root:

    psql -U postgres -h 127.0.0.1 -f Migration/01_create_role_and_db.sql -v approle_password="'choose-one'"
    psql -U postgres -h 127.0.0.1 -d pacelab -f Migration/02_schema.sql
    psql -U postgres -h 127.0.0.1 -d pacelab -f Migration/03_grants.sql

Then copy `.env.example` to `.env` and fill in `PGPASSWORD`. `.env` is ignored
by git and must never be committed.

## Moving the data across

    tools/php/php.exe -c tools/php/php.ini -d extension_dir=tools/php/ext Migration/load-data.php
    psql -U postgres -h 127.0.0.1 -d pacelab -f Migration/05_reset_sequences.sql

The loader copies every row and then proves it: row counts per table, then
every row compared field by field against the SQLite source. It opens the
SQLite database read-only and is safe to re-run.

The sequence reset is separate and runs as the superuser on purpose. Rows are
loaded with their original ids so the foreign keys still point where they did,
and Postgres does not advance an identity sequence when the id is supplied.
`setval()` needs UPDATE on the sequence, while the running application only
calls `nextval()`, which needs only USAGE. Granting the application UPDATE to
save this step would widen what a defect in it could do, for nothing.

For the same reason the loader uses DELETE rather than TRUNCATE: TRUNCATE is
its own privilege and the application does not have it.

## Running the tests against Postgres

By default the harness builds a throwaway SQLite file and pins itself to
sqlite, whatever `.env` says. That guard matters: a Postgres connection
ignores `LAB_DB_PATH`, so without it the tests would run against the live
database and write to it.

To exercise the Postgres path:

    PACELAB_TEST_DRIVER=pgsql tools/php/php.exe -c tools/php/php.ini -d extension_dir=tools/php/ext tests/run-tests.php

It runs against `pacelab_test` and refuses to start unless the database name
ends in `_test`, checked before it touches anything.

Create that database with `04_create_test_db.sql`. It copies the live database
with TEMPLATE and then hands the copy to `pacelab_app`, which is what lets the
harness empty every table and restart every identity sequence between runs.
The tests assume ids begin at 1 — `acting_as()` signs in as `admin_user_id` 1 —
which is what a fresh SQLite file gives them and what a reused Postgres
database does not. That ownership applies to the **test** database only; in
`pacelab` the application role still cannot create, alter or drop anything.

## What the move actually broke

Found by running the suite, not by reading the code:

- **`COLLATE NOCASE`**, in fourteen ORDER BY clauses. SQLite only. Replaced
  with `lower()`, which behaves identically on both rather than branching.
- **Date columns compared to `''`**, defensive code from SQLite's loose
  typing. Postgres rejects the comparison outright. Every date value was
  verified ISO or null before the load, so the guard was dead anyway.
- **`db_installed()` checked for the SQLite file.** On Postgres there is no
  such file, so it answered false forever and sent every visitor to the
  installer of a populated database.
- **The harness built its schema from SQLite DDL**, which Postgres refuses at
  AUTOINCREMENT.
- **Identity sequences did not rewind** between runs on a reused database.

`lastInsertId()` needed no change. PDO's pgsql driver falls back to `lastval()`
and returns the right id; thirteen call sites were left alone. Worth checking
before "fixing" them.

## Two things left faithful rather than correct

The translation preserves behaviour so that any difference after the move is
attributable to one change, not two. Both are worth fixing afterwards,
separately, with the tests watching:

- **Money is `double precision`**, because it was SQLite `REAL`. The columns
  are `equipment.rate`, `equipment_costs.amount`, `usage_records.rate_charged`,
  `usage_records.total_charge` and `export_batches.total_amount`. Floating
  point is the wrong type for currency; `numeric(12,2)` is right.
- **Booleans are `integer`**, because SQLite has none. `active`, `voided`,
  `exported`, `succeeded`, `protected` and `may_edit_code` would read better
  as `boolean`.

## On Radiant

The same three files against a different host, then the loader. Afterwards:

    pg_dump -Fc -U postgres -h HOST -d pacelab > backups/pacelab-YYYYMMDD.dump

Restore one into a scratch database and check the row counts before trusting
it. A backup that has never been restored is unproven.
