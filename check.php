<?php
/**
 * check.php — Server capability test.
 *
 * This is the first deliverable and it is deliberately standalone: it requires
 * no other file in this application, so it can be copied to the campus web
 * directory on its own and answer the hosting question in under a minute.
 *
 * Delete this file once the application is installed and running.
 */

declare(strict_types=1);

$results = [];

/** Record one check row. $status is one of ok, warn, fail. */
function check(string $label, string $status, string $detail): void
{
    $GLOBALS['results'][] = ['label' => $label, 'status' => $status, 'detail' => $detail];
}

// --- PHP version -----------------------------------------------------------
// Eight is the floor. The code is written to run on anything from there up and
// is tested on the current release; a warning rather than a failure below the
// tested range, because "older than we have tried" is worth saying out loud
// without refusing to run.
$phpOk     = version_compare(PHP_VERSION, '8.0.0', '>=');
$phpTested = version_compare(PHP_VERSION, '8.3.0', '>=');
check(
    'PHP version',
    $phpOk ? ($phpTested ? 'ok' : 'warn') : 'fail',
    PHP_VERSION . ($phpOk
        ? ($phpTested
            ? ' (8.0 or later required)'
            : ' — runs, but the application is tested on 8.3 and later. Worth upgrading.')
        : ' — this application requires PHP 8.0 or later')
);

// --- PDO and the SQLite driver --------------------------------------------
if (!extension_loaded('pdo')) {
    check('PDO extension', 'fail', 'Not loaded. The application cannot reach a database without PDO.');
    $drivers = [];
} else {
    $drivers = PDO::getAvailableDrivers();
    check('PDO extension', 'ok', 'Loaded.');
}

$hasSqlite = in_array('sqlite', $drivers, true);
check(
    'PDO drivers available',
    $hasSqlite ? 'ok' : 'fail',
    $drivers ? implode(', ', $drivers) : 'none'
);
check(
    'PDO SQLite driver',
    $hasSqlite ? 'ok' : 'fail',
    $hasSqlite ? 'Present. The application can store its database.' : 'Missing. Ask the host to enable pdo_sqlite, or the application needs a port to another database.'
);

if ($hasSqlite && class_exists('SQLite3')) {
    check('SQLite library version', 'ok', SQLite3::version()['versionString']);
}

// --- Sessions --------------------------------------------------------------
$sessionOk = function_exists('session_start');
check(
    'Sessions',
    $sessionOk ? 'ok' : 'fail',
    $sessionOk ? 'Available (used for sign-in and identity).' : 'Unavailable. Administrator sign-in cannot work.'
);

// --- The data directory ----------------------------------------------------
$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$madeDir = false;

if (!is_dir($dataDir)) {
    $madeDir = @mkdir($dataDir, 0770);
    check(
        'data/ directory',
        $madeDir ? 'ok' : 'fail',
        $madeDir ? 'Did not exist; created it just now.' : 'Does not exist and could not be created. Create it by hand and grant the web server write permission.'
    );
} else {
    check('data/ directory', 'ok', 'Exists at ' . $dataDir);
}

if (is_dir($dataDir)) {
    // Actually write a file rather than asking is_writable(). That function
    // reports false on a OneDrive or Dropbox placeholder folder, on some
    // network shares, and wherever Windows ACLs disagree with the POSIX bits,
    // even though writing works perfectly. Trying it is the only honest test.
    $probeFile = $dataDir . DIRECTORY_SEPARATOR . 'write-probe.tmp';
    $wrote     = @file_put_contents($probeFile, 'probe');
    $writable  = ($wrote !== false);
    @unlink($probeFile);

    check(
        'data/ writable by the web server',
        $writable ? 'ok' : 'fail',
        $writable
            ? 'Yes. Wrote a file here and removed it again.'
            : 'No. The web server process could not create a file here, so the database cannot be created. '
              . 'Grant the web server account write permission on this directory.'
    );

    // The real test: actually build a SQLite file, write to it, read it back.
    if ($writable && $hasSqlite) {
        $probe = $dataDir . DIRECTORY_SEPARATOR . 'capability-probe.sqlite';
        @unlink($probe);
        try {
            $pdo = new PDO('sqlite:' . $probe, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE probe (id INTEGER PRIMARY KEY, note TEXT)');
            $stmt = $pdo->prepare('INSERT INTO probe (note) VALUES (?)');
            $stmt->execute(['round trip']);
            $back = $pdo->query('SELECT note FROM probe')->fetchColumn();

            // Every handle has to go before the file can be deleted. Windows
            // keeps the file locked while a statement still references the
            // connection, so dropping $pdo alone is not enough.
            $stmt = null;
            $pdo  = null;
            @unlink($probe);
            check(
                'SQLite read/write round trip',
                $back === 'round trip' ? 'ok' : 'fail',
                $back === 'round trip'
                    ? 'Created a database, wrote a row with a prepared statement, and read it back.'
                    : 'The value written did not come back. Something is wrong with the SQLite driver.'
            );
        } catch (Throwable $e) {
            @unlink($probe);
            check('SQLite read/write round trip', 'fail', 'Failed: ' . $e->getMessage());
        }
    }
}

// --- Protection of the database file --------------------------------------
$server = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$isApache = stripos($server, 'apache') !== false;
check(
    'Web server',
    'ok',
    $server
);
check(
    '.htaccess protection for data/',
    $isApache ? 'ok' : 'warn',
    $isApache
        ? 'Apache detected, so the .htaccess shipped in data/ will deny web access to the database file.'
        : 'This server may ignore .htaccess. Confirm that data/lab.sqlite cannot be downloaded over the web, or move the database outside the web root (see README).'
);

// --- Nice to have ----------------------------------------------------------
check('Date/time default', ini_get('date.timezone') ? 'ok' : 'warn', ini_get('date.timezone') ?: 'Not set in php.ini. The application sets America/Chicago itself, so this is informational.');
check('Script owner', 'ok', function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
    : (get_current_user() ?: 'unknown'));
check('Application directory', 'ok', __DIR__);

// --- Verdict ---------------------------------------------------------------
$failures = 0;
$warnings = 0;
foreach ($results as $r) {
    if ($r['status'] === 'fail') { $failures++; }
    if ($r['status'] === 'warn') { $warnings++; }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Server capability check</title>
<!-- Styles are inline here, and only here, because this page ships on its own
     before the rest of the application (including assets/style.css) exists. -->
<style>
  body { font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f4f5f7; color: #1d2125; }
  main { max-width: 820px; margin: 2rem auto; padding: 0 1rem 3rem; }
  h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
  p.sub { color: #5b6672; margin: 0 0 1.5rem; }
  .verdict { padding: 1rem 1.25rem; border-radius: 6px; margin-bottom: 1.5rem; border: 1px solid; }
  .verdict h2 { margin: 0 0 .35rem; font-size: 1.1rem; }
  .verdict p { margin: 0; }
  .verdict.pass { background: #e8f6ed; border-color: #9dd3b2; }
  .verdict.warn { background: #fdf5e2; border-color: #e6cf8b; }
  .verdict.fail { background: #fdecea; border-color: #efab9f; }
  table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dfe3e8; border-radius: 6px; overflow: hidden; }
  th, td { text-align: left; padding: .6rem .75rem; border-bottom: 1px solid #eceff2; vertical-align: top; }
  th { background: #f8f9fa; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: #5b6672; }
  tr:last-child td { border-bottom: 0; }
  td.status { white-space: nowrap; font-weight: 600; width: 1%; }
  .ok { color: #1c7a45; }
  .warn { color: #8a6300; }
  .fail { color: #b3261e; }
  td.label { width: 32%; font-weight: 500; }
  td.detail { color: #3c4650; }
  footer { margin-top: 1.5rem; color: #5b6672; font-size: .875rem; }
  code { background: #eceff2; padding: .1em .35em; border-radius: 3px; }
</style>
</head>
<body>
<main>
  <h1>Server capability check</h1>
  <p class="sub">Shared Laboratory Equipment System &middot; run this before installing anything else.</p>

<?php if ($failures === 0 && $warnings === 0): ?>
  <div class="verdict pass">
    <h2>This server can run the application.</h2>
    <p>PHP and PDO SQLite are both present and the data directory is writable. Proceed to <code>install.php</code>.</p>
  </div>
<?php elseif ($failures === 0): ?>
  <div class="verdict warn">
    <h2>This server can run the application, with <?= $warnings ?> item<?= $warnings === 1 ? '' : 's' ?> to confirm.</h2>
    <p>Nothing blocks installation. Read the amber rows below and confirm each one, then proceed to <code>install.php</code>.</p>
  </div>
<?php else: ?>
  <div class="verdict fail">
    <h2><?= $failures ?> requirement<?= $failures === 1 ? '' : 's' ?> not met.</h2>
    <p>The application will not run as delivered. The red rows below name what is missing. If the PDO SQLite driver is the problem, ask the host whether it can be enabled before considering a port to another database.</p>
  </div>
<?php endif; ?>

  <table>
    <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
    <tbody>
<?php foreach ($results as $r): ?>
      <tr>
        <td class="label"><?= e($r['label']) ?></td>
        <td class="status <?= e($r['status']) ?>"><?= $r['status'] === 'ok' ? 'Pass' : ($r['status'] === 'warn' ? 'Confirm' : 'Fail') ?></td>
        <td class="detail"><?= e($r['detail']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>

  <footer>
    <p>Generated <?= e(date('F j, Y \a\t g:i a')) ?>. This page writes nothing permanent: any probe database it creates is deleted before the page finishes.</p>
  </footer>
</main>
</body>
</html>
