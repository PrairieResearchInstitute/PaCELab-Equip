<?php
/**
 * admin/index.php — Administrative overview.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
$admin = require_admin();

$counts = [
    'equipment'   => (int) db_value('SELECT COUNT(*) FROM equipment WHERE lab_id = ? AND active = 1', [current_lab_id()]),
    'retired'     => (int) db_value('SELECT COUNT(*) FROM equipment WHERE lab_id = ? AND active = 0', [current_lab_id()]),
    'grants'      => (int) db_value('SELECT COUNT(*) FROM grants WHERE lab_id = ? AND active = 1', [current_lab_id()]),
    'expiring'    => (int) db_value(
        'SELECT COUNT(*) FROM grants WHERE lab_id = ? AND active = 1
           AND end_date IS NOT NULL AND end_date <> \'\' AND end_date BETWEEN ? AND ?',
        [current_lab_id(), date('Y-m-d'), date('Y-m-d', strtotime('+60 days'))]),

    // Charges and bookings belong to a laboratory through the instrument they
    // were made against, so they are counted through that join rather than by
    // a column of their own that could drift out of step with it.
    'unexported'  => (int) db_value(
        'SELECT COUNT(*) FROM usage_records r JOIN equipment e ON e.equipment_id = r.equipment_id
          WHERE e.lab_id = ? AND r.exported = 0 AND r.voided = 0', [current_lab_id()]),
    'unexported_amount' => (float) db_value(
        'SELECT COALESCE(SUM(r.total_charge), 0) FROM usage_records r
           JOIN equipment e ON e.equipment_id = r.equipment_id
          WHERE e.lab_id = ? AND r.exported = 0 AND r.voided = 0', [current_lab_id()]),
    'upcoming'    => (int) db_value(
        'SELECT COUNT(*) FROM reservations res JOIN equipment e ON e.equipment_id = res.equipment_id
          WHERE e.lab_id = ? AND res.end_datetime >= ?', [current_lab_id(), date('Y-m-d H:i:s')]),
];

$recentBatches = db_all('SELECT * FROM export_batches WHERE lab_id = ? ORDER BY generated_at DESC LIMIT 5', [current_lab_id()]);
$expiring = db_all(
    'SELECT g.*, u.code AS unit_code FROM grants g LEFT JOIN units u ON u.unit_id = g.unit_id
      WHERE g.lab_id = ? AND g.active = 1 AND g.end_date IS NOT NULL AND g.end_date <> \'\'
        AND g.end_date BETWEEN ? AND ?
      ORDER BY g.end_date',
    [current_lab_id(), date('Y-m-d'), date('Y-m-d', strtotime('+60 days'))]
);

admin_header('index', 'Administration');
?>
<div class="page-head">
  <div>
    <h1><?= h(setting('admin_title', 'Administration')) ?></h1>
    <p class="lede">Signed in as <?= h($admin['display_name'] ?: $admin['username']) ?>.</p>
  </div>
  <form method="post" action="logout.php">
    <?= csrf_field() ?>
    <button type="submit" class="button button-secondary button-small">Sign out</button>
  </form>
</div>

<div class="totals-strip">
  <div class="total-tile">
    <div class="label">Active instruments</div>
    <div class="value"><?= $counts['equipment'] ?></div>
    <p class="hint"><?= $counts['retired'] ?> retired</p>
  </div>
  <div class="total-tile">
    <div class="label">Active grants</div>
    <div class="value"><?= $counts['grants'] ?></div>
    <p class="hint"><?= $counts['expiring'] ?> ending within 60 days</p>
  </div>
  <div class="total-tile">
    <div class="label">Waiting to export</div>
    <div class="value"><?= $counts['unexported'] ?></div>
    <p class="hint"><?= h(money($counts['unexported_amount'])) ?> in charges</p>
  </div>
  <div class="total-tile">
    <div class="label">Bookings ahead</div>
    <div class="value"><?= $counts['upcoming'] ?></div>
    <p class="hint">now and later</p>
  </div>
</div>

<?php if ($counts['equipment'] === 0 || $counts['grants'] === 0): ?>
  <div class="flash flash-notice">
    Seed the reference data before anyone uses the entry form:
    <?= $counts['equipment'] === 0 ? 'add at least one instrument' : '' ?><?= ($counts['equipment'] === 0 && $counts['grants'] === 0) ? ' and ' : '' ?><?= $counts['grants'] === 0 ? 'add at least one grant' : '' ?>.
  </div>
<?php endif; ?>

<h2>What this panel covers</h2>
<div class="grid-cards">
  <a class="card" href="equipment.php">
    <h3>Equipment</h3>
    <p>Add, edit, and retire instruments. Retiring hides an instrument from the dropdowns and preserves every historical charge.</p>
  </a>
  <a class="card" href="grants.php">
    <h3>Grants</h3>
    <p>CFOPA, title, short label, principal investigator, unit, and award dates. Expired grants leave the charge dropdown on their own.</p>
  </a>
  <a class="card" href="units.php">
    <h3>Units</h3>
    <p>The survey codes offered when a grant is added.</p>
  </a>
  <a class="card" href="records.php">
    <h3>Usage records</h3>
    <p>Correct or void any charge. A void needs a reason and leaves the row in place as an audit trail.</p>
  </a>
  <a class="card" href="costs.php">
    <h3>Running costs</h3>
    <p>Consumables, repairs, parts, and the contracts that cover each instrument, set against what it has earned.</p>
  </a>
  <a class="card" href="reservations.php">
    <h3>Reservations</h3>
    <p>Cancel or reassign any booking on any instrument.</p>
  </a>
  <a class="card" href="batches.php">
    <h3>Export history</h3>
    <p>Review past monthly exports and reopen one when the business office rejects it.</p>
  </a>
  <a class="card" href="settings.php">
    <h3>Interface text</h3>
    <p>Laboratory name, page titles, and the instructions shown on each screen.</p>
  </a>
  <a class="card" href="users.php">
    <h3>Administrators</h3>
    <p>Add or remove administrator accounts and reset passwords.</p>
  </a>
</div>

<?php if ($expiring): ?>
<h2>Grants ending within sixty days</h2>
<div class="card card-tight">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Grant</th><th>CFOPA</th><th>Unit</th><th>Ends</th></tr></thead>
      <tbody>
      <?php foreach ($expiring as $g): ?>
        <tr>
          <td><a href="grants.php?edit=<?= (int) $g['grant_id'] ?>"><?= h($g['display_label'] ?: $g['title']) ?></a></td>
          <td><code><?= h($g['cfopa']) ?></code></td>
          <td><?= h($g['unit_code'] ?? '') ?></td>
          <td class="nowrap"><?= h(pretty_date($g['end_date'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($recentBatches): ?>
<h2>Recent export batches</h2>
<div class="card card-tight">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Period</th><th>Generated</th><th>By</th><th class="num">Records</th><th class="num">Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recentBatches as $b): ?>
        <tr>
          <td class="nowrap"><?= h(pretty_date($b['period_start'])) ?> &ndash; <?= h(pretty_date($b['period_end'])) ?></td>
          <td class="nowrap"><?= h(pretty_datetime($b['generated_at'])) ?></td>
          <td><?= h($b['generated_by']) ?></td>
          <td class="num"><?= (int) $b['record_count'] ?></td>
          <td class="num"><?= h(money($b['total_amount'])) ?></td>
          <td><?= ((int) $b['reopened'] === 1) ? '<span class="pill pill-warn">reopened</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p><a class="link-quiet" href="batches.php">All export batches &rarr;</a></p>
<?php endif; ?>

<?php
page_footer();
