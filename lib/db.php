<?php
// ============================================================
// lib/db.php
// Single shared PDO connection for the whole application.
// No page should ever call "new PDO(...)" on its own.
// ============================================================

require_once __DIR__ . '/config.php';

/**
 * Return the shared PDO instance, creating it on first use.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never leak connection details to the browser.
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Service temporarily unavailable. Please try again later.');
        }
    }

    return $pdo;
}

// ------------------------------------------------------------
// Thin query wrappers so pages do not repeat prepare/execute.
// ------------------------------------------------------------

/** Run a parameterised statement and return the PDOStatement. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stm = db()->prepare($sql);
    $stm->execute($params);
    return $stm;
}

/** Fetch a single row (or false when nothing matches). */
function db_one(string $sql, array $params = [])
{
    return db_run($sql, $params)->fetch();
}

/** Fetch every matching row. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** Fetch the first column of the first row. */
function db_value(string $sql, array $params = [])
{
    return db_run($sql, $params)->fetchColumn();
}

/** Run INSERT/UPDATE/DELETE and return the number of affected rows. */
function db_exec(string $sql, array $params = []): int
{
    return db_run($sql, $params)->rowCount();
}

/** Last inserted auto-increment id. */
function db_last_id(): string
{
    return db()->lastInsertId();
}

/**
 * True when a table exists in the current database.
 * Lets optional modules degrade gracefully instead of throwing
 * a fatal error when a migration has not been run yet.
 */
function db_table_exists(string $table): bool
{
    static $cache = [];

    if (!array_key_exists($table, $cache)) {
        $cache[$table] = (bool)db_value(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
    }

    return $cache[$table];
}

/** True when a column exists on a table in the current database. */
function db_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (!array_key_exists($key, $cache)) {
        $cache[$key] = (bool)db_value(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
    }

    return $cache[$key];
}

// Backward-compatible variable for any code still expecting $pdo.
$pdo = db();
