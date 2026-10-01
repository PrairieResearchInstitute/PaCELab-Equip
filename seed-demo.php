<?php
/**
 * seed-demo.php — Dummy data for testing.
 *
 * Fills an installed but empty database with plausible instruments, grants,
 * bookings, and a few months of charges, so every screen has something to show.
 * Run it from a browser or from the command line. It is additive and safe to
 * run twice: nothing is duplicated.
 *
 * DELETE THIS FILE BEFORE THE APPLICATION GOES ON THE CAMPUS SERVER.
 * It is refused outright once real charges exist, but the surest protection is
 * for it not to be there.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$cli = (PHP_SAPI === 'cli');
$log = [];
function note(string $line): void { $GLOBALS['log'][] = $line; }

if (!db_installed()) {
    exit($cli ? "Not installed yet. Run install.php first.\n" : 'Not installed yet. Run install.php first.');
}

// A laboratory with real charges is not a test database.
$realCharges = (int) db_value("SELECT COUNT(*) FROM usage_records WHERE exported = 1");
if ($realCharges > 0) {
    exit($cli
        ? "Refusing: this database has exported charges, so it is not a test database.\n"
        : 'Refusing to seed: this database has exported charges in it, so it is not a test database.');
}

$now = date('Y-m-d H:i:s');

// --- Instruments -----------------------------------------------------------
// Tags follow the PRI Facilities form, P10 plus a letter and five digits.
$instruments = [
    // tag, class, name, make, model, location, rate, unit, receiving CFOPA, contact
    ['P10E35946', 'instrument', 'ICP-MS (Agilent 7900)',      'Agilent',   '7900',        'NRB 2054', 18.50, 'per sample', '1-303631-375002-375150-A51', 'Ruiz'],
    ['P10E41102', 'instrument', 'GC-MS bench',                 'Thermo',    'ISQ 7000',    'NRB 2054', 42.00, 'per hour',   '1-303631-375002-375150-A52', 'Whitaker'],
    ['P10H13027', 'shop',       'Freeze dryer',                'Labconco',  'FreeZone 6',  'NRB 1120', 75.00, 'per run',    '1-303631-375002-375150-A53', 'Okafor'],
    ['P10R16770', 'instrument', 'Stable isotope analyser',     'Elementar', 'visION',      'NRB 2061', 26.75, 'per sample', '1-303631-375002-375150-A54', 'Ruiz'],
    ['P10E22418', 'field',      'Water quality sonde',         'YSI',       'EXO2',        'Field',     9.00, 'per hour',   '1-303631-375002-375150-A55', 'Beaumont'],
];

foreach ($instruments as [$tag, $class, $name, $make, $model, $loc, $rate, $unit, $recv, $who]) {
    if (db_one('SELECT 1 FROM equipment WHERE property_tag = ?', [$tag])) { continue; }
    db_run(
        'INSERT INTO equipment (property_tag, equip_class, name, manufacturer, model, location, rate,
                                rate_unit, rate_effective_date, receiving_subaccount, contact_person, active, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)',
        [$tag, $class, $name, $make, $model, $loc, $rate, $unit, date('Y-01-01'), $recv, $who, $now]
    );
    note('instrument: ' . $name);
}

// --- Grants ----------------------------------------------------------------
// One current, one that has lapsed, one that has not started, and a
// self-supporting account, so the entry form's date rule can be exercised.
$unit = function (string $code): ?int {
    $id = db_value('SELECT unit_id FROM units WHERE code = ?', [$code]);
    return $id === null ? null : (int) $id;
};

$grants = [
    ['1-473723-375002-191100-A00', 'Detection, Occupancy, and Population Structure of Western Pond Turtles',
     '375 Navy sub Smithsonian', 'Dreslik, Michael J', 'INHS', '-2 years', '+13 months'],
    ['1-234567-375002-375139-A00', 'Mercury cycling in Illinois floodplain lakes',
     'Hg floodplain', 'Ruiz, Elena', 'INHS', '-18 months', '+8 months'],
    ['1-345678-375014-375150-A01', 'Nitrate transport in tile-drained watersheds',
     'Nitrate tile', 'Beaumont, Claire', 'ISWS', '-4 years', '-40 days'],
    ['1-456789-375002-375139-A00', 'Emerging contaminants survey, phase II',
     'Contaminants II', 'Okafor, Ada', 'INHS', '+30 days', '+3 years'],
    ['1-100026-375002-375139-A00', 'State appropriated operating line',
     'GRF operating', 'Dreslik, Michael J', 'INHS', '-3 years', '+2 years'],
];

foreach ($grants as [$cfopa, $title, $label, $pi, $unitCode, $from, $to]) {
    if (db_one('SELECT 1 FROM grants WHERE cfopa = ?', [$cfopa])) { continue; }
    db_run(
        'INSERT INTO grants (cfopa, cfopa_base, activity_code, title, display_label, principal_investigator,
                             unit_id, start_date, end_date, active, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,1,?)',
        [$cfopa, cfopa_base($cfopa), cfopa_activity($cfopa), $title, $label, $pi,
         $unit($unitCode), date('Y-m-d', strtotime($from)), date('Y-m-d', strtotime($to)), $now]
    );
    note('grant: ' . $label . ' (' . $cfopa . ')');
}

// --- Charges ---------------------------------------------------------------
$people  = ['Ruiz', 'Okafor', 'Beaumont', 'Whitaker', 'Dreslik'];
$batches = ['RUN-A', 'RUN-B', 'SED-2', 'TILE-7', ''];
$notes   = [
    'Sediment cores, Sangamon transect.',
    'Duplicate run after column change.',
    'Method blank plus twelve field samples.',
    '',
    'Rerun of failed batch.',
];

$equip     = db_all('SELECT * FROM equipment WHERE active = 1');
// The cut-off is computed in PHP rather than in SQL: date('now', ...) is
// SQLite only, and CURRENT_DATE - INTERVAL is Postgres only. A bound
// parameter works on both.
$chargeable = db_all(
    "SELECT * FROM grants WHERE active = 1 AND end_date >= ?",
    [date("Y-m-d", strtotime("-60 days"))]
);

if ($equip && $chargeable && (int) db_value('SELECT COUNT(*) FROM usage_records') === 0) {
    // Deterministic, so re-running gives the same laboratory rather than a new one.
    mt_srand(20260904);
    $made = 0;

    for ($daysBack = 100; $daysBack >= 0; $daysBack--) {
        $date = date('Y-m-d', strtotime('-' . $daysBack . ' days'));
        if ((int) date('N', strtotime($date)) >= 6) { continue; }   // weekdays only
        if (mt_rand(0, 100) < 35) { continue; }                     // not every day

        foreach (range(1, mt_rand(1, 3)) as $ignored) {
            $item  = $equip[array_rand($equip)];
            $grant = $chargeable[array_rand($chargeable)];

            // Only charge a grant whose award period covers the run.
            if ($grant['start_date'] > $date || ($grant['end_date'] && $grant['end_date'] < $date)) {
                continue;
            }

            $count = $item['rate_unit'] === 'per run' ? mt_rand(1, 3)
                   : ($item['rate_unit'] === 'per hour' ? mt_rand(1, 8) : mt_rand(4, 48));
            $rate  = (float) $item['rate'];

            db_run(
                'INSERT INTO usage_records (equipment_id, grant_id, operator_name, use_date, sample_count,
                        rate_charged, rate_unit_charged, receiving_subaccount, total_charge,
                        batch_identifier, notes, exported, voided, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,?)',
                [$item['equipment_id'], $grant['grant_id'], $people[array_rand($people)], $date, $count,
                 $rate, $item['rate_unit'], $item['receiving_subaccount'], round($rate * $count, 2),
                 $batches[array_rand($batches)], $notes[array_rand($notes)], $date . ' 09:00:00']
            );
            $made++;
        }
    }
    note($made . ' usage records across the last hundred days');

    // One voided record, so the audit trail has something in it.
    $victim = db_one('SELECT usage_id FROM usage_records ORDER BY usage_id LIMIT 1');
    if ($victim) {
        db_run('UPDATE usage_records SET voided = 1, void_reason = ? WHERE usage_id = ?',
            ['Demo data: instrument fault, samples rerun.', $victim['usage_id']]);
        note('one record voided, to show the audit trail');
    }
}

// --- Bookings --------------------------------------------------------------
if ($equip && (int) db_value('SELECT COUNT(*) FROM reservations') === 0) {
    $sunday = week_start(date('Y-m-d'));
    $plan = [
        // day offset, start slot (half hours from midnight), length, who, purpose
        [1, 18, 4, 'Ruiz',      'Sediment digest'],
        [1, 26, 6, 'Okafor',    'Freeze drying overnight batch'],
        [2, 20, 4, 'Beaumont',  'Tile drain samples'],
        [2, 30, 2, 'Whitaker',  'Column conditioning'],
        [3, 16, 8, 'Ruiz',      'Isotope run'],
        [4, 18, 4, 'Dreslik',   'Method development'],
        [4, 28, 3, 'Beaumont',  'Sonde calibration'],
        [5, 20, 6, 'Okafor',    'Contaminants batch'],
    ];

    $made = 0;
    foreach ($plan as $i => [$day, $slot, $len, $who, $purpose]) {
        $item  = $equip[$i % count($equip)];
        $start = date('Y-m-d H:i:s', strtotime($sunday . ' +' . $day . ' days') + $slot * 1800);
        $end   = date('Y-m-d H:i:s', strtotime($start) + $len * 1800);

        if (reservation_conflict((int) $item['equipment_id'], $start, $end)) { continue; }
        db_run(
            'INSERT INTO reservations (equipment_id, reserved_by, start_datetime, end_datetime, purpose, created_at)
             VALUES (?,?,?,?,?,?)',
            [$item['equipment_id'], $who, $start, $end, $purpose, $now]
        );
        $made++;
    }
    note($made . ' bookings on this week\'s calendar');
}

// --- Report ----------------------------------------------------------------
$summary = [
    'instruments' => (int) db_value('SELECT COUNT(*) FROM equipment'),
    'grants'      => (int) db_value('SELECT COUNT(*) FROM grants'),
    'charges'     => (int) db_value('SELECT COUNT(*) FROM usage_records'),
    'value'       => (float) db_value('SELECT COALESCE(SUM(total_charge),0) FROM usage_records WHERE voided = 0'),
    'bookings'    => (int) db_value('SELECT COUNT(*) FROM reservations'),
];

if ($cli) {
    foreach ($log as $line) { echo '  ', $line, PHP_EOL; }
    printf("\n%d instruments, %d grants, %d charges worth %s, %d bookings.\n",
        $summary['instruments'], $summary['grants'], $summary['charges'],
        money($summary['value']), $summary['bookings']);
    exit;
}

page_header('Demo data', ['nav' => 'home', 'mainClass' => 'page narrow']);
?>
<h1>Demo data</h1>
<?php if ($log): ?>
  <div class="flash flash-success">Added:</div>
  <ul>
    <?php foreach ($log as $line): ?><li><?= h($line) ?></li><?php endforeach; ?>
  </ul>
<?php else: ?>
  <div class="flash flash-notice">Nothing to add — the database already has this data.</div>
<?php endif; ?>

<div class="card">
  <p>The database now holds <strong><?= $summary['instruments'] ?></strong> instruments,
  <strong><?= $summary['grants'] ?></strong> grants,
  <strong><?= $summary['charges'] ?></strong> charges worth <strong><?= h(money($summary['value'])) ?></strong>,
  and <strong><?= $summary['bookings'] ?></strong> bookings.</p>
  <div class="button-row">
    <a class="button" href="home.php">Go to the home screen</a>
    <a class="button button-secondary" href="report.php">See the billing report</a>
    <a class="button button-secondary" href="schedule.php">See the calendar</a>
  </div>
</div>

<p class="hint">Delete <code>seed-demo.php</code> before this goes on the campus server.</p>
<?php
page_footer();
