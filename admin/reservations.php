<?php
/**
 * admin/reservations.php — Cancel or reassign any booking.
 *
 * The overlap rule that governs the calendar governs this page too: an
 * administrator moving a booking on top of another one is refused the same way
 * a laboratory user would be.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
require_admin();

$errors  = [];
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['reservation_id'] ?? 0);
    $row    = $id ? db_one('SELECT * FROM reservations WHERE reservation_id = ?', [$id]) : null;

    if (!$row) {
        flash('That booking no longer exists.', 'error');
        redirect('reservations.php');
    }

    if ($action === 'cancel') {
        db_run('DELETE FROM reservations WHERE reservation_id = ?', [$id]);
        flash('Booking cancelled. ' . ($row['reserved_by'] ?: 'The holder') . ' is not notified by this application.');
        redirect('reservations.php');
    }

    if ($action === 'save') {
        $reservedBy = trim((string) ($_POST['reserved_by'] ?? ''));
        $purpose    = trim((string) ($_POST['purpose'] ?? ''));
        $start      = clean_slot_datetime($_POST['start_datetime'] ?? null);
        $end        = clean_slot_datetime($_POST['end_datetime'] ?? null);
        $equipId    = (int) ($_POST['equipment_id'] ?? 0);

        if ($reservedBy === '')            { $errors[] = 'A booking needs a holder.'; }
        if (!$start || !$end)              { $errors[] = 'Give a valid start and end.'; }
        if (!equipment_by_id($equipId))    { $errors[] = 'Choose an instrument.'; }
        if ($start && $end && $start >= $end) { $errors[] = 'The booking ends at or before it starts.'; }

        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $clash = reservation_conflict($equipId, $start, $end, $id);
                if ($clash) {
                    $pdo->rollBack();
                    $errors[] = 'That span overlaps a booking held by ' . ($clash['reserved_by'] ?: 'someone else')
                        . ' from ' . pretty_datetime($clash['start_datetime']) . ' to ' . pretty_datetime($clash['end_datetime']) . '.';
                } else {
                    db_run(
                        'UPDATE reservations SET equipment_id = ?, reserved_by = ?, start_datetime = ?, end_datetime = ?, purpose = ?
                          WHERE reservation_id = ?',
                        [$equipId, $reservedBy, $start, $end, $purpose, $id]
                    );
                    $pdo->commit();
                    flash('Booking updated.');
                    redirect('reservations.php');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $errors[] = 'The booking was not changed: ' . $e->getMessage();
            }
        }
        $editing = $row;
    }
}

if ($editing === null && isset($_GET['edit'])) {
    $editing = db_one('SELECT * FROM reservations WHERE reservation_id = ?', [(int) $_GET['edit']]);
}

$scope       = ($_GET['scope'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
$filterEquip = (int) ($_GET['equipment_id'] ?? 0);
$now         = date('Y-m-d H:i:s');

$sql = 'SELECT r.*, e.name AS equipment_name
          FROM reservations r
          JOIN equipment e ON e.equipment_id = r.equipment_id
         WHERE e.lab_id = :lab AND r.end_datetime ' . ($scope === 'past' ? '<' : '>=') . ' :now';
$params = ['now' => $now, 'lab' => current_lab_id()];
if ($filterEquip) {
    $sql .= ' AND r.equipment_id = :eq';
    $params['eq'] = $filterEquip;
}
$sql .= ' ORDER BY r.start_datetime ' . ($scope === 'past' ? 'DESC' : 'ASC') . ' LIMIT 300';

$bookings  = db_all($sql, $params);
$equipment = lab_equipment(false);

admin_header('reservations', 'Reservations');
?>
<h1>Reservations</h1>
<p class="lede">Bookings are first come, first served. Cancelling here removes the block from the calendar; nobody is emailed.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="<?= $editing ? "stack" : "" ?>">
  <div>
    <form method="get" class="card report-filters">
      <div class="field">
        <label for="scope">Showing</label>
        <select id="scope" name="scope">
          <option value="upcoming" <?= $scope === 'upcoming' ? 'selected' : '' ?>>Current and upcoming</option>
          <option value="past" <?= $scope === 'past' ? 'selected' : '' ?>>Finished</option>
        </select>
      </div>
      <div class="field">
        <label for="equipment_id">Instrument</label>
        <select id="equipment_id" name="equipment_id">
          <option value="">All</option>
          <?php foreach ($equipment as $item): ?>
            <option value="<?= (int) $item['equipment_id'] ?>" <?= $filterEquip === (int) $item['equipment_id'] ? 'selected' : '' ?>>
              <?= h($item['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="button">Filter</button>
    </form>

    <div class="card card-tight">
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>Instrument</th><th>Held by</th><th>Start</th><th>End</th><th>Purpose</th><th>Use recorded</th><th></th></tr>
          </thead>
          <tbody>
          <?php if (!$bookings): ?>
            <tr><td colspan="7" class="empty">No bookings match.</td></tr>
          <?php endif; ?>
          <?php foreach ($bookings as $row): ?>
            <tr>
              <td><?= h($row['equipment_name']) ?></td>
              <td><?= h($row['reserved_by']) ?></td>
              <td class="nowrap"><?= h(pretty_datetime($row['start_datetime'])) ?></td>
              <td class="nowrap"><?= h(pretty_datetime($row['end_datetime'])) ?></td>
              <td><?= h($row['purpose']) ?></td>
              <td>
                <?php if ($row['usage_id']): ?>
                  <a href="records.php?edit=<?= (int) $row['usage_id'] ?>">#<?= (int) $row['usage_id'] ?></a>
                <?php else: ?>
                  <span class="muted">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="nowrap">
                <div class="button-row">
                  <a class="button button-secondary button-small" href="?edit=<?= (int) $row['reservation_id'] ?>&amp;scope=<?= h($scope) ?>">Edit</a>
                  <form method="post" onsubmit="return confirm('Cancel this booking?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="reservation_id" value="<?= (int) $row['reservation_id'] ?>">
                    <button type="submit" class="button button-danger button-small">Cancel</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php if ($editing): ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="reservation_id" value="<?= (int) $editing['reservation_id'] ?>">

    <h2 class="card-heading">Booking #<?= (int) $editing['reservation_id'] ?></h2>

    <div class="field">
      <label for="r_equipment">Instrument</label>
      <select id="r_equipment" name="equipment_id" required>
        <?php foreach ($equipment as $item): ?>
          <option value="<?= (int) $item['equipment_id'] ?>" <?= (int) $editing['equipment_id'] === (int) $item['equipment_id'] ? 'selected' : '' ?>>
            <?= h($item['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="r_by">Held by</label>
      <input type="text" id="r_by" name="reserved_by" required value="<?= h($editing['reserved_by']) ?>">
      <p class="hint">Changing this reassigns the booking.</p>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="r_start">Start</label>
        <input type="datetime-local" id="r_start" name="start_datetime" required
               value="<?= h(date('Y-m-d\TH:i', strtotime($editing['start_datetime']))) ?>">
      </div>
      <div class="field">
        <label for="r_end">End</label>
        <input type="datetime-local" id="r_end" name="end_datetime" required
               value="<?= h(date('Y-m-d\TH:i', strtotime($editing['end_datetime']))) ?>">
      </div>
    </div>
    <p class="hint">Times snap to the half hour the calendar works in.</p>

    <div class="field">
      <label for="r_purpose">Purpose</label>
      <input type="text" id="r_purpose" name="purpose" value="<?= h($editing['purpose']) ?>">
    </div>

    <div class="form-actions">
      <button type="submit" class="button">Save booking</button>
      <a class="link-quiet" href="reservations.php">Cancel</a>
    </div>
  </form>
<?php endif; ?>
</div>
<?php
page_footer();
