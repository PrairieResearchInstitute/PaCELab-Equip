<?php
/**
 * admin/batches.php — Export batch history, and reopening a rejected batch.
 *
 * Reopening returns every record in the batch to the unexported pool so the
 * next monthly run picks it up again. The batch row itself stays, marked
 * reopened, because the business office received it once and the history of
 * what was sent matters.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $batchId = (int) ($_POST['batch_id'] ?? 0);
    $batch   = db_one('SELECT * FROM export_batches WHERE batch_id = ? AND lab_id = ?', [$batchId, current_lab_id()]);

    if (!$batch) {
        flash('That batch no longer exists.', 'error');
        redirect('batches.php');
    }

    if (($_POST['action'] ?? '') === 'reopen') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $affected = db_run(
                'UPDATE usage_records SET exported = 0, export_batch_id = NULL WHERE export_batch_id = ?',
                [$batchId]
            )->rowCount();
            db_run('UPDATE export_batches SET reopened = 1 WHERE batch_id = ?', [$batchId]);
            $pdo->commit();
            flash('Batch ' . $batchId . ' reopened. ' . $affected . ' record(s) returned to the unexported pool.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('The batch was not reopened: ' . $e->getMessage(), 'error');
        }
        redirect('batches.php');
    }
}

$batches = db_all(
    'SELECT b.*,
            (SELECT COUNT(*) FROM usage_records r WHERE r.export_batch_id = b.batch_id) AS still_attached
       FROM export_batches b
      WHERE b.lab_id = ?
      ORDER BY b.generated_at DESC',
    [current_lab_id()]
);

$viewing = null;
$rows    = [];
if (isset($_GET['view'])) {
    $viewing = db_one('SELECT * FROM export_batches WHERE batch_id = ? AND lab_id = ?', [(int) $_GET['view'], current_lab_id()]);
    if ($viewing) {
        $rows = db_all(
            'SELECT r.*, e.name AS equipment_name, g.cfopa, g.display_label
               FROM usage_records r
               JOIN equipment e ON e.equipment_id = r.equipment_id
               JOIN grants    g ON g.grant_id     = r.grant_id
              WHERE r.export_batch_id = ?
              ORDER BY g.cfopa, e.name COLLATE NOCASE, r.use_date',
            [(int) $viewing['batch_id']]
        );
    }
}

admin_header('batches', 'Export history');
?>
<h1>Export history</h1>
<p class="lede">Every monthly run leaves a batch here. Reopen one when the business office rejects it.</p>

<div class="card card-tight">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Batch</th><th>Period</th><th>Generated</th><th>By</th>
          <th class="num">Records</th><th class="num">Total</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$batches): ?>
        <tr><td colspan="8" class="empty">No export batches yet. The billing report creates them.</td></tr>
      <?php endif; ?>
      <?php foreach ($batches as $batch): ?>
        <tr>
          <td><strong><?= (int) $batch['batch_id'] ?></strong></td>
          <td class="nowrap"><?= h(pretty_date($batch['period_start'])) ?> &ndash; <?= h(pretty_date($batch['period_end'])) ?></td>
          <td class="nowrap"><?= h(pretty_datetime($batch['generated_at'])) ?></td>
          <td><?= h($batch['generated_by']) ?></td>
          <td class="num"><?= (int) $batch['record_count'] ?></td>
          <td class="num nowrap"><?= h(money($batch['total_amount'])) ?></td>
          <td class="nowrap">
            <?php if ((int) $batch['reopened'] === 1): ?>
              <span class="pill pill-warn">reopened</span>
            <?php else: ?>
              <span class="pill pill-ok">sent</span>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <div class="button-row">
              <a class="button button-secondary button-small" href="?view=<?= (int) $batch['batch_id'] ?>">Records</a>
              <?php if ((int) $batch['still_attached'] > 0): ?>
                <form method="post" onsubmit="return confirm('Reopen batch <?= (int) $batch['batch_id'] ?>? Its <?= (int) $batch['still_attached'] ?> record(s) return to the unexported pool.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reopen">
                  <input type="hidden" name="batch_id" value="<?= (int) $batch['batch_id'] ?>">
                  <button type="submit" class="button button-secondary button-small">Reopen</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($viewing): ?>
<h2>Batch <?= (int) $viewing['batch_id'] ?> &middot; <?= h(pretty_date($viewing['period_start'])) ?> to <?= h(pretty_date($viewing['period_end'])) ?></h2>
<?php if (!$rows): ?>
  <div class="card"><p class="empty">No records are attached to this batch any more. It was reopened, and its charges went back into the pool.</p></div>
<?php else: ?>
<div class="card card-tight">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>#</th><th>CFOPA</th><th>Instrument</th><th>Use date</th><th>Operator</th><th class="num">Count</th><th class="num">Charge</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr class="<?= (int) $row['voided'] === 1 ? 'is-voided' : '' ?>">
          <td><a href="records.php?edit=<?= (int) $row['usage_id'] ?>"><?= (int) $row['usage_id'] ?></a></td>
          <td><code><?= h($row['cfopa']) ?></code></td>
          <td><?= h($row['equipment_name']) ?></td>
          <td class="nowrap"><?= h(pretty_date($row['use_date'])) ?></td>
          <td><?= h($row['operator_name']) ?></td>
          <td class="num"><?= (int) $row['sample_count'] ?></td>
          <td class="num nowrap"><?= h(money($row['total_charge'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<p><a class="link-quiet" href="batches.php">Back to all batches</a></p>
<?php endif; ?>
<?php
page_footer();
