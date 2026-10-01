<?php
/**
 * db.php — Database connection.
 *
 * Every query in the application runs through the PDO handle returned by db()
 * and uses prepared statements. Nothing concatenates a value into SQL.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// The database file normally sits in data/ next to the application. A host that
// allows storage outside the web root is the safer arrangement: create
// config.php beside index.php containing
//     <?php define('LAB_DB_PATH', '/home/account/private/lab.sqlite');
// and this file will use that path instead. See the README.
if (file_exists(APP_ROOT . '/config.php')) {
    require_once APP_ROOT . '/config.php';
}
if (!defined('LAB_DB_PATH')) {
    define('LAB_DB_PATH', APP_ROOT . '/data/lab.sqlite');
}

/**
 * Read .env into the environment, once.
 *
 * Values already present in the real environment win, so a server that sets
 * PGPASSWORD properly is not overridden by a stale file. Absent .env this
 * does nothing at all, which is why an installation that has never been
 * migrated carries on unchanged.
 *
 * Deliberately tiny and dependency-free: this project has no package
 * manager, by design.
 */
function load_dot_env(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $path = APP_ROOT . '/.env';
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '' && getenv($k) === false) {
            putenv($k . '=' . $v);
        }
    }
}

/**
 * Which driver this installation uses: 'sqlite' (the default) or 'pgsql'.
 *
 * Read once so a page cannot end up talking to two different databases.
 */
function db_driver(): string
{
    static $driver = null;
    if ($driver === null) {
        load_dot_env();
        $driver = strtolower(trim((string) (getenv('PACELAB_DRIVER') ?: 'sqlite')));
        if ($driver !== 'pgsql') {
            $driver = 'sqlite';
        }
    }
    return $driver;
}

/**
 * The shared PDO handle. Opens the database on first call.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(LAB_DB_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    /*
     * Postgres is used when PACELAB_DRIVER says so, and only then. The
     * default stays sqlite, so an installation that has not been migrated
     * behaves exactly as before and nobody has to do anything.
     *
     * Credentials come from the environment, never from a committed file.
     * See .env.example.
     */
    if (db_driver() === 'pgsql') {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('PGHOST') ?: '127.0.0.1',
            getenv('PGPORT') ?: '5432',
            getenv('PGDATABASE') ?: 'pacelab'
        );
        try {
            $pdo = new PDO($dsn, getenv('PGUSER') ?: '', getenv('PGPASSWORD') ?: '', $options);
        } catch (PDOException $e) {
            http_response_code(500);
            // The message is deliberately vague: a connection error can
            // otherwise print the host and user to the browser.
            exit('The database could not be reached. Check the service and the values in .env.');
        }
        return $pdo;
    }

    try {
        $pdo = new PDO('sqlite:' . LAB_DB_PATH, null, null, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        exit('The database could not be opened. Run check.php to test the server, then install.php to create the database.');
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
    // Several laboratory users post at once; a writer should wait its turn
    // rather than fail outright.
    $pdo->exec('PRAGMA busy_timeout = 5000');
    @$pdo->exec('PRAGMA journal_mode = WAL');

    return $pdo;
}

/** Run a prepared statement and return the statement. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Every matching row. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** The first matching row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** The first column of the first matching row, or null. */
function db_value(string $sql, array $params = [])
{
    $value = db_run($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Insert a row, doing nothing if it is already there.
 *
 * SQLite spells this INSERT OR IGNORE and Postgres spells it
 * ON CONFLICT DO NOTHING. Both are supported, so seeding code does not have
 * to know which database it is talking to.
 */
function db_insert_ignore(string $table, array $columns, array $values): void
{
    $cols = implode(', ', array_map(static fn ($c) => '"' . $c . '"', $columns));
    $marks = implode(', ', array_fill(0, count($columns), '?'));
    if (db_driver() === 'pgsql') {
        db_run("INSERT INTO \"$table\" ($cols) VALUES ($marks) ON CONFLICT DO NOTHING", $values);
        return;
    }
    db_run("INSERT OR IGNORE INTO \"$table\" ($cols) VALUES ($marks)", $values);
}

/** True when the schema has been created. */
function db_installed(): bool
{
    // Only meaningful for SQLite, where the database is a file. A Postgres
    // installation has no such file, and this guard previously made
    // db_installed() always false there - which sends every visitor to the
    // installer on a database that is already populated.
    if (db_driver() === 'sqlite' && !file_exists(LAB_DB_PATH)) {
        return false;
    }
    try {
        if (db_driver() === 'pgsql') {
            return (bool) db_value(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = 'equipment'"
            );
        }
        return (bool) db_value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'equipment'");
    } catch (Throwable $e) {
        return false;
    }
}
