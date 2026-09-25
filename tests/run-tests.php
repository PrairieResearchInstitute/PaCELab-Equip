<?php
/**
 * tests/run-tests.php — The rules this application must not break.
 *
 *     php tests/run-tests.php
 *
 * Builds a throwaway database in the temporary directory from the same schema
 * install.php uses, exercises the rules that cost money or leak data when they
 * go wrong, and prints a line per check. It never opens data/lab.sqlite, so it
 * is safe to run against a live installation.
 *
 * These are not tests of every screen. They are the things that were expensive
 * to get right and would fail silently: a charge keeping the rate it was made
 * at, two people not booking the same hour, and one laboratory never seeing
 * another's instruments, accounts, charges or messages.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line: php tests/run-tests.php\n");
}

// A database of its own, thrown away at the end. Defined before anything that
// would otherwise open data/lab.sqlite.
$tempDb = sys_get_temp_dir() . '/lab-tests-' . getmypid() . '.sqlite';
foreach (glob(sys_get_temp_dir() . '/lab-tests-*.sqlite*') ?: [] as $stale) {
    @unlink($stale);                    // leftovers from a previous run
}
define('LAB_DB_PATH', $tempDb);

require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/schema.php';

// ---------------------------------------------------------------------------
// The smallest harness that does the job
// ---------------------------------------------------------------------------

$passed = 0;
$failed = [];
$group  = '';

function heading(string $title): void
{
    $GLOBALS['group'] = $title;
    echo PHP_EOL, '  ', $title, PHP_EOL;
}

function ok(string $what, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['passed']++;
        echo '    pass  ', $what, PHP_EOL;
        return;
    }
    $GLOBALS['failed'][] = $GLOBALS['group'] . ' - ' . $what . ($detail !== '' ? ' (' . $detail . ')' : '');
    echo '    FAIL  ', $what, ($detail !== '' ? '   [' . $detail . ']' : ''), PHP_EOL;
}

function same(string $what, $expected, $actual): void
{
    ok($what, $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** Run something the database should refuse, and say whether it did. */
function refuses(string $what, callable $fn): void
{
    try {
        $fn();
        ok($what, false, 'it was allowed');
    } catch (Throwable $e) {
        ok($what, true);
    }
}

// ---------------------------------------------------------------------------
// Two laboratories with something in them
// ---------------------------------------------------------------------------

$pdo = db();
foreach (schema_statements() as $sql) {
    $pdo->exec($sql);
}
foreach (seed_units() as [$code, $name]) {
    db_run('INSERT INTO units (code, name, active) VALUES (?, ?, 1)', [$code, $name]);
}
foreach (seed_picklists() as [$list, $code, $label, $sort, $protected]) {
    db_run('INSERT OR IGNORE INTO picklists (list_key, code, label, sort_order, active, protected)
            VALUES (?, ?, ?, ?, 1, ?)', [$list, $code, $label, $sort, $protected]);
}
foreach (seed_settings('Test Installation') as $key => $value) {
    db_run('INSERT OR IGNORE INTO settings ("key", value) VALUES (?, ?)', [$key, $value]);
}

function make_lab(string $code, string $name): int
{
    db_run('INSERT INTO labs (code, name, description, lab_group_name, lab_group_email,
              equipment_contact_name, equipment_contact_email, equipment_contact_cc,
              active, created_at)
            VALUES (?, ?, \'\', ?, ?, ?, ?, \'\', 1, ?)',
        [$code, $name, 'the ' . $name . ' group', strtolower($code) . '-group@example.edu',
         'Contact ' . $code, strtolower($code) . '-contact@example.edu', date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

function add_member(int $labId, string $person): void
{
    db_run('INSERT INTO lab_members (lab_id, person_key, display_name, created_at)
            VALUES (?, ?, ?, ?)', [$labId, person_key($person), $person, date('Y-m-d H:i:s')]);
}

function make_equipment(int $labId, string $name, float $rate, string $unit, string $tag): int
{
    db_run('INSERT INTO equipment (lab_id, property_tag, equip_class, name, manufacturer, model,
              location, rate, rate_unit, rate_effective_date, receiving_subaccount,
              contact_person, status, active, created_at)
            VALUES (?, ?, \'instrument\', ?, \'Maker\', \'Model\', \'Room 1\', ?, ?, ?,
                    \'1-303631-375002-375150-A51\', \'Somebody\', \'available\', 1, ?)',
        [$labId, clean_property_tag($tag), $name, $rate, $unit, date('Y-01-01'), date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

function make_grant(int $labId, string $cfopa, string $from, string $to): int
{
    $cfopa = cfopa_normalize($cfopa);
    db_run('INSERT INTO grants (lab_id, cfopa, cfopa_base, activity_code, title, display_label,
              principal_investigator, start_date, end_date, active, created_at)
            VALUES (?, ?, ?, ?, \'Award title\', ?, \'A PI\', ?, ?, 1, ?)',
        [$labId, $cfopa, cfopa_base($cfopa), cfopa_activity($cfopa), substr($cfopa, 2, 6),
         date('Y-m-d', strtotime($from)), date('Y-m-d', strtotime($to)), date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

/** Record a charge the way the entry screen does, snapshotting the rate. */
function charge(int $equipmentId, int $grantId, string $date, int $count, string $who = 'Ruiz'): int
{
    $item = db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$equipmentId]);
    $rate = (float) $item['rate'];
    db_run('INSERT INTO usage_records (equipment_id, grant_id, operator_name, use_date, sample_count,
              rate_charged, rate_unit_charged, receiving_subaccount, total_charge,
              batch_identifier, notes, exported, voided, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'\', \'\', 0, 0, ?)',
        [$equipmentId, $grantId, $who, $date, $count, $rate, $item['rate_unit'],
         $item['receiving_subaccount'], round($rate * $count, 2), date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

/** Book a span the way the calendar does: the server decides, not the caller. */
function book(int $equipmentId, string $start, string $end, string $who): ?int
{
    if (reservation_conflict($equipmentId, $start, $end)) {
        return null;
    }
    db_run('INSERT INTO reservations (equipment_id, reserved_by, start_datetime, end_datetime,
              purpose, created_at)
            VALUES (?, ?, ?, ?, \'\', ?)', [$equipmentId, $who, $start, $end, date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

/** Look at the application as this person, in this laboratory. */
function acting_as(string $person, int $labId = 0, bool $admin = false): void
{
    $_SESSION['user_name'] = $person;
    if ($admin) {
        $_SESSION['admin_user_id']   = 1;
        $_SESSION['admin_last_seen'] = time();
    } else {
        unset($_SESSION['admin_user_id'], $_SESSION['admin_last_seen']);
    }
    unset($_SESSION['lab_id']);
    if ($labId > 0) {
        $_SESSION['lab_id'] = $labId;
    }
}

$labA = make_lab('AAA', 'Alpha Lab');
$labB = make_lab('BBB', 'Beta Lab');
add_member($labA, 'Ruiz');
add_member($labA, 'Okafor');
add_member($labB, 'Nakamura');
add_member($labB, 'Ruiz');                  // one person, both laboratories

db_run('INSERT INTO admin_users (username, password_hash, display_name, active, created_at)
        VALUES (?, ?, ?, 1, ?)',
    ['root', password_hash('a-long-enough-password', PASSWORD_DEFAULT), 'Root', date('Y-m-d H:i:s')]);

$icpA  = make_equipment($labA, 'ICP-MS',    18.50, 'per sample', 'P10E00001');
$gcA   = make_equipment($labA, 'GC-MS',     42.00, 'per hour',   'P10E00002');
$cytoB = make_equipment($labB, 'Cytometer', 28.00, 'per sample', 'P10E00003');

$shared = '1-473723-375002-191100-A00';
$grantA = make_grant($labA, $shared,                      '-2 years', '+1 year');
$grantB = make_grant($labB, $shared,                      '-2 years', '+1 year');  // same award, other lab
$gone   = make_grant($labA, '1-345678-375002-375139-A00', '-4 years', '-40 days'); // lapsed
$future = make_grant($labA, '1-456789-375002-375139-A00', '+30 days', '+3 years'); // not started

echo PHP_EOL, 'Shared Laboratory Equipment - regression tests', PHP_EOL;
echo str_repeat('=', 64), PHP_EOL;

// ---------------------------------------------------------------------------
heading('Money: a charge remembers the rate it was made at');

acting_as('Ruiz', $labA);
$early = charge($icpA, $grantA, date('Y-m-d', strtotime('-30 days')), 10);
same('the charge is made at the rate of the day', 185.0,
    (float) db_value('SELECT total_charge FROM usage_records WHERE usage_id = ?', [$early]));

db_run('UPDATE equipment SET rate = 22.75 WHERE equipment_id = ?', [$icpA]);
same('raising the rate does not rewrite the old charge', 18.5,
    (float) db_value('SELECT rate_charged FROM usage_records WHERE usage_id = ?', [$early]));
same('nor its total', 185.0,
    (float) db_value('SELECT total_charge FROM usage_records WHERE usage_id = ?', [$early]));

$late = charge($icpA, $grantA, date('Y-m-d'), 10);
same('a new charge uses the new rate', 227.5,
    (float) db_value('SELECT total_charge FROM usage_records WHERE usage_id = ?', [$late]));
same('so the billing report carries both rates', 2,
    count(db_all('SELECT DISTINCT rate_charged FROM usage_records WHERE equipment_id = ?', [$icpA])));
same('the unit is snapshotted too', 'per sample',
    (string) db_value('SELECT rate_unit_charged FROM usage_records WHERE usage_id = ?', [$early]));
same('and the receiving account', '1-303631-375002-375150-A51',
    (string) db_value('SELECT receiving_subaccount FROM usage_records WHERE usage_id = ?', [$early]));

// ---------------------------------------------------------------------------
heading('Accounts: CFOPA is five segments and belongs to a laboratory');

same('a four-segment code gains the parent activity',
    '1-100026-375002-375139-A00', cfopa_normalize('1-100026-375002-375139'));
same('a five-segment code is left alone',
    '1-100026-375002-375139-A51', cfopa_normalize('1-100026-375002-375139-A51'));
same('the base is the first four segments',
    '1-100026-375002-375139', cfopa_base('1-100026-375002-375139-A51'));
same('the activity segment reads back', 'A51', cfopa_activity('1-100026-375002-375139-A51'));
ok('a well formed code is recognised', cfopa_is_well_formed('1-303631-375002-375150-A51'));
ok('a short code is not', !cfopa_is_well_formed('303631-375002'));
ok('a code with letters in the fund is not', !cfopa_is_well_formed('1-3X3631-375002-375150-A51'));
same('the fund type comes from the fund segment', 'Federal', cfopa_fund_type('1-503631-375002-375150-A00'));

same('two laboratories may hold the same award', 2,
    (int) db_value('SELECT COUNT(*) FROM grants WHERE cfopa = ?', [$shared]));
refuses('the same award twice in one laboratory is refused',
    function () use ($labA, $shared) { make_grant($labA, $shared, '-1 year', '+1 year'); });

// ---------------------------------------------------------------------------
heading('Bookings: the server decides every conflict');

$day = date('Y-m-d', strtotime('+3 days'));
ok('a free slot is booked',
    book($icpA, $day . ' 10:00:00', $day . ' 12:00:00', 'Ruiz') !== null);
ok('a slot that overlaps it is refused',
    book($icpA, $day . ' 11:00:00', $day . ' 13:00:00', 'Okafor') === null);
ok('a slot wholly inside it is refused',
    book($icpA, $day . ' 10:30:00', $day . ' 11:00:00', 'Okafor') === null);
ok('a slot that swallows it is refused',
    book($icpA, $day . ' 09:00:00', $day . ' 14:00:00', 'Okafor') === null);
ok('a slot that only touches the end is allowed',
    book($icpA, $day . ' 12:00:00', $day . ' 13:00:00', 'Okafor') !== null);
ok('a slot that only touches the start is allowed',
    book($icpA, $day . ' 09:00:00', $day . ' 10:00:00', 'Okafor') !== null);
ok('the same hour on another instrument is free',
    book($gcA, $day . ' 10:00:00', $day . ' 12:00:00', 'Okafor') !== null);
ok('editing a booking does not collide with itself',
    reservation_conflict($icpA, $day . ' 10:00:00', $day . ' 12:00:00',
        (int) db_value('SELECT reservation_id FROM reservations WHERE equipment_id = ?
                         AND start_datetime = ?', [$icpA, $day . ' 10:00:00'])) === null);

// ---------------------------------------------------------------------------
heading('An instrument out of service takes no new work');

db_run('UPDATE equipment SET status = ?, status_note = ?, status_since = ? WHERE equipment_id = ?',
    ['down', 'The turbo pump failed.', date('Y-m-d'), $gcA]);
$downItem = db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$gcA]);
ok('a down instrument cannot be used', !equipment_is_usable($downItem));
ok('and knows it is down', equipment_is_down($downItem));
ok('the refusal repeats the reason given',
    strpos((string) equipment_unusable_reason($downItem), 'The turbo pump failed.') !== false);

db_run('UPDATE equipment SET status = ? WHERE equipment_id = ?', ['service_due', $gcA]);
ok('service due is a warning, not a bar',
    equipment_is_usable(db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$gcA])));

db_run('UPDATE equipment SET status = ?, active = 0 WHERE equipment_id = ?', ['retired', $gcA]);
$retired = db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$gcA]);
ok('a retired instrument cannot be used', !equipment_is_usable($retired));
ok('and says so in its own words',
    strpos((string) equipment_unusable_reason($retired), 'retired') !== false);
acting_as('Ruiz', $labA);
same('it leaves the active list', 1, count(lab_equipment(true)));
same('but stays on the books', 2, count(lab_equipment(false)));
same('and keeps its charge history', 0, (int) db_value(
    'SELECT COUNT(*) FROM usage_records WHERE equipment_id = ?', [$gcA]));
db_run('UPDATE equipment SET status = ?, active = 1, status_note = \'\' WHERE equipment_id = ?',
    ['available', $gcA]);

// ---------------------------------------------------------------------------
heading('Laboratories cannot see into each other');

acting_as('Okafor', $labA);                 // Alpha only, not an administrator
same('the instrument list is this laboratory only', 2, count(lab_equipment(true)));
ok('an instrument id from the other laboratory finds nothing',
    equipment_by_id($cytoB) === null);
ok('one from this laboratory is found', equipment_by_id($icpA) !== null);
same('the account list is this laboratory only', 3, count(lab_grants(false)));
ok('a grant id from the other laboratory finds nothing', grant_by_id($grantB) === null);
ok('a grant from this laboratory is found', grant_by_id($grantA) !== null);

acting_as('Nakamura', $labB);
same('the other laboratory sees only its own instrument', 1, count(lab_equipment(true)));
ok('and cannot reach the first laboratory\'s by id', equipment_by_id($icpA) === null);
same('no charge crosses between them', 0, (int) db_value(
    'SELECT COUNT(*) FROM usage_records r
       JOIN equipment e ON e.equipment_id = r.equipment_id
      WHERE e.lab_id = ?', [$labB]));
ok('somebody may not step into a laboratory they are not in', !may_use_lab($labA));
same('and asking for it does not put them there', $labB, current_lab_id());

acting_as('Ruiz', $labA);
ok('somebody in both may enter either', may_use_lab($labA) && may_use_lab($labB));
acting_as('Stranger');
same('somebody in none has none', 0, count(labs_for_person()));
ok('and there is no laboratory in view', current_lab() === null);
acting_as('Root', $labB, true);
same('an administrator may work in any of them', 2, count(labs_for_person()));

// ---------------------------------------------------------------------------
heading('Messages belong to one laboratory');

acting_as('Root', $labA, true);
$msgA = log_email(compose_equipment_email(
    db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$icpA]), 'down'));
acting_as('Root', $labB, true);
$msgB = log_email(compose_lab_group_email(
    db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$cytoB]), 'down'));

same('a message is stamped with its laboratory', $labA,
    (int) db_value('SELECT lab_id FROM email_log WHERE email_id = ?', [$msgA]));
same('one laboratory sees one message', 1,
    (int) db_value('SELECT COUNT(*) FROM email_log WHERE lab_id = ?', [$labA]));
same('the other sees its own', 1,
    (int) db_value('SELECT COUNT(*) FROM email_log WHERE lab_id = ?', [$labB]));
ok('a message names the laboratory it came from',
    strpos((string) db_value('SELECT body FROM email_log WHERE email_id = ?', [$msgB]), 'Beta Lab') !== false);
same('and goes to that laboratory\'s own group', 'bbb-group@example.edu',
    (string) db_value('SELECT to_address FROM email_log WHERE email_id = ?', [$msgB]));
same('the equipment message goes to that laboratory\'s equipment person',
    'aaa-contact@example.edu',
    (string) db_value('SELECT to_address FROM email_log WHERE email_id = ?', [$msgA]));
same('the log records it as written, not sent', 'composed',
    (string) db_value('SELECT status FROM email_log WHERE email_id = ?', [$msgA]));
mark_email_opened($msgA);
same('and as opened once the mail client was opened', 'opened',
    (string) db_value('SELECT status FROM email_log WHERE email_id = ?', [$msgA]));
ok('with the time it happened',
    (string) db_value('SELECT opened_at FROM email_log WHERE email_id = ?', [$msgA]) !== '');
same('a message with no address is not counted as sendable', 'no_recipient',
    (string) db_value('SELECT status FROM email_log WHERE email_id = ?',
        [log_email(['lab_id' => $labB, 'purpose' => 'test', 'to' => '', 'subject' => 'x', 'body' => 'y'])]));

// ---------------------------------------------------------------------------
heading('Tags and identity');

same('a tag is stored upper case', 'P10E35946', clean_property_tag('  p10e35946 '));
same('an empty tag stays empty', '', clean_property_tag('   '));
same('a person key is folded and trimmed', 'ruiz', person_key('  Ruiz  '));
same('a tag identifies one item across the whole institute', 1, (int) db_value(
    'SELECT COUNT(*) FROM equipment WHERE property_tag = ?', ['P10E00001']));
refuses('the same tag on a second instrument is refused, even in another laboratory',
    function () use ($labB) { make_equipment($labB, 'Clone', 1.0, 'per sample', 'P10E00001'); });
ok('two untagged instruments are allowed',
    make_equipment($labB, 'Untagged one', 1.0, 'per sample', '') > 0
    && make_equipment($labB, 'Untagged two', 1.0, 'per sample', '') > 0);

// ---------------------------------------------------------------------------
heading('Award dates decide what may be charged');

acting_as('Ruiz', $labA);
$offered = array_column(grants_for_date(date('Y-m-d')), 'grant_id');
ok('a current award is offered',           in_array($grantA, $offered, false));
ok('a lapsed award is not',                !in_array($gone, $offered, false));
ok('an award not yet started is not',      !in_array($future, $offered, false));
ok('the lapsed one is offered on a date it did cover',
    in_array($gone, array_column(grants_for_date(date('Y-m-d', strtotime('-1 year'))), 'grant_id'), false));
ok('the other laboratory\'s award is never offered here',
    !in_array($grantB, $offered, false));
db_run('UPDATE grants SET active = 0 WHERE grant_id = ?', [$grantA]);
ok('a closed award drops out even inside its dates',
    !in_array($grantA, array_column(grants_for_date(date('Y-m-d')), 'grant_id'), false));
db_run('UPDATE grants SET active = 1 WHERE grant_id = ?', [$grantA]);

// ---------------------------------------------------------------------------
heading('Voiding keeps the row and stops the money');

$victim = charge($icpA, $grantA, date('Y-m-d'), 4);
$before = (float) db_value('SELECT COALESCE(SUM(total_charge), 0) FROM usage_records WHERE voided = 0');
db_run('UPDATE usage_records SET voided = 1, void_reason = ? WHERE usage_id = ?', ['Rerun.', $victim]);
$after  = (float) db_value('SELECT COALESCE(SUM(total_charge), 0) FROM usage_records WHERE voided = 0');
ok('a voided charge stops counting', $after < $before);
ok('but the row is still there',
    db_one('SELECT * FROM usage_records WHERE usage_id = ?', [$victim]) !== null);
ok('with the reason attached',
    (string) db_value('SELECT void_reason FROM usage_records WHERE usage_id = ?', [$victim]) !== '');

// ---------------------------------------------------------------------------
heading('Export, lock, reopen');

$unexported = static function (int $labId): int {
    return (int) db_value(
        'SELECT COUNT(*) FROM usage_records r
           JOIN equipment e ON e.equipment_id = r.equipment_id
          WHERE e.lab_id = ? AND r.exported = 0 AND r.voided = 0', [$labId]);
};

$pending = $unexported($labA);
ok('there are charges waiting to be billed', $pending > 0);

db_run('INSERT INTO export_batches (lab_id, period_start, period_end, generated_at, generated_by,
          record_count, total_amount, reopened)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
    [$labA, date('Y-m-01'), date('Y-m-t'), date('Y-m-d H:i:s'), 'Root', $pending, 0]);
$batch = (int) db()->lastInsertId();
db_run('UPDATE usage_records SET exported = 1, export_batch_id = ?
         WHERE exported = 0 AND voided = 0
           AND equipment_id IN (SELECT equipment_id FROM equipment WHERE lab_id = ?)',
    [$batch, $labA]);

same('exporting empties the waiting pool', 0, $unexported($labA));
same('and attaches every record to the batch', $pending, (int) db_value(
    'SELECT COUNT(*) FROM usage_records WHERE export_batch_id = ?', [$batch]));
$repeat = charge($icpA, $grantA, date('Y-m-d'), 1);
same('a charge made afterwards is not in that batch', 1, $unexported($labA));
ok('and is not attached to it',
    db_value('SELECT export_batch_id FROM usage_records WHERE usage_id = ?', [$repeat]) === null);

db_run('UPDATE usage_records SET exported = 0, export_batch_id = NULL WHERE export_batch_id = ?', [$batch]);
db_run('UPDATE export_batches SET reopened = 1 WHERE batch_id = ?', [$batch]);
same('reopening returns every record to the pool', $pending + 1, $unexported($labA));
ok('and the batch itself is kept, marked reopened',
    (int) db_value('SELECT reopened FROM export_batches WHERE batch_id = ?', [$batch]) === 1);
same('the other laboratory has no batches', 0,
    (int) db_value('SELECT COUNT(*) FROM export_batches WHERE lab_id = ?', [$labB]));

// ---------------------------------------------------------------------------
heading('Alerts, in the order they matter');

acting_as('Root', $labA, true);
// A week booked solid inside the utilisation window: heavily subscribed.
$from = date('Y-m-d 08:00:00', strtotime('-10 days'));
$to   = date('Y-m-d 08:00:00', strtotime('-3 days'));
book($icpA, $from, $to, 'Ruiz');
$use = equipment_utilisation($icpA);
ok('a solidly booked week reads as heavily used', $use['percent'] >= 75,
    'percent was ' . $use['percent']);
ok('an instrument nobody booked reads as idle', equipment_utilisation($gcA)['percent'] === 0);

db_run('UPDATE equipment SET status = ?, status_note = ?, status_since = ? WHERE equipment_id = ?',
    ['down', 'Pump failed.', date('Y-m-d'), $gcA]);
db_run('UPDATE equipment SET service_due_date = ? WHERE equipment_id = ?',
    [date('Y-m-d', strtotime('-5 days')), $icpA]);

$alerts = equipment_alerts();
ok('something needs attention', count($alerts) > 0);
same('the broken instrument is read first', ALERT_DOWN, (int) $alerts[0]['rank']);
$ranks = array_column($alerts, 'rank');
$sorted = $ranks;
sort($sorted);
same('the list is in order of urgency', $sorted, $ranks);
ok('the overdue service is in there', in_array(ALERT_SERVICE_OVERDUE, $ranks, true));
ok('every alert has somewhere to go',
    count(array_filter($alerts, static function (array $a) { return ($a['href'] ?? '') !== ''; })) === count($alerts));

acting_as('Nakamura', $labB);
$names = array_column(equipment_alerts(), 'name');
ok('the other laboratory is not told about this one\'s instruments',
    !in_array('GC-MS', $names, true) && !in_array('ICP-MS', $names, true));

// ---------------------------------------------------------------------------
heading('Saying who you are returns you where you were going');

same('the chooser is where somebody lands by default', 'home.php', safe_return_to(null));
same('an empty return goes there too', 'home.php', safe_return_to(''));
same('a screen asked for is the screen returned to', 'schedule.php', safe_return_to('schedule.php'));
same('somebody who opened use entry stays on use entry', 'index.php',
    safe_return_to('index.php', 'index.php'));
same('a query string survives', 'schedule.php?equipment_id=4',
    safe_return_to('schedule.php?equipment_id=4'));
// An open redirect is how a plausible link turns into somebody else's sign-in page.
same('another site is refused',      'home.php', safe_return_to('https://evil.example.com/steal'));
same('a protocol-relative one too',  'home.php', safe_return_to('//evil.example.com'));
same('javascript: is refused',       'home.php', safe_return_to('javascript:alert(1)'));
same('so is a walk up the tree',     'home.php', safe_return_to('../admin/settings.php'));
same('and a page that does not exist', 'home.php', safe_return_to('nonsense.php'));

// ---------------------------------------------------------------------------
heading('The browser tab says where you are');

/** The <title> a screen would render. */
function tab_title(string $heading): string
{
    ob_start();
    page_header($heading);
    $html = (string) ob_get_clean();
    return preg_match('/<title>(.*?)<\/title>/s', $html, $m) ? html_entity_decode(trim($m[1])) : '';
}

acting_as('Ruiz', $labA);
same('a screen is named, then the laboratory', 'Use entry · Alpha Lab', tab_title('Use entry'));
same('the laboratory dashboard names the application rather than repeating itself',
    'Alpha Lab · Test Installation', tab_title('Alpha Lab'));
acting_as('Stranger');
same('with no laboratory yet, the application names itself',
    'Choose a laboratory · Test Installation', tab_title('Choose a laboratory'));

// ---------------------------------------------------------------------------
heading('Editing the code from inside the application');

require_once __DIR__ . '/../includes/studio.php';

// The containment check is the whole security of this feature. It has to be
// proved, not asserted: it decides whether a URL can reach outside the
// application folder and rewrite something on the server.
$root = studio_root();

ok('a real source file resolves',            studio_resolve('includes/functions.php') !== null);
ok('so does one in a subfolder',             studio_resolve('admin/users.php') !== null);
ok('walking up with .. is refused',          studio_resolve('../../../windows/win.ini') === null);
ok('so is a disguised walk',                 studio_resolve('includes/../../secrets.php') === null);
ok('an absolute path outside is refused',    studio_resolve('C:/Windows/win.ini') === null);
ok('a unix absolute path is refused',        studio_resolve('/etc/passwd') === null);
ok('a null byte is refused',                 studio_resolve("includes/db.php\0.txt") === null);
ok('the database is not editable',           studio_resolve('data/lab.sqlite') === null);
ok('nor anything else under data',           studio_resolve('data/.htaccess') === null);
ok('nor the bundled PHP runtime',            studio_resolve('tools/php/php.exe') === null);
ok('an empty path is refused',               studio_resolve('') === null);
ok('a directory is refused',                 studio_resolve('includes') === null);
ok('a file that does not exist is refused',  studio_resolve('includes/nope.php') === null);

// Everything the tree offers must itself resolve, or the rail would list
// files the editor then refuses to open.
$tree = studio_tree();
ok('the tree lists the application source', count($tree) > 20);
$unresolvable = [];
foreach ($tree as $rel) {
    if (studio_resolve($rel) === null) {
        $unresolvable[] = $rel;
    }
}
ok('everything it lists can be opened', $unresolvable === [], implode(', ', array_slice($unresolvable, 0, 5)));
$leaked = array_filter($tree, function ($p) {
    return strpos($p, 'data/') === 0 || strpos($p, 'tools/php/') === 0 || strpos($p, 'dist/') === 0;
});
ok('it lists nothing under data, dist or the runtime', $leaked === []);

// A file that will not parse must cost a message, not the application.
$good = studio_check('x.php', "<?php\nfunction a() { return 1; }\n");
ok('valid PHP passes the check', $good['ok'] === true);
$bad = studio_check('x.php', "<?php\nfunction a( { return 1;\n");
ok('a syntax error is caught', $bad['ok'] === false);
ok('and the message says where', strpos($bad['message'], 'line') !== false);
ok('a missing brace is caught', studio_check('x.php', "<?php if (true) {\n")['ok'] === false);
same('CSS is not parsed as PHP', true, studio_check('x.css', 'body { color: red }')['ok']);

// Saving: refuse broken code, keep the old copy, and put it back on request.
$scratch = 'studio-selftest.txt';
$full    = $root . '/' . $scratch;
file_put_contents($full, "one\n");
[$okSave, $msg] = studio_save($scratch, "two\n");
ok('a good save is written', $okSave && file_get_contents($full) === "two\n");
ok('and the previous copy is kept', count(studio_backups($scratch)) >= 1);

$php = 'studio-selftest.php';
$phpFull = $root . '/' . $php;
file_put_contents($phpFull, "<?php\nreturn 1;\n");
[$okBad, $msgBad] = studio_save($php, "<?php\nfunction ( {\n");
ok('broken PHP is refused', $okBad === false);
ok('and the file on disk is untouched',
   file_get_contents($phpFull) === "<?php\nreturn 1;\n");

$copies = studio_backups($scratch);
if ($copies) {
    studio_restore($scratch, $copies[0]['name']);
    ok('restoring puts the old content back', file_get_contents($full) === "one\n");
} else {
    ok('restoring puts the old content back', false, 'no backup to restore');
}

ok('a save outside the application is refused',
   studio_save('../escaped.php', '<?php')[0] === false);

@unlink($full);
@unlink($phpFull);
foreach (array_merge(studio_backups($scratch), studio_backups($php)) as $b) {
    @unlink(studio_backup_dir() . '/' . $b['name']);
}

// The grant itself. An administrator is not automatically allowed in.
db_run('INSERT INTO admin_users (username, password_hash, display_name, active, created_at, may_edit_code)
        VALUES (?, ?, ?, 1, ?, 0)',
    ['plain', password_hash('a-long-enough-password', PASSWORD_DEFAULT), 'Plain Admin', date('Y-m-d H:i:s')]);
$plainId = (int) db()->lastInsertId();
same('a new administrator may not edit code', 0,
    (int) db_value('SELECT may_edit_code FROM admin_users WHERE user_id = ?', [$plainId]));
db_run('UPDATE admin_users SET may_edit_code = 1 WHERE user_id = ?', [$plainId]);
same('the grant can be given', 1,
    (int) db_value('SELECT may_edit_code FROM admin_users WHERE user_id = ?', [$plainId]));

// ---------------------------------------------------------------------------
heading('The code runs on the server it is going to');

$sources = array_merge(
    glob(__DIR__ . '/../*.php') ?: [],
    glob(__DIR__ . '/../includes/*.php') ?: [],
    glob(__DIR__ . '/../admin/*.php') ?: []
);
// The campus server runs a current PHP, so the danger is no longer using
// something too new. It is using something a newer PHP has taken away: every
// one of these still runs today while printing a deprecation notice into the
// page, and stops running in a release after that.
$removed = [
    'utf8_encode'     => 'deprecated in 8.2',
    'utf8_decode'     => 'deprecated in 8.2',
    'create_function' => 'removed in 8.0',
    'each'            => 'removed in 8.0',
    'money_format'    => 'removed in 8.0',
    'strftime'        => 'deprecated in 8.1',
    'gmstrftime'      => 'deprecated in 8.1',
    'date_sunrise'    => 'deprecated in 8.1',
    'mhash'           => 'removed in 8.0',
];
$offenders = [];
foreach ($sources as $file) {
    $text = (string) file_get_contents($file);
    foreach ($removed as $fn => $when) {
        if (preg_match('/(?<![\w$>\'"])' . $fn . '\s*\(/', $text)) {
            $offenders[] = basename($file) . ': ' . $fn . '() ' . $when;
        }
    }
    // Deprecated in 8.2, and the kind of thing that slips into a heredoc.
    if (preg_match('/\$\{[a-zA-Z_]\w*\}/', $text)) {
        $offenders[] = basename($file) . ': ${var} interpolation, deprecated in 8.2';
    }
    // Deprecated in 8.4: a default of null without the parameter being nullable.
    if (preg_match('/function\s+\w+\s*\([^)]*?(?<!\?)\b(string|int|float|bool|array|iterable|object)\s+\$\w+\s*=\s*null/s', $text)) {
        $offenders[] = basename($file) . ': implicitly nullable parameter, deprecated in 8.4';
    }
}
ok('nothing calls what a newer PHP has taken away', $offenders === [],
    implode('; ', $offenders));

// Everything reaching htmlspecialchars() has been cast first. Passing null to
// an internal parameter has been deprecated since 8.1, and h() is the one
// funnel every screen puts its values through.
$hBody = (string) file_get_contents(__DIR__ . '/../includes/functions.php');
ok('the escaper casts before it escapes',
    preg_match('/function h\(\$value\): string\s*\{\s*return htmlspecialchars\(\(string\) \$value/', $hBody) === 1);
same('so it survives a null', '', h(null));
same('and a number', '42', h(42));
same('and escapes what matters', '&lt;script&gt;&amp;&quot;', h('<script>&"'));

// A value reaching SQL any way other than as a bound parameter. Two shapes are
// allowed and nothing else: a variable holding SQL being assembled ($sql), and
// a run of question marks for an IN list ($placeholders), which is checked
// below to be exactly that and never to carry a value.
$allowed     = ['sql', 'placeholders'];
$notPrepared = [];
foreach ($sources as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
        // Interpolation: a double-quoted SQL string with a variable inside it.
        if (preg_match('/"[^"]*\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b[^"]*\$\w/i', $line)) {
            $notPrepared[] = basename($file) . ':' . ($n + 1) . ' interpolated';
            continue;
        }
        // Concatenation: a variable glued onto a line that carries SQL.
        if (preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b/i', $line)
            && preg_match('/\'\s*\.\s*\$(\w+)/', $line, $m)
            && !in_array($m[1], $allowed, true)) {
            $notPrepared[] = basename($file) . ':' . ($n + 1) . ' concatenated $' . $m[1];
        }
    }
}
ok('no screen glues a value into an SQL string', $notPrepared === [],
    implode(', ', $notPrepared));

// Every named placeholder must be bound in the file that writes it.
//
// This is the check that would have caught the Reservations screen. SQLite
// binds a missing named parameter as NULL rather than raising, so
// "WHERE lab_id = :lab" with no :lab silently matches nothing: no error, no
// warning, just an empty table for as long as nobody notices. The dashboard's
// Today panel had the same fault in two queries.
$unbound = [];
foreach ($sources as $file) {
    $text = (string) file_get_contents($file);
    preg_match_all('/[ (,]:([a-z][a-zA-Z_]*)/', $text, $m);
    foreach (array_unique($m[1]) as $name) {
        $q = preg_quote($name, '/');
        $bound = preg_match('/[\'"]' . $q . '[\'"]\s*=>/', $text)
              || preg_match('/\[[\'"]' . $q . '[\'"]\]\s*=/', $text);
        if (!$bound) {
            $unbound[] = basename($file) . ': :' . $name;
        }
    }
}
ok('every named placeholder is bound where it is used', $unbound === [],
    implode(', ', $unbound));

$badPlaceholders = [];

foreach ($sources as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
        if (strpos($line, '$placeholders =') === false) {
            continue;
        }
        // The only honest way to build one: a count of question marks.
        if (!preg_match('/implode\(\s*\',\'\s*,\s*array_fill\(\s*0\s*,[^,]+,\s*\'\?\'\s*\)\s*\)/', $line)) {
            $badPlaceholders[] = basename($file) . ':' . ($n + 1);
        }
    }
}
ok('every IN list is question marks, never values', $badPlaceholders === [],
    implode(', ', $badPlaceholders));

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------

echo PHP_EOL, str_repeat('=', 64), PHP_EOL;

if ($failed) {
    echo count($failed), ' FAILED, ', $passed, ' passed', PHP_EOL, PHP_EOL;
    foreach ($failed as $line) {
        echo '  - ', $line, PHP_EOL;
    }
    echo PHP_EOL;
} else {
    echo 'All ', $passed, ' checks passed.', PHP_EOL, PHP_EOL;
}

// Windows keeps the file locked while the handle is open, so this may not
// succeed; the next run clears whatever is left behind.
@unlink($tempDb);
@unlink($tempDb . '-wal');
@unlink($tempDb . '-shm');

exit($failed ? 1 : 0);
