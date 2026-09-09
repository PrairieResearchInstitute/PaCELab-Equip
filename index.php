<?php
/**
 * index.php — Use entry.
 *
 * The screen that carries the daily traffic, so it stays short and it stays
 * fast to complete. The rate, the rate unit, and the receiving subaccount are
 * copied onto the record at the moment of entry: raising a rate next month
 * never rewrites this charge.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_installed();

// --- Identity --------------------------------------------------------------
if (identity_is_self_declared() && isset($_GET['switch_user'])) {
    set_current_user_name('');
    redirect('index.php');
}

// --- Changing laboratory ---------------------------------------------------
// Posted from the bar under the header on any screen, and returns you to the
// screen you were on rather than dumping you back at the entry form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'switch_lab') {
    csrf_require();
    $wanted = (string) ($_POST['lab_id'] ?? '');

    // Two entries in the picker are destinations rather than laboratories.
    if ($wanted === 'choose') {
        redirect('home.php');
    }
    if ($wanted === 'new') {
        if (!is_admin()) {
            flash('Only an administrator sets up a laboratory.', 'error');
            redirect('home.php');
        }
        redirect('admin/labs.php');
    }

    $wanted = (int) $wanted;
    if ($wanted && may_use_lab($wanted)) {
        set_current_lab($wanted);
        flash('Now working in ' . lab_by_id($wanted)['name'] . '.');
    } else {
        flash('That laboratory is not yours to work in.', 'error');
        redirect('home.php');
    }

    // Only somewhere this application actually serves.
    $back  = (string) ($_POST['return_to'] ?? 'lab.php');
    $known = array_merge(array_column(site_pages(), 1), ['lab.php']);
    redirect(in_array($back, $known, true) ? $back : 'lab.php');
}

if (identity_is_self_declared() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'identify') {
    csrf_require();
    $name = trim((string) ($_POST['user_name'] ?? ''));
    if ($name !== '') {
        set_current_user_name($name);
        redirect('index.php');
    }
    flash('Enter your last name so charges can be attributed.', 'error');
    redirect('index.php');
}

if (identity_is_self_declared() && !have_user_name()) {
    page_header('Who is using the laboratory?', ['nav' => 'entry', 'mainClass' => 'page narrow']);
    ?>
    <div class="identity-gate">
      <h1>Your last name</h1>
      <p class="lede">Entries and bookings are recorded under this name for the rest of the session.</p>
      <form method="post" class="card form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="identify">
        <div class="field">
          <label for="user_name">Last name</label>
          <input type="text" id="user_name" name="user_name" required autofocus autocomplete="family-name">
        </div>
        <div class="form-actions">
          <button type="submit" class="button">Continue</button>
        </div>
      </form>
    </div>
    <?php
    page_footer();
    exit;
}

// --- Save ------------------------------------------------------------------
$errors  = [];
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrf_require();

    $usageId  = (int) ($_POST['usage_id'] ?? 0);
    $existing = $usageId ? db_one('SELECT * FROM usage_records WHERE usage_id = ?', [$usageId]) : null;

    if ($usageId && !$existing) {
        $errors[] = 'That record no longer exists.';
    } elseif ($existing && ((int) $existing['exported'] === 1 || (int) $existing['voided'] === 1)) {
        $errors[] = 'That record is locked. An administrator can unlock an exported record or reverse a void.';
        $existing = null;
        $usageId  = 0;
    }

    $equipmentId  = (int) ($_POST['equipment_id'] ?? 0);
    $grantId      = (int) ($_POST['grant_id'] ?? 0);
    $useDate      = clean_date($_POST['use_date'] ?? null);
    $sampleCount  = (int) ($_POST['sample_count'] ?? 0);
    $operator     = trim((string) ($_POST['operator_name'] ?? ''));
    $batch        = trim((string) ($_POST['batch_identifier'] ?? ''));
    $notes        = trim((string) ($_POST['notes'] ?? ''));
    $reservation  = (int) ($_POST['reservation_id'] ?? 0);

    $equipment = equipment_by_id($equipmentId);
    $grant     = $grantId ? grant_by_id($grantId) : null;

    // Everything is checked here on the server. The browser's copy of these
    // rules exists for convenience alone.
    if (!$equipment)            { $errors[] = 'Choose an instrument.'; }

    // An instrument that is out of service takes no new charges. A correction
    // to a charge already recorded is allowed through: the run happened, and
    // refusing to fix its paperwork helps nobody.
    if ($equipment && !$existing && !equipment_is_usable($equipment)) {
        $errors[] = equipment_unusable_reason($equipment);
    }
    if (!$useDate)              { $errors[] = 'Give a valid date for the run.'; }
    if ($sampleCount < 1)       { $errors[] = 'The count must be at least 1.'; }
    if ($operator === '')       { $errors[] = 'Enter the last name of the person who ran the samples.'; }
    if (!$grant)                { $errors[] = 'Choose a grant to charge.'; }

    if ($grant && $useDate) {
        if ($grant['end_date'] && $grant['end_date'] < $useDate) {
            $errors[] = 'Grant ' . $grant['cfopa'] . ' ended ' . pretty_date($grant['end_date'])
                . ', before the date of this run. Ask an administrator which account should carry this charge — '
                . 'the entry has not been saved.';
        } elseif ($grant['start_date'] && $grant['start_date'] > $useDate) {
            $errors[] = 'Grant ' . $grant['cfopa'] . ' did not begin until ' . pretty_date($grant['start_date'])
                . ', after the date of this run. Ask an administrator which account should carry this charge — '
                . 'the entry has not been saved.';
        } elseif ((int) $grant['active'] !== 1) {
            $errors[] = 'Grant ' . $grant['cfopa'] . ' is not available for charging. Ask an administrator.';
        }
    }

    if (!$errors) {
        // On a correction the original snapshot stands, because it is what the
        // charge was made at. Changing the instrument is a different charge, so
        // that case takes a fresh snapshot.
        $keepSnapshot = $existing && (int) $existing['equipment_id'] === $equipmentId;

        $rate       = $keepSnapshot ? (float) $existing['rate_charged']         : (float) $equipment['rate'];
        $rateUnit   = $keepSnapshot ? (string) $existing['rate_unit_charged']   : (string) $equipment['rate_unit'];
        $subaccount = $keepSnapshot ? (string) $existing['receiving_subaccount']: (string) $equipment['receiving_subaccount'];
        $total      = round($rate * $sampleCount, 2);

        $fields = [
            'equipment_id'         => $equipmentId,
            'grant_id'             => $grantId,
            'operator_name'        => $operator,
            'use_date'             => $useDate,
            'sample_count'         => $sampleCount,
            'rate_charged'         => $rate,
            'rate_unit_charged'    => $rateUnit,
            'receiving_subaccount' => $subaccount,
            'total_charge'         => $total,
            'batch_identifier'     => $batch,
            'notes'                => $notes,
        ];

        if ($existing) {
            db_run(
                'UPDATE usage_records SET equipment_id = :equipment_id, grant_id = :grant_id,
                        operator_name = :operator_name, use_date = :use_date, sample_count = :sample_count,
                        rate_charged = :rate_charged, rate_unit_charged = :rate_unit_charged,
                        receiving_subaccount = :receiving_subaccount, total_charge = :total_charge,
                        batch_identifier = :batch_identifier, notes = :notes
                  WHERE usage_id = :id',
                $fields + ['id' => $usageId]
            );
            flash('Entry corrected. ' . $equipment['name'] . ', ' . money($total) . '.');
        } else {
            $fields['created_at'] = date('Y-m-d H:i:s');
            db_run(
                'INSERT INTO usage_records (equipment_id, grant_id, operator_name, use_date, sample_count,
                                            rate_charged, rate_unit_charged, receiving_subaccount, total_charge,
                                            batch_identifier, notes, exported, voided, created_at)
                 VALUES (:equipment_id, :grant_id, :operator_name, :use_date, :sample_count,
                         :rate_charged, :rate_unit_charged, :receiving_subaccount, :total_charge,
                         :batch_identifier, :notes, 0, 0, :created_at)',
                $fields
            );
            $newId = (int) db()->lastInsertId();

            // A booking that led to this run stops asking to be recorded.
            if ($reservation) {
                db_run('UPDATE reservations SET usage_id = ? WHERE reservation_id = ? AND usage_id IS NULL',
                    [$newId, $reservation]);
            }
            flash('Recorded. ' . $equipment['name'] . ', ' . $sampleCount . ' × ' . money($rate) . ' = ' . money($total) . '.');
        }

        set_current_user_name($operator);
        redirect('index.php');
    }

    $editing = [
        'usage_id'         => $usageId,
        'equipment_id'     => $equipmentId,
        'grant_id'         => $grantId,
        'use_date'         => $useDate ?: date('Y-m-d'),
        'sample_count'     => max(1, $sampleCount),
        'operator_name'    => $operator,
        'batch_identifier' => $batch,
        'notes'            => $notes,
        'reservation_id'   => $reservation,
    ];
}

// --- Load a record for correction, or prefill from the calendar ------------
if ($editing === null && isset($_GET['edit'])) {
    $row = db_one('SELECT * FROM usage_records WHERE usage_id = ?', [(int) $_GET['edit']]);
    if ($row && (int) $row['exported'] === 0 && (int) $row['voided'] === 0) {
        $editing = $row + ['reservation_id' => 0];
    } else {
        flash('That record is locked and cannot be corrected here. An administrator can unlock it.', 'notice');
        redirect('index.php');
    }
}

if ($editing === null) {
    $editing = [
        'usage_id'         => 0,
        'equipment_id'     => (int) ($_GET['equipment_id'] ?? 0),
        'grant_id'         => 0,
        'use_date'         => clean_date($_GET['use_date'] ?? null) ?? date('Y-m-d'),
        'sample_count'     => 1,
        'operator_name'    => trim((string) ($_GET['operator'] ?? '')) ?: current_user_name(),
        'batch_identifier' => '',
        'notes'            => '',
        'reservation_id'   => (int) ($_GET['reservation_id'] ?? 0),
    ];
}

// An instrument that is down stays in the list, greyed and unselectable, so a
// person looking for it learns why rather than wondering where it went.
$equipment = lab_equipment(true);
// The instrument on a record being corrected may since have been retired; it
// still has to appear in the dropdown or the correction cannot be saved.
if ($editing['equipment_id'] && !array_filter($equipment, fn($e) => (int) $e['equipment_id'] === (int) $editing['equipment_id'])) {
    $retired = equipment_by_id($editing['equipment_id']);
    if ($retired) { $equipment[] = $retired; }
}

// Every active grant goes to the browser with its award period attached, so
// changing the date of the run refilters the list without a page reload. The
// server checks the dates again on submit regardless.
$grants = lab_grants(true);
// As with a retired instrument: the grant on a record being corrected has to
// stay in the list even if it has since been retired.
if ($editing['grant_id'] && !array_filter($grants, fn($g) => (int) $g['grant_id'] === (int) $editing['grant_id'])) {
    $retiredGrant = db_one(
        'SELECT g.*, u.code AS unit_code FROM grants g LEFT JOIN units u ON u.unit_id = g.unit_id
          WHERE g.grant_id = ?',
        [(int) $editing['grant_id']]
    );
    if ($retiredGrant) { $grants[] = $retiredGrant; }
}

$recent = db_all(
    'SELECT r.*, e.name AS equipment_name, g.cfopa, g.display_label
       FROM usage_records r
       JOIN equipment e ON e.equipment_id = r.equipment_id
       JOIN grants    g ON g.grant_id     = r.grant_id
      ORDER BY r.created_at DESC, r.usage_id DESC
      LIMIT 20'
);

$reservationNote = null;
if ($editing['reservation_id']) {
    $reservationNote = db_one(
        'SELECT r.*, e.name AS equipment_name FROM reservations r
           JOIN equipment e ON e.equipment_id = r.equipment_id
          WHERE r.reservation_id = ?',
        [(int) $editing['reservation_id']]
    );
}

page_header('Use entry', ['nav' => 'entry']);
?>
<div class="page-head">
  <div>
    <h1><?= h(setting('entry_title', 'Record equipment use')) ?></h1>
    <p class="lede"><?= h(setting('entry_instructions')) ?></p>
  </div>
</div>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php if (!$equipment): ?>
  <div class="flash flash-notice">No instruments are set up yet. An administrator adds them under <a href="admin/equipment.php">Administration &rarr; Equipment</a>.</div>
<?php endif; ?>

<?php if ($reservationNote): ?>
  <div class="flash flash-success">
    Recording use from your booking of <?= h($reservationNote['equipment_name']) ?>,
    <?= h(pretty_datetime($reservationNote['start_datetime'])) ?>.
  </div>
<?php endif; ?>

<form method="post" class="entry-form" id="useForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="usage_id" value="<?= (int) $editing['usage_id'] ?>">
  <input type="hidden" name="reservation_id" value="<?= (int) $editing['reservation_id'] ?>">

  <div class="card">
    <?php if ($editing['usage_id']): ?>
      <h2 class="card-heading">Correcting entry #<?= (int) $editing['usage_id'] ?></h2>
    <?php endif; ?>

    <div class="field">
      <label for="equipment_id">Instrument</label>
      <select id="equipment_id" name="equipment_id" required data-role="equipment">
        <option value="">Choose an instrument&hellip;</option>
        <?php foreach ($equipment as $item): ?>
          <?php $unusable = !equipment_is_usable($item) && (int) $editing['equipment_id'] !== (int) $item['equipment_id']; ?>
          <option value="<?= (int) $item['equipment_id'] ?>"
                  data-rate="<?= h(number_format((float) $item['rate'], 2, '.', '')) ?>"
                  data-unit="<?= h($item['rate_unit']) ?>"
                  data-subaccount="<?= h($item['receiving_subaccount']) ?>"
                  <?= $unusable ? 'disabled' : '' ?>
                  <?= (int) $editing['equipment_id'] === (int) $item['equipment_id'] ? 'selected' : '' ?>>
            <?= h($item['name']) ?><?php
              if ((int) $item['active'] === 0) { echo ' (retired)'; }
              elseif (equipment_is_down($item)) { echo ' — down, out of service'; }
              elseif (($item['status'] ?? '') === 'service_due') { echo ' — service due'; }
            ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="use_date">Date of the run</label>
        <input type="date" id="use_date" name="use_date" required data-role="use-date"
               value="<?= h($editing['use_date']) ?>">
        <p class="hint">Entry often lags the run. Change this freely.</p>
      </div>

      <div class="field">
        <label for="sample_count">Count <span class="muted" data-role="count-unit"></span></label>
        <input type="number" id="sample_count" name="sample_count" min="1" step="1" required
               data-role="count" value="<?= (int) $editing['sample_count'] ?>">
      </div>
    </div>

    <div class="readonly-pair">
      <div class="field">
        <label for="rate_display">Rate</label>
        <input type="text" id="rate_display" data-role="rate-display" readonly value="&mdash;">
      </div>
      <div class="field">
        <label for="subaccount_display">Receiving account</label>
        <input type="text" id="subaccount_display" data-role="subaccount-display" readonly value="&mdash;">
      </div>
    </div>
    <p class="hint">Both come from the instrument record and are stored on this charge as they stand today.</p>

    <div class="field field-spaced">
      <label for="grant_id">Charge to grant</label>
      <select id="grant_id" name="grant_id" required data-role="grant">
        <option value="">Choose a grant&hellip;</option>
        <?php foreach ($grants as $grant): ?>
          <option value="<?= (int) $grant['grant_id'] ?>"
                  data-start="<?= h($grant['start_date'] ?? '') ?>"
                  data-end="<?= h($grant['end_date'] ?? '') ?>"
                  <?= (int) $editing['grant_id'] === (int) $grant['grant_id'] ? 'selected' : '' ?>>
            <?= h(grant_label($grant)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="hint" data-role="grant-hint">Only grants whose award period covers the date of the run are listed.</p>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="operator_name">Operator last name</label>
        <input type="text" id="operator_name" name="operator_name" required autocomplete="family-name"
               value="<?= h($editing['operator_name']) ?>">
      </div>
      <div class="field">
        <label for="batch_identifier">Batch or run identifier <span class="muted">(optional)</span></label>
        <input type="text" id="batch_identifier" name="batch_identifier" value="<?= h($editing['batch_identifier']) ?>">
      </div>
    </div>

    <div class="field">
      <label for="notes">Notes <span class="muted">(optional)</span></label>
      <textarea id="notes" name="notes" placeholder="What the run was for."><?= h($editing['notes']) ?></textarea>
    </div>

    <!-- The total sits with the button, where the decision to save is made,
         rather than off in a column beside the form. -->
    <div class="charge-bar" data-role="preview">
      <div class="charge-bar-total">
        <span class="label">Total charge</span>
        <span class="charge-total" data-role="total">&mdash;</span>
        <span class="charge-formula" data-role="formula">Choose an instrument and a count.</span>
      </div>
      <dl class="charge-bar-meta">
        <div><dt>Rate</dt><dd data-role="meta-rate">&mdash;</dd></div>
        <div><dt>Unit</dt><dd data-role="meta-unit">&mdash;</dd></div>
        <div><dt>Receiving account</dt><dd data-role="meta-subaccount">&mdash;</dd></div>
      </dl>
    </div>

    <div class="form-actions">
      <button type="submit" class="button"><?= $editing['usage_id'] ? 'Save correction' : 'Record use' ?></button>
      <?php if ($editing['usage_id']): ?>
        <a class="link-quiet" href="index.php">Cancel</a>
      <?php endif; ?>
    </div>
  </div>

</form>

<h2>Twenty most recent entries</h2>
<div class="card card-tight">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Date</th><th>Instrument</th><th>Operator</th><th>Grant</th>
          <th class="num">Count</th><th class="num">Rate</th><th class="num">Charge</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="8" class="empty">Nothing recorded yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $row):
          $locked = (int) $row['exported'] === 1 || (int) $row['voided'] === 1;
      ?>
        <tr class="<?= (int) $row['voided'] === 1 ? 'is-voided' : ((int) $row['exported'] === 1 ? 'is-locked' : '') ?>">
          <td class="nowrap"><?= h(pretty_date($row['use_date'])) ?></td>
          <td>
            <?= h($row['equipment_name']) ?>
            <?php if ($row['batch_identifier']): ?><p class="hint">batch <?= h($row['batch_identifier']) ?></p><?php endif; ?>
          </td>
          <td><?= h($row['operator_name']) ?></td>
          <td><?= h($row['display_label']) ?><br><span class="hint"><?= h($row['cfopa']) ?></span></td>
          <td class="num"><?= (int) $row['sample_count'] ?></td>
          <td class="num nowrap"><?= h(money($row['rate_charged'])) ?></td>
          <td class="num nowrap"><?= h(money($row['total_charge'])) ?></td>
          <td class="nowrap">
            <?php if ((int) $row['voided'] === 1): ?>
              <span class="pill pill-error">voided</span>
            <?php elseif ((int) $row['exported'] === 1): ?>
              <span class="pill pill-locked">exported</span>
            <?php else: ?>
              <a class="button button-secondary button-small" href="?edit=<?= (int) $row['usage_id'] ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="hint">An exported charge is locked because the business office already has it. An administrator can reopen the export batch.</p>
<?php
page_footer(['scripts' => ['assets/app.js']]);
