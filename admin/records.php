<?php
/**
 * admin/records.php — Correct or void any usage record.
 *
 * A void never deletes. It sets a flag and stores a reason, so the row stays in
 * place as an audit trail and the report simply stops counting it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
require_admin();

$errors  = [];
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action  = (string) ($_POST['action'] ?? '');
    $usageId = (int) ($_POST['usage_id'] ?? 0);
    $record  = $usageId ? db_one('SELECT * FROM usage_records WHERE usage_id = ?', [$usageId]) : null;

    if (!$record) {
        flash('That record no longer exists.', 'error');
        redirect('records.php');
    }

    if ($action === 'void') {
        $reason = trim((string) ($_POST['void_reason'] ?? ''));
        if ($reason === '') {
            flash('A void needs a reason. Nothing was changed.', 'error');
            redirect('records.php?edit=' . $usageId);
        }
        db_run('UPDATE usage_records SET voided = 1, void_reason = ? WHERE usage_id = ?',
            [admin_display_name() . ': ' . $reason, $usageId]);
        flash('Record #' . $usageId . ' voided. The row stays in place as an audit trail.'
            . ((int) $record['exported'] === 1 ? ' It was already exported, so tell the business office.' : ''));
        redirect('records.php');
    }

    if ($action === 'unvoid') {
        db_run('UPDATE usage_records SET voided = 0, void_reason = \'\' WHERE usage_id = ?', [$usageId]);
        flash('Record #' . $usageId . ' restored. It counts on the report again.');
        redirect('records.php');
    }

    if ($action === 'unlock') {
        db_run('UPDATE usage_records SET exported = 0, export_batch_id = NULL WHERE usage_id = ?', [$usageId]);
        flash('Record #' . $usageId . ' unlocked and detached from its export batch. It will appear in the next monthly run.');
        redirect('records.php');
    }

    if ($action === 'save') {
        $equipmentId = (int) ($_POST['equipment_id'] ?? 0);
        $grantId     = (int) ($_POST['grant_id'] ?? 0);
        $useDate     = clean_date($_POST['use_date'] ?? null);
        $count       = (int) ($_POST['sample_count'] ?? 0);
        $operator    = trim((string) ($_POST['operator_name'] ?? ''));
        $rate        = (float) ($_POST['rate_charged'] ?? 0);
        $rateUnit    = (string) ($_POST['rate_unit_charged'] ?? '');
        $subaccount  = trim((string) ($_POST['receiving_subaccount'] ?? ''));
        $batch       = trim((string) ($_POST['batch_identifier'] ?? ''));
        $notes       = trim((string) ($_POST['notes'] ?? ''));

        if (!equipment_by_id($equipmentId))                            { $errors[] = 'Choose an instrument.'; }
        if (!grant_by_id($grantId)) { $errors[] = 'Choose a grant.'; }
        if (!$useDate)                                                 { $errors[] = 'Give a valid use date.'; }
        if ($count < 1)                                                { $errors[] = 'The count must be at least 1.'; }
        if ($operator === '')                                          { $errors[] = 'The operator name is required.'; }
        if ($rate < 0)                                                 { $errors[] = 'The rate cannot be negative.'; }
        if ($subaccount === '')                                        { $errors[] = 'The receiving subaccount is required.'; }

        if (!$errors) {
            db_run(
                'UPDATE usage_records SET equipment_id = :equipment_id, grant_id = :grant_id,
                        operator_name = :operator_name, use_date = :use_date, sample_count = :sample_count,
                        rate_charged = :rate_charged, rate_unit_charged = :rate_unit_charged,
                        receiving_subaccount = :receiving_subaccount, total_charge = :total_charge,
                        batch_identifier = :batch_identifier, notes = :notes
                  WHERE usage_id = :id',
                [
                    'equipment_id'         => $equipmentId,
                    'grant_id'             => $grantId,
                    'operator_name'        => $operator,
                    'use_date'             => $useDate,
                    'sample_count'         => $count,
                    'rate_charged'         => $rate,
                    'rate_unit_charged'    => $rateUnit,
                    'receiving_subaccount' => $subaccount,
                    'total_charge'         => round($rate * $count, 2),
                    'batch_identifier'     => $batch,
                    'notes'                => $notes,
                    'id'                   => $usageId,
                ]
            );
            flash('Record #' . $usageId . ' corrected. New charge ' . money($rate * $count) . '.');
            redirect('records.php');
        }
        $editing = $record;
    }
}

if ($editing === null && isset($_GET['edit'])) {
    $editing = db_one('SELECT * FROM usage_records WHERE usage_id = ?', [(int) $_GET['edit']]);
}

// --- Filters ---------------------------------------------------------------
$filterStart = clean_date($_GET['start'] ?? null) ?? date('Y-m-d', strtotime('-90 days'));
$filterEnd   = clean_date($_GET['end'] ?? null)   ?? date('Y-m-d');
$filterEquip = (int) ($_GET['equipment_id'] ?? 0);
$filterText  = trim((string) ($_GET['q'] ?? ''));

$sql = 'SELECT r.*, e.name AS equipment_name, g.cfopa, g.display_label
          FROM usage_records r
          JOIN equipment e ON e.equipment_id = r.equipment_id
          JOIN grants    g ON g.grant_id     = r.grant_id
         WHERE e.lab_id = :lab AND r.use_date BETWEEN :start AND :end';
$params = ['start' => $filterStart, 'end' => $filterEnd, 'lab' => current_lab_id()];

if ($filterEquip) {
    $sql .= ' AND r.equipment_id = :eq';
    $params['eq'] = $filterEquip;
}
if ($filterText !== '') {
    $sql .= ' AND (r.operator_name LIKE :q OR r.batch_identifier LIKE :q OR r.notes LIKE :q OR g.cfopa LIKE :q)';
    $params['q'] = '%' . $filterText . '%';
}
$sql .= ' ORDER BY r.use_date DESC, r.usage_id DESC LIMIT 300';

$records   = db_all($sql, $params);
$equipment = lab_equipment(false);
$grants    = lab_grants(false);

admin_header('records', 'Usage records');
?>
<h1>Usage records</h1>
<p class="lede">Correct a charge, or void it with a reason. Voided rows stay in the table and drop out of the report.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php if ($editing): ?>
<div class="stack">
  <div>
<?php endif; ?>

<form method="get" class="card report-filters">
  <div class="field">
    <label for="start">From</label>
    <input type="date" id="start" name="start" value="<?= h($filterStart) ?>">
  </div>
  <div class="field">
    <label for="end">To</label>
    <input type="date" id="end" name="end" value="<?= h($filterEnd) ?>">
  </div>
  <div class="field">
    <label for="equipment_filter">Instrument</label>
    <select id="equipment_filter" name="equipment_id">
      <option value="">All</option>
      <?php foreach ($equipment as $item): ?>
        <option value="<?= (int) $item['equipment_id'] ?>" <?= $filterEquip === (int) $item['equipment_id'] ? 'selected' : '' ?>>
          <?= h($item['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="q">Operator, batch, notes, CFOPA</label>
    <input type="search" id="q" name="q" value="<?= h($filterText) ?>">
  </div>
  <button type="submit" class="button">Filter</button>
</form>

<div class="card card-tight">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>#</th><th>Date</th><th>Instrument</th><th>Operator</th><th>CFOPA</th>
          <th class="num">Count</th><th class="num">Charge</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$records): ?>
        <tr><td colspan="9" class="empty">Nothing matches those filters.</td></tr>
      <?php endif; ?>
      <?php foreach ($records as $row): ?>
        <tr class="<?= (int) $row['voided'] === 1 ? 'is-voided' : ((int) $row['exported'] === 1 ? 'is-locked' : '') ?>">
          <td><?= (int) $row['usage_id'] ?></td>
          <td class="nowrap"><?= h(pretty_date($row['use_date'])) ?></td>
          <td><?= h($row['equipment_name']) ?></td>
          <td><?= h($row['operator_name']) ?></td>
          <td><code><?= h($row['cfopa']) ?></code></td>
          <td class="num"><?= (int) $row['sample_count'] ?></td>
          <td class="num nowrap"><?= h(money($row['total_charge'])) ?></td>
          <td class="nowrap">
            <?php if ((int) $row['voided'] === 1): ?>
              <span class="pill pill-error">voided</span>
            <?php elseif ((int) $row['exported'] === 1): ?>
              <span class="pill pill-locked">batch <?= (int) $row['export_batch_id'] ?></span>
            <?php else: ?>
              <span class="pill pill-ok">open</span>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <div class="button-row">
              <a class="button button-secondary button-small" href="?edit=<?= (int) $row['usage_id'] ?>">Open</a>
              <?php if ((int) $row['voided'] === 1): ?>
                <form method="post" onsubmit="return confirm('Restore this record so it counts again?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="usage_id" value="<?= (int) $row['usage_id'] ?>">
                  <input type="hidden" name="action" value="unvoid">
                  <button class="button button-secondary button-small" type="submit">Restore</button>
                </form>
              <?php endif; ?>
              <?php if ((int) $row['exported'] === 1 && (int) $row['voided'] === 0): ?>
                <form method="post" onsubmit="return confirm('Unlock this record and detach it from its export batch?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="usage_id" value="<?= (int) $row['usage_id'] ?>">
                  <input type="hidden" name="action" value="unlock">
                  <button class="button button-secondary button-small" type="submit">Unlock</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php if ((int) $row['voided'] === 1 && $row['void_reason']): ?>
        <tr class="is-voided">
          <td></td>
          <td colspan="8" class="reason hint">Void reason: <?= h($row['void_reason']) ?></td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="hint">The most recent 300 matching records are listed.</p>

<?php if ($editing): ?>
  </div>

  <div>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="usage_id" value="<?= (int) $editing['usage_id'] ?>">

      <h2 class="card-heading">Record #<?= (int) $editing['usage_id'] ?></h2>
      <p class="hint">Entered <?= h(pretty_datetime($editing['created_at'])) ?>.</p>

      <div class="field">
        <label for="e_equipment">Instrument</label>
        <select id="e_equipment" name="equipment_id" required>
          <?php foreach ($equipment as $item): ?>
            <option value="<?= (int) $item['equipment_id'] ?>" <?= (int) $editing['equipment_id'] === (int) $item['equipment_id'] ? 'selected' : '' ?>>
              <?= h($item['name']) ?><?= (int) $item['active'] === 0 ? ' (retired)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="e_grant">Grant</label>
        <select id="e_grant" name="grant_id" required>
          <?php foreach ($grants as $grant): ?>
            <option value="<?= (int) $grant['grant_id'] ?>" <?= (int) $editing['grant_id'] === (int) $grant['grant_id'] ? 'selected' : '' ?>>
              <?= h(grant_label($grant)) ?><?= (int) $grant['active'] === 0 ? ' (retired)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="hint">This panel may charge a grant the entry form would refuse, which is the point of a correction.</p>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="e_date">Use date</label>
          <input type="date" id="e_date" name="use_date" required value="<?= h($editing['use_date']) ?>">
        </div>
        <div class="field">
          <label for="e_count">Count</label>
          <input type="number" id="e_count" name="sample_count" min="1" step="1" required value="<?= (int) $editing['sample_count'] ?>">
        </div>
      </div>

      <div class="field">
        <label for="e_operator">Operator</label>
        <input type="text" id="e_operator" name="operator_name" required value="<?= h($editing['operator_name']) ?>">
      </div>

      <fieldset>
        <legend>Charge snapshot</legend>
        <p class="hint">These were copied from the instrument when the entry was made. Change them only to fix a snapshot that was wrong at the time.</p>
        <div class="field-row">
          <div class="field">
            <label for="e_rate">Rate charged</label>
            <input type="number" id="e_rate" name="rate_charged" step="0.01" min="0" required value="<?= h(number_format((float) $editing['rate_charged'], 2, '.', '')) ?>">
          </div>
          <div class="field">
            <label for="e_rate_unit">Rate unit</label>
            <select id="e_rate_unit" name="rate_unit_charged">
              <?php foreach (rate_units() as $unit): ?>
                <option value="<?= h($unit) ?>" <?= $editing['rate_unit_charged'] === $unit ? 'selected' : '' ?>><?= h($unit) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="e_subaccount">Receiving subaccount</label>
          <input type="text" id="e_subaccount" name="receiving_subaccount" required value="<?= h($editing['receiving_subaccount']) ?>">
        </div>
      </fieldset>

      <div class="field">
        <label for="e_batch">Batch identifier</label>
        <input type="text" id="e_batch" name="batch_identifier" value="<?= h($editing['batch_identifier']) ?>">
      </div>

      <div class="field">
        <label for="e_notes">Notes</label>
        <textarea id="e_notes" name="notes"><?= h($editing['notes']) ?></textarea>
      </div>

      <div class="form-actions">
        <button type="submit" class="button">Save correction</button>
        <a class="link-quiet" href="records.php">Cancel</a>
      </div>
    </form>

    <?php if ((int) $editing['voided'] === 0): ?>
    <form method="post" class="card form" onsubmit="return confirm('Void this record? It stops counting on the report but stays in the table.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="void">
      <input type="hidden" name="usage_id" value="<?= (int) $editing['usage_id'] ?>">
      <h2 class="card-heading">Void this record</h2>
      <div class="field">
        <label for="void_reason">Reason</label>
        <textarea id="void_reason" name="void_reason" required placeholder="Why this charge should not be billed."></textarea>
        <p class="hint">Stored with your name against the record.</p>
      </div>
      <div class="form-actions">
        <button type="submit" class="button button-danger">Void record</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php
page_footer();
