<?php
/**
 * admin/labs.php — Laboratories, and who works in each.
 *
 * A laboratory owns its instruments, grants, bookings, charges and costs.
 * Adding one here gives another group of scientists their own set of all of it,
 * in the same installation, without either laboratory seeing the other's.
 *
 * Membership is keyed on the string that identifies a person — the last name
 * they type today, a NetID once the web server can pass one through. Somebody
 * with no laboratory can sign in and see nothing, which is deliberate: better a
 * clear "ask an administrator" than a laboratory chosen for them at random.
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
    $labId  = (int) ($_POST['lab_id'] ?? 0);

    // --- Add or edit a laboratory ------------------------------------------
    if ($action === 'save') {
        $data = [
            'code'                    => strtoupper(trim((string) ($_POST['code'] ?? ''))),
            'name'                    => trim((string) ($_POST['name'] ?? '')),
            'description'             => trim((string) ($_POST['description'] ?? '')),
            'unit_id'                 => ($_POST['unit_id'] ?? '') !== '' ? (int) $_POST['unit_id'] : null,
            'lab_group_name'          => trim((string) ($_POST['lab_group_name'] ?? '')),
            'lab_group_email'         => trim((string) ($_POST['lab_group_email'] ?? '')),
            'equipment_contact_name'  => trim((string) ($_POST['equipment_contact_name'] ?? '')),
            'equipment_contact_email' => trim((string) ($_POST['equipment_contact_email'] ?? '')),
            'equipment_contact_cc'    => trim((string) ($_POST['equipment_contact_cc'] ?? '')),
            'active'                  => isset($_POST['active']) ? 1 : 0,
        ];

        if ($data['code'] === '') { $errors[] = 'Give the laboratory a short code.'; }
        if ($data['name'] === '') { $errors[] = 'Give the laboratory a name.'; }
        if (db_one('SELECT lab_id FROM labs WHERE code = ? AND lab_id <> ?', [$data['code'], $labId])) {
            $errors[] = 'Another laboratory already uses the code ' . $data['code'] . '.';
        }
        foreach (['lab_group_email' => 'laboratory group', 'equipment_contact_email' => 'equipment person',
                  'equipment_contact_cc' => 'copy to'] as $field => $what) {
            foreach (array_filter(array_map('trim', explode(',', $data[$field]))) as $address) {
                if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'The ' . $what . ' address "' . $address . '" is not an email address.';
                }
            }
        }

        if (!$errors) {
            if ($labId) {
                db_run(
                    'UPDATE labs SET code = :code, name = :name, description = :description,
                            unit_id = :unit_id, lab_group_name = :lab_group_name,
                            lab_group_email = :lab_group_email,
                            equipment_contact_name = :equipment_contact_name,
                            equipment_contact_email = :equipment_contact_email,
                            equipment_contact_cc = :equipment_contact_cc, active = :active
                      WHERE lab_id = :id',
                    $data + ['id' => $labId]
                );
                flash($data['name'] . ' updated.');
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                db_run(
                    'INSERT INTO labs (code, name, description, unit_id, lab_group_name, lab_group_email,
                                       equipment_contact_name, equipment_contact_email, equipment_contact_cc,
                                       active, created_at)
                     VALUES (:code, :name, :description, :unit_id, :lab_group_name, :lab_group_email,
                             :equipment_contact_name, :equipment_contact_email, :equipment_contact_cc,
                             :active, :created_at)',
                    $data
                );
                $labId = (int) db()->lastInsertId();
                flash($data['name'] . ' created. Add its people, then its instruments and grants.');
            }
            redirect('labs.php?edit=' . $labId);
        }
        $editing = $data + ['lab_id' => $labId];
    }

    // --- Membership ---------------------------------------------------------
    if ($action === 'add_member') {
        $key  = person_key($_POST['person_key'] ?? '');
        $name = trim((string) ($_POST['display_name'] ?? ''));

        if ($key === '') {
            flash('Give the name the person types when they use the application.', 'error');
        } elseif (!lab_by_id($labId)) {
            flash('That laboratory no longer exists.', 'error');
        } elseif (db_one('SELECT 1 FROM lab_members WHERE lab_id = ? AND person_key = ?', [$labId, $key])) {
            flash($key . ' is already in this laboratory.', 'notice');
        } else {
            db_run(
                'INSERT INTO lab_members (lab_id, person_key, display_name, created_at) VALUES (?,?,?,?)',
                [$labId, $key, $name ?: $key, date('Y-m-d H:i:s')]
            );
            flash($key . ' added to the laboratory.');
        }
        redirect('labs.php?edit=' . $labId);
    }

    if ($action === 'remove_member') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $member   = db_one('SELECT * FROM lab_members WHERE member_id = ?', [$memberId]);
        if ($member) {
            db_run('DELETE FROM lab_members WHERE member_id = ?', [$memberId]);
            flash($member['person_key'] . ' removed. Everything they recorded stays exactly as it was.');
        }
        redirect('labs.php?edit=' . (int) ($_POST['lab_id'] ?? 0));
    }
}

if ($editing === null && isset($_GET['edit'])) {
    $editing = lab_by_id((int) $_GET['edit']);
}

$all = db_all(
    'SELECT l.*,
            (SELECT COUNT(*) FROM equipment e WHERE e.lab_id = l.lab_id AND e.active = 1) AS instruments,
            (SELECT COUNT(*) FROM grants g WHERE g.lab_id = l.lab_id AND g.active = 1) AS grants_count,
            (SELECT COUNT(*) FROM lab_members m WHERE m.lab_id = l.lab_id) AS people,
            (SELECT COUNT(*) FROM usage_records r
               JOIN equipment e2 ON e2.equipment_id = r.equipment_id
              WHERE e2.lab_id = l.lab_id AND r.voided = 0) AS charges
       FROM labs l
      ORDER BY l.active DESC, l.name COLLATE NOCASE'
);

$units   = db_all('SELECT * FROM units WHERE active = 1 ORDER BY code');
$members = $editing && !empty($editing['lab_id'])
    ? db_all('SELECT * FROM lab_members WHERE lab_id = ? ORDER BY person_key', [(int) $editing['lab_id']])
    : [];

admin_header('labs', 'Laboratories');
?>
<h1>Laboratories</h1>
<p class="lede">Each laboratory keeps its own instruments, grants, bookings, charges and costs.
Nothing is shared between them except the survey unit list and these administrator accounts.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php accordion_open('labs-list', 'Laboratories', [
    'open' => true,
    'meta' => count($all) . ' set up',
]); ?>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Laboratory</th><th>Code</th><th>Unit</th><th class="num">People</th>
            <th class="num">Instruments</th><th class="num">Grants</th><th class="num">Charges</th></tr>
      </thead>
      <tbody>
      <?php if (!$all): ?>
        <tr><td colspan="7" class="empty">No laboratories yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($all as $row): ?>
        <tr<?= (int) $row['active'] === 0 ? ' class="is-locked"' : '' ?>>
          <td>
            <a href="?edit=<?= (int) $row['lab_id'] ?>"><?= h($row['name']) ?></a>
            <?= (int) $row['active'] === 0 ? ' <span class="pill">closed</span>' : '' ?>
            <?php if ($row['description']): ?><p class="hint"><?= h($row['description']) ?></p><?php endif; ?>
          </td>
          <td><code><?= h($row['code']) ?></code></td>
          <td><?= h((string) db_value('SELECT code FROM units WHERE unit_id = ?', [$row['unit_id']])) ?></td>
          <td class="num"><?= (int) $row['people'] ?></td>
          <td class="num"><?= (int) $row['instruments'] ?></td>
          <td class="num"><?= (int) $row['grants_count'] ?></td>
          <td class="num"><?= (int) $row['charges'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php accordion_close(); ?>

<?php if ($editing && !empty($editing['lab_id'])): ?>
<?php accordion_open('labs-people', 'People in ' . ($editing['name'] ?? 'this laboratory'), [
    'open' => true,
    'meta' => count($members) . ' assigned',
]); ?>
  <p class="hint">A person types their last name when they open the application, and that is what
  goes here. Once the web server can pass a NetID through, these become NetIDs and nothing else changes.</p>

  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Types</th><th>Name</th><th>Added</th><th></th></tr></thead>
      <tbody>
      <?php if (!$members): ?>
        <tr><td colspan="4" class="empty">Nobody yet. Until somebody is added, only administrators can see this laboratory.</td></tr>
      <?php endif; ?>
      <?php foreach ($members as $member): ?>
        <tr>
          <td><code><?= h($member['person_key']) ?></code></td>
          <td><?= h($member['display_name']) ?></td>
          <td class="nowrap"><?= h(pretty_date($member['created_at'])) ?></td>
          <td class="nowrap">
            <form method="post" onsubmit="return confirm('Remove <?= h($member['person_key']) ?> from this laboratory? Their entries and bookings stay.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_member">
              <input type="hidden" name="member_id" value="<?= (int) $member['member_id'] ?>">
              <input type="hidden" name="lab_id" value="<?= (int) $editing['lab_id'] ?>">
              <button type="submit" class="button button-secondary button-small">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" class="form field-spaced">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_member">
    <input type="hidden" name="lab_id" value="<?= (int) $editing['lab_id'] ?>">
    <div class="field-row">
      <div class="field">
        <label for="person_key">Name they type</label>
        <input type="text" id="person_key" name="person_key" required placeholder="Ruiz">
        <p class="hint">Case does not matter.</p>
      </div>
      <div class="field">
        <label for="display_name">Full name <span class="muted">(optional)</span></label>
        <input type="text" id="display_name" name="display_name" placeholder="Elena Ruiz">
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="button">Add to laboratory</button>
    </div>
  </form>
<?php accordion_close(); ?>
<?php endif; ?>

<?php accordion_open('labs-form', $editing && !empty($editing['lab_id']) ? 'Edit this laboratory' : 'Add a laboratory', [
    'open' => (bool) $editing || (bool) $errors,
]); ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="lab_id" value="<?= (int) ($editing['lab_id'] ?? 0) ?>">

    <div class="field-row">
      <div class="field">
        <label for="code">Short code</label>
        <input type="text" id="code" name="code" required placeholder="PACE" value="<?= h($editing['code'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="unit_id">Survey unit</label>
        <select id="unit_id" name="unit_id">
          <option value="">&mdash;</option>
          <?php foreach ($units as $unit): ?>
            <option value="<?= (int) $unit['unit_id'] ?>" <?= (int) ($editing['unit_id'] ?? 0) === (int) $unit['unit_id'] ? 'selected' : '' ?>>
              <?= h($unit['code']) ?><?= $unit['name'] ? ' — ' . h($unit['name']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label for="name">Name</label>
      <input type="text" id="name" name="name" required placeholder="PaCE Lab" value="<?= h($editing['name'] ?? '') ?>">
      <p class="hint">Shown in the laboratory picker under the header.</p>
    </div>

    <div class="field">
      <label for="description">What it does <span class="muted">(optional)</span></label>
      <input type="text" id="description" name="description" value="<?= h($editing['description'] ?? '') ?>">
    </div>

    <fieldset>
      <legend>Who this laboratory tells</legend>
      <p class="hint">Each laboratory has its own. An instrument going out of service opens a message
      to the group; retiring one opens a message to the equipment person.</p>

      <div class="field-row">
        <div class="field">
          <label for="lab_group_name">Group name</label>
          <input type="text" id="lab_group_name" name="lab_group_name" value="<?= h($editing['lab_group_name'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="lab_group_email">Group email</label>
          <input type="text" id="lab_group_email" name="lab_group_email" value="<?= h($editing['lab_group_email'] ?? '') ?>">
        </div>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="equipment_contact_name">Equipment person</label>
          <input type="text" id="equipment_contact_name" name="equipment_contact_name" value="<?= h($editing['equipment_contact_name'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="equipment_contact_email">Their email</label>
          <input type="text" id="equipment_contact_email" name="equipment_contact_email" value="<?= h($editing['equipment_contact_email'] ?? '') ?>">
        </div>
      </div>

      <div class="field">
        <label for="equipment_contact_cc">Copy to <span class="muted">(optional)</span></label>
        <input type="text" id="equipment_contact_cc" name="equipment_contact_cc" value="<?= h($editing['equipment_contact_cc'] ?? '') ?>">
      </div>
    </fieldset>

    <div class="checkline">
      <input type="checkbox" id="active" name="active" value="1" <?= (!$editing || (int) ($editing['active'] ?? 1) === 1) ? 'checked' : '' ?>>
      <label for="active">Open</label>
    </div>
    <p class="hint">Closing a laboratory hides it from the picker. Nothing is deleted, and its charges
    stay on every report they already appear on.</p>

    <div class="form-actions">
      <button type="submit" class="button"><?= $editing && !empty($editing['lab_id']) ? 'Save changes' : 'Create laboratory' ?></button>
      <?php if ($editing): ?><a class="link-quiet" href="labs.php">Cancel</a><?php endif; ?>
    </div>
  </form>
<?php accordion_close(); ?>
<?php
page_footer(['scripts' => ['assets/app.js']]);
