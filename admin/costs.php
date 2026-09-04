<?php
/**
 * admin/costs.php — What each instrument costs to run.
 *
 * Consumables, repairs, parts, calibration, and the contracts that cover it,
 * set against what the instrument has earned. This is the screen that answers
 * whether a rate is right: a recharge account is meant to recover its costs,
 * not to make money and not to quietly lose it.
 *
 * Nothing here touches a charge. Costs are what the laboratory spends; charges
 * are what it bills. Keeping them apart is the point.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
require_admin();

$errors  = [];
$editing = null;

// --- Write -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    $costId = (int) ($_POST['cost_id'] ?? 0);

    if ($action === 'delete') {
        $row = db_one('SELECT * FROM equipment_costs WHERE cost_id = ?', [$costId]);
        if ($row) {
            db_run('DELETE FROM equipment_costs WHERE cost_id = ?', [$costId]);
            flash('Removed ' . money($row['amount']) . ' from the cost record.');
        }
        redirect('costs.php?equipment_id=' . (int) ($_POST['equipment_id'] ?? 0));
    }

    if ($action === 'save') {
        $data = [
            'equipment_id'    => (int) ($_POST['equipment_id'] ?? 0),
            'cost_date'       => clean_date($_POST['cost_date'] ?? null),
            'category'        => (string) ($_POST['category'] ?? 'consumable'),
            'description'     => trim((string) ($_POST['description'] ?? '')),
            'vendor'          => trim((string) ($_POST['vendor'] ?? '')),
            'amount'          => (float) str_replace([',', '$'], '', (string) ($_POST['amount'] ?? '0')),
            'paid_from_cfopa' => cfopa_normalize($_POST['paid_from_cfopa'] ?? ''),
            'reference'       => trim((string) ($_POST['reference'] ?? '')),
            'covers_start'    => clean_date($_POST['covers_start'] ?? null),
            'covers_end'      => clean_date($_POST['covers_end'] ?? null),
            'notes'           => trim((string) ($_POST['notes'] ?? '')),
        ];

        if (!equipment_by_id($data['equipment_id']))                  { $errors[] = 'Choose an instrument.'; }
        if (!$data['cost_date'])                                      { $errors[] = 'Give the date of the spend.'; }
        if ($data['description'] === '')                              { $errors[] = 'Say what the money went on.'; }
        if ($data['amount'] <= 0)                                     { $errors[] = 'The amount has to be more than nothing.'; }
        if (!array_key_exists($data['category'], picklist('cost_category'))) { $errors[] = 'Choose a category.'; }
        if ($data['covers_start'] && $data['covers_end'] && $data['covers_start'] > $data['covers_end']) {
            $errors[] = 'The cover starts after it ends.';
        }

        if (!$errors) {
            if ($costId) {
                db_run(
                    'UPDATE equipment_costs SET equipment_id = :equipment_id, cost_date = :cost_date,
                            category = :category, description = :description, vendor = :vendor,
                            amount = :amount, paid_from_cfopa = :paid_from_cfopa, reference = :reference,
                            covers_start = :covers_start, covers_end = :covers_end, notes = :notes
                      WHERE cost_id = :id',
                    $data + ['id' => $costId]
                );
                flash('Cost updated.');
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['created_by'] = admin_display_name();
                db_run(
                    'INSERT INTO equipment_costs (equipment_id, cost_date, category, description, vendor,
                            amount, paid_from_cfopa, reference, covers_start, covers_end, notes,
                            created_at, created_by)
                     VALUES (:equipment_id, :cost_date, :category, :description, :vendor,
                             :amount, :paid_from_cfopa, :reference, :covers_start, :covers_end, :notes,
                             :created_at, :created_by)',
                    $data
                );
                flash(money($data['amount']) . ' recorded against ' . equipment_by_id($data['equipment_id'])['name'] . '.');
            }
            redirect('costs.php?equipment_id=' . $data['equipment_id']);
        }
        $editing = $data + ['cost_id' => $costId];
    }
}

if ($editing === null && isset($_GET['edit'])) {
    $editing = db_one('SELECT * FROM equipment_costs WHERE cost_id = ?', [(int) $_GET['edit']]);
}

// --- Read ------------------------------------------------------------------
$filterEquip = (int) ($_GET['equipment_id'] ?? ($editing['equipment_id'] ?? 0));
$window      = max(30, (int) ($_GET['window'] ?? 365));
$equipment   = lab_equipment(false);

$sql = 'SELECT c.*, e.name AS equipment_name
          FROM equipment_costs c
          JOIN equipment e ON e.equipment_id = c.equipment_id
          WHERE e.lab_id = ?';
$params = [current_lab_id()];
if ($filterEquip) {
    $sql .= ' AND c.equipment_id = ?';
    $params[] = $filterEquip;
}
$sql .= ' ORDER BY c.cost_date DESC, c.cost_id DESC LIMIT 300';
$costs = db_all($sql, $params);

/** Cost, revenue, and the gap between them, per instrument over the window. */
function recovery_table(array $equipment, int $window): array
{
    $rows = [];
    foreach ($equipment as $item) {
        if ((int) $item['active'] !== 1) { continue; }
        $id      = (int) $item['equipment_id'];
        $cost    = equipment_cost_total($id, $window);
        $revenue = equipment_revenue_total($id, $window);
        if ($cost <= 0 && $revenue <= 0) { continue; }
        $rows[] = [
            'item'    => $item,
            'cost'    => $cost,
            'revenue' => $revenue,
            'gap'     => $revenue - $cost,
            'percent' => $cost > 0 ? (int) round($revenue / $cost * 100) : null,
            'use'     => equipment_utilisation($id),
        ];
    }
    usort($rows, fn($a, $b) => $a['gap'] <=> $b['gap']);   // worst recovery first
    return $rows;
}

$recovery = recovery_table($equipment, $window);
$byCategory = db_all(
    'SELECT c.category, COUNT(*) AS n, SUM(c.amount) AS total FROM equipment_costs c
        JOIN equipment e ON e.equipment_id = c.equipment_id
       WHERE e.lab_id = ' . current_lab_id() . ' AND c.cost_date >= ?' . ($filterEquip ? ' AND c.equipment_id = ' . (int) $filterEquip : '') . '
      GROUP BY c.category ORDER BY total DESC',
    [date('Y-m-d', strtotime('-' . $window . ' days'))]
);

admin_header('costs', 'Running costs');
?>
<h1>Running costs</h1>
<p class="lede">What each instrument costs to keep working, set against what it has earned.
A recharge account is meant to recover its costs — no more, and no less.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="stack">

  <!-- Cost recovery ------------------------------------------------------- -->
  <div>
    <?php
      $spent  = array_sum(array_column($recovery, 'cost'));
      $earned = array_sum(array_column($recovery, 'revenue'));
      accordion_open('costs-recovery', 'Cost recovery, last ' . (int) round($window / 30) . ' months', [
          'open' => true,
          'tone' => $earned < $spent ? 'warning' : '',
          'meta' => money($spent) . ' spent · ' . money($earned) . ' earned',
      ]);
    ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Instrument</th><th class="num">Spent</th><th class="num">Earned</th>
              <th class="num">Recovered</th><th class="num">Gap</th><th class="num">Booked</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$recovery): ?>
            <tr><td colspan="6" class="empty">Nothing spent and nothing earned yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($recovery as $row): ?>
            <tr>
              <td>
                <a href="?equipment_id=<?= (int) $row['item']['equipment_id'] ?>"><?= h($row['item']['name']) ?></a>
                <?php if (equipment_is_down($row['item'])): ?><span class="pill pill-error">down</span><?php endif; ?>
              </td>
              <td class="num nowrap"><?= h(money($row['cost'])) ?></td>
              <td class="num nowrap"><?= h(money($row['revenue'])) ?></td>
              <td class="num nowrap">
                <?= $row['percent'] === null ? '<span class="muted">&mdash;</span>' : $row['percent'] . '%' ?>
              </td>
              <td class="num nowrap <?= $row['gap'] < 0 ? 'is-short' : 'is-ahead' ?>">
                <?= ($row['gap'] < 0 ? '&minus;' : '+') . h(money(abs($row['gap']))) ?>
              </td>
              <td class="num nowrap"><?= (int) $row['use']['percent'] ?>%</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint">Gap is what the instrument earned minus what was spent on it.
      A negative figure means the laboratory is subsidising it: either the rate is too low,
      or it is not being used enough to spread its costs.</p>
    <?php accordion_close(); ?>

    <?php if ($byCategory): ?>
      <?php accordion_open('costs-breakdown', 'Where the money went', [
          'meta' => count($byCategory) . ' categories',
      ]); ?>
        <ul class="cost-breakdown">
          <?php foreach ($byCategory as $cat): ?>
            <li>
              <span><?= h(picklist_label('cost_category', $cat['category'])) ?></span>
              <span class="muted"><?= (int) $cat['n'] ?> item<?= (int) $cat['n'] === 1 ? '' : 's' ?></span>
              <strong><?= h(money($cat['total'])) ?></strong>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php accordion_close(); ?>
    <?php endif; ?>
  </div>

  <!-- The ledger ----------------------------------------------------------- -->
  <div>
    <?php accordion_open('costs-ledger', 'Recorded costs', [
        'open' => true,
        'meta' => count($costs) . ' entries',
    ]); ?>
    <form method="get" class="report-filters">
      <div class="field">
        <label for="equipment_id">Instrument</label>
        <select id="equipment_id" name="equipment_id">
          <option value="">All instruments</option>
          <?php foreach ($equipment as $item): ?>
            <option value="<?= (int) $item['equipment_id'] ?>" <?= $filterEquip === (int) $item['equipment_id'] ? 'selected' : '' ?>>
              <?= h($item['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="window">Period</label>
        <select id="window" name="window">
          <?php foreach ([90 => 'Last 3 months', 180 => 'Last 6 months', 365 => 'Last 12 months', 1095 => 'Last 3 years'] as $days => $label): ?>
            <option value="<?= $days ?>" <?= $window === $days ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="button">Show</button>
    </form>

    <div class="card card-tight">
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Date</th><th>Instrument</th><th>Category</th><th>What for</th>
              <th>Vendor</th><th>Paid from</th><th class="num">Amount</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$costs): ?>
            <tr><td colspan="8" class="empty">No costs recorded yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($costs as $row): ?>
            <tr>
              <td class="nowrap"><?= h(pretty_date($row['cost_date'])) ?></td>
              <td><?= h($row['equipment_name']) ?></td>
              <td class="nowrap"><?= h(picklist_label('cost_category', $row['category'])) ?></td>
              <td>
                <?= h($row['description']) ?>
                <?php if ($row['covers_end']): ?>
                  <p class="hint">Covers <?= h(pretty_date($row['covers_start'])) ?>
                  to <?= h(pretty_date($row['covers_end'])) ?>
                  <?php if ($row['covers_end'] < date('Y-m-d')): ?>
                    <span class="pill pill-error">expired</span>
                  <?php endif; ?></p>
                <?php endif; ?>
                <?php if ($row['reference']): ?><p class="hint">Ref <?= h($row['reference']) ?></p><?php endif; ?>
              </td>
              <td><?= h($row['vendor']) ?></td>
              <td><?php if ($row['paid_from_cfopa']): ?><code><?= h($row['paid_from_cfopa']) ?></code><?php endif; ?></td>
              <td class="num nowrap"><?= h(money($row['amount'])) ?></td>
              <td class="nowrap">
                <div class="button-row">
                  <a class="button button-secondary button-small" href="?edit=<?= (int) $row['cost_id'] ?>">Edit</a>
                  <form method="post" onsubmit="return confirm('Remove this cost entry?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="cost_id" value="<?= (int) $row['cost_id'] ?>">
                    <input type="hidden" name="equipment_id" value="<?= (int) $filterEquip ?>">
                    <button type="submit" class="button button-danger button-small">Remove</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <p class="hint">The most recent 300 entries are listed.</p>
    <?php accordion_close(); ?>
  </div>

  <!-- Add or edit ---------------------------------------------------------- -->
  <?php accordion_open('costs-add', !empty($editing['cost_id']) ? 'Edit this cost' : 'Record a cost', [
      'open' => !empty($editing) || $errors,
  ]); ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="cost_id" value="<?= (int) ($editing['cost_id'] ?? 0) ?>">

    <div class="field-row">
      <div class="field">
        <label for="c_equipment">Instrument</label>
        <select id="c_equipment" name="equipment_id" required>
          <option value="">Choose an instrument&hellip;</option>
          <?php foreach ($equipment as $item): ?>
            <option value="<?= (int) $item['equipment_id'] ?>"
              <?= (int) ($editing['equipment_id'] ?? $filterEquip) === (int) $item['equipment_id'] ? 'selected' : '' ?>>
              <?= h($item['name']) ?><?= (int) $item['active'] === 0 ? ' (retired)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="c_date">Date of the spend</label>
        <input type="date" id="c_date" name="cost_date" required
               value="<?= h($editing['cost_date'] ?? date('Y-m-d')) ?>">
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="c_category">Category</label>
        <select id="c_category" name="category">
          <?php foreach (picklist('cost_category') as $code => $label): ?>
            <option value="<?= h($code) ?>" <?= ($editing['category'] ?? 'consumable') === $code ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="c_amount">Amount</label>
        <input type="text" id="c_amount" name="amount" required inputmode="decimal"
               placeholder="1250.00" value="<?= h($editing['amount'] ?? '') ?>">
      </div>
    </div>

    <div class="field">
      <label for="c_description">What the money went on</label>
      <input type="text" id="c_description" name="description" required
             placeholder="Argon supply, twelve cylinders" value="<?= h($editing['description'] ?? '') ?>">
    </div>

    <div class="field-row">
      <div class="field">
        <label for="c_vendor">Vendor</label>
        <input type="text" id="c_vendor" name="vendor" value="<?= h($editing['vendor'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="c_reference">Purchase order or invoice</label>
        <input type="text" id="c_reference" name="reference" value="<?= h($editing['reference'] ?? '') ?>">
      </div>
    </div>

    <div class="field">
      <label for="c_paid">Paid from (CFOPA)</label>
      <input type="text" id="c_paid" name="paid_from_cfopa" placeholder="1-303631-375002-375150-A00"
             value="<?= h($editing['paid_from_cfopa'] ?? '') ?>">
      <p class="hint">Which account the money came out of. Not the account the instrument earns into.</p>
    </div>

    <fieldset>
      <legend>Cover, for a warranty or service contract</legend>
      <p class="hint">Leave both blank for a one-off spend. Filled in, the end date raises a
      warning before the cover runs out.</p>
      <div class="field-row">
        <div class="field">
          <label for="c_from">Cover starts</label>
          <input type="date" id="c_from" name="covers_start" value="<?= h($editing['covers_start'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="c_to">Cover ends</label>
          <input type="date" id="c_to" name="covers_end" value="<?= h($editing['covers_end'] ?? '') ?>">
        </div>
      </div>
    </fieldset>

    <div class="field">
      <label for="c_notes">Notes</label>
      <textarea id="c_notes" name="notes"><?= h($editing['notes'] ?? '') ?></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="button"><?= !empty($editing['cost_id']) ? 'Save changes' : 'Record the cost' ?></button>
      <?php if (!empty($editing['cost_id'])): ?><a class="link-quiet" href="costs.php">Cancel</a><?php endif; ?>
    </div>
  </form>
  <?php accordion_close(); ?>
</div>
<?php
page_footer(['scripts' => ['assets/app.js']]);
