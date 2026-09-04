<?php
/**
 * schedule.php — The instrument calendar.
 *
 * One instrument at a time, seven day columns, forty-eight half-hour rows
 * covering the full twenty-four hours, and the whole grid inside the viewport
 * with nothing to scroll. Row height comes from the available height through
 * CSS grid, not from a pixel count, and the cells carry no text.
 *
 * The skeleton is rendered here so the week is legible before any script runs.
 * calendar.js fills in the bookings and handles dragging.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_installed();

if (identity_is_self_declared() && !have_user_name()) {
    flash('Enter your last name first, so bookings carry a holder.', 'notice');
    redirect('index.php');
}

$equipment = active_equipment();
$selectedId = (int) ($_GET['equipment_id'] ?? 0);
if (!$selectedId && $equipment) {
    $selectedId = (int) $equipment[0]['equipment_id'];
}

$weekStart = week_start(clean_date($_GET['week'] ?? null) ?? date('Y-m-d'));
$today     = date('Y-m-d');

page_header('Calendar', [
    'nav'       => 'schedule',
    'bodyClass' => 'schedule-page',
    'mainClass' => 'schedule-main',
]);
?>
<div class="cal-toolbar">
  <label class="visually-hidden" for="equipmentPicker">Instrument</label>
  <select id="equipmentPicker">
    <?php foreach ($equipment as $item): ?>
      <option value="<?= (int) $item['equipment_id'] ?>" <?= $selectedId === (int) $item['equipment_id'] ? 'selected' : '' ?>>
        <?= h($item['name']) ?><?= $item['location'] ? ' — ' . h($item['location']) : '' ?>
      </option>
    <?php endforeach; ?>
  </select>

  <div class="button-row">
    <button type="button" class="button button-secondary button-small" data-nav="prev">&larr; Week</button>
    <button type="button" class="button button-secondary button-small" data-nav="today">Today</button>
    <button type="button" class="button button-secondary button-small" data-nav="next">Week &rarr;</button>
  </div>

  <span class="week-label" id="weekLabel"></span>

  <div class="spacer"></div>

  <div class="cal-legend">
    <span><i class="swatch-free"></i>free</span>
    <span><i class="swatch-booked"></i>booked</span>
    <span><i class="swatch-mine"></i>yours</span>
  </div>
</div>

<div id="calMessages"></div>

<?php if (!$equipment): ?>
  <div class="flash flash-notice">No instruments are set up yet. An administrator adds them under <a href="admin/equipment.php">Administration &rarr; Equipment</a>.</div>
<?php else: ?>

<div class="cal-grid" id="calGrid"
     data-equipment="<?= (int) $selectedId ?>"
     data-week="<?= h($weekStart) ?>"
     data-me="<?= h(current_user_name()) ?>"
     data-admin="<?= is_admin() ? '1' : '0' ?>"
     data-csrf="<?= h(csrf_token()) ?>">

  <div class="cal-corner r1 c1"></div>

  <?php for ($day = 0; $day < 7; $day++):
      $date = date('Y-m-d', strtotime($weekStart . ' +' . $day . ' days'));
  ?>
    <div class="cal-dayname r1 c<?= $day + 2 ?><?= $date === $today ? ' is-today' : '' ?>" data-day="<?= $day ?>">
      <?= h(date('D', strtotime($date))) ?>
      <span class="dnum"><?= h(date('j', strtotime($date))) ?></span>
    </div>
  <?php endfor; ?>

  <?php for ($slot = 0; $slot < 48; $slot++):
      $row = $slot + 2;
      $isHour = ($slot % 2) === 0;
  ?>
    <?php if ($isHour): ?>
      <div class="cal-hour r<?= $row ?> c1"><?= h(date('g a', mktime((int) ($slot / 2), 0))) ?></div>
    <?php endif; ?>
    <?php for ($day = 0; $day < 7; $day++): ?>
      <div class="cal-cell r<?= $row ?> c<?= $day + 2 ?><?= $isHour ? ' hour-line' : '' ?><?= ($day === 0 || $day === 6) ? ' weekend' : '' ?><?= $day === 6 ? ' col-6' : '' ?>"
           data-day="<?= $day ?>" data-slot="<?= $slot ?>"></div>
    <?php endfor; ?>
  <?php endfor; ?>
</div>

<div class="cal-hintbar">
  <span><?= h(setting('schedule_instructions')) ?></span>
  <span class="muted">Select a block for repeat, copy, and record-use actions. Ctrl+C and Ctrl+V copy a block to where the pointer sits.</span>
</div>

<?php endif; ?>
<?php
page_footer(['scripts' => ['assets/calendar.js']]);
