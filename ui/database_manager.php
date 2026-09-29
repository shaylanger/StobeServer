<?php
/**
 * StobeServer Database Manager.
 * Stobe-native replacement of Herika import_db style manager.
 */

// Shared "Playthrough Management" fragment mode. The Dwemer Dashboard includes this
// page in-process and renders its controls inside the shared shell, so only the
// document chrome and asset URLs change. Every action handler below is untouched.
$dbmFragment = defined('DWEMER_STORAGE_FRAGMENT') && DWEMER_STORAGE_FRAGMENT === true;
if (!$dbmFragment) {
    // Shared compatibility policy lives in one place: redirect a bookmarked view,
    // refuse stale writes, and stay standalone when the Dashboard is absent.
    $dbmRouteHelper = dirname(__DIR__) . DIRECTORY_SEPARATOR . "lib"
        . DIRECTORY_SEPARATOR . "storage_manager_route.php";
    if (is_file($dbmRouteHelper)) {
        require_once $dbmRouteHelper;
        dwemerStorageRedirect("stobe", "databases");
    }
}

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function safeFetchAll(sql $db, string $query, array $params = []): array
{
    try {
        return $db->fetchAll($query, $params);
    } catch (Throwable $exception) {
        return [];
    }
}

function safeFetchOne(sql $db, string $query, array $params = []): array|false
{
    try {
        return $db->fetchOne($query, $params);
    } catch (Throwable $exception) {
        return false;
    }
}

function safeExec(sql $db, string $query, array $params = []): bool
{
    try {
        return (bool)$db->exec($query, $params);
    } catch (Throwable $exception) {
        return false;
    }
}

function formatFileSize(int|float $bytes): string
{
    $bytes = max(0, floatval($bytes));
    if ($bytes <= 0) {
        return "0 B";
    }
    $units = ["B", "KB", "MB", "GB", "TB"];
    $idx = (int)floor(log($bytes, 1024));
    $idx = max(0, min($idx, count($units) - 1));
    $value = $bytes / pow(1024, $idx);
    return number_format($value, $idx === 0 ? 0 : 2) . " " . $units[$idx];
}

function runShellCommand(string $command, array &$output = null, int &$exitCode = null): bool
{
    $out = [];
    $code = 1;
    @exec($command . " 2>&1", $out, $code);
    $output = $out;
    $exitCode = $code;
    return $code === 0;
}

function listBackups(string $backupDir): array
{
    if (!is_dir($backupDir)) {
        return [];
    }

    $items = [];
    $files = @scandir($backupDir);
    if (!is_array($files)) {
        return [];
    }

    foreach ($files as $file) {
        if ($file === "." || $file === "..") {
            continue;
        }
        $full = $backupDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($full)) {
            continue;
        }
        if (!preg_match('/\.sql(\.gz)?$/i', $file)) {
            continue;
        }
        $items[] = [
            "filename" => $file,
            "path" => $full,
            "size" => intval(@filesize($full)),
            "mtime" => intval(@filemtime($full)),
        ];
    }

    usort($items, static function ($a, $b) {
        return ($b["mtime"] <=> $a["mtime"]);
    });

    return $items;
}

function databaseManagerUrl(bool $embedded): string
{
    // In shared mode every action link must stay on the central route so the
    // mod and task selection survive downloads, deletes and redirects.
    if (defined("DWEMER_STORAGE_FRAGMENT") && DWEMER_STORAGE_FRAGMENT === true) {
        return (string)DWEMER_STORAGE_FRAGMENT_ROUTE;
    }
    $url = "database_manager.php";
    if ($embedded) {
        $url .= "?embed=1";
    }
    return $url;
}

function getVersioningColumns(sql $db): array
{
    $rows = safeFetchAll(
        $db,
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema='public' AND table_name='database_versioning'"
    );
    $columns = [];
    foreach ($rows as $row) {
        $columns[] = strtolower(trim(strval($row["column_name"] ?? "")));
    }

    $tableCol = in_array("tablename", $columns, true) ? "tablename" : (in_array("table_name", $columns, true) ? "table_name" : "");
    $versionCol = in_array("version", $columns, true) ? "version" : (in_array("patch_version", $columns, true) ? "patch_version" : "");

    return [$tableCol, $versionCol];
}

function resolveBackupFilePath(string $backupDir, string $manualBackupDir, string $source, string $file): array
{
    $safeFile = basename($file);
    $normalizedSource = strtolower(trim($source));
    $baseDir = ($normalizedSource === "manual") ? $manualBackupDir : $backupDir;
    $full = realpath($baseDir . DIRECTORY_SEPARATOR . $safeFile);
    $base = realpath($baseDir);
    $ok = ($full !== false && $base !== false && str_starts_with($full, $base) && is_file($full));
    return [
        "ok" => $ok,
        "file" => $safeFile,
        "source" => ($normalizedSource === "manual") ? "manual" : "db_backups",
        "full" => $ok ? $full : "",
        "base" => $base ?: "",
    ];
}

$db = $GLOBALS["db"];
$isEmbedded = $dbmFragment || (isset($_GET["embed"]) && strval($_GET["embed"]) === "1");
$dbmWebRoot = "/StobeServer";
if ($dbmFragment) {
    $dbmWebRoot = (string)DWEMER_STORAGE_FRAGMENT_WEBROOT;
} else {
    $dbmScriptPath = str_replace(DIRECTORY_SEPARATOR, "/", (string)($_SERVER["SCRIPT_NAME"] ?? ""));
    $dbmUiPos = strpos($dbmScriptPath, "/ui/");
    if ($dbmUiPos !== false) {
        $dbmWebRoot = rtrim(substr($dbmScriptPath, 0, $dbmUiPos), "/");
    }
}
$message = "";
$messageType = "info";

// Download / delete actions first.
$rootPath = dirname(__DIR__);
$backupDir = $rootPath . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "db_backups";
$manualBackupDir = $rootPath . DIRECTORY_SEPARATOR . "ui" . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "manualbackup";
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !is_dir($manualBackupDir)) {
    @mkdir($manualBackupDir, 0775, true);
}

if (isset($_GET["action"]) && $_GET["action"] === "download_backup" && isset($_GET["file"])) {
    $fileInfo = resolveBackupFilePath(
        $backupDir,
        $manualBackupDir,
        strval($_GET["source"] ?? "db_backups"),
        strval($_GET["file"] ?? "")
    );
    if ($fileInfo["ok"]) {
        header("Content-Type: application/octet-stream");
        header("Content-Disposition: attachment; filename=\"" . $fileInfo["file"] . "\"");
        header("Content-Length: " . strval(filesize($fileInfo["full"])));
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($fileInfo["full"]);
        exit;
    }
}

if (isset($_GET["action"]) && $_GET["action"] === "delete_backup" && isset($_GET["file"])) {
    $fileInfo = resolveBackupFilePath(
        $backupDir,
        $manualBackupDir,
        strval($_GET["source"] ?? "db_backups"),
        strval($_GET["file"] ?? "")
    );
    if ($fileInfo["ok"] && @unlink($fileInfo["full"])) {
        $message = "Backup deleted: " . $fileInfo["file"];
        $messageType = "ok";
    } else {
        $message = "Failed to delete backup file.";
        $messageType = "error";
    }
}

// POST actions.
$backupConfig = stobePlaythroughDbConfig();
$dbHost = $backupConfig['host'];
$dbPort = $backupConfig['port'];
$dbName = $backupConfig['dbname'];
$dbUser = $backupConfig['user'];
$dbPass = $backupConfig['password'];

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    $action = trim(strval($_POST["action"] ?? ""));

    if ($action === "create_backup") {
        $filename = "stobe_backup_" . gmdate("Ymd_His") . ".sql";
        $outPath = $backupDir . DIRECTORY_SEPARATOR . $filename;
        $cmd = "PGPASSWORD=" . escapeshellarg($dbPass)
            . " pg_dump -h " . escapeshellarg($dbHost)
            . " -p " . escapeshellarg($dbPort)
            . " -U " . escapeshellarg($dbUser)
            . " -d " . escapeshellarg($dbName)
            . " --no-owner --no-privileges -F p -f " . escapeshellarg($outPath);

        $out = [];
        $code = 1;
        if (runShellCommand($cmd, $out, $code)) {
            $message = "Backup created: " . $filename;
            $messageType = "ok";
        } else {
            $message = "Backup failed. " . implode(" | ", array_slice($out, 0, 3));
            $messageType = "error";
        }
    } elseif (in_array($action, ['restore_uploaded_backup', 'restore_server_backup', 'restore_prepared_backup'], true)) {
        try {
            require_once $rootPath . '/lib/storage_backup_validation.php';
            $restorePath = null;
            if ($action === 'restore_prepared_backup') {
                if (!defined('DWEMER_STORAGE_RESTORE_FILE')) throw new RuntimeException('A verified restore preview is required.');
                $restorePath = DWEMER_STORAGE_RESTORE_FILE;
            } elseif ($action === 'restore_uploaded_backup') {
                $upload = $_FILES['restore_file'] ?? null;
                if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Choose a complete .sql or .sql.gz backup.');
                }
                $name = basename((string)$upload['name']);
                if (!preg_match('/\\.sql(\\.gz)?$/i', $name)) throw new RuntimeException('Only .sql and .sql.gz backups are supported.');
                $restorePath = $backupDir . '/upload_restore_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
                if (!move_uploaded_file($upload['tmp_name'], $restorePath)) throw new RuntimeException('Could not store the uploaded backup.');
            } else {
                $fileInfo = resolveBackupFilePath($backupDir, $manualBackupDir,
                    (string)($_POST['restore_source'] ?? 'db_backups'), (string)($_POST['restore_server_file'] ?? ''));
                if (!$fileInfo['ok']) throw new RuntimeException('Choose a valid server backup.');
                $restorePath = $fileInfo['full'];
            }
            $restore = stobeRestoreScopedBackup($restorePath, stobePlaythroughDbConfig());
            $message = $restore['message'];
            $messageType = $restore['ok'] ? 'ok' : 'error';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === "vacuum_analyze") {
        if (safeExec($db, "VACUUM ANALYZE")) {
            $message = "VACUUM ANALYZE completed.";
            $messageType = "ok";
        } else {
            $message = "VACUUM ANALYZE failed.";
            $messageType = "error";
        }
    } elseif ($action === "factory_reset_database") {
        $resetCmd = "PGPASSWORD=" . escapeshellarg($dbPass)
            . " psql -h " . escapeshellarg($dbHost)
            . " -p " . escapeshellarg($dbPort)
            . " -U " . escapeshellarg($dbUser)
            . " -d " . escapeshellarg($dbName)
            . " -v ON_ERROR_STOP=1 -c "
            . escapeshellarg("DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;");
        $resetOut = [];
        $resetCode = 1;
        $resetOk = runShellCommand($resetCmd, $resetOut, $resetCode);

        if ($resetOk) {
            $runnerPath = $rootPath . DIRECTORY_SEPARATOR . "debug" . DIRECTORY_SEPARATOR . "run_db_updates.php";
            $rebuildCmd = "php " . escapeshellarg($runnerPath);
            $rebuildOut = [];
            $rebuildCode = 1;
            if (runShellCommand($rebuildCmd, $rebuildOut, $rebuildCode)) {
                $message = "Factory reset completed and schema updates were re-applied.";
                $messageType = "ok";
            } else {
                $message = "Schema reset completed but update replay failed. " . implode(" | ", array_slice($rebuildOut, 0, 3));
                $messageType = "error";
            }
        } else {
            $message = "Factory reset failed. " . implode(" | ", array_slice($resetOut, 0, 3));
            $messageType = "error";
        }
    } elseif ($action === "reindex_database") {
        if (safeExec($db, 'REINDEX DATABASE "' . str_replace('"', '""', $dbName) . '"')) {
            $message = "REINDEX DATABASE completed.";
            $messageType = "ok";
        } else {
            $message = "REINDEX DATABASE failed.";
            $messageType = "error";
        }
    } elseif ($action === "reset_db_version") {
        [$tableCol, $versionCol] = getVersioningColumns($db);
        $tableName = trim(strval($_POST["version_table"] ?? ""));
        if ($tableCol === "" || $tableName === "") {
            $message = "database_versioning table/column not available.";
            $messageType = "error";
        } else {
            if (safeExec($db, "DELETE FROM public.database_versioning WHERE {$tableCol} = $1", [$tableName])) {
                $message = "Reset version entry for table: " . $tableName;
                $messageType = "ok";
            } else {
                $message = "Failed to reset version entry.";
                $messageType = "error";
            }
        }
    } elseif ($action === "reset_all_db_versions") {
        [$tableCol, $versionCol] = getVersioningColumns($db);
        if ($tableCol === "") {
            $message = "database_versioning table not available.";
            $messageType = "error";
        } else {
            if (safeExec($db, "DELETE FROM public.database_versioning")) {
                $message = "All database_versioning entries removed.";
                $messageType = "ok";
            } else {
                $message = "Failed to reset all version entries.";
                $messageType = "error";
            }
        }
    }
}

// Keep API results separate from the standalone database manager layout.
if (defined('DWEMER_STORAGE_ACTIONS_ONLY')) {
    return ['ok' => $messageType === 'ok', 'message' => $message];
}
$dbSizeRow = safeFetchOne($db, "SELECT pg_database_size($1) AS size_bytes", [$dbName]);
$dbSizeBytes = intval($dbSizeRow["size_bytes"] ?? 0);

$tableCountRow = safeFetchOne(
    $db,
    "SELECT COUNT(*) AS cnt
     FROM information_schema.tables
     WHERE table_schema='public' AND table_type='BASE TABLE'"
);
$tableCount = intval($tableCountRow["cnt"] ?? 0);

$versioningExistsRow = safeFetchOne(
    $db,
    "SELECT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema='public' AND table_name='database_versioning'
     ) AS exists_flag"
);
$versioningExists = in_array(strtolower(strval($versioningExistsRow["exists_flag"] ?? "false")), ["t", "true", "1", "yes"], true);

$versionRows = [];
$versionCount = 0;
[$versionTableCol, $versionNumberCol] = getVersioningColumns($db);
if ($versioningExists && $versionTableCol !== "") {
    $orderCol = $versionTableCol;
    $selectVersionCol = ($versionNumberCol !== "") ? ", {$versionNumberCol} AS version_value" : "";
    $versionRows = safeFetchAll(
        $db,
        "SELECT {$versionTableCol} AS table_name {$selectVersionCol}
         FROM public.database_versioning
         ORDER BY {$orderCol} ASC
         LIMIT 500"
    );
    $countRow = safeFetchOne($db, "SELECT COUNT(*) AS cnt FROM public.database_versioning");
    $versionCount = intval($countRow["cnt"] ?? 0);
}

$backups = listBackups($backupDir);
$manualBackups = listBackups($manualBackupDir);
$serverRestoreFiles = [];
foreach ($manualBackups as $backup) {
    $backup["source"] = "manual";
    $serverRestoreFiles[] = $backup;
}
foreach ($backups as $backup) {
    $backup["source"] = "db_backups";
    $serverRestoreFiles[] = $backup;
}
usort($serverRestoreFiles, static function ($a, $b) {
    return (intval($b["mtime"] ?? 0) <=> intval($a["mtime"] ?? 0));
});

$baseActionUrl = databaseManagerUrl($isEmbedded);
$baseActionSep = (strpos($baseActionUrl, "?") !== false) ? "&" : "?";
if ($dbmFragment) {
    $dbmStyleHref = $dbmWebRoot . "/ui/css/main.css";
    if (function_exists("dwemer_storage_fragment_style")) {
        dwemer_storage_fragment_style($dbmStyleHref);
    } else {
        echo '<link rel="stylesheet" href="' . h($dbmStyleHref) . '">';
    }
}
?>
<?php if (!$dbmFragment): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Manager</title>
    <link rel="icon" type="image/x-icon" href="<?= h($dbmWebRoot) ?>/ui/images/favicon.ico">
    <link rel="stylesheet" href="<?= h($dbmWebRoot) ?>/ui/css/main.css">
    <link rel="stylesheet" href="<?= h($dbmWebRoot) ?>/ui/css/navbar.css">
<?php endif; ?>
    <style>
        main {
            padding-top: <?= $isEmbedded ? "20px" : "120px" ?>;
            padding-bottom: 40px;
            padding-left: 10px;
            padding-right: 10px;
        }

        @font-face {
            font-family: "MagicCards";
            src: url("<?php echo h($dbmFragment ? DWEMER_STORAGE_FRAGMENT_WEBROOT . '/ui/css/font/MailartRubberstamp-Regular.otf' : 'css/font/MailartRubberstamp-Regular.otf'); ?>") format("opentype");
            font-weight: normal;
            font-style: normal;
        }

        h1, h2, h3 {
            font-family: "MagicCards", sans-serif;
            letter-spacing: 1.5px;
        }

        .panel {
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            padding: 18px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .stat-card {
            background: #2a2a2a;
            border: 1px solid #4a4a4a;
            border-radius: 8px;
            padding: 10px;
        }

        .stat-label {
            color: #aab2bb;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .stat-value {
            color: #e6b76c;
            font-weight: 700;
            font-size: 18px;
            margin-top: 2px;
        }

        .message {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid transparent;
            font-size: 13px;
        }

        .message.ok {
            background: rgba(54, 114, 60, 0.25);
            border-color: rgba(89, 160, 97, 0.5);
            color: #9be49f;
        }

        .message.error {
            background: rgba(145, 45, 45, 0.25);
            border-color: rgba(187, 76, 76, 0.5);
            color: #ffb0b0;
        }

        .section-grid {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(2, minmax(320px, 1fr));
            gap: 14px;
        }

        .section {
            background: rgba(20, 20, 20, 0.55);
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            padding: 12px;
        }

        .section h3 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .btn-base {
            cursor: pointer;
            padding: 7px 12px;
            border-radius: 6px;
            border: 1px solid #666;
            background: #3a3a3a;
            color: #f8f9fa;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .btn-primary { background: #2f5d87; border-color: #4677a4; }
        .btn-warning { background: #a04040; border-color: #804040; }
        .btn-danger { background: #7a1e1e; border-color: #5a1515; }

        .field-input, .field-select {
            padding: 6px 8px;
            border: 1px solid #595959;
            border-radius: 6px;
            background: #2a2a2a;
            color: #f8f9fa;
            font-size: 12px;
        }

        .backup-list {
            max-height: 320px;
            overflow: auto;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            background: rgba(14, 14, 14, 0.55);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 2;
            text-align: left;
            padding: 8px;
            background: rgba(26, 26, 26, 0.95);
            color: #e6b76c;
            border-bottom: 1px solid rgba(230, 183, 108, 0.35);
        }

        td {
            padding: 8px;
            border-bottom: 1px solid rgba(74, 74, 74, 0.35);
            color: #f8f9fa;
            vertical-align: top;
        }

        tr:hover td {
            background: rgba(230, 183, 108, 0.08);
        }

        .mono {
            font-family: Consolas, "Courier New", monospace;
            font-size: 11px;
            word-break: break-word;
        }

        .help {
            color: #aab2bb;
            font-size: 12px;
            margin: 0 0 8px 0;
        }

        .link-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
            margin-bottom: 2px;
        }

        @media (max-width: 1100px) {
            .section-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
<?php if (!$dbmFragment): ?>
</head>
<body>
<?php endif; ?>
<?php if (!$isEmbedded): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main class="container-fluid">
    <div class="panel">
        <?php if ($dbmFragment): ?><h2>STOBE database tools</h2><?php else: ?><h1>Database Manager</h1><?php endif; ?>
        <p class="help">High-level controls for backup, restore, maintenance, and versioning of the <code>stobe</code> database.</p>
        <div class="link-row">
            <a class="btn-base btn-primary" href="/pgAdmin/" target="_blank" rel="noopener noreferrer">Open pgAdmin</a>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-label">Database</div>
                <div class="stat-value"><?= h($dbName) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Database Size</div>
                <div class="stat-value"><?= h(formatFileSize($dbSizeBytes)) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Public Tables</div>
                <div class="stat-value"><?= h((string)$tableCount) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Version Entries</div>
                <div class="stat-value"><?= h((string)$versionCount) ?></div>
            </div>
        </div>

        <?php if ($message !== ""): ?>
            <div class="message <?= h($messageType) ?>"><?= h($message) ?></div>
        <?php endif; ?>

        <div class="section-grid">
            <section class="section">
                <h3>Backup</h3>
                <p class="help">Create full SQL backups using <code>pg_dump</code>. Files are stored under <code>StobeServer/data/db_backups</code>.</p>
                <form method="post" class="row">
                    <input type="hidden" name="action" value="create_backup">
                    <button type="submit" class="btn-base btn-primary">Create Backup</button>
                </form>

                <div class="backup-list">
                    <table>
                        <thead>
                        <tr>
                            <th>File</th>
                            <th>Size</th>
                            <th>Modified (UTC)</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($backups) === 0): ?>
                            <tr><td colspan="4">No backup files found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($backups as $bk): ?>
                                <tr>
                                    <td class="mono"><?= h($bk["filename"]) ?></td>
                                    <td><?= h(formatFileSize($bk["size"])) ?></td>
                                    <td><?= h(gmdate("d-m-Y H:i:s", intval($bk["mtime"]))) ?></td>
                                    <td>
                                        <a class="btn-base btn-primary" href="<?= h($baseActionUrl . $baseActionSep . "action=download_backup&file=" . urlencode($bk["filename"])) ?>">Download</a>
                                        <a class="btn-base btn-danger" href="<?= h($baseActionUrl . $baseActionSep . "action=delete_backup&file=" . urlencode($bk["filename"])) ?>" onclick="return confirm('Delete this backup file?');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="section">
                <h3>Restore</h3>
                <p class="help">Upload a SQL backup and restore into <code>stobe</code>. This operation is destructive if the SQL contains drop/truncate statements.</p>
                <form method="post" enctype="multipart/form-data" class="row" onsubmit="return confirm('Restore database from uploaded file?');">
                    <input type="hidden" name="action" value="restore_uploaded_backup">
                    <input class="field-input" type="file" name="restore_file" accept=".sql,.gz,.sql.gz" required>
                    <button type="submit" class="btn-base btn-warning">Restore Uploaded Backup</button>
                </form>

                <p class="help" style="margin-top: 12px;">Restore directly from server-side files in <code>data/db_backups</code> or <code>ui/data/manualbackup</code>.</p>
                <form method="post" class="row" onsubmit="return confirm('Restore database from selected server file?');">
                    <input type="hidden" name="action" value="restore_server_backup">
                    <select class="field-select" name="restore_server_file" required>
                        <option value="">Select server backup file...</option>
                        <?php foreach ($serverRestoreFiles as $file): ?>
                            <option value="<?= h(strval($file["filename"] ?? "")) ?>">
                                <?= h(strval($file["filename"] ?? "")) ?> [<?= h(strval($file["source"] ?? "db_backups")) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select class="field-select" name="restore_source" required>
                        <option value="db_backups">Source: data/db_backups</option>
                        <option value="manual">Source: ui/data/manualbackup</option>
                    </select>
                    <button type="submit" class="btn-base btn-warning">Restore Server File</button>
                </form>

                <h3 style="margin-top:18px;">Maintenance</h3>
                <p class="help">Run PostgreSQL maintenance commands.</p>
                <form method="post" class="row">
                    <input type="hidden" name="action" value="vacuum_analyze">
                    <button type="submit" class="btn-base btn-primary">Run VACUUM ANALYZE</button>
                </form>
                <form method="post" class="row" onsubmit="return confirm('Run REINDEX DATABASE stobe?');">
                    <input type="hidden" name="action" value="reindex_database">
                    <button type="submit" class="btn-base btn-warning">Run REINDEX DATABASE</button>
                </form>

                <h3 style="margin-top:18px;">Factory Reset</h3>
                <p class="help">Drop and recreate the public schema, then replay Stobe database updates. This permanently removes current DB data.</p>
                <form method="post" class="row" onsubmit="return confirm('FACTORY RESET DATABASE?\n\nThis will wipe current Stobe data and rebuild schema defaults.\nThis action cannot be undone. Continue?');">
                    <input type="hidden" name="action" value="factory_reset_database">
                    <button type="submit" class="btn-base btn-danger">Factory Reset Database</button>
                </form>
            </section>
        </div>

        <section class="section" style="margin-top:14px;">
            <h3>Database Versioning</h3>
            <p class="help">Manage entries in <code>public.database_versioning</code> used by update scripts.</p>

            <?php if (!$versioningExists || $versionTableCol === ""): ?>
                <p class="help">Table <code>public.database_versioning</code> not found or not compatible.</p>
            <?php else: ?>
                <div class="row">
                    <form method="post" class="row" onsubmit="return confirm('Reset selected version entry?');">
                        <input type="hidden" name="action" value="reset_db_version">
                        <select class="field-select" name="version_table" required>
                            <option value="">Select table entry...</option>
                            <?php foreach ($versionRows as $vr): ?>
                                <option value="<?= h(strval($vr["table_name"] ?? "")) ?>">
                                    <?= h(strval($vr["table_name"] ?? "")) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-base btn-warning">Reset Selected</button>
                    </form>

                    <form method="post" class="row" onsubmit="return confirm('Reset ALL version entries?');">
                        <input type="hidden" name="action" value="reset_all_db_versions">
                        <button type="submit" class="btn-base btn-danger">Reset All</button>
                    </form>
                </div>

                <div class="backup-list" style="margin-top:8px;">
                    <table>
                        <thead>
                        <tr>
                            <th>Table</th>
                            <?php if ($versionNumberCol !== ""): ?><th>Version</th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($versionRows) === 0): ?>
                            <tr><td colspan="<?= $versionNumberCol !== "" ? "2" : "1" ?>">No versioning entries.</td></tr>
                        <?php else: ?>
                            <?php foreach ($versionRows as $vr): ?>
                                <tr>
                                    <td class="mono"><?= h(strval($vr["table_name"] ?? "")) ?></td>
                                    <?php if ($versionNumberCol !== ""): ?>
                                        <td><?= h(strval($vr["version_value"] ?? "")) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php if (!$dbmFragment): ?>
</body>
</html>
<?php endif; ?>
