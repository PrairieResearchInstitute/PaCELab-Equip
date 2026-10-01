<?php
/**
 * lab.php — The laboratory you are working in.
 *
 * What is happening in the laboratory you have gone into: what wants attention,
 * what is booked today, what you have recorded lately, and the way in to each of
 * the four modules. Reached by choosing a laboratory on the landing screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_installed();
require_lab();

// The laboratories this person may choose between. The chooser is the whole
// point of the landing screen, so it is loaded before anything else.
$myLabs = labs_for_person();

$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');

$stats = [
    'instruments' => (int) db_value('SELECT COUNT(*) FROM equipment WHERE lab_id = ? AND active = 1', [current_lab_id()]),
    'pending'     => (int) db_value('SELECT COUNT(*) FROM usage_records r JOIN equipment e ON e.equipment_id = r.equipment_id WHERE e.lab_id = ? AND r.exported = 0 AND r.voided = 0', [current_lab_id()]),
    'pending_amt' => (float) db_value('SELECT COALESCE(SUM(r.total_charge), 0) FROM usage_records r JOIN equipment e ON e.equipment_id = r.equipment_id WHERE e.lab_id = ? AND r.exported = 0 AND r.voided = 0', [current_lab_id()]),
    'today'       => (int) db_value('SELECT COUNT(*) FROM usage_records r JOIN equipment e ON e.equipment_id = r.equipment_id WHERE e.lab_id = ? AND r.use_date = ? AND r.voided = 0', [current_lab_id(), $today]),
    'booked'      => (int) db_value('SELECT COUNT(*) FROM reservations res JOIN equipment e ON e.equipment_id = res.equipment_id WHERE e.lab_id = ? AND res.end_datetime >= ?', [current_lab_id(), $now]),
];

// What is on an instrument right now, and what is coming up today.
$onNow = db_all(
    'SELECT r.*, e.name AS equipment_name FROM reservations r
       JOIN equipment e ON e.equipment_id = r.equipment_id
      WHERE e.lab_id = :lab AND r.start_datetime <= :now AND r.end_datetime > :now
      ORDER BY lower(e.name)',
    ['now' => $now, 'lab' => current_lab_id()]
);

$laterToday = db_all(
    'SELECT r.*, e.name AS equipment_name FROM reservations r
       JOIN equipment e ON e.equipment_id = r.equipment_id
      WHERE e.lab_id = :lab AND r.start_datetime > :now AND r.start_datetime < :endOfDay
      ORDER BY r.start_datetime LIMIT 8',
    ['now' => $now, 'endOfDay' => $today . ' 23:59:59', 'lab' => current_lab_id()]
);

$myRecent = [];
if (have_user_name()) {
    $myRecent = db_all(
        'SELECT r.*, e.name AS equipment_name, g.cfopa
           FROM usage_records r
           JOIN equipment e ON e.equipment_id = r.equipment_id
           JOIN grants    g ON g.grant_id     = r.grant_id
          WHERE e.lab_id = ? AND r.operator_name = ? AND r.voided = 0
          ORDER BY r.created_at DESC LIMIT 5',
        [current_lab_id(), current_user_name()]
    );
}

page_header(current_lab_name(), ['nav' => 'lab']);
?>
<h2><?= h(current_lab_name()) ?></h2>
<p class="lede"><?= h(setting('home_instructions',
  'Log equipment use, book time on an instrument, and produce the monthly charge report.')) ?></p>

<?php render_alerts(equipment_alerts(), 'Needs attention', 6); ?>

<div class="totals-strip">
  <div class="total-tile">
    <div class="label">Instruments</div>
    <div class="value"><?= $stats['instruments'] ?></div>
    <p class="hint">available to book and charge</p>
  </div>
  <div class="total-tile">
    <div class="label">Runs logged today</div>
    <div class="value"><?= $stats['today'] ?></div>
  </div>
  <div class="total-tile">
    <div class="label">In use now</div>
    <div class="value"><?= count($onNow) ?></div>
    <p class="hint"><?= $stats['booked'] ?> bookings ahead</p>
  </div>
  <div class="total-tile">
    <div class="label">Waiting to bill</div>
    <div class="value"><?= h(money($stats['pending_amt'])) ?></div>
    <p class="hint"><?= $stats['pending'] ?> charge<?= $stats['pending'] === 1 ? '' : 's' ?> not yet exported</p>
  </div>
</div>

<h2>What do you want to do?</h2>
<div class="grid-cards">
  <a class="card" href="index.php">
    <h3>Record equipment use</h3>
    <p>Log a run against a grant. The rate and the receiving account come from the instrument, and the total is worked out before you save.</p>
  </a>
  <a class="card" href="schedule.php">
    <h3>Book instrument time</h3>
    <p>A week at a glance, half hour by half hour. Drag down a day to book, drag a block to move it. First come, first served.</p>
  </a>
  <a class="card" href="report.php">
    <h3>Monthly billing report</h3>
    <p>Charges grouped by account and instrument, with the CSV the business office needs.</p>
  </a>
  <a class="card" href="admin/index.php">
    <h3>Administration</h3>
    <p>Instruments, grants, rates, corrections, and the wording on every screen. Behind a password.</p>
  </a>
</div>

<?php if ($onNow || $laterToday): ?>
<?php accordion_open('home-today', 'Today', [
    'open' => true,
    'meta' => count($onNow) . ' in use now, ' . count($laterToday) . ' still to come',
]); ?>
  <p class="button-row">
    <a class="button button-secondary button-small" href="schedule.php">Open the week's calendar</a>
  </p>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Instrument</th><th>Held by</th><th>From</th><th>Until</th><th>Purpose</th></tr></thead>
      <tbody>
      <?php foreach ($onNow as $r): ?>
        <tr>
          <td><strong><?= h($r['equipment_name']) ?></strong> <span class="pill pill-ok">in use</span></td>
          <td><?= h($r['reserved_by']) ?></td>
          <td class="nowrap"><?= h(date('g:i a', strtotime($r['start_datetime']))) ?></td>
          <td class="nowrap"><?= h(date('g:i a', strtotime($r['end_datetime']))) ?></td>
          <td><?= h($r['purpose']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($laterToday as $r): ?>
        <tr>
          <td><?= h($r['equipment_name']) ?></td>
          <td><?= h($r['reserved_by']) ?></td>
          <td class="nowrap"><?= h(date('g:i a', strtotime($r['start_datetime']))) ?></td>
          <td class="nowrap"><?= h(date('g:i a', strtotime($r['end_datetime']))) ?></td>
          <td><?= h($r['purpose']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php accordion_close(); ?>
<?php endif; ?>

<?php if ($myRecent): ?>
<?php accordion_open('home-mine', 'Your recent entries', [
    'meta' => count($myRecent) . ' most recent, as ' . current_user_name(),
]); ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Instrument</th><th>Account</th><th class="num">Count</th><th class="num">Charge</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($myRecent as $r): ?>
        <tr>
          <td class="nowrap"><?= h(pretty_date($r['use_date'])) ?></td>
          <td><?= h($r['equipment_name']) ?></td>
          <td><code><?= h($r['cfopa']) ?></code></td>
          <td class="num"><?= (int) $r['sample_count'] ?></td>
          <td class="num nowrap"><?= h(money($r['total_charge'])) ?></td>
          <td class="nowrap">
            <?php if ((int) $r['exported'] === 1): ?>
              <span class="pill pill-locked">billed</span>
            <?php else: ?>
              <a class="button button-secondary button-small" href="index.php?edit=<?= (int) $r['usage_id'] ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php accordion_close(); ?>
<?php endif; ?>
<?php
page_footer(['scripts' => ['assets/app.js']]);
