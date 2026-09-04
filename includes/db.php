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

    try {
        $pdo = new PDO('sqlite:' . LAB_DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
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

/** True when the schema has been created. */
function db_installed(): bool
{
    if (!file_exists(LAB_DB_PATH)) {
        return false;
    }
    try {
        return (bool) db_value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'equipment'");
    } catch (Throwable $e) {
        return false;
    }
}
