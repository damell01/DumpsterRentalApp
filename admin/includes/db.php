<?php
/**
 * Database layer – PDO singleton + convenience wrappers
 * Trash Panda Roll-Offs
 */

/**
 * Returns the shared PDO instance, creating it on first call.
 */
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // Trim config values that commonly pick up accidental whitespace.
        $dbHost = preg_replace('/^\s+|\s+$/u', '', (string) DB_HOST) ?? (string) DB_HOST;
        $dbName = preg_replace('/^\s+|\s+$/u', '', (string) DB_NAME) ?? (string) DB_NAME;
        $dbUser = preg_replace('/\s+/u', '', (string) DB_USER) ?? (string) DB_USER;

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $dbHost,
            $dbName,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO($dsn, $dbUser, DB_PASS, $options);
    }

    return $pdo;
}

/**
 * Prepare and execute a query, returning the PDOStatement.
 *
 * @param string $sql
 * @param array  $params
 * @return PDOStatement
 */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch a single row as an associative array, or false if not found.
 *
 * @param string $sql
 * @param array  $params
 * @return array|false
 */
function db_fetch(string $sql, array $params = []): array|false
{
    return db_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}

/**
 * Fetch all rows as an array of associative arrays.
 *
 * @param string $sql
 * @param array  $params
 * @return array
 */
function db_fetchall(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Build and execute an INSERT from an associative array.
 * Returns the last inserted ID.
 *
 * @param string $table
 * @param array  $data  column => value pairs
 * @return string  last insert ID
 */
function db_insert(string $table, array $data): string
{
    $cols        = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $cols);

    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(fn($c) => '`' . $c . '`', $cols)),
        implode(', ', $placeholders)
    );

    $named = [];
    foreach ($data as $col => $val) {
        $named[':' . $col] = $val;
    }

    get_db()->prepare($sql)->execute($named);

    return get_db()->lastInsertId();
}

/**
 * Build and execute an UPDATE … WHERE $where_col = $where_val.
 *
 * @param string $table
 * @param array  $data        column => value pairs to set
 * @param string $where_col   column name for WHERE clause
 * @param mixed  $where_val   value for WHERE clause
 * @return bool
 */
function db_update(string $table, array $data, string $where_col, mixed $where_val): bool
{
    $sets = [];
    foreach (array_keys($data) as $col) {
        $sets[] = '`' . $col . '` = :set_' . $col;
    }

    $sql = sprintf(
        'UPDATE `%s` SET %s WHERE `%s` = :where_val',
        $table,
        implode(', ', $sets),
        $where_col
    );

    $named = [':where_val' => $where_val];
    foreach ($data as $col => $val) {
        $named[':set_' . $col] = $val;
    }

    return get_db()->prepare($sql)->execute($named);
}

/**
 * Prepare and execute a statement, returning a bool indicating success.
 * Useful for DELETE and other non-SELECT statements.
 *
 * @param string $sql
 * @param array  $params
 * @return bool
 */
function db_execute(string $sql, array $params = []): bool
{
    return get_db()->prepare($sql)->execute($params);
}

/**
 * Execute a callback within a DB transaction.
 *
 * @template T
 * @param callable(PDO):T $callback
 * @return T
 * @throws Throwable
 */
function db_transaction(callable $callback): mixed
{
    $pdo = get_db();
    $started = false;

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        $result = $callback($pdo);
        if ($started && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Fetch the first column from the first row, or null if no rows are returned.
 */
function db_value(string $sql, array $params = []): mixed
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Check whether a table exists in the current database.
 */
function db_table_exists(string $table): bool
{
    $sql = "SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    return (int)(db_value($sql, [$table]) ?? 0) > 0;
}

/**
 * Check whether a column exists in a table in the current database.
 */
function db_column_exists(string $table, string $column): bool
{
    $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    return (int)(db_value($sql, [$table, $column]) ?? 0) > 0;
}
