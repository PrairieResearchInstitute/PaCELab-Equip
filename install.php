<?php
/**
 * install.php — Creates the schema, seeds the unit list and the interface text,
 * and registers the first administrator. Locks itself after a successful run.
 *
 * Run check.php first.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/schema.php';

$lockFile = dirname(LAB_DB_PATH) . '/.installed';

// --- The lock --------------------------------------------------------------
if (file_exists($lockFile)) {
    page_header('Already installed', ['chromeless' => true, 'mainClass' => 'page narrow']);
    echo '<h1>Already installed</h1>';
    echo '<p class="lede">This application has been installed and the installer has locked itself.</p>';
    echo '<p>Delete <code>' . h(basename(dirname($lockFile)) . '/.installed') . '</code> only if you intend to install over the existing database.</p>';
    echo '<p><a class="button" href="index.php">Open the application</a></p>';
    page_footer(['chromeless' => true]);
    exit;
}


// --- Handle the form -------------------------------------------------------
$errors   = [];
$labName  = trim((string) ($_POST['lab_name'] ?? 'Shared Laboratory Equipment'));
$username = trim((string) ($_POST['username'] ?? ''));
$display  = trim((string) ($_POST['display_name'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($labName === '')                       { $errors[] = 'Give the laboratory a name.'; }
    if ($username === '')                      { $errors[] = 'Choose a username for the first administrator.'; }
    if (strlen($password) < 10)                { $errors[] = 'The administrator password must be at least ten characters.'; }
    if ($password !== $confirm)                { $errors[] = 'The two passwords do not match.'; }

    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            foreach (schema_statements() as $sql) {
                $pdo->exec($sql);
            }

            $now = date('Y-m-d H:i:s');

            if ((int) db_value('SELECT COUNT(*) FROM units') === 0) {
                foreach (seed_units() as [$code, $name]) {
                    db_run('INSERT INTO units (code, name, active) VALUES (?, ?, 1)', [$code, $name]);
                }
            }

            foreach (seed_picklists() as [$list, $code, $label, $sort, $protected]) {
                db_insert_ignore(
                    'picklists',
                    ['list_key', 'code', 'label', 'sort_order', 'active', 'protected'],
                    [$list, $code, $label, $sort, 1, $protected]
                );
            }

            foreach (seed_settings($labName) as $key => $value) {
                db_insert_ignore('settings', ['key', 'value'], [$key, $value]);
            }

            db_run(
                'INSERT INTO admin_users (username, password_hash, display_name, active, created_at)
                 VALUES (?, ?, ?, 1, ?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $display ?: $username, $now]
            );

            $pdo->commit();

            // Make sure the database file itself is not downloadable. Two files
            // because two servers: .htaccess is Apache, web.config is IIS, and
            // each is ignored by the other. Neither helps on nginx, which reads
            // no per-directory file at all — there the database belongs outside
            // the web root, and check.php says so after asking the server for it.
            $dataDir = dirname(LAB_DB_PATH);
            if (!file_exists($dataDir . '/.htaccess')) {
                @file_put_contents($dataDir . '/.htaccess',
                    "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
            }
            if (!file_exists($dataDir . '/web.config')) {
                @file_put_contents($dataDir . '/web.config',
                    "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                    . "<configuration>\n  <system.webServer>\n    <security>\n"
                    . "      <authorization>\n        <deny users=\"*\" />\n      </authorization>\n"
                    . "    </security>\n  </system.webServer>\n</configuration>\n");
            }

            @file_put_contents($lockFile, "Installed " . $now . "\n");

            flash('Installation finished. Add your instruments and grants next.', 'success');
            redirect('admin/login.php');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}

page_header('Install', ['chromeless' => true, 'mainClass' => 'page narrow']);
?>
<h1>Install the equipment system</h1>
<p class="lede">This creates the database, seeds the survey unit list and the on-screen wording, and registers the first administrator. It runs once and then locks itself.</p>

<?php if (!file_exists(__DIR__ . '/check.php')): ?>
  <div class="flash flash-notice">check.php is not in this directory. If you have not yet confirmed that the server provides PHP and PDO SQLite, do that first.</div>
<?php else: ?>
  <p><a class="link-quiet" href="check.php">Run the server capability check first &rarr;</a></p>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<form method="post" class="card form">
  <?= csrf_field() ?>

  <div class="field">
    <label for="lab_name">Laboratory name</label>
    <input type="text" id="lab_name" name="lab_name" value="<?= h($labName) ?>" required>
    <p class="hint">Shown in the header of every screen. Changeable later from the administrative panel.</p>
  </div>

  <h2>First administrator</h2>

  <div class="field-row">
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= h($username) ?>" autocomplete="username" required>
    </div>
    <div class="field">
      <label for="display_name">Display name</label>
      <input type="text" id="display_name" name="display_name" value="<?= h($display) ?>" autocomplete="name">
    </div>
  </div>

  <div class="field-row">
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required minlength="10">
      <p class="hint">Ten characters or more.</p>
    </div>
    <div class="field">
      <label for="password_confirm">Password again</label>
      <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required minlength="10">
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="button">Create the database</button>
  </div>
</form>

<p class="hint">The database will be written to <code><?= h(LAB_DB_PATH) ?></code>.</p>
<?php
page_footer(['chromeless' => true]);
