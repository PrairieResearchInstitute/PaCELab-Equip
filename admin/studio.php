<?php
/**
 * admin/studio.php — edit this application from inside it.
 *
 * A rail of files on the left, the one you are working on in the middle, and
 * what is true about it underneath. The safety is all in includes/studio.php;
 * this screen only has to be honest about what it is doing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/studio.php';
require_installed();
require_code_editor();

$file = (string) ($_GET['file'] ?? '');
$note = '';
$kind = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    $file   = (string) ($_POST['file'] ?? '');

    if ($action === 'save') {
        // A textarea posts CRLF. Storing that rewrites every line of a file
        // that only changed on one, which makes the backup useless for seeing
        // what actually happened.
        $code = str_replace("\r\n", "\n", (string) ($_POST['code'] ?? ''));
        [$ok, $note] = studio_save($file, $code);
        $kind = $ok ? 'success' : 'error';
    } elseif ($action === 'restore') {
        [$ok, $note] = studio_restore($file, (string) ($_POST['backup'] ?? ''));
        $kind = $ok ? 'success' : 'error';
    }
}

$tree    = studio_tree();
$full    = studio_resolve($file);
$code    = $full !== null ? (string) file_get_contents($full) : '';
$rel     = $full !== null ? studio_relative($full) : '';
$backups = $rel !== '' ? studio_backups($rel) : [];
$history = studio_history();

/** Group the rail by folder, so the list reads as the application is laid out. */
$grouped = [];
foreach ($tree as $path) {
    $dir = dirname($path);
    $grouped[$dir === '.' ? '(root)' : $dir][] = $path;
}

admin_header('studio', 'Studio');
?>
<h1>Studio</h1>
<p class="lede">The application's own source, editable here. Every save is parsed
before it is written, the previous copy is kept, and the change is logged.</p>

<?php if ($note !== ''): ?>
  <div class="flash flash-<?= h($kind) ?>"><?= h($note) ?></div>
<?php endif; ?>

<div class="card card-tight" style="background:#FFF8E1;border-color:#E6CF8B">
  <p style="margin:.4rem .6rem">
    <strong>This writes code that the next request runs.</strong>
    A file that will not parse is refused rather than saved, but a file that parses
    can still be wrong. Change one thing, reload the application in another tab, and
    use <em>Restore</em> if it is worse than it was.
  </p>
</div>

<div class="studio">
  <div class="studio-rail">
    <?php foreach ($grouped as $dir => $files): ?>
      <p class="studio-dir"><?= h($dir) ?></p>
      <ul class="studio-files">
        <?php foreach ($files as $path): ?>
          <li<?= $path === $rel ? ' class="on"' : '' ?>>
            <a href="?file=<?= urlencode($path) ?>"><?= h(basename($path)) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  </div>

  <div class="studio-main">
    <?php if ($full === null): ?>
      <div class="card"><p class="empty">Choose a file on the left.</p></div>
    <?php else: ?>
      <form method="post" class="card card-tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="file" value="<?= h($rel) ?>">
        <div class="studio-head">
          <strong><?= h($rel) ?></strong>
          <span class="muted"><?= number_format(strlen($code)) ?> bytes ·
            <?= number_format(substr_count($code, "\n") + 1) ?> lines</span>
        </div>
        <textarea name="code" class="studio-code" spellcheck="false" wrap="off"><?= h($code) ?></textarea>
        <div class="form-actions">
          <button type="submit" class="button">Save this file</button>
          <a class="button button-secondary" href="?file=<?= urlencode($rel) ?>">Reload, discarding changes</a>
        </div>
      </form>

      <?php accordion_open('studio-backups', 'Previous copies of this file',
                           ['meta' => count($backups) . ' kept']); ?>
        <?php if (!$backups): ?>
          <p class="empty">None yet. One is kept every time you save.</p>
        <?php else: ?>
          <table class="data">
            <thead><tr><th>Kept</th><th class="num">Size</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
              <tr>
                <td class="nowrap"><?= h(substr($b['when'], 0, 4) . '-' . substr($b['when'], 4, 2)
                    . '-' . substr($b['when'], 6, 2) . ' ' . substr($b['when'], 9, 2)
                    . ':' . substr($b['when'], 11, 2)) ?></td>
                <td class="num"><?= number_format($b['size']) ?></td>
                <td class="nowrap">
                  <form method="post" onsubmit="return confirm('Put this copy back? The current file is kept first.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="file" value="<?= h($rel) ?>">
                    <input type="hidden" name="backup" value="<?= h($b['name']) ?>">
                    <button type="submit" class="button button-secondary button-small">Restore</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php accordion_close(); ?>
    <?php endif; ?>

    <?php accordion_open('studio-history', 'What has been changed here',
                         ['meta' => count($history) . ' most recent']); ?>
      <?php if (!$history): ?>
        <p class="empty">Nothing has been saved from this screen yet.</p>
      <?php else: ?>
        <table class="data">
          <thead><tr><th>When</th><th>Who</th><th>What</th><th>File</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td class="nowrap"><?= h(pretty_datetime($h['at'] ?? '')) ?></td>
              <td><?= h($h['who'] ?? '') ?></td>
              <td><?= h($h['action'] ?? '') ?></td>
              <td><code><?= h($h['file'] ?? '') ?></code></td>
              <td class="muted"><?= h($h['detail'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php accordion_close(); ?>
  </div>
</div>
<?php
page_footer();
