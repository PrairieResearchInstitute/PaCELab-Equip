<?php
/**
 * api.php — The JSON endpoint behind the calendar.
 *
 * Every conflict is decided here, never in the browser. Each create, move,
 * resize, and repeat runs inside a transaction that tests overlap with
 *
 *     new_start < existing_end AND new_end > existing_start
 *
 * against the other bookings on the same instrument. A collision returns an
 * error naming the holder; the browser puts the block back where it was.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Answer and stop. */
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Answer with a failure the browser can show the user. */
function fail(string $message, int $status = 400, array $extra = []): void
{
    respond(['ok' => false, 'error' => $message] + $extra, $status);
}

if (!db_installed()) {
    fail('The application is not installed yet.', 503);
}

$action = (string) ($_REQUEST['action'] ?? '');
$isWrite = in_array($action, ['create', 'update', 'delete', 'repeat', 'mail_opened'], true);

if ($isWrite) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('Changes must be posted.', 405);
    }
    // The token travels in a header from the calendar's fetch calls.
    if (!csrf_valid()) {
        fail('Your session expired. Reload the page and try again.', 400, ['reload' => true]);
    }
    if (!is_admin() && current_user_name() === '') {
        fail('Tell the application who you are before booking time.', 403, ['reload' => true]);
    }
}

$me = current_user_name();

/** A reservation as the calendar wants it. */
function shape(array $row, string $me, bool $admin): array
{
    return [
        'id'        => (int) $row['reservation_id'],
        'equipment' => (int) $row['equipment_id'],
        'by'        => (string) $row['reserved_by'],
        'start'     => (string) $row['start_datetime'],
        'end'       => (string) $row['end_datetime'],
        'purpose'   => (string) $row['purpose'],
        'usage_id'  => $row['usage_id'] ? (int) $row['usage_id'] : null,

        // Two different questions. "mine" decides the colour, and only the
        // person who holds a booking sees it as theirs; an administrator
        // looking at the week should still see whose time it is. "may_edit"
        // decides whether dragging it does anything.
        'mine'      => ($me !== '' && strcasecmp((string) $row['reserved_by'], $me) === 0),
        'may_edit'  => $admin || ($me !== '' && strcasecmp((string) $row['reserved_by'], $me) === 0),
    ];
}

/** Refuse to touch a booking that belongs to somebody else. */
function assert_may_change(array $row, string $me): void
{
    if (is_admin()) {
        return;
    }
    if ($me === '' || strcasecmp((string) $row['reserved_by'], $me) !== 0) {
        fail('That booking belongs to ' . ($row['reserved_by'] ?: 'somebody else')
            . '. An administrator can move or cancel it.', 403);
    }
}

/** Both ends of a span, snapped and sanity checked. */
function read_span(string $startKey = 'start', string $endKey = 'end'): array
{
    $start = clean_slot_datetime($_REQUEST[$startKey] ?? null);
    $end   = clean_slot_datetime($_REQUEST[$endKey] ?? null);

    if (!$start || !$end) {
        fail('That start or end time could not be read.');
    }
    if ($end <= $start) {
        fail('A booking has to end after it starts.');
    }
    if (strtotime($end) - strtotime($start) > 14 * 86400) {
        fail('A single booking cannot run longer than two weeks.');
    }
    return [$start, $end];
}

switch ($action) {

    // -----------------------------------------------------------------------
    case 'list':
        $equipmentId = (int) ($_GET['equipment_id'] ?? 0);
        $weekStart   = clean_date($_GET['week_start'] ?? null) ?? week_start(date('Y-m-d'));
        $weekEnd     = date('Y-m-d', strtotime($weekStart . ' +7 days'));

        if (!equipment_by_id($equipmentId)) {
            fail('Choose an instrument.');
        }

        $rows = db_all(
            'SELECT * FROM reservations
              WHERE equipment_id = :eq
                AND start_datetime < :weekEnd
                AND end_datetime   > :weekStart
              ORDER BY start_datetime',
            [
                'eq'        => $equipmentId,
                'weekStart' => $weekStart . ' 00:00:00',
                'weekEnd'   => $weekEnd . ' 00:00:00',
            ]
        );

        respond([
            'ok'           => true,
            'week_start'   => $weekStart,
            'me'           => $me,
            'admin'        => is_admin(),
            'reservations' => array_map(fn($r) => shape($r, $me, is_admin()), $rows),
        ]);

    // -----------------------------------------------------------------------
    case 'create':
        $equipmentId = (int) ($_POST['equipment_id'] ?? 0);
        $purpose     = trim((string) ($_POST['purpose'] ?? ''));
        $reservedBy  = $me !== '' ? $me : admin_display_name();
        [$start, $end] = read_span();

        $instrument = equipment_by_id($equipmentId);
        if (!$instrument) {
            fail('That instrument does not exist.');
        }
        // Booking time on something that is out of service only wastes the
        // booker's afternoon.
        if (!equipment_is_usable($instrument)) {
            fail((string) equipment_unusable_reason($instrument), 409);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $clash = reservation_conflict($equipmentId, $start, $end);
            if ($clash) {
                $pdo->rollBack();
                fail(conflict_message($clash), 409);
            }
            db_run(
                'INSERT INTO reservations (equipment_id, reserved_by, start_datetime, end_datetime, purpose, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$equipmentId, $reservedBy, $start, $end, $purpose, date('Y-m-d H:i:s')]
            );
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            fail('The booking was not saved. Try again.', 500);
        }

        respond([
            'ok'          => true,
            'reservation' => shape(db_one('SELECT * FROM reservations WHERE reservation_id = ?', [$id]), $me, is_admin()),
        ]);

    // -----------------------------------------------------------------------
    case 'update':
        $id  = (int) ($_POST['reservation_id'] ?? 0);
        $row = db_one('SELECT * FROM reservations WHERE reservation_id = ?', [$id]);
        if (!$row) {
            fail('That booking has already been cancelled.', 404, ['gone' => true]);
        }
        assert_may_change($row, $me);

        [$start, $end] = read_span();
        $purpose = array_key_exists('purpose', $_POST) ? trim((string) $_POST['purpose']) : $row['purpose'];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $clash = reservation_conflict((int) $row['equipment_id'], $start, $end, $id);
            if ($clash) {
                $pdo->rollBack();
                fail(conflict_message($clash), 409);
            }
            db_run(
                'UPDATE reservations SET start_datetime = ?, end_datetime = ?, purpose = ? WHERE reservation_id = ?',
                [$start, $end, $purpose, $id]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            fail('The change was not saved. Try again.', 500);
        }

        respond([
            'ok'          => true,
            'reservation' => shape(db_one('SELECT * FROM reservations WHERE reservation_id = ?', [$id]), $me, is_admin()),
        ]);

    // -----------------------------------------------------------------------
    case 'delete':
        $id  = (int) ($_POST['reservation_id'] ?? 0);
        $row = db_one('SELECT * FROM reservations WHERE reservation_id = ?', [$id]);
        if (!$row) {
            respond(['ok' => true, 'deleted' => $id]); // already gone is the outcome asked for
        }
        assert_may_change($row, $me);
        db_run('DELETE FROM reservations WHERE reservation_id = ?', [$id]);
        respond(['ok' => true, 'deleted' => $id]);

    // -----------------------------------------------------------------------
    // Repeat: one time span applied across chosen weekdays of a week, so
    // booking an instrument from one to two every weekday is two clicks.
    case 'repeat':
        $equipmentId = (int) ($_POST['equipment_id'] ?? 0);
        $weekStart   = clean_date($_POST['week_start'] ?? null);
        $purpose     = trim((string) ($_POST['purpose'] ?? ''));
        $days        = $_POST['days'] ?? [];
        $reservedBy  = $me !== '' ? $me : admin_display_name();
        [$start, $end] = read_span();

        $instrument = equipment_by_id($equipmentId);
        if (!$instrument)                   { fail('That instrument does not exist.'); }
        if (!equipment_is_usable($instrument)) {
            fail((string) equipment_unusable_reason($instrument), 409);
        }
        if (!$weekStart)                    { fail('The week could not be read.'); }
        if (!is_array($days) || !$days)     { fail('Choose at least one day to repeat on.'); }

        $duration  = strtotime($end) - strtotime($start);
        $timeOfDay = date('H:i:s', strtotime($start));

        $created = [];
        $skipped = [];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($days as $day) {
                $day = (int) $day;
                if ($day < 0 || $day > 6) { continue; }

                $dayStart = date('Y-m-d H:i:s', strtotime($weekStart . ' +' . $day . ' days ' . $timeOfDay));
                $dayEnd   = date('Y-m-d H:i:s', strtotime($dayStart) + $duration);

                $clash = reservation_conflict($equipmentId, $dayStart, $dayEnd);
                if ($clash) {
                    $skipped[] = date('D j M', strtotime($dayStart)) . ' (' . ($clash['reserved_by'] ?: 'held') . ')';
                    continue;
                }
                db_run(
                    'INSERT INTO reservations (equipment_id, reserved_by, start_datetime, end_datetime, purpose, created_at)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$equipmentId, $reservedBy, $dayStart, $dayEnd, $purpose, date('Y-m-d H:i:s')]
                );
                $created[] = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            fail('The repeat was not saved. Try again.', 500);
        }

        $rows = [];
        if ($created) {
            $placeholders = implode(',', array_fill(0, count($created), '?'));
            $rows = db_all('SELECT * FROM reservations WHERE reservation_id IN (' . $placeholders . ')', $created);
        }

        respond([
            'ok'           => true,
            'reservations' => array_map(fn($r) => shape($r, $me, is_admin()), $rows),
            'skipped'      => $skipped,
        ]);

    // -----------------------------------------------------------------------
    // The browser telling us it followed a mailto: link, which is the closest
    // this application can get to knowing a message reached somebody.
    case 'mail_opened':
        if (!is_admin()) {
            fail('Only an administrator composes messages.', 403);
        }
        $emailId = (int) ($_POST['email_id'] ?? 0);
        respond(['ok' => mark_email_opened($emailId)]);

    // -----------------------------------------------------------------------
    default:
        fail('Unknown action.', 404);
}

/** Wording for a collision, naming who holds the time. */
function conflict_message(array $clash): string
{
    return 'That time is already held by ' . ($clash['reserved_by'] ?: 'another user') . ', '
        . date('g:i a', strtotime($clash['start_datetime'])) . ' to '
        . date('g:i a', strtotime($clash['end_datetime'])) . ' on '
        . date('D j M', strtotime($clash['start_datetime'])) . '.';
}
