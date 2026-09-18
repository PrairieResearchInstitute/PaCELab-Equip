# Putting this on the server

The application is 29 files and 445 KB. There is nothing to build, nothing to
install, and no package manager. You copy a folder and open it in a browser.

This was tested by doing exactly that: the deployment set below was copied to an
empty folder, installed from nothing, and every screen walked through. It came
up clean.

## What to copy

Copy these, keeping the folder structure:

    index.php          home.php           lab.php
    schedule.php       report.php         api.php
    check.php          install.php        admin-recovery.php
    includes/          auth.php  db.php  functions.php  schema.php
    assets/            style.css  app.js  calendar.js
    admin/             all 15 .php files

## What NOT to copy

| Leave behind | Why |
|---|---|
| `tools/` | 84 MB of Windows PHP. The server has its own. |
| `data/` | Your local database and its install lock. Copying it would put local test data on the server, and the lock would stop the installer. |
| `START HERE.bat`, `RUN TESTS.bat`, `Open Lab Equipment.url` | Windows launchers. Meaningless on a web server. |
| `seed-demo.php` | Creates fake instruments and charges. Do not put this where anybody can reach it. |
| `tests/` | Optional. Harmless, but it belongs with the source rather than on the server. |
| `.git/`, `.claude/`, `.gitignore`, `.gitattributes` | Not part of the application. |
| `*.docx` | The spec and the README. |

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

The installer writes `data/.htaccess`. On Apache that is enough. **On nginx or
IIS it does nothing** — put the database outside the web root instead: create
`config.php` beside `index.php` containing

```php
<?php define('LAB_DB_PATH', '/home/account/private/lab.sqlite');
```

Then browse to `data/lab.sqlite` yourself and confirm you get a 403 and not a
download. Do this before anybody puts real data in.

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
