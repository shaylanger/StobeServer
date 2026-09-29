<?php
/**
 * StobeServer Playthrough Saves.
 * Schema-clone playthrough manager with rollback automatic playthrough save visibility.
 */

// Shared "Playthrough Saves" fragment mode. The Dwemer Dashboard includes this
// page in-process and renders its controls inside the shared shell, so only the
// document chrome and asset URLs adapt while server-owned operations stay here.
$ptmFragment = defined('DWEMER_STORAGE_FRAGMENT') && DWEMER_STORAGE_FRAGMENT === true;
if (!$ptmFragment) {
    // Shared compatibility policy lives in one place: redirect a bookmarked view,
    // refuse stale writes, and stay standalone when the Dashboard is absent.
    $ptmRouteHelper = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib'
        . DIRECTORY_SEPARATOR . 'storage_manager_route.php';
    if (is_file($ptmRouteHelper)) {
        require_once $ptmRouteHelper;
        dwemerStorageRedirect('stobe', 'manage');
    }
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['ptm_csrf'])) $_SESSION['ptm_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['ptm_csrf'];

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . 'lib/bootstrap.php');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') require_once($path . 'debug/db_updates.php');
require_once($path . 'lib/playthrough_storage.php');

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatBytesHuman(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = max(0, $bytes);
    $idx = 0;
    while ($size >= 1024 && $idx < count($units) - 1) {
        $size /= 1024;
        $idx++;
    }
    return number_format($size, $idx > 0 ? 2 : 0) . ' ' . $units[$idx];
}

function boolish(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return intval($value) !== 0;
    }
    $normalized = strtolower(trim(strval($value)));
    return in_array($normalized, ['1', 'true', 't', 'yes', 'on'], true);
}

function decodePlaythroughMemberNames(mixed $value): array
{
    if (is_array($value)) {
        $rawItems = $value;
    } else {
        $raw = trim(strval($value));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $rawItems = $decoded;
    }

    $memberMap = [];
    foreach ($rawItems as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        $name = trim($entry);
        if ($name === '') {
            continue;
        }
        $key = strtolower($name);
        if (!isset($memberMap[$key])) {
            $memberMap[$key] = $name;
        }
    }

    if (count($memberMap) === 0) {
        return [];
    }
    $members = array_values($memberMap);
    natcasesort($members);
    return array_values($members);
}

$isEmbedded = $ptmFragment || (isset($_GET['embed']) && strval($_GET['embed']) === '1');

$status = '';
$statusClass = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim(strval($_POST['action'] ?? ''));
    $profileId = intval($_POST['profile_id'] ?? 0);

    if ($action === 'create_playthrough') {
        $name = trim(strval($_POST['name'] ?? ''));
        $notes = trim(strval($_POST['notes'] ?? ''));
        $result = stobePlaythroughCreate($name, $notes, [
            'mark_active' => false,
            'storage_type' => 'schema',
            'game' => 'Kenshi',
        ]);
        if (boolish($result['success'] ?? false)) {
            $statusClass = 'success';
            $status = 'Playthrough Save created: ' . strval($result['name'] ?? '') . ' (ID ' . strval(intval($result['id'] ?? 0)) . ')';
        } else {
            $statusClass = 'error';
            $status = 'Could not create Playthrough Save: ' . strval($result['error'] ?? 'unknown');
        }
    } elseif ($action === 'switch_profile' && $profileId > 0) {
        $result = stobePlaythroughSwitchToProfile($profileId, true);
        if (boolish($result['success'] ?? false)) {
            $statusClass = 'success';
            $autosaveId = intval($result['autosave_id'] ?? 0);
            $status = !empty($result['runtime_ready'])
                ? 'Playthrough Save restored. Background processing refreshed. Load the matching Kenshi save.'
                : 'Playthrough Save restored. Warning: background processing could not be confirmed. Restart the Stobe server before loading the matching Kenshi save.';
            if ($autosaveId > 0) {
                $status .= ' Before-Switch Save ID: ' . $autosaveId . '.';
            }
        } else {
            $statusClass = 'error';
            $status = 'Profile switch failed: ' . strval($result['error'] ?? 'unknown');
        }
    } elseif ($action === 'delete_profile' && $profileId > 0) {
        $result = stobePlaythroughDeleteProfile($profileId);
        if (boolish($result['success'] ?? false)) {
            $statusClass = 'success';
            $status = 'Playthrough Save deleted.';
        } else {
            $statusClass = 'error';
            $status = 'Delete failed: ' . strval($result['error'] ?? 'unknown');
        }
    }
}

// API callers stop before rendering or loading the legacy playthrough list.
if (defined('DWEMER_STORAGE_ACTIONS_ONLY')) {
    return ['ok' => $statusClass === 'success', 'message' => $status];
}
$profiles = stobePlaythroughListProfiles(1000, false);
$activeName = stobePlaythroughCurrentActiveProfileName(false);

$lastSeenGamets = intval(getConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '0'));
$lastRollbackGamets = intval(getConfOpt('PLAYTHROUGH_LAST_ROLLBACK_GAMETS', '0'));
$lastRollbackFrom = intval(getConfOpt('PLAYTHROUGH_LAST_ROLLBACK_FROM_GAMETS', '0'));
$lastRollbackDelta = intval(getConfOpt('PLAYTHROUGH_LAST_ROLLBACK_DELTA_GAMETS', '0'));
$lastRollbackTs = intval(getConfOpt('PLAYTHROUGH_LAST_ROLLBACK_TS', '0'));
$lastSeenTs = intval(getConfOpt('PLAYTHROUGH_LAST_SEEN_TS', '0'));

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : '';
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');
if ($ptmFragment) {
    // The shared page lives under a different path, so assets need this
    // server's own web root instead of a document-relative URL.
    $webRoot = DWEMER_STORAGE_FRAGMENT_WEBROOT;
    foreach ([
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        $webRoot . '/ui/css/main.css',
    ] as $ptmStyleHref) {
        if (function_exists('dwemer_storage_fragment_style')) {
            dwemer_storage_fragment_style($ptmStyleHref);
        } else {
            echo '<link rel="stylesheet" href="' . h($ptmStyleHref) . '">';
        }
    }
}
?>
<?php if (!$ptmFragment): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Playthrough Saves</title>
    <link rel="icon" type="image/x-icon" href="<?= h($webRoot) ?>/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= h($webRoot) ?>/ui/css/main.css">
    <link rel="stylesheet" href="<?= h($webRoot) ?>/ui/css/navbar.css">
<?php endif; ?>
    <style>
        main {
            padding-top: <?= $isEmbedded ? '20px' : '96px' ?>;
            padding-bottom: 28px;
            padding-left: 10px;
            padding-right: 10px;
        }

        .page-grid {
            display: grid;
            grid-template-columns: minmax(320px, 420px) minmax(0, 1fr);
            gap: 14px;
            align-items: start;
        }

        @media (max-width: 1100px) {
            .page-grid {
                grid-template-columns: 1fr;
            }
        }

        .panel {
            background: rgba(22, 24, 30, 0.9);
            border: 1px solid rgba(230, 183, 108, 0.3);
            border-radius: 10px;
            padding: 14px;
            box-shadow: 0 0 18px rgba(0, 0, 0, 0.25);
        }

        .panel h1,
        .panel h2,
        .panel h3 {
            margin: 0 0 8px;
            color: #fff;
            letter-spacing: .5px;
        }

        .subtitle {
            color: #cfd5de;
            margin: 0 0 12px;
            font-size: 0.95rem;
        }

        .status {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid transparent;
        }

        .status.success {
            background: rgba(25, 78, 45, 0.25);
            border-color: rgba(49, 163, 92, 0.55);
            color: #b5f0cc;
        }

        .status.error {
            background: rgba(90, 23, 23, 0.3);
            border-color: rgba(204, 87, 87, 0.55);
            color: #ffd4d4;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 12px;
            margin-bottom: 12px;
        }

        .meta-label {
            color: #9ca3af;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        .meta-value {
            color: #f8f9fa;
            font-size: 0.95rem;
            line-height: 1.35;
            word-break: break-word;
        }

        .form-label {
            color: #f1f1f1;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        .form-control {
            background: rgba(7, 10, 16, 0.85);
            color: #f8f9fa;
            border-color: rgba(230, 183, 108, 0.35);
        }

        .form-control:focus {
            background: rgba(7, 10, 16, 0.95);
            color: #fff;
            border-color: #e6b76c;
            box-shadow: 0 0 0 .2rem rgba(230, 183, 108, 0.15);
        }

        .btn-stobe {
            border: 1px solid rgba(230, 183, 108, 0.45);
            background: linear-gradient(180deg, rgba(36, 40, 48, .95), rgba(20, 24, 30, .98));
            color: #fff;
        }

        .btn-stobe:hover {
            border-color: rgba(230, 183, 108, 0.7);
            color: #fff;
            box-shadow: 0 0 10px rgba(230, 183, 108, 0.25);
        }

        .btn-danger-soft {
            border: 1px solid rgba(221, 95, 95, 0.5);
            background: rgba(88, 24, 24, 0.45);
            color: #ffd7d7;
        }

        .btn-danger-soft:hover {
            color: #fff;
            border-color: rgba(250, 130, 130, 0.75);
            background: rgba(110, 30, 30, 0.6);
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid rgba(230, 183, 108, 0.25);
            border-radius: 8px;
        }

        table {
            margin: 0;
            width: 100%;
            border-collapse: collapse;
            min-width: 1240px;
        }

        th,
        td {
            padding: 9px 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            vertical-align: top;
            color: #f3f5f8;
            font-size: .9rem;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: rgba(26, 30, 36, 0.98);
            color: #fff;
            text-transform: uppercase;
            letter-spacing: .6px;
            font-size: .78rem;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.03);
        }

        .badge-active {
            display: inline-block;
            border: 1px solid rgba(78, 204, 141, 0.6);
            color: #c5f9df;
            background: rgba(24, 90, 57, 0.25);
            border-radius: 999px;
            padding: 1px 9px;
            font-size: .72rem;
            font-weight: 600;
            letter-spacing: .4px;
        }

        .small-muted {
            color: #a8b0bc;
            font-size: .78rem;
        }

        .member-list {
            color: #a8b0bc;
            font-size: .78rem;
            white-space: normal;
            word-break: break-word;
            max-width: 320px;
            line-height: 1.3;
        }

        .action-stack {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .empty {
            color: #c3c7cf;
            padding: 20px;
            text-align: center;
        }
    </style>
<?php if (!$ptmFragment): ?>
</head>
<body>
<?php endif; ?>
<?php if (!$isEmbedded): ?>
    <?php include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php'); ?>
<?php endif; ?>

<main class="container-fluid">
    <div class="indent5">
        <div class="panel" style="margin-bottom: 12px;">
            <?php if ($ptmFragment): ?><h2>Playthrough Saves</h2><?php else: ?><h1>Playthrough Saves</h1><?php endif; ?>
            <p class="subtitle">Save and restore STOBE data. Use each Playthrough Save with its matching Kenshi save.</p>
            <?php if ($status !== ''): ?>
                <div class="status <?= h($statusClass) ?>"><?= h($status) ?></div>
            <?php endif; ?>
        </div>

        <div class="page-grid">
            <section class="panel">
                <h2>New Playthrough Save</h2>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="create_playthrough">
                    <div class="mb-3">
                        <label class="form-label" for="name">Save name</label>
                        <input class="form-control" id="name" name="name" maxlength="220" placeholder="e.g., Before a big battle">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="4" placeholder="Optional notes"></textarea>
                    </div>
                    <button class="btn btn-stobe w-100" type="submit">New Playthrough Save</button>
                </form>

                <hr style="border-color: rgba(230,183,108,.25)">

                <h3>Runtime State</h3>
                <div class="meta-grid">
                    <div>
                        <div class="meta-label">Active Profile</div>
                        <div class="meta-value"><?= h($activeName !== '' ? $activeName : 'None') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Last Seen Gamets</div>
                        <div class="meta-value"><?= h($lastSeenGamets > 0 ? stobeGametsDisplayWithRaw($lastSeenGamets) : 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Last Rollback To</div>
                        <div class="meta-value"><?= h($lastRollbackGamets > 0 ? stobeGametsDisplayWithRaw($lastRollbackGamets) : 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Last Rollback From</div>
                        <div class="meta-value"><?= h($lastRollbackFrom > 0 ? stobeGametsDisplayWithRaw($lastRollbackFrom) : 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Rollback Delta</div>
                        <div class="meta-value"><?= h($lastRollbackDelta > 0 ? number_format($lastRollbackDelta) . ' gamets' : 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Last Rollback TS</div>
                        <div class="meta-value"><?= h($lastRollbackTs > 0 ? gmdate('Y-m-d H:i:s', $lastRollbackTs) . ' UTC' : 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Last Seen TS</div>
                        <div class="meta-value"><?= h($lastSeenTs > 0 ? gmdate('Y-m-d H:i:s', $lastSeenTs) . ' UTC' : 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Current Game</div>
                        <div class="meta-value">Kenshi</div>
                    </div>
                </div>
                <div class="small-muted">Before restoring, STOBE saves your current progress as a new Before-Switch Save.</div>
            </section>

            <section class="panel">
                <h2>Playthrough Saves</h2><button type="button" class="ptx-import">Import save</button>
                <div class="table-wrap">
                    <?php if (count($profiles) === 0): ?>
                        <div class="empty">No Playthrough Saves yet.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Created</th>
                                    <th>Size</th>
                                    <th>Last Gamets</th>
                                    <th>Rows</th>
                                    <th>Player Faction Members</th>
                                    <th>Storage</th>
                                    <th>Rollback</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profiles as $row): ?>
                                    <?php
                                        $id = intval($row['id'] ?? 0);
                                        $isActive = boolish($row['is_active'] ?? false);
                                        $playthroughName = strval($row['name'] ?? '');
                                        $playthroughNameDisplay = preg_replace('/^Dragon Break\\s*\\(/i', 'Automatic Playthrough Save (', $playthroughName, 1);
                                        if (!is_string($playthroughNameDisplay) || $playthroughNameDisplay === '') {
                                            $playthroughNameDisplay = $playthroughName;
                                        }
                                        $createdAt = trim(strval($row['created_at'] ?? ''));
                                        $sizeBytes = intval($row['size_bytes'] ?? 0);
                                        $lastGamets = intval($row['last_gamets'] ?? 0);
                                        $eventCount = intval($row['eventlog_count'] ?? 0);
                                        $oghmaCount = intval($row['oghma_count'] ?? 0);
                                        $playerFactionMembers = decodePlaythroughMemberNames($row['player_faction_members'] ?? '[]');
                                        $storageType = trim(strval($row['storage_type'] ?? 'schema'));
                                        $schemaName = trim(strval($row['schema_name'] ?? ''));
                                        $schemaNameDisplay = $schemaName;
                                        $stobeProfilePrefix = 'stobe_profile_';
                                        $stobeProfilePrefixLength = strlen($stobeProfilePrefix);
                                        if (strtolower(substr($schemaNameDisplay, 0, $stobeProfilePrefixLength)) === $stobeProfilePrefix) {
                                            $schemaNameDisplay = $stobeProfilePrefix . substr($schemaNameDisplay, $stobeProfilePrefixLength);
                                        }
                                        $rollbackDays = intval($row['rollback_delta_days'] ?? 0);
                                        $rollbackFrom = intval($row['rollback_from_gamets'] ?? 0);
                                        $rollbackTo = intval($row['rollback_to_gamets'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?= $id ?></td>
                                        <td>
                                            <strong><?= h($playthroughNameDisplay) ?></strong>
                                            <?php if ($isActive): ?>
                                                <span class="badge-active">ACTIVE</span>
                                            <?php endif; ?>
                                            <?php if (trim(strval($row['notes'] ?? '')) !== ''): ?>
                                                <div class="small-muted"><?= h(strval($row['notes'] ?? '')) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($createdAt !== '' ? $createdAt : '-') ?></td>
                                        <td><?= h(formatBytesHuman($sizeBytes)) ?></td>
                                        <td><?= h($lastGamets > 0 ? stobeGametsDisplayWithRaw($lastGamets) : '-') ?></td>
                                        <td><?= h(number_format($eventCount) . ' events / ' . number_format($oghmaCount) . ' knowledge') ?></td>
                                        <td>
                                            <?php if (count($playerFactionMembers) > 0): ?>
                                                <div><?= h(strval(count($playerFactionMembers))) ?> member(s)</div>
                                                <div class="member-list"><?= h(implode(', ', $playerFactionMembers)) ?></div>
                                            <?php else: ?>
                                                <span class="small-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?= h($storageType) ?></div>
                                            <div class="small-muted"><?= h($schemaNameDisplay !== '' ? $schemaNameDisplay : '-') ?></div>
                                        </td>
                                        <td>
                                            <?php if ($rollbackDays > 0): ?>
                                                <div><?= h(number_format($rollbackDays)) ?> day(s)</div>
                                                <div class="small-muted"><?= h($rollbackFrom) ?> â†’ <?= h($rollbackTo) ?></div>
                                            <?php else: ?>
                                                <span class="small-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-stack"><button type="button" class="ptx-download" data-profile-id="<?= $id ?>">Download</button>
                                                <form method="post" onsubmit="return confirm('Restore this Playthrough Save? Stop Kenshi first. STOBE makes a new Before-Switch Save of your current progress, then restores this save. Load the matching Kenshi save afterwards.');">
                                                    <input type="hidden" name="action" value="switch_profile">
                                                    <input type="hidden" name="profile_id" value="<?= $id ?>">
                                                    <button class="btn btn-sm btn-stobe" type="submit">Restore</button>
                                                </form>
                                                <form method="post" onsubmit="return confirm('Permanently delete this Playthrough Save? This cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete_profile">
                                                    <input type="hidden" name="profile_id" value="<?= $id ?>">
                                                    <button class="btn btn-sm btn-danger-soft" type="submit">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
<?php include __DIR__ . '/tmpl/playthrough_transfer_controls.php'; include __DIR__ . '/tmpl/playthrough_save_controls.php'; ?></main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!$ptmFragment): ?>
</body>
</html>
<?php endif; ?>
