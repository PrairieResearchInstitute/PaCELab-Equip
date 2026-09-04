<?php
/**
 * auth.php — Identity and administrator sign-in.
 *
 * ---------------------------------------------------------------------------
 * THE NETID SWAP POINT
 *
 * Laboratory users identify themselves by last name. Every screen that needs to
 * know who is at the keyboard calls current_user_name() and nothing else, so if
 * the web server can be made to pass a campus NetID through to PHP, replacing
 * the body of that one function converts the whole application:
 *
 *     function current_user_name(): string
 *     {
 *         return $_SERVER['REMOTE_USER'] ?? '';
 *     }
 *
 * At that point identity_is_self_declared() should return false, which removes
 * the "who are you" prompt and the name field from every form. Nothing else in
 * the application changes.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Start the session once, with cookie settings that suit a campus host. */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // A backup or maintenance script run from the command line pulls in these
    // same files and has no session to start; so does any page that has already
    // begun sending output. Both would only produce warnings, so say no here.
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_name('LABEQUIP');
    session_start();
}

// ---------------------------------------------------------------------------
// Laboratory user identity
// ---------------------------------------------------------------------------

/**
 * The person at the keyboard. Replace the body of this function to adopt NetID.
 */
function current_user_name(): string
{
    session_boot();
    return trim((string) ($_SESSION['user_name'] ?? ''));
}

/**
 * True while users type their own name. Returns false once current_user_name()
 * draws on the web server, which is the signal to hide the name prompt and the
 * operator field.
 */
function identity_is_self_declared(): bool
{
    return true;
}

/** Record the self-declared name. Part of the manual path only. */
function set_current_user_name(string $name): void
{
    session_boot();
    $_SESSION['user_name'] = trim(preg_replace('/\s+/', ' ', $name));
}

/** True when we know who is using the application. */
function have_user_name(): bool
{
    return current_user_name() !== '';
}

// ---------------------------------------------------------------------------
// Administrator sign-in
// ---------------------------------------------------------------------------

const ADMIN_IDLE_TIMEOUT = 1800; // thirty minutes, per the security requirements

/** The signed-in administrator row, or null. */
function admin_user(): ?array
{
    session_boot();
    if (empty($_SESSION['admin_user_id'])) {
        return null;
    }

    // Idle timeout. Any gap longer than the limit ends the administrative session.
    $last = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($last > 0 && (time() - $last) > ADMIN_IDLE_TIMEOUT) {
        admin_logout();
        return null;
    }
    $_SESSION['admin_last_seen'] = time();

    $admin = db_one(
        'SELECT user_id, username, display_name, active FROM admin_users WHERE user_id = ?',
        [$_SESSION['admin_user_id']]
    );
    if (!$admin || (int) $admin['active'] !== 1) {
        admin_logout();
        return null;
    }
    return $admin;
}

/** True when an administrator is signed in. */
function is_admin(): bool
{
    return admin_user() !== null;
}

/** Send anyone who is not signed in to the sign-in page. */
function require_admin(): array
{
    $admin = admin_user();
    if ($admin === null) {
        $target = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?next=' . urlencode($target));
        exit;
    }
    return $admin;
}

/** The display name of the signed-in administrator, for stamping records. */
function admin_display_name(): string
{
    $admin = admin_user();
    return $admin ? (string) ($admin['display_name'] ?: $admin['username']) : '';
}

/** The client address used for throttling. */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * How long the caller must wait before another sign-in attempt, in seconds.
 * Zero means the attempt may proceed.
 */
function login_lockout_seconds(string $username): int
{
    $since = date('Y-m-d H:i:s', time() - 900); // fifteen minute window

    $byIp = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = 0 AND attempted_at > ?',
        [client_ip(), $since]
    );
    $byUser = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND succeeded = 0 AND attempted_at > ?',
        [$username, $since]
    );

    if ($byIp < 8 && $byUser < 5) {
        return 0;
    }

    $newest = db_value(
        'SELECT MAX(attempted_at) FROM login_attempts WHERE succeeded = 0 AND attempted_at > ? AND (ip = ? OR username = ?)',
        [$since, client_ip(), $username]
    );
    $remaining = 900 - (time() - strtotime((string) $newest));
    return max(1, $remaining);
}

/** Note an attempt so throttling has something to count. */
function record_login_attempt(string $username, bool $succeeded): void
{
    db_run(
        'INSERT INTO login_attempts (ip, username, succeeded, attempted_at) VALUES (?, ?, ?, ?)',
        [client_ip(), $username, $succeeded ? 1 : 0, date('Y-m-d H:i:s')]
    );
    // Keep the table small; anything older than a day has no bearing on throttling.
    db_run('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}

/**
 * Verify credentials and open an administrative session.
 * Returns an error message, or null on success.
 */
function admin_login(string $username, string $password): ?string
{
    session_boot();

    $wait = login_lockout_seconds($username);
    if ($wait > 0) {
        return 'Too many failed sign-in attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
    }

    $admin = db_one('SELECT * FROM admin_users WHERE username = ? AND active = 1', [$username]);

    // Verify against a dummy hash when the account is unknown, so a missing
    // account and a wrong password take the same amount of time to answer.
    $hash = $admin['password_hash'] ?? '$2y$10$usesomesillystringforsalt00000000000000000000000000000000';
    $ok = password_verify($password, $hash) && $admin !== null;

    record_login_attempt($username, $ok);

    if (!$ok) {
        return 'That username and password do not match an active administrator account.';
    }

    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE admin_users SET password_hash = ? WHERE user_id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $admin['user_id']]);
    }

    session_regenerate_id(true);
    $_SESSION['admin_user_id']   = (int) $admin['user_id'];
    $_SESSION['admin_last_seen'] = time();
    db_run('UPDATE admin_users SET last_login = ? WHERE user_id = ?',
        [date('Y-m-d H:i:s'), $admin['user_id']]);

    return null;
}

/** Close the administrative session, leaving the laboratory identity alone. */
function admin_logout(): void
{
    session_boot();
    unset($_SESSION['admin_user_id'], $_SESSION['admin_last_seen']);
    session_regenerate_id(true);
}
