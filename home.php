<?php
/**
 * home.php — The landing screen: choose a laboratory.
 *
 * This page does one thing. Which laboratory you are in decides what every
 * other screen shows, so it is the first question the application asks, and
 * asking it is the whole of this page. Choosing one takes you into it.
 *
 * The laboratory bar is deliberately absent here — a picker above a page whose
 * entire purpose is picking would be the same question asked twice.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_installed();

// Not require_lab(): somebody with no laboratory at all still belongs on this
// screen, because this is where they are told so.
if (identity_is_self_declared() && !have_user_name()) {
    redirect('index.php?next=home.php');
}

$mine   = labs_for_person();
$mineIds = array_map('intval', array_column($mine, 'lab_id'));

// Laboratories this person cannot enter. Shown, but shut: knowing the
// laboratory exists is what turns "nothing here" into "ask to be added".
$others = array_values(array_filter(labs(), function ($lab) use ($mineIds) {
    return !in_array((int) $lab['lab_id'], $mineIds, true);
}));

/** Instruments and people, for the card. */
function lab_counts(int $labId): string
{
    $instruments = (int) db_value('SELECT COUNT(*) FROM equipment WHERE lab_id = ? AND active = 1', [$labId]);
    $people      = (int) db_value('SELECT COUNT(*) FROM lab_members WHERE lab_id = ?', [$labId]);
    return $instruments . ' instrument' . ($instruments === 1 ? '' : 's')
         . ' · ' . $people . ' ' . ($people === 1 ? 'person' : 'people');
}

page_header('Choose a laboratory', ['nav' => 'home']);
?>
<h1>Choose a laboratory</h1>

<?php if ($mine): ?>
  <p class="lede">Each laboratory keeps its own instruments, grants, bookings and charges.
  <?= count($mine) === 1 ? 'You are in one of them.' : 'You are in ' . count($mine) . ' of them.' ?></p>

  <div class="grid-cards lab-cards">
  <?php foreach ($mine as $lab): ?>
    <form method="post" action="index.php" class="lab-card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="switch_lab">
      <input type="hidden" name="lab_id" value="<?= (int) $lab['lab_id'] ?>">
      <input type="hidden" name="return_to" value="lab.php">

      <h3><?= h($lab['name']) ?></h3>
      <?php if ($lab['description']): ?><p class="hint"><?= h($lab['description']) ?></p><?php endif; ?>
      <p class="lab-card-counts"><?= h(lab_counts((int) $lab['lab_id'])) ?></p>

      <button type="submit" class="button">Enter this laboratory</button>
    </form>
  <?php endforeach; ?>

  <?php if (is_admin()): ?>
    <a class="lab-card lab-card-new" href="admin/labs.php">
      <h3>Add a laboratory</h3>
      <p class="hint">Set up another group with its own instruments, grants and people.</p>
      <span class="button button-secondary button-small">Set one up</span>
    </a>
  <?php endif; ?>
  </div>

<?php else: ?>
  <div class="card notify-card">
    <h2 class="card-heading">You are not in a laboratory yet</h2>
    <p><strong><?= h(current_user_name() ?: 'You') ?></strong> has not been added to any laboratory,
    so there is nothing to show: no instruments, no bookings, and nothing to charge.</p>
    <p>An administrator adds people to a laboratory. Ask whoever looks after yours, and tell them
    the name you type when you open this application — <code><?= h(person_key(current_user_name())) ?></code>.</p>
    <div class="button-row">
      <a class="button button-secondary" href="index.php?switch_user=1">Use a different name</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($others): ?>
  <?php accordion_open('home-other-labs', 'Other laboratories', [
      'meta' => count($others) . ' you are not in',
  ]); ?>
    <p class="hint">These exist in this installation but are not open to you. Everything inside them —
    instruments, bookings, charges — stays theirs.</p>
    <div class="grid-cards lab-cards">
    <?php foreach ($others as $lab): ?>
      <div class="lab-card lab-card-shut">
        <h3><?= h($lab['name']) ?> <span class="pill">no access</span></h3>
        <?php if ($lab['description']): ?><p class="hint"><?= h($lab['description']) ?></p><?php endif; ?>
        <p class="lab-card-counts"><?= h(lab_counts((int) $lab['lab_id'])) ?></p>
        <p class="hint">Ask an administrator to add
        <code><?= h(person_key(current_user_name()) ?: 'your name') ?></code> to it.</p>
      </div>
    <?php endforeach; ?>
    </div>
  <?php accordion_close(); ?>
<?php endif; ?>
<?php
page_footer(['scripts' => ['assets/app.js']]);
