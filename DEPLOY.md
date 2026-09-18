# Putting this on the server

The application is 33 files and 398 KB. There is nothing to compile, no package
manager, and no dependencies to fetch. You unzip a folder and open it in a
browser.

## Getting the package

Double-click **`BUILD FOR SERVER.bat`**. It runs the tests, refuses to build if
any fail, and writes `dist\shared-lab-equipment-<date>.zip`.

**Give IT that zip.** Do not give them the working folder: it carries 84 MB of
Windows PHP, a local test database, and a script that invents fake charges.

The build works from an explicit list of files rather than an exclusion pattern,
and warns about anything in the project that is on neither the ship list nor the
do-not-ship list — so a file added later cannot go missing silently.

The zip was tested by unpacking it on Linux tooling, installing it from nothing,
and walking every screen. It came up clean, with no PHP diagnostics of any kind.

## What is in it

    index.php          home.php           lab.php
    schedule.php       report.php         api.php
    check.php          install.php        admin-recovery.php
    DEPLOY.md          (this file)
    includes/          auth.php  db.php  functions.php  schema.php
                       .htaccess  web.config
    assets/            style.css  app.js  calendar.js
    admin/             all 15 .php files

## What is deliberately left out

| Leave behind | Why |
|---|---|
| `tools/` | 84 MB of Windows PHP. The server has its own. |
| `data/` | Your local database and its install lock. Copying it would put local test data on the server, and the lock would stop the installer. |
| `START HERE.bat`, `RUN TESTS.bat`, `Open Lab Equipment.url` | Windows launchers. Meaningless on a web server. |
| `seed-demo.php` | Creates fake instruments and charges. Do not put this where anybody can reach it. |
| `tests/` | Optional. Harmless, but it belongs with the source rather than on the server. |
| `.git/`, `.claude/`, `.gitignore`, `.gitattributes` | Not part of the application. |
| `*.docx` | The spec and the README. |

## What the server needs

PHP 8.0 or later with PDO SQLite and sessions. Nothing else — no Composer, no
extensions to install, no database server to provision.

It is developed and tested on PHP 8.3, and driven end to end with
`error_reporting=E_ALL` and `display_errors` on: no deprecation, no warning and
no notice comes out of any screen. The suite also refuses anything a newer PHP
has taken away — `utf8_encode`, `strftime`, `${var}` interpolation, implicitly
nullable parameters — so moving to 8.4 or 8.5 should be uneventful. `check.php`
warns if the server is older than 8.3 while still letting it run.

## Installing

1. Open `check.php`. It tests PHP, PDO SQLite, sessions, and whether it can
   write. Fix anything it reports before going on.
2. Open `install.php`. Name the installation, set the first administrator's
   username and password. It creates `data/`, writes the database, drops an
   `.htaccess` in `data/` denying web access to it, and locks itself.
3. **Delete `check.php` and `install.php`.** The installer locks itself, but
   deleting it is the version that does not depend on a lock file surviving.
4. Sign in at `admin/login.php`, create a laboratory under Administration →
   Laboratories, and add people to it.

## Confirm the database is not downloadable

**This is the one thing that must not be skipped.** `data/lab.sqlite` holds every
charge and every administrator password hash. If the web server will serve it,
anyone who guesses the URL downloads the lot.

The installer writes both `data/.htaccess` (Apache) and `data/web.config` (IIS),
and ships the same pair in `includes/`. Each server reads one and ignores the
other. **nginx reads neither** — it has no per-directory config at all — so on
nginx the deny has to go in the site configuration, or the database has to live
outside the web root.

`check.php` no longer guesses. It asks this server for `data/lab.sqlite` over
HTTP, exactly as an outsider would, and reports one of:

- **ok** — the request came back 403 or 404, or the database is outside the web root.
- **fail** — the request came back with the database. It says so in capitals. Do
  not put real data in until this is fixed.
- **warn** — it could not run the test, and tells you the URL to try by hand.

So: **run `check.php` again after installing, before deleting it.** Before
installation there is no database and the test has nothing to answer.

To move the database out of the web root — the safest arrangement, and the only
one that does not depend on server configuration — create `config.php` beside
`index.php` containing:

```php
<?php define('LAB_DB_PATH', '/var/www/private/lab.sqlite');
```

The directory must exist and be writable by the web server account.

## The one outside dependency

The approved Illinois header and footer are web components loaded from
Illinois-run hosts:

- `https://cdn.toolkit.illinois.edu/3/toolkit.css` and `toolkit.js`
- `https://use.typekit.net/etc1rtr.css` (the campus typefaces)

Nothing else leaves the server. If those hosts are unreachable, the application
still works — every screen it renders is plain HTML and its own stylesheet — but
the banner and footer fall back to unstyled text. That is the tradeoff for using
the approved branding rather than a copy of it that goes stale.

## Before any change goes up

Double-click `RUN TESTS.bat`, or run

```bash
php tests/run-tests.php
```

96 checks. It builds a scratch database in the temporary folder and never opens
`data/lab.sqlite`, so it is safe to run against a live installation.

## If the administrator password is lost

From a shell on the server, in the application folder:

```bash
php admin-recovery.php list
```

`reset`, `add` and `unlock` do what they say. It refuses to run over the web.
