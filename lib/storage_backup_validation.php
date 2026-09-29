<?php

// The STOBE-only importer must not follow a combined archive into another database.
function stobeValidateScopedBackup(string $path): void
{
    $handle = @gzopen($path, 'rb'); // zlib also reads uncompressed .sql files.
    if (!$handle) throw new RuntimeException('The backup could not be read.');
    try {
        $lineStart = true;
        while (!gzeof($handle)) {
            $line = gzgets($handle, 1048576);
            if ($line === false) throw new RuntimeException('The backup could not be read completely.');
            if ($lineStart && preg_match('/^\\\\(?:connect|c)\s/', trim($line))) {
                throw new RuntimeException('This backup can switch databases. Restore combined backups from Shared databases, not the STOBE-only tools. Nothing was changed.');
            }
            $lineStart = str_ends_with($line, "\n");
        }
    } finally { gzclose($handle); }
}

// Restore a trusted pg_dump file in one database transaction; a SQL error keeps the old database.
function stobeRestoreScopedBackup(string $path, array $settings): array
{
    stobeValidateScopedBackup($path);
    $source = gzopen($path, 'rb');
    $temporary = tempnam(sys_get_temp_dir(), 'stobe-restore-');
    if (!$source || !$temporary) {
        if ($source) gzclose($source);
        if ($temporary) unlink($temporary);
        throw new RuntimeException('Could not prepare the restore file.');
    }
    $conn = null;
    try {
        $output = fopen($temporary, 'wb');
        if (!$output) throw new RuntimeException('Could not prepare the restore file.');
        try {
            while (!gzeof($source)) {
                $chunk = gzread($source, 1048576);
                if ($chunk === false || fwrite($output, $chunk) !== strlen($chunk)) throw new RuntimeException('Could not read the complete backup.');
            }
        } finally { fclose($output); gzclose($source); $source = null; }
        if (filesize($temporary) === 0) throw new RuntimeException('The backup is empty.');
        $parts = [];
        foreach (['host', 'port', 'dbname', 'user', 'password'] as $key) {
            $parts[] = $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$settings[$key]) . "'";
        }
        $conn = pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);
        if (!$conn) throw new RuntimeException('Could not connect to the STOBE database.');
        $schemas = pg_query($conn, "SELECT nspname FROM pg_namespace WHERE nspname <> 'information_schema' AND nspname NOT LIKE 'pg_%'");
        if (!$schemas) throw new RuntimeException('Could not read the database schemas.');
        $prepare = "SET lock_timeout='5s';";
        while ($row = pg_fetch_assoc($schemas)) $prepare .= 'DROP SCHEMA IF EXISTS ' . pg_escape_identifier($conn, $row['nspname']) . ' CASCADE;';
        $prepare .= 'CREATE SCHEMA public;';
        pg_close($conn); $conn = null;
        $command = 'PGPASSWORD=' . escapeshellarg($settings['password']) . ' psql -X --single-transaction -v ON_ERROR_STOP=1'
            . ' -h ' . escapeshellarg($settings['host']) . ' -p ' . escapeshellarg($settings['port'])
            . ' -U ' . escapeshellarg($settings['user']) . ' -d ' . escapeshellarg($settings['dbname'])
            . ' -c ' . escapeshellarg($prepare) . ' -f ' . escapeshellarg($temporary);
        $lines = []; $code = 1;
        exec($command . ' 2>&1', $lines, $code);
        if ($code !== 0) {
            error_log('[StorageManager] STOBE backup restore failed with exit code ' . $code);
            return ['ok' => false, 'message' => 'Restore failed. The previous database was kept. Check the server log and use a complete STOBE pg_dump backup.'];
        }
        return ['ok' => true, 'message' => 'STOBE database restored. Restart the server and load the matching Kenshi save.'];
    } finally {
        if ($source) gzclose($source);
        if ($conn) pg_close($conn);
        unlink($temporary);
    }
}
