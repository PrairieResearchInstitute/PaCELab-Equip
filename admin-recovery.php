<?php
/**
 * admin-recovery.php — Getting back in when every administrator is locked out.
 *
 * COMMAND LINE ONLY. It refuses to run over the web, and that refusal is the
 * whole security model: a password reset reachable from a browser is a way in
 * for anybody who finds the URL. Whoever can run commands on the server is
 * already trusted with the database file; nobody else can use this.
 *
 * Because the application sends no mail, there is no "email me a reset link" to
 * fall back on. This is the fallback, and it is deliberately the kind that
 * requires access to the machine.
 *
 *   php admin-recovery.php list
 *   php admin-recovery.php reset <username>
 *   php admin-recovery.php reset <username> "new password"
 *   php admin-recovery.php add <username> "Display Name" "new password"
 *   php admin-recovery.php unlock <username>
 *
 * Safe to leave on the server: over the web it prints a refusal and nothing else.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

// --- The gate --------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit(
        "This tool runs from the command line only.\n\n" .
        "On the server, in the directory holding index.php:\n" .
        "    php admin-recovery.php list\n" .
        "    php admin-recovery.php reset <username>\n"
    );
}

if (!db_installed()) {
    exit("The application is not installed yet. Run install.php first.\n");
}

$argv    = $_SERVER['argv'];
$command = $argv[1] ?? 'help';

/** Ask for a password without echoing it, where the terminal allows it. */
function ask_password(): string
{
    echo 'New password (at least ten characters): ';

    // stty is the portable way to turn off echo; Windows terminals have no
    // equivalent here, so there the password is visible as it is typed.
    $quiet = (DIRECTORY_SEPARATOR === '/') && @shell_exec('stty -g 2>/dev/null');
    if ($quiet) {
        $previous = trim((string) shell_exec('stty -g'));
        shell_exec('stty -echo');
    }

    $password = trim((string) fgets(STDIN));

    if ($quiet) {
        shell_exec('stty ' . $previous);
        echo PHP_EOL;
    }
    return $password;
}

/** Print the administrator table. */
function show_admins(): void
{
    $admins = db_all('SELECT * FROM admin_users ORDER BY active DESC, lower(username)');
    if (!$admins) {
        echo "There are no administrator accounts at all. Create one:\n";
        echo "    php admin-recovery.php add <username> \"Display Name\"\n";
        return;
    }

    printf("%-20s %-26s %-8s %s\n", 'USERNAME', 'NAME', 'ACTIVE', 'LAST SIGNED IN');
    foreach ($admins as $a) {
        printf("%-20s %-26s %-8s %s\n",
            $a['username'],
            substr((string) $a['display_name'], 0, 26),
            ((int) $a['active'] === 1 ? 'yes' : 'no'),
            $a['last_login'] ? pretty_datetime($a['last_login']) : 'never');
    }
}

switch ($command) {

    case 'list':
        show_admins();
        break;

    // -----------------------------------------------------------------------
    case 'reset':
        $username = $argv[2] ?? '';
        if ($username === '') {
            echo "Which account? Usage: php admin-recovery.php reset <username>\n\n";
            show_admins();
            exit(1);
        }

        $admin = db_one('SELECT * FROM admin_users WHERE username = ?', [$username]);
        if (!$admin) {
            echo "No account called '" . $username . "'.\n\n";
            show_admins();
            exit(1);
        }

        $password = $argv[3] ?? ask_password();
        if (strlen($password) < 10) {
            exit("That is shorter than ten characters. Nothing was changed.\n");
        }

        db_run('UPDATE admin_users SET password_hash = ?, active = 1 WHERE user_id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $admin['user_id']]);

        // A forgotten password usually means several failed attempts, and those
        // attempts are what the throttle counts. Clear them or the new password
        // is refused for the next quarter of an hour.
        db_run('DELETE FROM login_attempts WHERE username = ?', [$username]);

        echo "Password reset for " . $username . ", and the account is active.\n";
        echo "Failed sign-in attempts cleared, so you can sign in straight away.\n";
        break;

    // -----------------------------------------------------------------------
    case 'add':
        $username = $argv[2] ?? '';
        $display  = $argv[3] ?? $username;
        if ($username === '') {
            exit("Usage: php admin-recovery.php add <username> \"Display Name\" [password]\n");
        }
        if (db_one('SELECT 1 FROM admin_users WHERE username = ?', [$username])) {
            exit("'" . $username . "' already exists. Use reset instead.\n");
        }

        $password = $argv[4] ?? ask_password();
        if (strlen($password) < 10) {
            exit("That is shorter than ten characters. Nothing was created.\n");
        }

        db_run(
            'INSERT INTO admin_users (username, password_hash, display_name, active, created_at)
             VALUES (?, ?, ?, 1, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $display, date('Y-m-d H:i:s')]
        );
        echo "Created administrator '" . $username . "'.\n";
        break;

    // -----------------------------------------------------------------------
    // Locked out by the throttle rather than by a forgotten password.
    case 'grant':
    case 'revoke':
        $username = $argv[2] ?? '';
        if ($username === '') {
            echo "Which account? Usage: php admin-recovery.php " . $command . " <username>\n\n";
            show_admins();
            exit(1);
        }
        $admin = db_one('SELECT * FROM admin_users WHERE username = ?', [$username]);
        if (!$admin) {
            echo "No account called '" . $username . "'.\n\n";
            show_admins();
            exit(1);
        }
        $on = $command === 'grant' ? 1 : 0;
        db_run('UPDATE admin_users SET may_edit_code = ? WHERE user_id = ?', [$on, $admin['user_id']]);
        echo $username . ($on
            ? " may now edit the application from the Studio screen.\n"
            : " may no longer edit the application.\n");
        echo "This is the one grant the web interface will not let anybody give themselves.\n";
        break;

    case 'unlock':
        $username = $argv[2] ?? '';
        $count = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0'
            . ($username !== '' ? ' AND username = ?' : ''), $username !== '' ? [$username] : []);

        db_run('DELETE FROM login_attempts WHERE succeeded = 0'
            . ($username !== '' ? ' AND username = ?' : ''), $username !== '' ? [$username] : []);

        echo "Cleared " . $count . " failed sign-in attempt(s). Try again now.\n";
        break;

    // -----------------------------------------------------------------------
    default:
        echo "Getting back into the administrative panel.\n\n";
        echo "  php admin-recovery.php list\n";
        echo "      Show every administrator account.\n\n";
        echo "  php admin-recovery.php reset <username> [\"new password\"]\n";
        echo "      Set a new password and reactivate the account. Leave the password\n";
        echo "      off and it is asked for without being echoed.\n\n";
        echo "  php admin-recovery.php add <username> \"Display Name\" [\"password\"]\n";
        echo "      Create a new administrator, for when there are none left.\n\n";
        echo "  php admin-recovery.php grant <username>\n";
        echo "      Let this administrator edit the application from the Studio screen.\n\n";
        echo "  php admin-recovery.php revoke <username>\n";
        echo "      Take that back.\n\n";
        echo "  php admin-recovery.php unlock [username]\n";
        echo "      Clear failed sign-in attempts when the throttle is in the way.\n\n";
        echo "This tool refuses to run over the web.\n";
        break;
}
