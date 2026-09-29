<?php
require_once __DIR__ . '/playthrough_runtime.php';

/**
 * PostgreSQL database driver for StobeServer.
 * Reuses the same pattern as HerikaServer - connects to the dwemer database.
 */

class sql {
    private $conn;
    private string $lastError = '';

    public function __construct() {
        ptr_runtime_enter();
        $host = trim(strval(getenv('STOBE_DB_HOST') ?: 'localhost'));
        $dbname = trim(strval(getenv('STOBE_DB_NAME') ?: 'stobe'));
        $user = trim(strval(getenv('STOBE_DB_USER') ?: 'dwemer'));
        $password = strval(getenv('STOBE_DB_PASSWORD') ?: 'dwemer');

        $connectionParts = [
            'host=' . $this->escapeConnectionValue($host),
            'dbname=' . $this->escapeConnectionValue($dbname),
            'user=' . $this->escapeConnectionValue($user),
            'password=' . $this->escapeConnectionValue($password),
        ];
        $this->conn = pg_connect(implode(' ', $connectionParts));
        if (!$this->conn) {
            throw new Exception("Database connection failed");
        }
        if ((isset($_SERVER['HTTP_X_STOBE_PLAYTHROUGH']) && basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'playthrough_session.php')
            || in_array(basename($_SERVER['SCRIPT_FILENAME'] ?? ''), ['main.php','gamedata.php'], true)) {
            require_once __DIR__ . '/playthrough_switching.php';
            pas_guard($this->conn, true);
        }
    }

    private function escapeConnectionValue(string $value): string {
        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $value) === 1) {
            return $value;
        }
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    public function exec(string $query, array $params = []): mixed {
        if (empty($params)) {
            // Suppress native PHP warnings from pg_* calls; callers handle false
            // and we capture the DB error in $this->lastError for diagnostics.
            $result = @pg_query($this->conn, $query);
        } else {
            $normalizedParams = $this->normalizeParams($params);
            // Suppress native PHP warnings from pg_* calls; callers handle false
            // and we capture the DB error in $this->lastError for diagnostics.
            $result = @pg_query_params($this->conn, $query, $normalizedParams);
        }
        if ($result === false) {
            $this->lastError = pg_last_error($this->conn) ?: 'Unknown PostgreSQL error';
        }
        return $result;
    }

    public function affectedRows(mixed $result): int {
        if ($result === false) {
            return 0;
        }
        return intval(@pg_affected_rows($result));
    }

    private function normalizeParams(array $params): array {
        $normalized = [];
        foreach ($params as $value) {
            if (is_bool($value)) {
                $normalized[] = $value ? 'true' : 'false';
                continue;
            }
            $normalized[] = $value;
        }
        return $normalized;
    }

    public function fetchAll(string $query, array $params = []): array {
        $result = $this->exec($query, $params);
        if (!$result) {
            return [];
        }
        return pg_fetch_all($result) ?: [];
    }

    public function fetchOne(string $query, array $params = []): array|false {
        $result = $this->exec($query, $params);
        if (!$result) {
            return false;
        }
        return pg_fetch_assoc($result);
    }

    public function query(string $query): mixed {
        return $this->exec($query);
    }

    public function execQuery(string $query): mixed {
        return $this->exec($query);
    }

    private function sanitizeIdentifier(string $value): string {
        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $trimmed) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $value);
        }
        return $trimmed;
    }

    public function insert(string $table, array $data): mixed {
        if (count($data) === 0) {
            return false;
        }

        $safeTable = $this->sanitizeIdentifier($table);
        $columns = [];
        $placeholders = [];
        $params = [];
        $index = 1;

        foreach ($data as $column => $value) {
            $safeColumn = $this->sanitizeIdentifier(strval($column));
            $columns[] = $safeColumn;
            $placeholders[] = '$' . $index;
            $params[] = $value;
            $index++;
        }

        $query = "INSERT INTO {$safeTable} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        return $this->exec($query, $params);
    }

    public function updateRow(string $table, array $data, string $where): mixed {
        if (count($data) === 0) {
            return false;
        }

        $safeTable = $this->sanitizeIdentifier($table);
        $safeWhere = trim($where);
        if ($safeWhere === '') {
            $safeWhere = 'FALSE';
        }

        $setClauses = [];
        $params = [];
        $index = 1;
        foreach ($data as $column => $value) {
            $safeColumn = $this->sanitizeIdentifier(strval($column));
            $setClauses[] = "{$safeColumn} = $" . $index;
            $params[] = $value;
            $index++;
        }

        $query = "UPDATE {$safeTable} SET " . implode(', ', $setClauses) . " WHERE {$safeWhere}";
        return $this->exec($query, $params);
    }

    public function delete(string $table, string $where = 'FALSE'): bool {
        $safeTable = $this->sanitizeIdentifier($table);
        $safeWhere = trim($where);
        if ($safeWhere === '') {
            $safeWhere = 'FALSE';
        }
        $result = $this->exec("DELETE FROM {$safeTable} WHERE {$safeWhere}");
        return $result !== false;
    }

    public function truncate(string $table, bool $restart = false, bool $cascade = false): bool {
        $safeTable = $this->sanitizeIdentifier($table);
        $query = "TRUNCATE {$safeTable}";
        if ($restart) {
            $query .= " RESTART IDENTITY";
        }
        if ($cascade) {
            $query .= " CASCADE";
        }
        $result = $this->exec($query);
        return $result !== false;
    }

    public function escape(string $str): string {
        return pg_escape_string($this->conn, $str);
    }

    public function escapeLiteral(string $str): string {
        if ($str === '') {
            return "''";
        }
        return pg_escape_literal($this->conn, $str);
    }

    public function fetchArray(mixed $res): array|false {
        if (!$res) {
            return false;
        }
        return pg_fetch_array($res);
    }

    public function GetLastError(): string {
        $runtimeError = pg_last_error($this->conn);
        if (is_string($runtimeError) && trim($runtimeError) !== '') {
            return $runtimeError;
        }
        return $this->lastError;
    }

    public function close(): void {
        if ($this->conn) {
            try {
                pg_close($this->conn);
            } catch (Throwable $e) {
                // Connection may already be closed by another lifecycle path.
            }
            $this->conn = null;
        }
    }

    public function __destruct() {
        $this->close();
    }
}
