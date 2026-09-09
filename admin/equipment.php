<?php
/**
 * admin/equipment.php — Add, edit, and retire instruments.
 *
 * Retiring sets active to 0. It never deletes, because every historical charge
 * points at the instrument row and the report still has to name it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
require_admin();

$editing = null;
$errors  = [];

// --- Write -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'retire' || $action === 'restore') {
        $id = (int) ($_POST['equipment_id'] ?? 0);
        $row = equipment_by_id($id);
        if ($row) {
            $retiring = ($action === 'retire');
            db_run(
                'UPDATE equipment SET active = ?, status = ?, status_note = ?, status_since = ?
                  WHERE equipment_id = ?',
                [
                    $retiring ? 0 : 1,
                    $retiring ? 'retired' : 'available',
                    $retiring ? trim((string) ($_POST['status_note'] ?? '')) : '',
                    date('Y-m-d'),
                    $id,
                ]
            );
            flash($row['name'] . ($retiring
                ? ' is retired. Its charge history is untouched.'
                : ' is available again.'));

            // Retiring is the thing the equipment person has to know about.
            // Compose and log it here, once, rather than on the page that
            // follows: a refresh of that page must not write a second record.
            if ($retiring) {
                $emailId = log_email(compose_equipment_email(equipment_by_id($id), 'retired'));
                redirect('equipment.php?notify=' . $emailId);
            }
        }
        redirect('equipment.php');
    }

    // Operational status: down, service due, or back in service.
    if ($action === 'status') {
        $id   = (int) ($_POST['equipment_id'] ?? 0);
        $row  = equipment_by_id($id);
        $new  = (string) ($_POST['status'] ?? '');
        $note = trim((string) ($_POST['status_note'] ?? ''));
        $due  = clean_date($_POST['service_due_date'] ?? null);

        if (!$row) {
            flash('That instrument no longer exists.', 'error');
            redirect('equipment.php');
        }
        if (!array_key_exists($new, picklist('equip_status')) || $new === 'retired') {
            flash('Choose a status. Retiring is a separate action.', 'error');
            redirect('equipment.php?edit=' . $id);
        }
        if ($new === 'down' && $note === '') {
            flash('Say what is wrong with it. Anyone who tries to book it will be shown that sentence.', 'error');
            redirect('equipment.php?edit=' . $id);
        }

        db_run(
            'UPDATE equipment SET status = ?, status_note = ?, status_since = ?, service_due_date = ?
              WHERE equipment_id = ?',
            [$new, $note, ($new === $row['status'] ? $row['status_since'] : date('Y-m-d')) ?: date('Y-m-d'), $due, $id]
        );

        flash($row['name'] . ' is now ' . strtolower(picklist_label('equip_status', $new)) . '.');

        // Going out of service needs two messages, because it lands on two
        // different sets of people. The equipment person has to get it fixed;
        // the laboratory has to work around it today. Coming back into service
        // is good news and can wait for somebody to notice.
        if ($new === 'down') {
            $item = equipment_by_id($id);
            $ids  = [
                log_email(compose_lab_group_email($item, 'down')),
                log_email(compose_equipment_email($item, 'down')),
            ];
            redirect('equipment.php?notify=' . implode(',', $ids));
        }
        redirect('equipment.php');
    }

    if ($action === 'save') {
        $id = (int) ($_POST['equipment_id'] ?? 0);
        $existing = $id ? equipment_by_id($id) : null;

        $data = [
            'property_tag'         => clean_property_tag($_POST['property_tag'] ?? ''),
            'equip_class'          => trim((string) ($_POST['equip_class'] ?? 'instrument')),
            'name'                 => trim((string) ($_POST['name'] ?? '')),
            'manufacturer'         => trim((string) ($_POST['manufacturer'] ?? '')),
            'model'                => trim((string) ($_POST['model'] ?? '')),
            'location'             => trim((string) ($_POST['location'] ?? '')),
            'rate'                 => (float) ($_POST['rate'] ?? 0),
            'rate_unit'            => (string) ($_POST['rate_unit'] ?? 'per sample'),
            'rate_effective_date'  => clean_date($_POST['rate_effective_date'] ?? null),
            // The account the money lands in is a CFOPA too. PRI already bills
            // its field stations this way: one self-supporting fund, one
            // activity segment per facility, so A51 and A52 are two instruments
            // sharing a fund.
            'receiving_subaccount' => cfopa_normalize($_POST['receiving_subaccount'] ?? ''),
            'contact_person'       => trim((string) ($_POST['contact_person'] ?? '')),
            'active'               => isset($_POST['active']) ? 1 : 0,
        ];

        if ($data['name'] === '')                                  { $errors[] = 'The instrument needs a display name.'; }
        if ($data['rate'] < 0)                                     { $errors[] = 'The rate cannot be negative.'; }
        if (!in_array($data['rate_unit'], rate_units(), true))     { $errors[] = 'Choose a rate unit.'; }
        if ($data['receiving_subaccount'] === '')                  { $errors[] = 'The receiving subaccount is required; every charge snapshots it.'; }

        // A tag identifies one item. The wording follows PRI Facilities, which
        // says the same thing when a tag is already spoken for.
        if ($data['property_tag'] !== '') {
            $tagClash = db_one(
                'SELECT name FROM equipment WHERE property_tag = ? AND equipment_id <> ?',
                [$data['property_tag'], $id]
            );
            if ($tagClash) {
                $errors[] = 'Inventory number ' . $data['property_tag'] . ' is already on ' . $tagClash['name'] . '.';
            }
        }

        // A rate change without a stated effective date takes effect today.
        if (!$data['rate_effective_date'] || ($existing && (float) $existing['rate'] !== $data['rate'] && $data['rate_effective_date'] === $existing['rate_effective_date'])) {
            $data['rate_effective_date'] = date('Y-m-d');
        }

        if (!$errors) {
            if ($existing) {
                db_run(
                    'UPDATE equipment SET property_tag = :property_tag, equip_class = :equip_class, name = :name,
                            manufacturer = :manufacturer, model = :model, location = :location,
                            rate = :rate, rate_unit = :rate_unit, rate_effective_date = :rate_effective_date,
                            receiving_subaccount = :receiving_subaccount, contact_person = :contact_person,
                            active = :active
                      WHERE equipment_id = :id',
                    $data + ['id' => $id]
                );
                flash($data['name'] . ' updated. Charges already recorded keep the rate they were entered with.');
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['lab_id']     = current_lab_id();
                db_run(
                    'INSERT INTO equipment (lab_id, property_tag, equip_class, name, manufacturer, model, location, rate,
                                            rate_unit, rate_effective_date, receiving_subaccount,
                                            contact_person, active, created_at)
                     VALUES (:lab_id, :property_tag, :equip_class, :name, :manufacturer, :model, :location, :rate,
                             :rate_unit, :rate_effective_date, :receiving_subaccount,
                             :contact_person, :active, :created_at)',
                    $data
                );
                flash($data['name'] . ' added.');
            }
            redirect('equipment.php');
        }

        $editing = $data + ['equipment_id' => $id];
    }
}

// --- Read ------------------------------------------------------------------
if ($editing === null && isset($_GET['edit'])) {
    $editing = equipment_by_id((int) $_GET['edit']);
}

$all = lab_equipment(false);

// --- The message to the equipment person -----------------------------------
// Read back from the log rather than recomposed, so what the panel offers and
// what the record says are the same words.
$notify = [];
if (isset($_GET['notify'])) {
    foreach (array_slice(explode(',', (string) $_GET['notify']), 0, 5) as $rawId) {
        $logged = db_one('SELECT * FROM email_log WHERE email_id = ? AND lab_id = ?', [(int) $rawId, current_lab_id()]);
        if (!$logged) {
            continue;
        }
        $notify[] = [
            'id'       => (int) $logged['email_id'],
            'to'       => $logged['to_address'],
            'cc'       => $logged['cc_address'],
            'subject'  => $logged['subject'],
            'audience' => strpos($logged['purpose'], 'labgroup_') === 0
                ? (lab_group()['name'] ?: 'the laboratory group')
                : (setting('equipment_contact_name') ?: 'the equipment person'),
            'href'     => $logged['to_address'] === '' ? null : mailto_link(
                $logged['to_address'],
                $logged['subject'],
                explode("\r\n", $logged['body']),
                $logged['cc_address']
            ),
        ];
    }
}


admin_header('equipment', 'Equipment');
?>
<h1>Equipment</h1>
<p class="lede">The rate, rate unit, and receiving subaccount here are copied onto each charge at the moment of entry. Raising a rate today never rewrites yesterday's charges.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php if ($notify): ?>
  <?php
    // Only the first message opens by itself. Firing several compose windows at
    // once would have the operating system fighting itself, and the person
    // would lose track of which one they had dealt with.
    $sendable = array_values(array_filter($notify, fn($m) => $m['href'] !== null));
    $orphans  = array_values(array_filter($notify, fn($m) => $m['href'] === null));
  ?>

  <?php if ($sendable): ?>
  <div class="card notify-card"
       data-mailto="<?= h($sendable[0]['href']) ?>"
       data-mailto-id="<?= (int) $sendable[0]['id'] ?>"
       data-csrf="<?= h(csrf_token()) ?>">
    <h2 class="card-heading">
      <?= count($sendable) === 1 ? 'One message to send' : count($sendable) . ' messages to send' ?>
    </h2>
    <p>The first is opening in Outlook now. Nothing has been sent yet — read each one,
       change it if you want to, and send it yourself.</p>

    <ol class="notify-list">
      <?php foreach ($sendable as $i => $message): ?>
        <li>
          <div class="notify-row">
            <span class="notify-to">
              <strong>To <?= h($message['audience']) ?></strong>
              <span class="hint"><?= h($message['to']) ?><?= $message['cc'] ? ' · copy to ' . h($message['cc']) : '' ?></span>
              <span class="hint"><?= h($message['subject']) ?></span>
            </span>
            <a class="button <?= $i === 0 ? 'button-secondary' : '' ?>" href="<?= h($message['href']) ?>">
              <?= $i === 0 ? 'Open it again' : 'Open this one' ?>
            </a>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>

    <div class="button-row">
      <a class="button button-secondary" href="emails.php">See the message log</a>
      <a class="button button-secondary" href="equipment.php">Done</a>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($orphans as $message): ?>
    <div class="flash flash-notice">
      A message to <?= h($message['audience']) ?> was written and logged, but there is
      nobody to send it to: no address has been set.
      <a href="settings.php">Add one under Interface text</a>, then
      <a href="emails.php?open=<?= (int) $message['id'] ?>">open it from the message log</a>.
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php render_alerts(equipment_alerts('../'), 'Needs attention'); ?>

<div class="stack">
  <div>
    <div class="card card-tight">
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Instrument</th><th>Kind</th><th>Location</th><th class="num">Rate</th>
              <th>Subaccount</th><th>Held by</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$all): ?>
            <tr><td colspan="7" class="empty">No instruments yet. Add the first one with the form beside this list.</td></tr>
          <?php endif; ?>
          <?php foreach ($all as $row): ?>
            <tr<?= (int) $row['active'] === 0 ? ' class="is-locked"' : '' ?>>
              <td>
                <a href="?edit=<?= (int) $row['equipment_id'] ?>"><?= h($row['name']) ?></a>
                <?= (int) $row['active'] === 0 ? ' <span class="pill">retired</span>' : '' ?>
                <?php if ((int) $row['active'] === 1 && equipment_is_down($row)): ?>
                  <span class="pill pill-error">down</span>
                <?php elseif ((int) $row['active'] === 1 && ($row['status'] ?? '') === 'service_due'): ?>
                  <span class="pill pill-warn">service due</span>
                <?php endif; ?>
                <?php if ($row['manufacturer'] || $row['model'] || $row['property_tag']): ?>
                  <p class="hint"><?= h(trim($row['manufacturer'] . ' ' . $row['model'])) ?><?= $row['property_tag'] ? ' &middot; ' . h($row['property_tag']) : '' ?></p>
                <?php endif; ?>
              </td>
              <td><?= h(picklist_label('equip_class', $row['equip_class'])) ?></td>
              <td><?= h($row['location']) ?></td>
              <td class="num nowrap">
                <?= h(money($row['rate'])) ?><br>
                <span class="hint"><?= h($row['rate_unit']) ?></span>
              </td>
              <td><code><?= h($row['receiving_subaccount']) ?></code></td>
              <td><?= h($row['contact_person']) ?></td>
              <td class="nowrap">
                <form method="post" onsubmit="return confirm('<?= (int) $row['active'] === 1
                    ? 'Retire this instrument? It stops appearing in the booking and charging lists, its history is kept, and a message to the equipment person will open.'
                    : 'Put this instrument back on the books?' ?>');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="equipment_id" value="<?= (int) $row['equipment_id'] ?>">
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
    <input type="hidden" name="equipment_id" value="<?= (int) ($editing['equipment_id'] ?? 0) ?>">

    <h2><?= $editing ? 'Edit instrument' : 'Add an instrument' ?></h2>

    <div class="field">
      <label for="name">Display name</label>
      <input type="text" id="name" name="name" required value="<?= h($editing['name'] ?? '') ?>">
      <p class="hint">Shown in every dropdown and on the report.</p>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="manufacturer">Make</label>
        <input type="text" id="manufacturer" name="manufacturer" value="<?= h($editing['manufacturer'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="model">Model</label>
        <input type="text" id="model" name="model" value="<?= h($editing['model'] ?? '') ?>">
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="property_tag">University inventory number</label>
        <input type="text" id="property_tag" name="property_tag" placeholder="P10E35946"
               value="<?= h($editing['property_tag'] ?? '') ?>">
        <p class="hint">The tag PRI Facilities knows the item by. Leave blank for anything untagged.</p>
      </div>
      <div class="field">
        <label for="location">Location</label>
        <input type="text" id="location" name="location" value="<?= h($editing['location'] ?? '') ?>" placeholder="Building and room">
      </div>
    </div>

    <div class="field">
      <label for="equip_class">Kind of thing</label>
      <select id="equip_class" name="equip_class">
        <?php foreach (picklist('equip_class') as $code => $label): ?>
          <option value="<?= h($code) ?>" <?= ($editing['equip_class'] ?? 'instrument') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <fieldset>
      <legend>Charging</legend>
      <div class="field-row">
        <div class="field">
          <label for="rate">Rate</label>
          <input type="number" id="rate" name="rate" step="0.01" min="0" required value="<?= h((string) ($editing['rate'] ?? '0.00')) ?>">
        </div>
        <div class="field">
          <label for="rate_unit">Rate unit</label>
          <select id="rate_unit" name="rate_unit">
            <?php foreach (rate_units() as $unit): ?>
              <option value="<?= h($unit) ?>" <?= ($editing['rate_unit'] ?? '') === $unit ? 'selected' : '' ?>><?= h($unit) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="rate_effective_date">Rate effective date</label>
        <input type="date" id="rate_effective_date" name="rate_effective_date" value="<?= h($editing['rate_effective_date'] ?? date('Y-m-d')) ?>">
        <p class="hint">Left blank on a rate change, this becomes today.</p>
      </div>
      <div class="field">
        <label for="receiving_subaccount">Receiving account (CFOPA)</label>
        <input type="text" id="receiving_subaccount" name="receiving_subaccount" required
               placeholder="1-303631-375002-375150-A51"
               value="<?= h($editing['receiving_subaccount'] ?? '') ?>">
        <p class="hint">The self-supporting account the revenue lands in. Give each instrument its
        own activity segment on a shared fund, the way the field stations are billed.
        <?php if (!empty($editing['receiving_subaccount']) && !cfopa_is_well_formed($editing['receiving_subaccount'])): ?>
          <br><span class="grant-hint-warn">This is not the usual CFOPA shape; check it before the next export.</span>
        <?php endif; ?>
        </p>
      </div>
    </fieldset>

    <div class="field">
      <label for="contact_person">Responsible person</label>
      <input type="text" id="contact_person" name="contact_person" value="<?= h($editing['contact_person'] ?? '') ?>">
    </div>

    <div class="checkline">
      <input type="checkbox" id="active" name="active" value="1" <?= (!$editing || (int) ($editing['active'] ?? 1) === 1) ? 'checked' : '' ?>>
      <label for="active">Show in dropdowns</label>
    </div>

    <div class="form-actions">
      <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Add instrument' ?></button>
      <?php if ($editing): ?><a class="link-quiet" href="equipment.php">Cancel</a><?php endif; ?>
    </div>
  </form>

<?php if ($editing && !empty($editing['equipment_id']) && (int) ($editing['active'] ?? 1) === 1): ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="status">
    <input type="hidden" name="equipment_id" value="<?= (int) $editing['equipment_id'] ?>">

    <h2 class="card-heading">Service and downtime</h2>
    <p class="hint">An instrument that is down keeps its rate, its account, and its whole
    charge history. It simply stops accepting new bookings and new charges until it
    is back. Marking one down opens a message to the equipment person.</p>

    <div class="field-row">
      <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
          <?php foreach (picklist('equip_status') as $code => $label): ?>
            <?php if ($code === 'retired') { continue; } ?>
            <option value="<?= h($code) ?>" <?= ($editing['status'] ?? 'available') === $code ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="service_due_date">Next service due</label>
        <input type="date" id="service_due_date" name="service_due_date"
               value="<?= h($editing['service_due_date'] ?? '') ?>">
        <p class="hint">Flagged on the home screen a fortnight before.</p>
      </div>
    </div>

    <div class="field">
      <label for="status_note">What is wrong, or what service is due</label>
      <textarea id="status_note" name="status_note"
                placeholder="Shown to anyone who tries to book or charge it."><?= h($editing['status_note'] ?? '') ?></textarea>
      <p class="hint">Required when marking an instrument down.</p>
    </div>

    <?php if (!empty($editing['status_since']) && ($editing['status'] ?? '') !== 'available'): ?>
      <p class="hint">Marked <?= h(strtolower(picklist_label('equip_status', $editing['status']))) ?>
      on <?= h(pretty_date($editing['status_since'])) ?>.</p>
    <?php endif; ?>

    <div class="form-actions">
      <button type="submit" class="button">Save status</button>
    </div>
  </form>
<?php endif; ?>
</div>
<?php
page_footer(['scripts' => ['assets/app.js']]);
