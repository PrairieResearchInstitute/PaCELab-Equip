<?php
/**
 * Copy data/lab.sqlite into Postgres, then prove the copy.
 *
 * Reads the connection from the environment (.env), so no password is ever
 * written into a file or passed on a command line.
 *
 *   tools\php\php.exe -c tools\php\php.ini -d extension_dir=tools\php\ext ^
 *       Migration/load-data.php
 *
 * Safe to re-run: it truncates the Postgres tables first and never writes to
 * the SQLite database, which is opened read-only.
 *
 * Verification is the point of this script, not a postscript to it. It
 * compares row counts per table and then every row of every table field by
 * field, because a load that silently drops or mangles rows looks exactly
 * like one that worked.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Minimal .env reader. No dependencies, by project convention. */
function load_env(string $path): void
{
    if (!is_file($path)) {
        fwrite(STDERR, "No .env at $path. Copy .env.example to .env first.\n");
        exit(1);
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        putenv(trim($k) . '=' . $v);
    }
}

load_env($root . DIRECTORY_SEPARATOR . '.env');

$sqlitePath = $root . '/data/lab.sqlite';
if (!is_file($sqlitePath)) {
    fwrite(STDERR, "No SQLite database at $sqlitePath\n");
    exit(1);
}

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    getenv('PGHOST') ?: '127.0.0.1',
    getenv('PGPORT') ?: '5432',
    getenv('PGDATABASE') ?: 'pacelab'
);

try {
    $pg = new PDO($dsn, getenv('PGUSER') ?: '', getenv('PGPASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect to Postgres: " . $e->getMessage() . "\n");
    exit(1);
}

$sq = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$sq->exec('PRAGMA query_only = 1');

/*
 * Load order follows the foreign keys. reservations comes after
 * usage_records because it points at it.
 */
$tables = [
    'units', 'labs', 'grants', 'equipment', 'lab_members', 'equipment_costs',
    'export_batches', 'usage_records', 'reservations', 'email_log',
    'admin_users', 'login_attempts', 'picklists', 'settings',
];

echo "Loading " . count($tables) . " tables\n";

/*
 * DELETE rather than TRUNCATE, deliberately. TRUNCATE is its own privilege
 * and pacelab_app does not have it, because the application never truncates
 * anything - granting it here to save a few milliseconds would widen what a
 * defect in the running application could do. Deleting in reverse dependency
 * order respects the foreign keys; the identity sequences are reset below in
 * any case.
 */
foreach (array_reverse($tables) as $t) {
    $pg->exec('DELETE FROM "' . $t . '"');
}

$loaded = [];
foreach ($tables as $t) {
    $rows = $sq->query('SELECT * FROM "' . $t . '"')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        $loaded[$t] = 0;
        printf("  %-16s %4d\n", $t, 0);
        continue;
    }
    $cols = array_keys($rows[0]);
    $sql = 'INSERT INTO "' . $t . '" ('
        . implode(', ', array_map(static fn ($c) => '"' . $c . '"', $cols))
        . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $stmt = $pg->prepare($sql);

    $pg->beginTransaction();
    foreach ($rows as $r) {
        // SQLite hands back '' for a DATE that was never set in some rows;
        // Postgres will not cast that, and null is what it means.
        $vals = [];
        foreach ($cols as $c) {
            $v = $r[$c];
            $vals[] = ($v === '' && preg_match('/_date$|^covers_|^period_/', $c)) ? null : $v;
        }
        $stmt->execute($vals);
    }
    $pg->commit();

    $loaded[$t] = count($rows);
    printf("  %-16s %4d\n", $t, count($rows));
}

/*
 * Identity sequences are NOT reset here. setval() needs UPDATE on the
 * sequence and pacelab_app has only USAGE, which is all nextval() requires.
 * Run Migration/05_reset_sequences.sql as the superuser after this, or the
 * next insert collides with an existing id.
 */
echo "Sequences: run Migration/05_reset_sequences.sql as superuser
";

/*
 * Verify. Row counts first, then every row compared field by field.
 */
echo "Verifying\n";
$problems = 0;
$checked = 0;
foreach ($tables as $t) {
    $n = (int) $pg->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
    if ($n !== $loaded[$t]) {
        printf("  %-16s COUNT MISMATCH sqlite=%d postgres=%d\n", $t, $loaded[$t], $n);
        $problems++;
        continue;
    }

    $src = $sq->query('SELECT * FROM "' . $t . '"')->fetchAll(PDO::FETCH_ASSOC);
    if ($src === []) {
        printf("  %-16s ok (empty)\n", $t);
        continue;
    }
    $pkCol = array_keys($src[0])[0];
    $dst = [];
    foreach ($pg->query('SELECT * FROM "' . $t . '"')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dst[(string) $r[$pkCol]] = $r;
    }

    $bad = 0;
    foreach ($src as $r) {
        $key = (string) $r[$pkCol];
        if (!isset($dst[$key])) {
            $bad++;
            continue;
        }
        foreach ($r as $c => $v) {
            $o = $dst[$key][$c] ?? null;
            // Compare loosely across type systems: SQLite returns everything
            // as a string, Postgres returns typed values, and '' and null
            // mean the same thing for the date columns normalised above.
            $a = $v === null ? '' : (string) $v;
            $b = $o === null ? '' : (string) $o;
            if (is_numeric($a) && is_numeric($b)) {
                if ((float) $a !== (float) $b) {
                    $bad++;
                    break;
                }
            } elseif (rtrim($a) !== rtrim($b)) {
                // Dates come back as Y-m-d from both once cast.
                if (strtotime($a) === false || strtotime($a) !== strtotime($b)) {
                    $bad++;
                    break;
                }
            }
        }
        $checked++;
    }
    if ($bad > 0) {
        printf("  %-16s %d row(s) differ\n", $t, $bad);
        $problems++;
    } else {
        printf("  %-16s ok (%d rows match field for field)\n", $t, count($src));
    }
}

echo "\n";
if ($problems === 0) {
    echo "VERIFIED: $checked rows across " . count($tables) . " tables match the SQLite source.\n";
    exit(0);
}
echo "FAILED: $problems table(s) did not verify. The Postgres copy is not trustworthy.\n";
exit(1);
