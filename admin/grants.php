<?php
/**
 * admin/grants.php — Add, edit, and retire grants.
 *
 * A grant leaves the charge dropdown two ways: its award period stops covering
 * the date of the run, or an administrator clears the active flag by hand.
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

    if ($action === 'retire' || $action === 'restore') {
        $id  = (int) ($_POST['grant_id'] ?? 0);
        $row = grant_by_id($id);
        if ($row) {
            db_run('UPDATE grants SET active = ? WHERE grant_id = ?', [$action === 'restore' ? 1 : 0, $id]);
            flash($row['cfopa'] . ($action === 'restore' ? ' is available again.' : ' is retired. Existing charges are untouched.'));
        }
        redirect('grants.php');
    }

    if ($action === 'save') {
        $id       = (int) ($_POST['grant_id'] ?? 0);
        $existing = $id ? grant_by_id($id) : null;

        // A code typed without its activity segment means the parent account,
        // which is A00. Normalising rather than refusing follows the PRI Suite,
        // which fills the segment in the same way on import.
        $cfopa = cfopa_normalize($_POST['cfopa'] ?? '');

        $data = [
            'cfopa'                  => $cfopa,
            'cfopa_base'             => cfopa_base($cfopa),
            'activity_code'          => cfopa_activity($cfopa),
            'title'                  => trim((string) ($_POST['title'] ?? '')),
            'display_label'          => trim((string) ($_POST['display_label'] ?? '')),
            'principal_investigator' => trim((string) ($_POST['principal_investigator'] ?? '')),
            'unit_id'                => ($_POST['unit_id'] ?? '') !== '' ? (int) $_POST['unit_id'] : null,
            'start_date'             => clean_date($_POST['start_date'] ?? null),
            'end_date'               => clean_date($_POST['end_date'] ?? null),
            'active'                 => isset($_POST['active']) ? 1 : 0,
        ];

        if ($data['cfopa'] === '')          { $errors[] = 'The CFOPA number is required.'; }
        if ($data['title'] === '' && $data['display_label'] === '') {
            $errors[] = 'Give the grant a title, a short label, or both.';
        }
        if ($data['start_date'] && $data['end_date'] && $data['start_date'] > $data['end_date']) {
            $errors[] = 'The award start date falls after the end date.';
        }

        $clash = db_one('SELECT grant_id FROM grants WHERE cfopa = ? AND grant_id <> ? AND lab_id = ?', [$data['cfopa'], $id, current_lab_id()]);
        if ($clash) { $errors[] = 'Another grant already carries CFOPA ' . $data['cfopa'] . '.'; }

        if (!$data['display_label']) { $data['display_label'] = $data['title']; }

        // An odd shape is worth saying out loud but is not worth refusing over:
        // a legacy or hand-entered account still has to be recordable, and the
        // business office is the authority on what is valid, not this form.
        $shapeNote = ($data['cfopa'] !== '' && !cfopa_is_well_formed($data['cfopa']))
            ? ' Note that ' . $data['cfopa'] . ' is not the usual shape'
              . ' (1-000000-000000-000000-A00); check it before the next export.'
            : '';

        if (!$errors) {
            if ($existing) {
                db_run(
                    'UPDATE grants SET cfopa = :cfopa, cfopa_base = :cfopa_base, activity_code = :activity_code,
                            title = :title, display_label = :display_label,
                            principal_investigator = :principal_investigator, unit_id = :unit_id,
                            start_date = :start_date, end_date = :end_date, active = :active
                      WHERE grant_id = :id',
                    $data + ['id' => $id]
                );
                flash('Grant ' . $data['cfopa'] . ' updated.' . $shapeNote);
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['lab_id']     = current_lab_id();
                db_run(
                    'INSERT INTO grants (lab_id, cfopa, cfopa_base, activity_code, title, display_label,
                                         principal_investigator, unit_id,
                                         start_date, end_date, active, created_at)
                     VALUES (:lab_id, :cfopa, :cfopa_base, :activity_code, :title, :display_label,
                             :principal_investigator, :unit_id,
                             :start_date, :end_date, :active, :created_at)',
                    $data
                );
                flash('Grant ' . $data['cfopa'] . ' added.' . $shapeNote);
            }
            redirect('grants.php');
        }

        $editing = $data + ['grant_id' => $id];
    }
}

if ($editing === null && isset($_GET['edit'])) {
    $editing = grant_by_id((int) $_GET['edit']);
}

$units = db_all('SELECT * FROM units WHERE active = 1 ORDER BY code');
$all   = db_all(
    'SELECT g.*, u.code AS unit_code,
            (SELECT COUNT(*) FROM usage_records ur WHERE ur.grant_id = g.grant_id AND ur.voided = 0) AS charge_count
       FROM grants g
       LEFT JOIN units u ON u.unit_id = g.unit_id
      WHERE g.lab_id = ?
      ORDER BY g.active DESC, g.display_label COLLATE NOCASE',
    [current_lab_id()]
);
$today = date('Y-m-d');

admin_header('grants', 'Grants');
?>
<h1>Grants</h1>
<p class="lede">The use form offers only grants whose award period covers the date of the run, so a lapsed award cannot pick up a new charge by accident.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php if (!$units): ?>
  <div class="flash flash-notice">No survey units are defined yet. <a href="units.php">Add the unit list</a> so grants can be attributed.</div>
<?php endif; ?>

<div class="stack">
  <div>
    <div class="card card-tight">
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>Grant</th><th>CFOPA</th><th>PI</th><th>Unit</th><th>Award period</th><th class="num">Charges</th><th></th></tr>
          </thead>
          <tbody>
          <?php if (!$all): ?>
            <tr><td colspan="7" class="empty">No grants yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($all as $row):
              $expired = $row['end_date'] && $row['end_date'] < $today;
              $future  = $row['start_date'] && $row['start_date'] > $today;
          ?>
            <tr<?= ((int) $row['active'] === 0 || $expired) ? ' class="is-locked"' : '' ?>>
              <td>
                <a href="?edit=<?= (int) $row['grant_id'] ?>"><?= h($row['display_label'] ?: $row['title']) ?></a>
                <?php if ((int) $row['active'] === 0): ?><span class="pill">retired</span><?php endif; ?>
                <?php if ($expired): ?><span class="pill pill-warn">expired</span><?php endif; ?>
                <?php if ($future): ?><span class="pill pill-warn">not started</span><?php endif; ?>
                <?php if ($row['title'] && $row['title'] !== $row['display_label']): ?>
                  <p class="hint"><?= h($row['title']) ?></p>
                <?php endif; ?>
              </td>
              <td><code><?= h($row['cfopa']) ?></code></td>
              <td><?= h($row['principal_investigator']) ?></td>
              <td><?= h($row['unit_code'] ?? '') ?></td>
              <td class="nowrap">
                <?= $row['start_date'] ? h(pretty_date($row['start_date'])) : '&mdash;' ?>
                to
                <?= $row['end_date'] ? h(pretty_date($row['end_date'])) : '&mdash;' ?>
              </td>
              <td class="num"><?= (int) $row['charge_count'] ?></td>
              <td class="nowrap">
                <form method="post" onsubmit="return confirm('<?= (int) $row['active'] === 1 ? 'Retire' : 'Restore' ?> this grant?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="grant_id" value="<?= (int) $row['grant_id'] ?>">
                  <input type="hidden" name="action" value="<?= (int) $row['active'] === 1 ? 'retire' : 'restore' ?>">
                  <button type="submit" class="button button-secondary button-small"><?= (int) $row['active'] === 1 ? 'Retire' : 'Restore' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="grant_id" value="<?= (int) ($editing['grant_id'] ?? 0) ?>">

    <h2><?= $editing ? 'Edit grant' : 'Add a grant' ?></h2>

    <div class="field">
      <label for="cfopa">CFOPA</label>
      <input type="text" id="cfopa" name="cfopa" required placeholder="1-303631-375002-375150-A00"
             value="<?= h($editing['cfopa'] ?? '') ?>">
      <p class="hint">Chart, fund, organization, program, activity. Leave the activity segment
      off and it becomes A00, the parent account.
      <?php if (!empty($editing['cfopa'])): ?>
        <br>Parent <code><?= h(cfopa_base($editing['cfopa'])) ?></code>,
        activity <code><?= h(cfopa_activity($editing['cfopa'])) ?></code>,
        <?= h(cfopa_fund_type($editing['cfopa'])) ?> fund.
      <?php endif; ?>
      </p>
    </div>

    <div class="field">
      <label for="title">Full title</label>
      <input type="text" id="title" name="title" value="<?= h($editing['title'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="display_label">Short label</label>
      <input type="text" id="display_label" name="display_label" value="<?= h($editing['display_label'] ?? '') ?>">
      <p class="hint">What the dropdown shows, because full titles grow unwieldy. Defaults to the title.</p>
    </div>

    <div class="field">
      <label for="principal_investigator">Principal investigator</label>
      <input type="text" id="principal_investigator" name="principal_investigator" value="<?= h($editing['principal_investigator'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="unit_id">Unit</label>
      <select id="unit_id" name="unit_id">
        <option value="">&mdash;</option>
        <?php foreach ($units as $unit): ?>
          <option value="<?= (int) $unit['unit_id'] ?>" <?= (int) ($editing['unit_id'] ?? 0) === (int) $unit['unit_id'] ? 'selected' : '' ?>>
            <?= h($unit['code']) ?><?= $unit['name'] ? ' — ' . h($unit['name']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="start_date">Award start</label>
        <input type="date" id="start_date" name="start_date" value="<?= h($editing['start_date'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="end_date">Award end</label>
        <input type="date" id="end_date" name="end_date" value="<?= h($editing['end_date'] ?? '') ?>">
      </div>
    </div>

    <div class="checkline">
      <input type="checkbox" id="active" name="active" value="1" <?= (!$editing || (int) ($editing['active'] ?? 1) === 1) ? 'checked' : '' ?>>
      <label for="active">Available for charging</label>
    </div>

    <div class="form-actions">
      <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Add grant' ?></button>
      <?php if ($editing): ?><a class="link-quiet" href="grants.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php
page_footer();
