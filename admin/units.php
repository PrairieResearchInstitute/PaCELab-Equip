<?php
/**
 * admin/units.php — The survey unit list.
 *
 * These live in a table rather than in code so adding a unit needs no file edit.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
require_admin();

$editing = null;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'toggle') {
        $id  = (int) ($_POST['unit_id'] ?? 0);
        $row = db_one('SELECT * FROM units WHERE unit_id = ?', [$id]);
        if ($row) {
            db_run('UPDATE units SET active = ? WHERE unit_id = ?', [(int) $row['active'] === 1 ? 0 : 1, $id]);
            flash($row['code'] . ((int) $row['active'] === 1 ? ' hidden from the grant form.' : ' available again.'));
        }
        redirect('units.php');
    }

    if ($action === 'save') {
        $id   = (int) ($_POST['unit_id'] ?? 0);
        $code = trim((string) ($_POST['code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($code === '') { $errors[] = 'A unit needs a short code.'; }
        if (db_one('SELECT unit_id FROM units WHERE code = ? AND unit_id <> ?', [$code, $id])) {
            $errors[] = 'That code is already in the list.';
        }

        if (!$errors) {
            if ($id) {
                db_run('UPDATE units SET code = ?, name = ?, active = ? WHERE unit_id = ?', [$code, $name, $active, $id]);
                flash($code . ' updated.');
            } else {
                db_run('INSERT INTO units (code, name, active) VALUES (?, ?, ?)', [$code, $name, $active]);
                flash($code . ' added.');
            }
            redirect('units.php');
        }
        $editing = ['unit_id' => $id, 'code' => $code, 'name' => $name, 'active' => $active];
    }
}

if ($editing === null && isset($_GET['edit'])) {
    $editing = db_one('SELECT * FROM units WHERE unit_id = ?', [(int) $_GET['edit']]);
}

$all = db_all(
    'SELECT u.*, (SELECT COUNT(*) FROM grants g WHERE g.unit_id = u.unit_id) AS grant_count
       FROM units u ORDER BY u.active DESC, u.code'
);

admin_header('units', 'Units');
?>
<h1>Survey units</h1>
<p class="lede">The unit list offered when a grant is added.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="stack">
  <div class="card card-tight">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Code</th><th>Name</th><th class="num">Grants</th><th></th></tr></thead>
        <tbody>
        <?php if (!$all): ?>
          <tr><td colspan="4" class="empty">No units defined.</td></tr>
        <?php endif; ?>
        <?php foreach ($all as $row): ?>
          <tr<?= (int) $row['active'] === 0 ? ' class="is-locked"' : '' ?>>
            <td>
              <a href="?edit=<?= (int) $row['unit_id'] ?>"><strong><?= h($row['code']) ?></strong></a>
              <?= (int) $row['active'] === 0 ? ' <span class="pill">hidden</span>' : '' ?>
            </td>
            <td><?= h($row['name']) ?></td>
            <td class="num"><?= (int) $row['grant_count'] ?></td>
            <td class="nowrap">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="unit_id" value="<?= (int) $row['unit_id'] ?>">
                <input type="hidden" name="action" value="toggle">
                <button type="submit" class="button button-secondary button-small"><?= (int) $row['active'] === 1 ? 'Hide' : 'Show' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="unit_id" value="<?= (int) ($editing['unit_id'] ?? 0) ?>">

    <h2><?= $editing ? 'Edit unit' : 'Add a unit' ?></h2>

    <div class="field">
      <label for="code">Code</label>
      <input type="text" id="code" name="code" required value="<?= h($editing['code'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="name">Full name</label>
      <input type="text" id="name" name="name" value="<?= h($editing['name'] ?? '') ?>">
    </div>

    <div class="checkline">
      <input type="checkbox" id="active" name="active" value="1" <?= (!$editing || (int) ($editing['active'] ?? 1) === 1) ? 'checked' : '' ?>>
      <label for="active">Offer in the grant form</label>
    </div>

    <div class="form-actions">
      <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Add unit' ?></button>
      <?php if ($editing): ?><a class="link-quiet" href="units.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php
page_footer();
