<?php
/**
 * report.php — Monthly billing report and export.
 *
 * The default range is last calendar month and the default filter is records
 * not yet exported, which together are the normal monthly run.
 *
 * The export carries charge lines only: CFOPA, receiving subaccount, instrument,
 * count, rate, and total. Operator, date, and notes stay on screen, where a
 * questioned charge gets traced.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_installed();

// --- Range and filter ------------------------------------------------------
$defaultStart = date('Y-m-01', strtotime('first day of last month'));
$defaultEnd   = date('Y-m-t',  strtotime('first day of last month'));

$start = clean_date($_REQUEST['start'] ?? null) ?? $defaultStart;
$end   = clean_date($_REQUEST['end']   ?? null) ?? $defaultEnd;
if ($start > $end) { [$start, $end] = [$end, $start]; }

// The filter select always submits all_records, with an empty value for the
// normal monthly run, so this tests the value rather than the key's presence.
$unexportedOnly = trim((string) ($_REQUEST['all_records'] ?? '')) === '';

/** Charge lines in the range, ordered so grouping is a single pass. */
function charge_lines(string $start, string $end, bool $unexportedOnly): array
{
    $sql = 'SELECT r.*, e.name AS equipment_name, e.equipment_id,
                   g.cfopa, g.title AS grant_title, g.display_label,
                   b.batch_id AS batch_id, b.generated_at AS batch_generated_at
              FROM usage_records r
              JOIN equipment e ON e.equipment_id = r.equipment_id
              JOIN grants    g ON g.grant_id     = r.grant_id
              LEFT JOIN export_batches b ON b.batch_id = r.export_batch_id
             WHERE e.lab_id = :lab
               AND r.voided = 0
               AND r.use_date BETWEEN :start AND :end';
    if ($unexportedOnly) {
        $sql .= ' AND r.exported = 0';
    }
    $sql .= ' ORDER BY g.cfopa, e.name COLLATE NOCASE, r.use_date, r.usage_id';

    return db_all($sql, ['start' => $start, 'end' => $end, 'lab' => current_lab_id()]);
}

/**
 * CFOPA → instrument → charge lines, with subtotals at each level.
 * The export writes one row per instrument-and-rate group; the screen expands
 * each group into its individual runs.
 */
function group_lines(array $lines): array
{
    $groups = [];
    foreach ($lines as $line) {
        $cfopa = (string) $line['cfopa'];
        if (!isset($groups[$cfopa])) {
            $groups[$cfopa] = [
                'cfopa'       => $cfopa,
                'title'       => $line['display_label'] ?: $line['grant_title'],
                'instruments' => [],
                'count'       => 0,
                'total'       => 0.0,
            ];
        }

        // The rate is part of the key: a rate change mid-period produces two
        // charge lines for the same instrument, each at the rate actually charged.
        $key = $line['equipment_id'] . '|' . $line['rate_charged'] . '|' . $line['receiving_subaccount'];
        if (!isset($groups[$cfopa]['instruments'][$key])) {
            $groups[$cfopa]['instruments'][$key] = [
                'name'       => $line['equipment_name'],
                'rate'       => (float) $line['rate_charged'],
                'rate_unit'  => $line['rate_unit_charged'],
                'subaccount' => $line['receiving_subaccount'],
                'rows'       => [],
                'count'      => 0,
                'total'      => 0.0,
            ];
        }

        $groups[$cfopa]['instruments'][$key]['rows'][]  = $line;
        $groups[$cfopa]['instruments'][$key]['count']  += (int) $line['sample_count'];
        $groups[$cfopa]['instruments'][$key]['total']  += (float) $line['total_charge'];
        $groups[$cfopa]['count'] += (int) $line['sample_count'];
        $groups[$cfopa]['total'] += (float) $line['total_charge'];
    }
    return $groups;
}

/** Marking a batch is an administrator action, because unlocking one is too. */
function mark_period_exported(string $start, string $end): void
{
    if (!is_admin()) {
        flash('Marking records as exported is an administrator action. Sign in first.', 'error');
        redirect('admin/login.php');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Re-read inside the transaction so the batch contains exactly what is
        // unexported at this moment, not what the screen showed a minute ago.
        $rows = db_all(
            'SELECT r.usage_id, r.total_charge FROM usage_records r
               JOIN equipment e ON e.equipment_id = r.equipment_id
              WHERE e.lab_id = ? AND r.voided = 0 AND r.exported = 0
                AND r.use_date BETWEEN ? AND ?',
            [current_lab_id(), $start, $end]
        );

        if (!$rows) {
            $pdo->rollBack();
            flash('Nothing in that range is waiting to be exported.', 'notice');
            redirect('report.php?start=' . $start . '&end=' . $end);
        }

        $total = 0.0;
        foreach ($rows as $row) { $total += (float) $row['total_charge']; }

        db_run(
            'INSERT INTO export_batches (lab_id, period_start, period_end, generated_at, generated_by, record_count, total_amount, reopened)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
            [current_lab_id(), $start, $end, date('Y-m-d H:i:s'), admin_display_name(), count($rows), round($total, 2)]
        );
        $batchId = (int) $pdo->lastInsertId();

        $ids = array_column($rows, 'usage_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db_run(
            'UPDATE usage_records SET exported = 1, export_batch_id = ? WHERE usage_id IN (' . $placeholders . ')',
            array_merge([$batchId], $ids)
        );

        $pdo->commit();
        flash('Batch ' . $batchId . ' created: ' . count($ids) . ' records, ' . money($total) . '. Those charges are now locked.', 'success');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash('The batch was not created: ' . $e->getMessage(), 'error');
    }
    redirect('report.php?start=' . $start . '&end=' . $end);
}

// --- Mark as exported ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_exported') {
    csrf_require();
    mark_period_exported($start, $end);
}

// --- CSV -------------------------------------------------------------------
if (($_GET['download'] ?? '') === 'csv') {
    $groups = group_lines(charge_lines($start, $end, $unexportedOnly));

    $filename = 'equipment-charges_' . $start . '_to_' . $end . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // byte order mark, so Excel reads UTF-8 correctly

    fputcsv($out, ['CFOPA charged', 'Receiving CFOPA', 'Equipment name', 'Sample count', 'Rate', 'Total charge']);

    $grand = 0.0;
    foreach ($groups as $group) {
        foreach ($group['instruments'] as $instrument) {
            fputcsv($out, [
                $group['cfopa'],
                $instrument['subaccount'],
                $instrument['name'],
                $instrument['count'],
                number_format($instrument['rate'], 2, '.', ''),
                number_format($instrument['total'], 2, '.', ''),
            ]);
        }
        $grand += $group['total'];
    }

    fputcsv($out, []);
    fputcsv($out, ['Grand total', '', '', '', '', number_format($grand, 2, '.', '')]);
    fclose($out);
    exit;
}

// --- Screen ----------------------------------------------------------------
$lines  = charge_lines($start, $end, $unexportedOnly);
$groups = group_lines($lines);

$grandCount   = 0;
$grandTotal   = 0.0;
$lockedCount  = 0;
foreach ($lines as $line) {
    $grandCount += (int) $line['sample_count'];
    $grandTotal += (float) $line['total_charge'];
    if ((int) $line['exported'] === 1) { $lockedCount++; }
}
$pendingCount = count($lines) - $lockedCount;

$query = 'start=' . urlencode($start) . '&end=' . urlencode($end) . ($unexportedOnly ? '' : '&all_records=1');

page_header('Billing report', ['nav' => 'report', 'mainClass' => 'page wide']);
?>
<div class="page-head">
  <div>
    <h1><?= h(setting('report_title', 'Monthly billing report')) ?></h1>
    <p class="lede"><?= h(setting('report_instructions')) ?></p>
  </div>
</div>

<?php accordion_open('report-filters', 'Range and filter', [
    'open' => true,
    'meta' => 'showing ' . pretty_date($start) . ' to ' . pretty_date($end),
]); ?>
<form method="get" class="report-filters no-print">
  <div class="field">
    <label for="start">From</label>
    <input type="date" id="start" name="start" value="<?= h($start) ?>">
  </div>
  <div class="field">
    <label for="end">To</label>
    <input type="date" id="end" name="end" value="<?= h($end) ?>">
  </div>
  <div class="field">
    <label for="all_records">Records</label>
    <select id="all_records" name="all_records">
      <option value="" <?= $unexportedOnly ? 'selected' : '' ?>>Not yet exported</option>
      <option value="1" <?= $unexportedOnly ? '' : 'selected' ?>>All records in range</option>
    </select>
  </div>
  <div class="button-row">
    <button type="submit" class="button">Run report</button>
    <a class="button button-secondary" href="?<?= h($query) ?>&amp;download=csv">Download CSV</a>
  </div>
</form>
<?php accordion_close(); ?>

<div class="totals-strip">
  <div class="total-tile">
    <div class="label">Period</div>
    <div class="value value-small"><?= h(pretty_date($start)) ?><br><?= h(pretty_date($end)) ?></div>
  </div>
  <div class="total-tile">
    <div class="label">Charge lines</div>
    <div class="value"><?= count($lines) ?></div>
    <p class="hint"><?= $pendingCount ?> not yet exported<?= $lockedCount ? ', ' . $lockedCount . ' locked' : '' ?></p>
  </div>
  <div class="total-tile">
    <div class="label">Accounts charged</div>
    <div class="value"><?= count($groups) ?></div>
  </div>
  <div class="total-tile">
    <div class="label">Grand total</div>
    <div class="value"><?= h(money($grandTotal)) ?></div>
    <p class="hint"><?= $grandCount ?> samples, hours, and runs</p>
  </div>
</div>

<?php if ($unexportedOnly && $pendingCount > 0): ?>
<form method="post" class="card no-print"
      onsubmit="return confirm('Create an export batch for <?= $pendingCount ?> record(s) totalling <?= h(money($grandTotal)) ?>? Those charges become locked.');">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="mark_exported">
  <input type="hidden" name="start" value="<?= h($start) ?>">
  <input type="hidden" name="end" value="<?= h($end) ?>">
  <h2 class="card-heading">Close the period</h2>
  <p>Marking these records as exported records an export batch, stamps every record with it, and locks the charges. They stay visible on this screen. An administrator can reopen the batch if the business office rejects it.</p>
  <div class="button-row">
    <button type="submit" class="button">Mark <?= $pendingCount ?> record<?= $pendingCount === 1 ? '' : 's' ?> as exported</button>
    <?php if (!is_admin()): ?>
      <span class="hint">Requires an administrator sign-in.</span>
    <?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php if (!$groups): ?>
  <div class="card"><p class="empty">No charges in this range<?= $unexportedOnly ? ' are waiting to be exported' : '' ?>.</p></div>
<?php endif; ?>

<?php if ($groups): ?>
<?php
  // The whole detail folds away, and every account folds away inside it. The
  // summary lines carry the account, the grant, and the total, so a closed
  // report still answers "who is being charged what" without opening anything.
  accordion_open('report-detail', 'Charge detail', [
      'open' => true,
      'meta' => count($groups) . ' account' . (count($groups) === 1 ? '' : 's')
              . ' · ' . count($lines) . ' charge lines · ' . money($grandTotal),
  ]);
?>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
<?php accordion_open('report-cfopa-' . $group['cfopa'], $group['cfopa'] . ' — ' . $group['title'], [
    'meta' => (int) $group['count'] . ' counted · ' . money($group['total']),
]); ?>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Use date</th><th>Operator</th><th>Batch</th><th>Notes</th>
          <th class="num">Count</th><th class="num">Rate</th><th class="num">Charge</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($group['instruments'] as $instrument): ?>
        <tr class="instrument-head">
          <td colspan="4">
            <?= h($instrument['name']) ?>
            <span class="muted">&middot; <?= h(money($instrument['rate'])) ?> <?= h($instrument['rate_unit']) ?>
            &middot; subaccount <?= h($instrument['subaccount']) ?></span>
          </td>
          <td class="num"><?= (int) $instrument['count'] ?></td>
          <td class="num"><?= h(money($instrument['rate'])) ?></td>
          <td class="num"><?= h(money($instrument['total'])) ?></td>
          <td></td>
        </tr>
        <?php foreach ($instrument['rows'] as $row): ?>
        <tr>
          <td class="nowrap"><?= h(pretty_date($row['use_date'])) ?></td>
          <td><?= h($row['operator_name']) ?></td>
          <td><?= h($row['batch_identifier']) ?></td>
          <td><?= h($row['notes']) ?></td>
          <td class="num"><?= (int) $row['sample_count'] ?></td>
          <td class="num nowrap"><?= h(money($row['rate_charged'])) ?></td>
          <td class="num nowrap"><?= h(money($row['total_charge'])) ?></td>
          <td class="nowrap">
            <?php if ((int) $row['exported'] === 1): ?>
              <span class="pill pill-locked">batch <?= (int) $row['batch_id'] ?></span>
            <?php else: ?>
              <span class="pill">pending</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
        <tr class="subtotal">
          <td colspan="4"><?= h($group['cfopa']) ?> subtotal</td>
          <td class="num"><?= (int) $group['count'] ?></td>
          <td></td>
          <td class="num"><?= h(money($group['total'])) ?></td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>
<?php accordion_close(); ?>
<?php endforeach; ?>

<?php if ($groups): accordion_close(); endif; ?>

<?php if ($groups): ?>
<table class="data card">
  <tbody>
    <tr class="grand">
      <td>Grand total, <?= h(pretty_date($start)) ?> to <?= h(pretty_date($end)) ?></td>
      <td class="num"><?= $grandCount ?></td>
      <td class="num"><?= h(money($grandTotal)) ?></td>
    </tr>
  </tbody>
</table>
<p class="hint">Operator, use date, and notes appear here so a questioned charge can be traced. The CSV carries the charge lines only.</p>
<?php endif; ?>
<?php
page_footer();
