<?php
/**
 * admin/emails.php — Every message the application has composed.
 *
 * The application hands messages to a mail client rather than sending them, so
 * the strongest thing it can honestly record is that a message was written and
 * that the mail client was opened with it. The log says exactly that. It does
 * not claim anything was sent, because it cannot know.
 *
 * The words are kept in full, so months later "was the equipment person told?"
 * has an answer, and so does "what did we actually say?".
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
require_admin();

// Reopening a logged message. Nothing is recomposed: the stored words are used
// exactly as they were written.
$reopen = null;
if (isset($_GET['open'])) {
    $row = db_one('SELECT * FROM email_log WHERE email_id = ? AND lab_id = ?', [(int) $_GET['open'], current_lab_id()]);
    if ($row && $row['to_address'] !== '') {
        $reopen = [
            'id'   => (int) $row['email_id'],
            'href' => mailto_link($row['to_address'], $row['subject'],
                          explode("\r\n", $row['body']), $row['cc_address']),
        ];
    } elseif ($row) {
        flash('That message has no recipient. Set the equipment person under Interface text first.', 'error');
        redirect('emails.php');
    }
}

$filterPurpose = trim((string) ($_GET['purpose'] ?? ''));
$filterEquip   = (int) ($_GET['equipment_id'] ?? 0);

$sql = 'SELECT l.*, e.name AS equipment_name
          FROM email_log l
          LEFT JOIN equipment e ON e.equipment_id = l.equipment_id
         WHERE l.lab_id = ?';
$params = [current_lab_id()];
if ($filterPurpose !== '') { $sql .= ' AND l.purpose = ?';      $params[] = $filterPurpose; }
if ($filterEquip)          { $sql .= ' AND l.equipment_id = ?'; $params[] = $filterEquip; }
$sql .= ' ORDER BY l.created_at DESC, l.email_id DESC LIMIT 200';

$messages  = db_all($sql, $params);
$purposes  = db_all('SELECT purpose, COUNT(*) AS n FROM email_log WHERE lab_id = ? GROUP BY purpose ORDER BY purpose', [current_lab_id()]);
$equipment = lab_equipment(false);

$counts = [
    'total'   => (int) db_value('SELECT COUNT(*) FROM email_log WHERE lab_id = ?', [current_lab_id()]),
    'opened'  => (int) db_value("SELECT COUNT(*) FROM email_log WHERE lab_id = ? AND status = 'opened'", [current_lab_id()]),
    'unsent'  => (int) db_value("SELECT COUNT(*) FROM email_log WHERE lab_id = ? AND status = 'composed'", [current_lab_id()]),
    'orphans' => (int) db_value("SELECT COUNT(*) FROM email_log WHERE lab_id = ? AND status = 'no_recipient'", [current_lab_id()]),
];

/** A purpose code as a sentence. */
function purpose_label(string $purpose): string
{
    $map = [
        'equipment_retired' => 'Instrument retired',
        'equipment_down'    => 'Instrument out of service',
    ];
    return $map[$purpose] ?? ucfirst(str_replace('_', ' ', $purpose));
}

admin_header('emails', 'Message log');
?>
<h1>Message log</h1>
<p class="lede">Every message this application has composed, with the words it used.
Messages are handed to Outlook rather than sent, so the log records that one was
written and whether the mail client was opened with it — never that it was sent.</p>

<?php if ($reopen): ?>
  <div class="card notify-card" data-mailto="<?= h($reopen['href']) ?>"
       data-mailto-id="<?= (int) $reopen['id'] ?>" data-csrf="<?= h(csrf_token()) ?>">
    <p>Reopening message #<?= (int) $reopen['id'] ?> in your mail client.</p>
    <div class="button-row">
      <a class="button" href="<?= h($reopen['href']) ?>">Open it again</a>
      <a class="button button-secondary" href="emails.php">Back to the log</a>
    </div>
  </div>
<?php endif; ?>

<div class="totals-strip">
  <div class="total-tile">
    <div class="label">Messages written</div>
    <div class="value"><?= $counts['total'] ?></div>
  </div>
  <div class="total-tile">
    <div class="label">Opened in a mail client</div>
    <div class="value"><?= $counts['opened'] ?></div>
  </div>
  <div class="total-tile">
    <div class="label">Written, never opened</div>
    <div class="value"><?= $counts['unsent'] ?></div>
    <p class="hint">Worth checking these went</p>
  </div>
  <div class="total-tile">
    <div class="label">No recipient</div>
    <div class="value"><?= $counts['orphans'] ?></div>
    <?php if ($counts['orphans'] > 0): ?>
      <p class="hint"><a href="settings.php">Set the equipment person</a></p>
    <?php endif; ?>
  </div>
</div>

<?php accordion_open('emails-filter', 'Narrow the list', ['open' => $filterPurpose !== '' || $filterEquip > 0]); ?>
  <form method="get" class="report-filters">
    <div class="field">
      <label for="purpose">Kind of message</label>
      <select id="purpose" name="purpose">
        <option value="">All</option>
        <?php foreach ($purposes as $p): ?>
          <option value="<?= h($p['purpose']) ?>" <?= $filterPurpose === $p['purpose'] ? 'selected' : '' ?>>
            <?= h(purpose_label($p['purpose'])) ?> (<?= (int) $p['n'] ?>)
          </option>
        <?php endforeach; ?>
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
<?php accordion_close(); ?>

<h2>Messages</h2>

<?php if (!$messages): ?>
  <div class="card"><p class="empty">No messages have been composed yet.</p></div>
<?php endif; ?>

<?php foreach ($messages as $message): ?>
  <?php
    $meta = pretty_datetime($message['created_at'])
          . ' · ' . email_status_label($message['status']);
  ?>
  <?php accordion_open('email-' . $message['email_id'], $message['subject'] ?: '(no subject)', [
      'meta' => $meta,
      'tone' => $message['status'] === 'no_recipient' ? 'warning'
              : ($message['status'] === 'composed' ? 'notice' : ''),
  ]); ?>
    <dl class="email-head">
      <div><dt>To</dt><dd><?= $message['to_address'] ? h($message['to_address']) : '<span class="muted">nobody — no address was set</span>' ?></dd></div>
      <?php if ($message['cc_address']): ?>
        <div><dt>Copy to</dt><dd><?= h($message['cc_address']) ?></dd></div>
      <?php endif; ?>
      <div><dt>About</dt><dd><?= h(purpose_label($message['purpose'])) ?><?= $message['equipment_name'] ? ' — ' . h($message['equipment_name']) : '' ?></dd></div>
      <div><dt>Written by</dt><dd><?= h($message['created_by'] ?: 'unknown') ?>, <?= h(pretty_datetime($message['created_at'])) ?></dd></div>
      <div><dt>Status</dt><dd>
        <?= h(email_status_label($message['status'])) ?>
        <?= $message['opened_at'] ? ' on ' . h(pretty_datetime($message['opened_at'])) : '' ?>
      </dd></div>
    </dl>

    <pre class="email-body"><?= h($message['body']) ?></pre>

    <div class="button-row">
      <?php if ($message['to_address']): ?>
        <a class="button button-secondary button-small" href="?open=<?= (int) $message['email_id'] ?>">Open in the mail client</a>
      <?php endif; ?>
      <?php if ($message['equipment_id']): ?>
        <a class="button button-secondary button-small" href="equipment.php?edit=<?= (int) $message['equipment_id'] ?>">The instrument</a>
      <?php endif; ?>
    </div>
  <?php accordion_close(); ?>
<?php endforeach; ?>

<p class="hint">The most recent 200 messages are listed. Nothing here is ever deleted by the application.</p>
<?php
page_footer(['scripts' => ['assets/app.js']]);
