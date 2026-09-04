<?php
/**
 * admin/settings.php — Interface text.
 *
 * Laboratory name, page titles, and the instructions on each screen. Wording
 * changes land here rather than in a file, which is the whole reason the
 * settings table exists.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
require_admin();

/** The editable text, in the order it makes sense to read. */
function editable_settings(): array
{
    return [
        'lab_name' => [
            'label' => 'Laboratory name',
            'hint'  => 'Shown in the header and the browser tab of every screen.',
            'type'  => 'text',
        ],
        'unit_name' => [
            'label' => 'Survey name',
            'hint'  => 'Named in the banner above the application name, and again in the footer.',
            'type'  => 'text',
        ],
        'unit_url' => [
            'label' => 'Survey web address',
            'hint'  => 'Where the survey name in the banner and footer links to.',
            'type'  => 'text',
        ],
        'footer_note' => [
            'label' => 'Footer line',
            'hint'  => 'The small line at the bottom of each page.',
            'type'  => 'text',
        ],
        'entry_title' => [
            'label' => 'Use entry: heading',
            'hint'  => '',
            'type'  => 'text',
        ],
        'entry_instructions' => [
            'label' => 'Use entry: instructions',
            'hint'  => 'The sentence under the heading on the entry screen.',
            'type'  => 'textarea',
        ],
        'schedule_title' => [
            'label' => 'Calendar: heading',
            'hint'  => '',
            'type'  => 'text',
        ],
        'schedule_instructions' => [
            'label' => 'Calendar: instructions',
            'hint'  => 'Shown beneath the calendar toolbar.',
            'type'  => 'textarea',
        ],
        'report_title' => [
            'label' => 'Billing report: heading',
            'hint'  => '',
            'type'  => 'text',
        ],
        'report_instructions' => [
            'label' => 'Billing report: instructions',
            'hint'  => '',
            'type'  => 'textarea',
        ],
        'admin_title' => [
            'label' => 'Administration: heading',
            'hint'  => '',
            'type'  => 'text',
        ],

        // Everybody who uses the instruments. Told when one goes out of
        // service, because their afternoon has just changed.
        'lab_group_name' => [
            'label' => 'Laboratory group: name',
            'hint'  => 'How the group is referred to on screen, for example "the PaCE Lab group".',
            'type'  => 'text',
            'group' => 'Who gets told',
        ],
        'lab_group_email' => [
            'label' => 'Laboratory group: email',
            'hint'  => 'A distribution list is best. Several addresses separated by commas also work. '
                     . 'Told when an instrument goes out of service. Leave blank to turn that off.',
            'type'  => 'email_list',
            'group' => 'Who gets told',
        ],

        // Who hears about a retirement. The application composes the message
        // and opens the mail client; it never sends anything itself.
        'equipment_contact_name' => [
            'label' => 'Equipment person: name',
            'hint'  => 'Told when an instrument is retired.',
            'type'  => 'text',
            'group' => 'Who gets told',
        ],
        'equipment_contact_email' => [
            'label' => 'Equipment person: email',
            'hint'  => 'Retiring an instrument opens a message to this address in Outlook. Leave blank to turn that off.',
            'type'  => 'email',
            'group' => 'Who gets told',
        ],
        'equipment_contact_cc' => [
            'label' => 'Copy to',
            'hint'  => 'Optional. Separate several addresses with commas.',
            'type'  => 'email_list',
            'group' => 'Who gets told',
        ],
    ];
}

/** Reject anything that is plainly not an address before it reaches a mailto. */
function bad_addresses(string $value): array
{
    $bad = [];
    foreach (array_filter(array_map('trim', explode(',', $value))) as $address) {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $bad[] = $address;
        }
    }
    return $bad;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    foreach (editable_settings() as $key => $meta) {
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $value = trim((string) $_POST[$key]);

        if ($value !== '' && ($meta['type'] === 'email' || $meta['type'] === 'email_list')) {
            $bad = bad_addresses($value);
            if ($bad) {
                $errors[] = $meta['label'] . ': ' . implode(', ', $bad)
                    . (count($bad) === 1 ? ' is not an email address.' : ' are not email addresses.');
                continue;
            }
        }
        set_setting($key, $value);
    }

    if (!$errors) {
        flash('Settings saved.');
        redirect('settings.php');
    }
}

admin_header('settings', 'Interface text');
?>
<h1>Interface text</h1>
<p class="lede">Everything the laboratory rewords regularly. None of this requires a file edit or the web person.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<form method="post" class="card form">
  <?= csrf_field() ?>
  <?php $group = null; ?>
  <?php foreach (editable_settings() as $key => $meta): ?>
    <?php if (($meta['group'] ?? null) !== $group): ?>
      <?php $group = $meta['group'] ?? null; ?>
      <?php if ($group !== null): ?><h2><?= h($group) ?></h2><?php endif; ?>
    <?php endif; ?>
    <div class="field">
      <label for="s_<?= h($key) ?>"><?= h($meta['label']) ?></label>
      <?php if ($meta['type'] === 'textarea'): ?>
        <textarea id="s_<?= h($key) ?>" name="<?= h($key) ?>"><?= h(setting($key)) ?></textarea>
      <?php else: ?>
        <input type="<?= $meta['type'] === 'email' ? 'email' : 'text' ?>"
               id="s_<?= h($key) ?>" name="<?= h($key) ?>"
               value="<?= h((string) ($_POST[$key] ?? setting($key))) ?>">
      <?php endif; ?>
      <?php if ($meta['hint']): ?><p class="hint"><?= h($meta['hint']) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="form-actions">
    <button type="submit" class="button">Save text</button>
  </div>
</form>

<h2>What is deliberately not here</h2>
<div class="card">
  <p>This panel does not edit PHP, JavaScript, HTML, or the stylesheet. A browser form that writes source files into a campus web directory is a remote code execution path: one leaked password or one cross-site scripting flaw would let an attacker run arbitrary code on a university server, and campus security offices treat such an interface as a finding.</p>
  <p>Everything the laboratory changes regularly lives in the database instead, and this panel covers all of it: rates, equipment, grants, unit lists, labels, and page text. Code changes go through the web person editing files in the directory. Appearance changes touch <code>assets/style.css</code> alone, so a full visual redesign never touches application logic.</p>
</div>
<?php
page_footer();
