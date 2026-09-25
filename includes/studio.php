<?php
/**
 * studio.php — the application's own source, editable from inside it.
 *
 * This exists so that somebody other than the person who wrote it can fix a
 * problem on the campus server without a code editor, a checkout, or a way to
 * copy files up. It is deliberately powerful — it writes PHP that the next
 * request will run — so every part of it assumes whoever is using it is tired,
 * in a hurry, and about to make a mistake:
 *
 *   confined    a path is RESOLVED and then checked to be inside the
 *               application folder. Not string-matched — resolved — because
 *               "..", a symbolic link, and on Windows an 8.3 short name all
 *               defeat comparing the string the browser sent.
 *   checked     PHP is parsed before it is written. A file that would white
 *               screen the application is refused, not saved.
 *   reversible  every save copies the old file aside first, so any change goes
 *               back with one click and no typing.
 *   recorded    who saved what, when, and how much it changed.
 *
 * Access is granted separately from administrator. Running the laboratory's
 * back end and rewriting the application are different jobs, and the point of
 * the second list is that it is shorter.
 *
 * The design follows the Studio in PRI Facilities Request, deliberately: the
 * same idea solved the same way keeps the two systems recognisable to the same
 * person at two in the morning.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/** Files the Studio will open. Anything else is data, not code. */
const STUDIO_EXTENSIONS = ['php', 'css', 'js', 'html', 'htm', 'json', 'md',
                           'sql', 'txt', 'svg', 'bat', 'ps1', 'ini', 'yml', 'yaml'];

/**
 * Never listed: the database and what the application writes beside it, the
 * bundled PHP runtime, build output, and version control.
 */
const STUDIO_SKIP_DIRS = ['data', 'tools/php', 'dist', '.git', '.claude',
                          'node_modules', 'vendor'];

/** Larger than any source file here, and small enough to edit in a browser. */
const STUDIO_MAX_BYTES = 1048576;


/** Slashes one way, whatever the operating system hands back. */
function studio_slash(string $p): string
{
    return str_replace('\\', '/', $p);
}

/** The application folder. */
function studio_root(): string
{
    $root = realpath(APP_ROOT);
    if ($root === false) {
        throw new RuntimeException('Cannot resolve the application folder.');
    }
    return studio_slash($root);
}

function studio_backup_dir(): string
{
    $dir = dirname(LAB_DB_PATH) . '/studio-backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return studio_slash($dir);
}

/**
 * May this administrator edit the code?
 *
 * An explicit grant, not a consequence of being an administrator. The column
 * defaults to 0, so a new account cannot rewrite the application until
 * somebody deliberately says it may.
 */
function can_edit_code(): bool
{
    $admin = admin_user();
    if (!$admin) {
        return false;
    }
    return (int) db_value('SELECT may_edit_code FROM admin_users WHERE user_id = ?',
                          [$admin['user_id']]) === 1;
}

/** The same question, where the answer has to stop the request. */
function require_code_editor(): array
{
    $admin = require_admin();
    if (!can_edit_code()) {
        page_header('Not granted', ['nav' => '', 'mainClass' => 'page narrow']);
        echo '<h1>Editing the code is granted separately</h1>';
        echo '<p class="lede">Being an administrator is not enough to rewrite the application.</p>';
        echo '<p>Another administrator can grant it on the '
           . '<a href="users.php">Administrators</a> screen. If you are the only '
           . 'administrator, the grant can be set from the server command line with '
           . '<code>php admin-recovery.php grant ' . h($admin['username']) . '</code>.</p>';
        page_footer();
        exit;
    }
    return $admin;
}

/**
 * Turn a path from the browser into a real file inside the application, or
 * nothing at all. The check is on the resolved path for the reasons in the
 * header comment.
 */
function studio_resolve(?string $rel): ?string
{
    $rel = studio_slash(trim((string) $rel));
    if ($rel === '' || strpos($rel, "\0") !== false) {
        return null;
    }
    $root = studio_root();
    $full = realpath($root . '/' . ltrim($rel, '/'));
    if ($full === false) {
        return null;
    }
    $full = studio_slash($full);
    if ($full !== $root && strpos($full, $root . '/') !== 0) {
        return null;
    }
    if (!is_file($full)) {
        return null;
    }
    if (studio_skipped(dirname(studio_relative($full)))) {
        return null;
    }
    $ext = strtolower((string) pathinfo($full, PATHINFO_EXTENSION));
    if (!in_array($ext, STUDIO_EXTENSIONS, true)) {
        return null;
    }
    return $full;
}

/** The path as the Studio shows it: relative to the application folder. */
function studio_relative(string $full): string
{
    return ltrim(substr(studio_slash($full), strlen(studio_root())), '/');
}

function studio_skipped(string $relDir): bool
{
    $relDir = trim(studio_slash($relDir), '/');
    if ($relDir === '' || $relDir === '.') {
        return false;
    }
    foreach (STUDIO_SKIP_DIRS as $skip) {
        if ($relDir === $skip || strpos($relDir . '/', $skip . '/') === 0) {
            return true;
        }
    }
    return false;
}

/** Every editable file, as relative paths, sorted for reading. */
function studio_tree(): array
{
    $root = studio_root();
    $out  = [];

    $walk = function (string $dir) use (&$walk, $root, &$out): void {
        $rel = trim(substr(studio_slash($dir), strlen($root)), '/');
        if ($rel !== '' && studio_skipped($rel)) {
            return;
        }
        foreach (@scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $dir . '/' . $e;
            if (is_dir($path)) {
                $walk($path);
                continue;
            }
            $ext = strtolower((string) pathinfo($e, PATHINFO_EXTENSION));
            if (!in_array($ext, STUDIO_EXTENSIONS, true)) {
                continue;
            }
            if ((int) @filesize($path) > STUDIO_MAX_BYTES) {
                continue;
            }
            $out[] = studio_relative($path);
        }
    };
    $walk($root);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Will the parser accept this PHP?
 *
 * token_get_all with TOKEN_PARSE raises on a syntax error without running a
 * line of it, so the check is safe to make on the very server about to save
 * the file. Shelling out to "php -l" is the other way and exec() is commonly
 * closed on a campus host.
 */
function studio_check(string $rel, string $code): array
{
    $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
    if ($ext !== 'php') {
        return ['ok' => true, 'message' => 'Not PHP, so saved as written.'];
    }
    try {
        token_get_all($code, TOKEN_PARSE);
        return ['ok' => true, 'message' => 'PHP parses.'];
    } catch (ParseError $e) {
        return ['ok' => false, 'message' => 'PHP syntax error on line ' . $e->getLine()
                                          . ': ' . $e->getMessage()];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not parse: ' . $e->getMessage()];
    }
}

function studio_backup_name(string $rel): string
{
    return date('Ymd-His') . '__' . str_replace('/', '~', $rel);
}

/** Every kept copy of one file, newest first. */
function studio_backups(string $rel): array
{
    $dir    = studio_backup_dir();
    $suffix = '__' . str_replace('/', '~', $rel);
    $out    = [];
    foreach (@scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..' || substr($e, -strlen($suffix)) !== $suffix) {
            continue;
        }
        $out[] = ['name' => $e, 'when' => substr($e, 0, 15),
                  'size' => (int) @filesize($dir . '/' . $e)];
    }
    usort($out, function ($a, $b) { return strcmp($b['name'], $a['name']); });
    return $out;
}

/** The audit line. A change to the code is worth a record. */
function studio_log(string $action, string $rel, string $detail = ''): void
{
    $line = json_encode([
        'at'     => date('c'),
        'who'    => admin_display_name(),
        'action' => $action,
        'file'   => $rel,
        'detail' => $detail,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents(dirname(LAB_DB_PATH) . '/studio-history.log', $line,
                       FILE_APPEND | LOCK_EX);
}

/** The last few lines of that log, newest first. */
function studio_history(int $limit = 12): array
{
    $file = dirname(LAB_DB_PATH) . '/studio-history.log';
    if (!is_file($file)) {
        return [];
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -$limit);
    $out = [];
    foreach (array_reverse($lines) as $l) {
        $row = json_decode($l, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Save a file: check it, keep the old one, then write.
 * Nothing is written unless the check passes, so a syntax error costs a
 * message rather than the application.
 */
function studio_save(string $rel, string $code): array
{
    $full = studio_resolve($rel);
    if ($full === null) {
        return [false, 'That file is not part of this application.'];
    }
    if (!is_writable($full)) {
        return [false, 'The file is read-only on disk. Nothing was written.'];
    }

    $check = studio_check($rel, $code);
    if (!$check['ok']) {
        studio_log('refused', $rel, $check['message']);
        return [false, $check['message'] . '  Nothing was written.'];
    }

    $before = (string) @file_get_contents($full);
    if ($before === $code) {
        return [true, 'No change to save.'];
    }
    if (@copy($full, studio_backup_dir() . '/' . studio_backup_name($rel)) === false) {
        return [false, 'Could not keep a copy of the old file, so nothing was written.'];
    }

    // Written whole and then moved into place, so a half-written file never
    // exists for the next request to load.
    $tmp = $full . '.studio-tmp';
    if (@file_put_contents($tmp, $code, LOCK_EX) === false || !@rename($tmp, $full)) {
        @unlink($tmp);
        return [false, 'The write failed. The file is unchanged.'];
    }

    $delta = strlen($code) - strlen($before);
    studio_log('saved', $rel, ($delta >= 0 ? '+' : '') . $delta . ' bytes');
    return [true, 'Saved. ' . $check['message'] . ' The previous copy is kept.'];
}

/** Put a kept copy back, keeping the current one first. */
function studio_restore(string $rel, string $backupName): array
{
    if (basename($backupName) !== $backupName) {
        return [false, 'Not a backup this Studio wrote.'];
    }
    $src = studio_backup_dir() . '/' . $backupName;
    if (!is_file($src)) {
        return [false, 'That copy is no longer there.'];
    }
    $res = studio_save($rel, (string) @file_get_contents($src));
    if ($res[0]) {
        studio_log('restored', $rel, $backupName);
        return [true, 'Restored the copy from ' . substr($backupName, 0, 15) . '.'];
    }
    return $res;
}
