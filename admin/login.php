<?php
/**
 * admin/login.php — Administrator sign-in.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();

/**
 * Only same-directory relative targets are followed after sign-in, so a crafted
 * link cannot bounce an administrator off to another site.
 */
function safe_next(?string $raw): string
{
    $raw = (string) $raw;
    if ($raw === '' || preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $raw)) {
        return 'index.php';
    }
    $path = parse_url($raw, PHP_URL_PATH) ?: '';
    $file = basename($path);
    if (!isset(array_flip(array_column(admin_pages(), 1))[$file])) {
        return 'index.php';
    }
    $query = parse_url($raw, PHP_URL_QUERY);
    return $file . ($query ? '?' . $query : '');
}

$next  = safe_next($_GET['next'] ?? $_POST['next'] ?? null);
$error = null;

if (is_admin()) {
    redirect($next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $error = admin_login(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''));
    if ($error === null) {
        flash('Signed in as ' . admin_display_name() . '.', 'success');
        redirect($next);
    }
}

page_header('Administrator sign-in', ['nav' => 'admin', 'mainClass' => 'page narrow']);
?>
<h1>Administrator sign-in</h1>
<p class="lede">The administrative panel covers equipment, grants, units, record corrections, and interface text.</p>

<?php if ($error !== null): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" class="card form" autocomplete="on">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= h($next) ?>">

  <div class="field">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" autocomplete="username" required autofocus
           value="<?= h((string) ($_POST['username'] ?? '')) ?>">
  </div>

  <div class="field">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
  </div>

  <div class="form-actions">
    <button type="submit" class="button">Sign in</button>
    <a class="link-quiet" href="../index.php">Back to use entry</a>
  </div>
</form>

<p class="hint">Sessions on administrative pages end after thirty idle minutes. Repeated failed attempts are throttled.</p>
<?php
page_footer();
