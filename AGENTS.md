# Working on the Shared Laboratory Equipment system

Read this before the first edit. Several things here look wrong and are
deliberate; a few look harmless and have already cost a day each.

---

## What this is

A booking, use-recording and grant-billing system for instruments that more
than one laboratory shares. Illinois Natural History Survey, Prairie Research
Institute.

PHP 8 and SQLite. **No Composer, no npm, no build step, no CDN for anything the
application itself renders.** It is meant to be unzipped into a campus web
directory and opened. That constraint came from the specification and is the
reason the stack is not the one in `pri-ai-project-template` — do not "improve"
it into Docker and FastAPI.

33 files, about 400 KB. `BUILD FOR SERVER.bat` makes the deployable zip.

---

## Run the tests. They are not decoration.

```
RUN TESTS.bat            or   php tests/run-tests.php
```

111 checks. They build a throwaway database from `includes/schema.php` — the
same source `install.php` uses — so a test can never drift from production the
way a hand-written `CREATE TABLE` would. They never open `data/lab.sqlite`.

**Three real bugs have been caught by adding a check rather than by reading
code.** If you fix something that failed silently, add the check that would
have caught it, then break the code on purpose and confirm the check fails. A
verification that cannot fail is decoration.

---

## Rules that cost money or leak data if broken

**A charge keeps the rate it was made at.** `usage_records` stores
`rate_charged`, `rate_unit_charged` and `receiving_subaccount` at the moment of
entry. Changing an instrument's rate must never alter a charge already made.
This is the single most important rule in the system.

**CFOPA is five segments.** `1-303631-375002-375150-A51`. Four segments
normalise to `-A00`, the parent. The billing file goes straight to the business
office; a code with a segment missing is a line they cannot post. Unique per
laboratory, not globally — two labs charging one award is normal.

**Laboratories cannot see each other.** `equipment_by_id()` and
`grant_by_id()` are the choke points; every screen loads through them. Three
cross-laboratory leaks were found by hand during the build, which is why the
suite tests isolation by attack rather than by inspection.

**Nothing is deleted.** Charges void with a reason and stay in the table.
Instruments retire and keep their history. Export batches survive being
reopened. An auditor must be able to find everything that ever happened.

**The application never sends mail.** It composes a message, hands it to
Outlook with `mailto:`, and logs the exact words. The log says *written* or
*opened* — never *sent*, because it cannot know that. Do not make it claim
otherwise.

---

## Traps that have already bitten

**Named placeholders must be bound.** SQLite binds a missing named parameter as
NULL rather than raising, so `WHERE lab_id = :lab` with no `:lab` silently
matches nothing — no error, no warning, an empty table that looks like a quiet
week. This shipped in three queries and made the Reservations screen useless
for months. A test now scans every file for it.

**Paths in this project contain a space.** `Start-Process` does not quote its
arguments. An unquoted `-t` split the document root and PHP exited instantly;
an unquoted `-File` meant a script never ran and wrote no log at all. Quote
every path handed to `Start-Process`.

**Do not estimate text height.** Two layout bugs came from advancing a cursor
by a guessed line count. Let the renderer lay text out — one text frame with
real paragraph spacing, not a box per item.

**`cmd /c "RESET ..."` runs the Windows `reset` command**, not your file. The
launcher is `FORGOT ADMIN PASSWORD.bat` for that reason. `START HERE.bat` has
the same hazard and works only because it is double-clicked.

---

## How it runs locally

`tools/supervisor.ps1` owns the server: it starts PHP on **port 8147**,
restarts it if it stops, and takes the daily backup. A Startup shortcut runs it
at logon. `SETUP ON THIS PC.bat` installs that on a new machine — the app
folder travels with OneDrive, the logon shortcut does not.

**Do not run it from two machines at once.** The database is inside the synced
folder; two writers produce OneDrive conflict copies and lose charges.

---

## Backups

`tools/backup.ps1` uses `VACUUM INTO`, not a file copy — copying a SQLite file
mid-write gives you something that usually opens and is occasionally torn.
Every backup is reopened and its row counts compared before it is kept.
`-Restore` runs the drill. `data/backup-path.txt` redirects the destination;
point it at Taiga once hosted.

---

## Decisions already taken

| Decision | Why |
|---|---|
| SQLite for now, **Postgres before production hosting** | Agreed with the project owner. Testing continues on SQLite; a virtual test server and the Postgres move come before real billing data. Everything goes through PDO, so keep it that way and do not write SQLite-specific SQL. |
| PHP, no framework | The spec: drop into a campus web directory, no build step. |
| Illinois toolkit from `cdn.toolkit.illinois.edu` | The one outside dependency. Approved branding; the app still works without it, just unstyled chrome. |
| `property_tag` matches PRI Facilities | Natural key shared with that system so an instrument can be reconciled without either owning the other. |
| Identity is a typed last name | `person_key` is the lowercased string. When NetID sign-on arrives, that column holds NetIDs and nothing else changes. Swap point is `current_user_name()` in `includes/auth.php`. |
| Repository on the owner's GitHub, not the PRI org | For now. |

---

## Before you hand anything over

- `RUN TESTS.bat` passes.
- `BUILD FOR SERVER.bat` builds. It refuses if the tests fail, works from an
  explicit ship list, and warns about any file on neither list.
- Nothing in `data/` is in the zip, and no `.bat` is either.
- On the server: run `check.php` **after** installing, before deleting it. It
  asks the server for `data/lab.sqlite` over HTTP and says in capitals if the
  database can be downloaded.
