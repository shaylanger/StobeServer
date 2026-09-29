<?php
/**
 * StobeServer main dashboard.
 * Herika-style home layout adapted for Stobe tables.
 */

error_reporting(E_ALL);

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

try {
    require_once($path . "debug/db_updates.php");
} catch (Throwable $exception) {
    stobeLogException($exception, "Dashboard db update check failed");
}

if (count($_GET) === 0) {
    if (function_exists('stobeEnsureBackgroundProcessorRunning')) {
        stobeEnsureBackgroundProcessorRunning(true);
    }
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$webRoot = dirname(dirname($scriptPath));
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

$pageTitle = "Stobe Dashboard";
$db = $GLOBALS["db"];

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safeRows(sql $db, string $query): array
{
    try {
        return $db->fetchAll($query);
    } catch (Throwable $exception) {
        return [];
    }
}

function safeCount(sql $db, string $query): int
{
    try {
        $row = $db->fetchOne($query);
        return intval($row['total'] ?? 0);
    } catch (Throwable $exception) {
        return 0;
    }
}

function formatLlmStats(array $rows): string
{
    if (count($rows) === 0 || !isset($rows[0])) {
        return "0/0 (0%)";
    }

    $success = intval($rows[0]['llm_requests_success'] ?? 0);
    $total = intval($rows[0]['total_requests'] ?? 0);
    $percentage = $total > 0 ? round(($success / $total) * 100) : 0;
    return "{$success}/{$total} ({$percentage}%)";
}

function readVersionFile(string $path): string
{
    if (!file_exists($path)) {
        return '';
    }
    return trim((string)file_get_contents($path));
}

function render_widget(string $title, string $content, string $type = 'default', array $options = []): string
{
    $widgetClass = "widget widget-{$type}";
    if (isset($options['class']) && is_string($options['class']) && $options['class'] !== '') {
        $widgetClass .= " " . $options['class'];
    }

    $html = "<div class='{$widgetClass}'>";
    $html .= "<div class='widget-header'>";
    $html .= "<h3>{$title}</h3>";
    if (isset($options['actions'])) {
        $html .= "<div class='widget-actions'>{$options['actions']}</div>";
    }
    $html .= "</div>";
    $html .= "<div class='widget-content'>{$content}</div>";
    $html .= "</div>";
    return $html;
}

function truncateText(string $value, int $maxLen = 140): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (strlen($value) <= $maxLen) {
        return $value;
    }
    return substr($value, 0, $maxLen - 3) . '...';
}

function formatUtcFromTs(mixed $ts): string
{
    $safe = intval($ts);
    if ($safe <= 0) {
        return 'N/A';
    }
    return gmdate('jS F, Y, H:i', $safe);
}

function buildWordCloudData(array $rows): array
{
    $wordFrequencies = [];
    foreach ($rows as $row) {
        $text = (string)($row['data'] ?? '');
        if ($text === '') {
            continue;
        }

        $text = preg_replace('/\([^)]+\)/', '', $text);
        $text = preg_replace('/^[^:]+:/', '', $text);
        $text = trim($text);
        if ($text === '') {
            continue;
        }
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s\']/', '', $text);
        $text = preg_replace('/\s\'|\'(\s|$)|(\'+)/', ' ', $text);
        $words = preg_split('/\s+/', $text);
        if (!is_array($words)) {
            continue;
        }

        $words = array_filter($words, function ($word) {
            $stopWords = [
                'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have', 'i', 'it', 'for',
                'not', 'on', 'with', 'he', 'as', 'you', 'do', 'at', 'this', 'but', 'his',
                'by', 'from', 'they', 'we', 'say', 'her', 'she', 'or', 'an', 'will', 'my',
                'one', 'all', 'would', 'there', 'their', 'what', 'so', 'up', 'out', 'if',
                'about', 'who', 'get', 'which', 'go', 'me', 'when', 'make', 'can', 'like',
                'time', 'no', 'just', 'him', 'know', 'take', 'people', 'into', 'year', 'your',
                'good', 'some', 'could', 'them', 'see', 'other', 'than', 'then', 'now', 'look',
                'only', 'come', 'its', 'over', 'think', 'also', 'back', 'after', 'use', 'two',
                'how', 'our', 'work', 'first', 'well', 'way', 'even', 'new', 'want', 'because',
                'any', 'these', 'give', 'day', 'most', 'us', 'im', 'ive', 'are', 'was', 'been',
                'had', 'has', 'yes', 'ok', 'okay', 'oh', 'ah', 'hmm', 'uh', 'er', 'um',
                'whats', 'thats', 'youre', 'dont', 'cant', 'wont', 'shouldnt', 'couldnt',
                'wouldnt', 'lets', 'theres', 'heres', 'wheres', 'whos', 'nobodys', 'everybodys',
                'talking', 'talk', 'said', 'says', 'tell', 'told', 'went', 'gone', 'coming',
                'going', 'doing', 'done', 'being', 'having', 'getting', 'putting', 'taking',
                'making', 'finding', 'found', 'made', 'put', 'took', 'got', 'goes'
            ];
            return strlen($word) > 2 && !in_array($word, $stopWords, true);
        });

        // Count in place instead of copying all previous words for every row.
        foreach ($words as $word) {
            $wordFrequencies[$word] = ($wordFrequencies[$word] ?? 0) + 1;
        }
    }

    if (empty($wordFrequencies)) {
        return [];
    }

    arsort($wordFrequencies);
    $wordFrequencies = array_slice($wordFrequencies, 0, 100, true);

    return array_map(function ($word, $count) {
        return [
            'text' => $word,
            'size' => log($count * 5) * 8 + 20,
            'count' => $count
        ];
    }, array_keys($wordFrequencies), array_values($wordFrequencies));
}

$stats = [
    'events_total' => safeCount($db, "SELECT COUNT(*) AS total FROM eventlog"),
    'deaths_total' => safeCount($db, "SELECT COUNT(*) AS total FROM eventlog WHERE LOWER(type) = 'death'"),
    'limbs_lost_total' => safeCount($db, "SELECT COUNT(*) AS total FROM eventlog WHERE LOWER(type) = 'limb_loss'"),
    'diaries_total' => safeCount($db, "SELECT COUNT(*) AS total FROM diarylog"),
    'npcs_total' => safeCount($db, "SELECT COUNT(*) AS total FROM core_npc"),
    'zones_total' => safeCount($db, "SELECT COUNT(*) AS total FROM location_zones WHERE metadata->>'knowledge_only' IS DISTINCT FROM 'true'"),
];

$latestDiaryRows = safeRows(
    $db,
    "SELECT rowid, topic, content, people AS author, localts, gamets
     FROM diarylog
     ORDER BY localts DESC
     LIMIT 1"
);
$latestDiary = count($latestDiaryRows) > 0 ? $latestDiaryRows[0] : [];

$wordSourceRows = safeRows(
    $db,
    "SELECT data
     FROM eventlog
     WHERE type = 'chat'
     ORDER BY localts DESC
     LIMIT 10000"
);
$wordCloud = buildWordCloudData($wordSourceRows);

$serverVersionDisplay = '';
$versionCandidates = [
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'versionnumber.txt',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'version.txt',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . '.version_number.txt',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . '.version.txt',
];
foreach ($versionCandidates as $versionPath) {
    $candidate = readVersionFile($versionPath);
    if ($candidate !== '') {
        $serverVersionDisplay = trim(str_ireplace('dev', '', $candidate));
        break;
    }
}
if ($serverVersionDisplay === '') {
    $serverVersionDisplay = '1.3.1';
}
$serverReleaseDate = readVersionFile(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'release_date.txt');
if ($serverReleaseDate === '') {
    $serverReleaseDate = '2026-09-19';
}

$pluginVersionDisplay = 'N/A';
try {
    $pluginVersionRow = $db->fetchOne("SELECT value FROM conf_opts WHERE id='plugin_dll_version' LIMIT 1");
    if ($pluginVersionRow && isset($pluginVersionRow['value'])) {
        $candidate = trim((string)$pluginVersionRow['value']);
        if ($candidate !== '' && strlen($candidate) <= 16) {
            $pluginVersionDisplay = $candidate;
        }
    }
} catch (Throwable $exception) {
    // Keep default N/A.
}

$activeModelSlot = '1';
try {
    $activeModelRow = $db->fetchOne(
        "SELECT value FROM conf_opts WHERE id='stobe_profile_model' LIMIT 1"
    );
    $candidate = trim(strval(is_array($activeModelRow) ? ($activeModelRow['value'] ?? '') : ''));
    if (in_array($candidate, ['1', '2', '3', '4'], true)) {
        $activeModelSlot = $candidate;
    }
} catch (Throwable $exception) {
    // Keep the standard model fallback.
}
$activeModelLabels = [
    '1' => 'Standard',
    '2' => 'Fast',
    '3' => 'Powerful',
    '4' => 'Experimental',
];
$activeModelDisplay = $activeModelLabels[$activeModelSlot];

$playerFactionSquadmateCount = safeCount(
    $db,
    "SELECT COUNT(*) AS total
     FROM core_npc_master n
     INNER JOIN core_profiles p ON p.id = n.profile_id
     WHERE COALESCE(p.is_player_faction_profile, FALSE) = TRUE"
);

$currentPlayerBase = function_exists('stobeGetCurrentPlayerBaseState')
    ? stobeGetCurrentPlayerBaseState(30)
    : [];
$playerBaseRows = '';
if (count($currentPlayerBase) > 0) {
    $generated = floatval($currentPlayerBase['power_generated'] ?? 0);
    $required = floatval($currentPlayerBase['power_required'] ?? 0);
    $surplus = $generated - $required;
    $powerStatus = $surplus >= 0
        ? ('surplus ' . number_format($surplus, 1))
        : ('deficit ' . number_format(abs($surplus), 1));
    $playerBaseRows .= '<tr><td>Current Player Base</td><td>'
        . h($currentPlayerBase['name'] ?? 'Player Base') . '</td></tr>';
    $playerBaseRows .= '<tr><td>Base Power</td><td>'
        . h(number_format($generated, 1) . ' / ' . number_format($required, 1) . ' (' . $powerStatus . ')')
        . '</td></tr>';
    $batteryCapacity = floatval($currentPlayerBase['battery_capacity'] ?? 0);
    if ($batteryCapacity > 0) {
        $playerBaseRows .= '<tr><td>Base Battery</td><td>'
            . h(number_format(floatval($currentPlayerBase['battery_charge'] ?? 0), 1)
                . ' / ' . number_format($batteryCapacity, 1))
            . '</td></tr>';
        $playerBaseRows .= '<tr><td>Battery Flow</td><td>'
            . h('Charging ' . number_format(floatval($currentPlayerBase['battery_charging'] ?? 0), 1)
                . ' | Drain ' . number_format(floatval($currentPlayerBase['battery_drain'] ?? 0), 1))
            . '</td></tr>';
    }
    $playerBaseRows .= '<tr><td>Squadmates At Base</td><td>'
        . h(intval($currentPlayerBase['members_inside'] ?? 0)) . '</td></tr>';
    if (coerceBoolean($currentPlayerBase['has_gates'] ?? false)) {
        $playerBaseRows .= '<tr><td>Base Gates</td><td>'
            . (coerceBoolean($currentPlayerBase['gates_closed'] ?? false) ? 'Closed' : 'Open')
            . '</td></tr>';
    }
    $baseDetails = $currentPlayerBase['details'] ?? [];
    if (is_string($baseDetails)) {
        $decodedBaseDetails = json_decode($baseDetails, true);
        $baseDetails = is_array($decodedBaseDetails) ? $decodedBaseDetails : [];
    }
    if (is_array($baseDetails) && coerceBoolean($baseDetails['available'] ?? false)) {
        $security = is_array($baseDetails['security'] ?? null) ? $baseDetails['security'] : [];
        $infrastructure = is_array($baseDetails['infrastructure'] ?? null)
            ? $baseDetails['infrastructure']
            : [];
        $construction = is_array($baseDetails['construction'] ?? null)
            ? $baseDetails['construction']
            : [];
        $power = is_array($baseDetails['power'] ?? null) ? $baseDetails['power'] : [];
        $supplies = is_array($baseDetails['supplies'] ?? null) ? $baseDetails['supplies'] : [];
        $storage = is_array($baseDetails['storage'] ?? null) ? $baseDetails['storage'] : [];
        $production = is_array($baseDetails['production'] ?? null) ? $baseDetails['production'] : [];
        $farms = is_array($baseDetails['farms'] ?? null) ? $baseDetails['farms'] : [];
        $formatGroups = static function (mixed $candidate, callable $formatter): string {
            if (!is_array($candidate)) {
                return '';
            }
            $parts = [];
            foreach (array_slice($candidate, 0, 6) as $group) {
                if (is_array($group)) {
                    $summary = trim(strval($formatter($group)));
                    if ($summary !== '') {
                        $parts[] = $summary;
                    }
                }
            }
            if (count($candidate) > 6) {
                $parts[] = '+' . (count($candidate) - 6) . ' more';
            }
            return implode('; ', $parts);
        };

        $playerBaseRows .= '<tr><td>Base Security</td><td>'
            . h(
                'Alarm ' . ucfirst(strval($security['alarm_state'] ?? 'none'))
                . ' | Hostiles ' . intval($security['hostiles_inside'] ?? 0)
                . ' | Defenses damaged ' . intval($security['damaged_defenses'] ?? 0)
                . ', destroyed ' . intval($security['destroyed_defenses'] ?? 0)
                . ' | Turrets ' . intval($security['turrets_manned'] ?? 0)
                . '/' . intval($security['turrets_total'] ?? 0) . ' manned'
                . ', ' . intval($security['turrets_unpowered'] ?? 0) . ' unpowered'
            )
            . '</td></tr>';
        $infrastructureProblemCount = intval($infrastructure['damaged'] ?? 0)
            + intval($infrastructure['destroyed'] ?? 0)
            + intval($infrastructure['broken'] ?? 0)
            + intval($infrastructure['unpowered'] ?? 0);
        if ($infrastructureProblemCount > 0) {
            $issueGroups = $formatGroups(
                $infrastructure['issues'] ?? [],
                static fn (array $group): string => strval($group['name'] ?? 'Unknown')
                    . ' x' . intval($group['count'] ?? 0)
            );
            $playerBaseRows .= '<tr><td>Infrastructure Problems</td><td>'
                . h(
                    'Damaged ' . intval($infrastructure['damaged'] ?? 0)
                    . ' | Destroyed ' . intval($infrastructure['destroyed'] ?? 0)
                    . ' | Broken ' . intval($infrastructure['broken'] ?? 0)
                    . ' | Unpowered ' . intval($infrastructure['unpowered'] ?? 0)
                    . ($issueGroups !== '' ? ' | ' . $issueGroups : '')
                )
                . '</td></tr>';
        }
        if (intval($construction['total'] ?? 0) > 0) {
            $constructionGroups = $formatGroups(
                $construction['groups'] ?? [],
                static fn (array $group): string => strval($group['name'] ?? 'Unknown')
                    . ' x' . intval($group['count'] ?? 0)
                    . ' (' . number_format(floatval($group['average_progress'] ?? 0), 0) . '%)'
            );
            $playerBaseRows .= '<tr><td>Construction</td><td>'
                . h(
                    intval($construction['total'] ?? 0) . ' building'
                    . (intval($construction['total'] ?? 0) === 1 ? '' : 's')
                    . ' | Average ' . number_format(floatval($construction['average_progress'] ?? 0), 0) . '%'
                    . ' | Paused ' . intval($construction['paused'] ?? 0)
                    . ' | Need materials ' . intval($construction['missing_materials'] ?? 0)
                    . ($constructionGroups !== '' ? ' | ' . $constructionGroups : '')
                )
                . '</td></tr>';
        }
        $playerBaseRows .= '<tr><td>Power Resilience</td><td>'
            . h(
                'Generators ' . intval($power['generators_active'] ?? 0)
                . '/' . intval($power['generators_total'] ?? 0) . ' active'
                . ' | Consumers ' . intval($power['consumers'] ?? 0)
                . ' | Unpowered ' . intval($power['unpowered'] ?? 0)
                . ' | Switched off ' . intval($power['switched_off'] ?? 0)
            )
            . '</td></tr>';
        $playerBaseRows .= '<tr><td>Base Supplies</td><td>'
            . h(
                'Food ' . intval($supplies['food'] ?? 0)
                . ' | Medicine ' . intval($supplies['medicine'] ?? 0)
                . ' | Building materials ' . intval($supplies['building_materials'] ?? 0)
                . ' | Iron plates ' . intval($supplies['iron_plates'] ?? 0)
                . ' | Fuel ' . intval($supplies['fuel'] ?? 0)
                . ' | Water ' . intval($supplies['water'] ?? 0)
                . ' | Ammunition ' . intval($supplies['ammunition'] ?? 0)
            )
            . '</td></tr>';
        $storageGroups = $formatGroups(
            $storage['groups'] ?? [],
            static fn (array $group): string => strval($group['name'] ?? 'Unknown')
                . ' x' . intval($group['total'] ?? 0)
                . (intval($group['full'] ?? 0) > 0 ? ', ' . intval($group['full']) . ' full' : '')
        );
        $playerBaseRows .= '<tr><td>Storage</td><td>'
            . h(
                intval($storage['total'] ?? 0) . ' containers'
                . ' | Empty ' . intval($storage['empty'] ?? 0)
                . ' | Full ' . intval($storage['full'] ?? 0)
                . ' | Stored units ' . intval($storage['item_units'] ?? 0)
                . ($storageGroups !== '' ? ' | ' . $storageGroups : '')
            )
            . '</td></tr>';
        $productionGroups = $formatGroups(
            $production['groups'] ?? [],
            static fn (array $group): string => strval($group['name'] ?? 'Unknown')
                . ' ' . intval($group['active'] ?? 0) . '/' . intval($group['total'] ?? 0)
                . ' active'
        );
        $playerBaseRows .= '<tr><td>Production Health</td><td>'
            . h(
                intval($production['active'] ?? 0) . '/' . intval($production['total'] ?? 0)
                . ' active'
                . ' | Missing inputs ' . intval($production['input_blocked'] ?? 0)
                . ' | Output full ' . intval($production['output_blocked'] ?? 0)
                . ' | Unpowered ' . intval($production['unpowered'] ?? 0)
                . ' | Staffed ' . intval($production['staffed'] ?? 0)
                . ' | Efficiency ' . number_format(floatval($production['average_efficiency'] ?? 0), 0) . '%'
                . ($productionGroups !== '' ? ' | ' . $productionGroups : '')
            )
            . '</td></tr>';
        $farmGroups = $formatGroups(
            $farms['groups'] ?? [],
            static fn (array $group): string => strval($group['name'] ?? 'Unknown')
                . ' ' . intval($group['active'] ?? 0) . '/' . intval($group['total'] ?? 0)
                . ' active'
        );
        $playerBaseRows .= '<tr><td>Farm Status</td><td>'
            . h(
                intval($farms['active'] ?? 0) . '/' . intval($farms['total'] ?? 0)
                . ' active'
                . ' | Need water ' . intval($farms['needs_water'] ?? 0)
                . ' | Output full ' . intval($farms['output_full'] ?? 0)
                . ' | Unpowered ' . intval($farms['unpowered'] ?? 0)
                . ' | Staffed ' . intval($farms['staffed'] ?? 0)
                . ' | Hydroponic ' . intval($farms['hydroponic'] ?? 0)
                . ' | Average yield ' . number_format(floatval($farms['average_yield'] ?? 0), 0) . '%'
                . ($farmGroups !== '' ? ' | ' . $farmGroups : '')
            )
            . '</td></tr>';
        if (coerceBoolean($baseDetails['scan_truncated'] ?? false)) {
            $playerBaseRows .= '<tr><td>Base Scan</td><td>Limited by safety cap</td></tr>';
        }
    }
}

$latestTimelineRow = $db->fetchOne(
    "SELECT COALESCE(MAX(localts), 0) AS last_localts, COALESCE(MAX(gamets), 0) AS last_gamets
     FROM eventlog"
);
$lastPlayedUtc = formatUtcFromTs($latestTimelineRow['last_localts'] ?? 0);
$currentInGameTime = stobeGametsDateLabel(intval($latestTimelineRow['last_gamets'] ?? 0));
if (trim($currentInGameTime) === '') {
    $currentInGameTime = 'N/A';
}

$backgroundProcessorStatus = [
    'healthy' => false,
    'state' => 'unknown',
    'last_tick_age_seconds' => null,
];
try {
    if (function_exists('stobeBackgroundProcessorStatus')) {
        $backgroundProcessorStatus = stobeBackgroundProcessorStatus();
    }
} catch (Throwable $exception) {
    $backgroundProcessorStatus['state'] = 'unknown';
}
$backgroundProcessorState = strval($backgroundProcessorStatus['state'] ?? 'unknown');
$backgroundProcessorAge = $backgroundProcessorStatus['last_tick_age_seconds'] ?? null;
$backgroundProcessorDisplay = match ($backgroundProcessorState) {
    'running' => 'Running',
    'starting' => 'Starting (waiting for manager tick)',
    'stalled' => 'Stalled'
        . (is_int($backgroundProcessorAge) ? ' (last tick ' . $backgroundProcessorAge . 's ago)' : ''),
    'offline' => 'Not running',
    default => 'Unknown',
};

$currentPlaythroughContent = "
<div class='quest-list'>
    <h4>World Information</h4>
    <table class='widget-table'>
        <tr><th>Stats</th><th>Value</th></tr>
        <tr><td>Last Played (UTC)</td><td>" . h($lastPlayedUtc) . "</td></tr>
        <tr><td>Current In-Game Time</td><td>" . h($currentInGameTime) . "</td></tr>
        <tr><td>STOBE Active Model</td><td>" . h($activeModelDisplay) . "</td></tr>
        <tr><td>Player Faction Squadmates</td><td>" . h($playerFactionSquadmateCount) . "</td></tr>
        {$playerBaseRows}
        <tr><td>Background Processor</td><td>" . h($backgroundProcessorDisplay) . "</td></tr>
    </table>
</div>";

$recentDialogue = safeRows(
    $db,
    "SELECT data, localts, gamets
     FROM eventlog
     WHERE type IN ('chat', 'inputtext')
     ORDER BY localts DESC, gamets DESC, ts DESC, rowid DESC
     LIMIT 5"
);
$recentDialogueRows = "";
if (count($recentDialogue) === 0) {
    $recentDialogueRows .= "<tr><td colspan='3'>No recent dialogue found.</td></tr>";
} else {
    foreach ($recentDialogue as $row) {
        $recentDialogueRows .= "<tr>"
            . "<td>" . h($row['data'] ?? '') . "</td>"
            . "<td>" . h(formatUtcFromTs($row['localts'] ?? 0)) . "</td>"
            . "<td>" . h(stobeGametsDateLabel($row['gamets'] ?? 0)) . "</td>"
            . "</tr>";
    }
}
$recentDialogueContent = "<div class='widget-table'><table><thead><tr>
<th style='width:50%;'>Dialogue</th><th style='width:25%;'>Time (UTC)</th><th style='width:25%;'>Kenshi Time</th>
</tr></thead><tbody>{$recentDialogueRows}</tbody></table></div>";

$llmStats24h = safeRows(
    $db,
    "SELECT
        SUM(CASE WHEN LOWER(COALESCE(status, '')) = 'ok' THEN 1 ELSE 0 END) AS llm_requests_success,
        COUNT(*) AS total_requests
     FROM audit_request
     WHERE localts >= EXTRACT(EPOCH FROM (NOW() - INTERVAL '24 HOURS'))"
);
$llmStats72h = safeRows(
    $db,
    "SELECT
        SUM(CASE WHEN LOWER(COALESCE(status, '')) = 'ok' THEN 1 ELSE 0 END) AS llm_requests_success,
        COUNT(*) AS total_requests
     FROM audit_request
     WHERE localts >= EXTRACT(EPOCH FROM (NOW() - INTERVAL '72 HOURS'))"
);
$llmStats1w = safeRows(
    $db,
    "SELECT
        SUM(CASE WHEN LOWER(COALESCE(status, '')) = 'ok' THEN 1 ELSE 0 END) AS llm_requests_success,
        COUNT(*) AS total_requests
     FROM audit_request
     WHERE localts >= EXTRACT(EPOCH FROM (NOW() - INTERVAL '7 DAYS'))"
);
$llmStatsLifetime = safeRows(
    $db,
    "SELECT
        SUM(CASE WHEN LOWER(COALESCE(status, '')) = 'ok' THEN 1 ELSE 0 END) AS llm_requests_success,
        COUNT(*) AS total_requests
     FROM audit_request"
);

$llmStatsCardHtml = "
    <div class='stat-card double-width' id='llm-stats-card' style='cursor: pointer; position: relative;'>
        <div class='stat-value'>
            <span id='llm-stats-24h'>" . h(formatLlmStats($llmStats24h)) . "</span>
            <span id='llm-stats-72h' style='display: none;'>" . h(formatLlmStats($llmStats72h)) . "</span>
            <span id='llm-stats-1w' style='display: none;'>" . h(formatLlmStats($llmStats1w)) . "</span>
            <span id='llm-stats-lifetime' style='display: none;'>" . h(formatLlmStats($llmStatsLifetime)) . "</span>
        </div>
        <div class='stat-label'>
            <span id='llm-label-24h'>LLM Requests Success Rate (24h)</span>
            <span id='llm-label-72h' style='display: none;'>LLM Requests Success Rate (72h)</span>
            <span id='llm-label-1w' style='display: none;'>LLM Requests Success Rate (1w)</span>
            <span id='llm-label-lifetime' style='display: none;'>LLM Requests Success Rate (lifetime)</span>
        </div>
        <div class='stat-cycle-hint'>Click to change range</div>
    </div>";

$locationsRows = safeRows(
    $db,
    "SELECT
        zone_name,
        city_name,
        first_game_ts,
        last_game_ts,
        last_seen_ts
     FROM location_zones
     WHERE metadata->>'knowledge_only' IS DISTINCT FROM 'true'
     ORDER BY first_game_ts DESC, last_seen_ts DESC, zone_name ASC
     LIMIT 500"
);
$locationsCount = count($locationsRows);

$locationsTraveledCardHtml = "";
$locationsTraveledModalHtml = "";
if ($locationsCount > 0) {
    $locationsTraveledCardHtml = "
    <div class='stat-card double-width' style='cursor: pointer; position: relative;' onclick=\"openModal('locationsTraveledModal')\">
        <div class='stat-value'>" . intval($locationsCount) . "</div>
        <div class='stat-label'>Locations Traveled</div>
        <div class='stat-cycle-hint'>Click to view list</div>
    </div>";

    $modalRows = "";
    foreach ($locationsRows as $locationRow) {
        $zoneName = h($locationRow['zone_name'] ?? '');
        $cityName = h($locationRow['city_name'] ?? '');
        $firstSeen = h(stobeGametsDisplayWithRaw($locationRow['first_game_ts'] ?? 0));
        $lastSeen = h(stobeGametsDisplayWithRaw($locationRow['last_game_ts'] ?? 0));
        $modalRows .= "<tr>"
            . "<td>{$zoneName}</td>"
            . "<td>{$cityName}</td>"
            . "<td>{$firstSeen}</td>"
            . "<td>{$lastSeen}</td>"
            . "</tr>";
    }

    $locationsTraveledModalHtml = "
    <div id='locationsTraveledModal' class='modal-overlay' style='display:none;'>
        <div class='modal-panel'>
            <button type='button' class='modal-close-btn' onclick=\"closeModal('locationsTraveledModal')\">&times;</button>
            <h3>Locations Traveled</h3>
            <div class='widget-table modal-table-wrap'>
                <table>
                    <thead>
                        <tr>
                            <th>Zone</th>
                            <th>City</th>
                            <th>First Seen</th>
                            <th>Last Seen</th>
                        </tr>
                    </thead>
                    <tbody>{$modalRows}</tbody>
                </table>
            </div>
        </div>
    </div>";
} else {
    $locationsTraveledCardHtml = "
    <div class='stat-card double-width'>
        <div class='stat-value'>0</div>
        <div class='stat-label'>Locations Traveled</div>
        <div class='stat-cycle-hint'>No locations tracked yet</div>
    </div>";
}

$statsContent = "
<div class='widget-stats'>
    <div class='stat-card'><div class='stat-value'>" . intval($stats['events_total']) . "</div><div class='stat-label'>Events</div></div>
    <div class='stat-card'><div class='stat-value'>" . intval($stats['deaths_total']) . "</div><div class='stat-label'>Deaths</div></div>
    <div class='stat-card'><div class='stat-value'>" . intval($stats['limbs_lost_total']) . "</div><div class='stat-label'>Limbs Lost</div></div>
    <div class='stat-card'><div class='stat-value'>" . intval($stats['diaries_total']) . "</div><div class='stat-label'>Diaries</div></div>
    <div class='stat-card'><div class='stat-value'>" . intval($stats['npcs_total']) . "</div><div class='stat-label'>NPC Profiles</div></div>
    <div class='stat-card'><div class='stat-value'>" . intval($stats['zones_total']) . "</div><div class='stat-label'>Known Zones</div></div>
    {$llmStatsCardHtml}
    {$locationsTraveledCardHtml}
</div>";

$diaryContent = "";
if (!empty($latestDiary)) {
    $author = h($latestDiary['author'] ?? 'Unknown');
    $content = nl2br(h($latestDiary['content'] ?? ''));
    $topic = h($latestDiary['topic'] ?? '');
    $gameTime = h(stobeGametsDisplayWithRaw($latestDiary['gamets'] ?? 0));
    $rowId = intval($latestDiary['rowid'] ?? 0);
    $diaryContent = "
        <div class='diary-entry' style='background: #1a1a1a; padding: 25px; border-radius: 8px; max-width: 1200px; margin: 0 auto;'>
            <div style='background: url(\"/StobeServer/ui/images/paper.jpg\") center/cover; padding: 40px; border-radius: 6px; box-shadow: 0 4px 8px rgba(0,0,0,0.5);'>
                <div style='color: #000; line-height: 1.4; font-family: Exo2, Arial, sans-serif !important;'>
                    <div style='font-size: 1.1em; margin-bottom: 6px; font-family: Exo2, Arial, sans-serif !important;'>{$author}</div>
                    <div style='font-size: 0.95em; margin-bottom: 14px; opacity: .8; font-family: Exo2, Arial, sans-serif !important;'>{$topic}</div>
                    <div style='font-size: 1.2em; padding-top: 8px; font-family: Exo2, Arial, sans-serif !important;'>{$content}</div>
                    <div style='font-size: 0.9em; margin-top: 16px; opacity: .75; font-family: Exo2, Arial, sans-serif !important;'>{$gameTime}</div>
                </div>
            </div>
            <div class='latest-diary-audio-controls'>
                <button type='button' id='latestDiaryAudioButton' class='action-button' onclick='toggleLatestDiaryAudio({$rowId})'>&#9654; Play Audio</button>
                <span id='latestDiaryAudioStatus' aria-live='polite'></span>
            </div>
        </div>";
} else {
    $diaryContent = "
        <div class='diary-entry' style='background: #1a1a1a; padding: 25px; border-radius: 8px; max-width: 1200px; margin: 0 auto; text-align: center;'>
            <div style='color: #6c757d; font-size: 1.2em; padding: 40px 20px;'>
                No diary entries found yet.
            </div>
        </div>";
}

$wordWidgetContent = "";
if (count($wordCloud) > 0) {
    $wordWidgetContent = "
        <script src='https://d3js.org/d3.v7.min.js'></script>
        <script src='https://cdn.jsdelivr.net/gh/jasondavies/d3-cloud/build/d3.layout.cloud.js'></script>
        <div class='word-cloud-container'>
            <div id='word-count-display' style='text-align: center; padding: 10px; margin-bottom: 20px; font-size: 24px; color: rgba(230, 183, 108, 0.9); height: 30px; font-weight: bold;'></div>
            <svg id='word-cloud' style='width: 100%; height: 500px;'></svg>
        </div>
        <style>
            .word-cloud-container {
                background: #1a1a1a;
                border-radius: 8px;
                padding: 20px;
                position: relative;
            }
            .word-cloud-text {
                font-family: 'Arial', sans-serif;
                cursor: pointer;
                transition: opacity 0.3s;
            }
            .word-cloud-text:hover {
                opacity: 0.7;
            }
        </style>
        <script>
            const words = " . json_encode($wordCloud) . ";
            const display = document.getElementById('word-count-display');
            const color = d3.scaleOrdinal()
                .range(['#e6b76c', '#d9aa62', '#cc9d58', '#f0c888', '#f4dcb2']);

            const layout = d3.layout.cloud()
                .size([document.getElementById('word-cloud').clientWidth, 500])
                .words(words)
                .padding(5)
                .rotate(() => 0)
                .font('Arial')
                .fontSize(d => d.size)
                .on('end', draw);

            function draw(drawWords) {
                d3.select('#word-cloud')
                    .append('g')
                    .attr('transform', 'translate(' + layout.size()[0] / 2 + ',' + layout.size()[1] / 2 + ')')
                    .selectAll('text')
                    .data(drawWords)
                    .enter()
                    .append('text')
                    .style('font-size', d => d.size + 'px')
                    .style('font-family', 'Arial')
                    .style('fill', (d, i) => color(i % 5))
                    .attr('class', 'word-cloud-text')
                    .attr('text-anchor', 'middle')
                    .attr('transform', d => 'translate(' + [d.x, d.y] + ')')
                    .text(d => d.text)
                    .on('mouseover', function(event, d) {
                        display.textContent = d.text + ' [' + d.count + ']';
                        d3.select(this).style('opacity', 0.7);
                    })
                    .on('mouseout', function() {
                        display.textContent = '';
                        d3.select(this).style('opacity', 1);
                    });
            }

            layout.start();
            window.addEventListener('resize', () => {
                const svg = document.getElementById('word-cloud');
                if (svg) {
                    svg.innerHTML = '';
                    layout.size([svg.clientWidth, 500]).start();
                }
            });
        </script>";
} else {
    $wordWidgetContent = "<div style='color:#d9aa62; text-align:center; padding:40px 20px;'>Not enough chat history to build a word list yet.</div>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/StobeServer/ui/css/main.css">
    <link rel="stylesheet" href="/StobeServer/ui/css/navbar.css">
    <style>
        body {
            padding-top: 80px;
        }

        /* Dashboard specific styles */
        .dashboard-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }

        .widget {
            background: #2d2d2d;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .widget-header {
            background: #1a1a1a;
            padding: 15px;
            border-bottom: 1px solid #3a3a3a;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .widget-header h3 {
            margin: 0;
            color: #f8f9fa;
            font-size: 1.2em;
        }

        .latest-diary-audio-controls {
            align-items: center;
            display: flex;
            gap: 12px;
            justify-content: center;
            padding-top: 14px;
        }

        #latestDiaryAudioStatus {
            color: #d7d7d7;
            font-size: 0.9rem;
        }

        .widget-actions {
            display: flex;
            gap: 10px;
        }

        .widget-content {
            padding: 15px;
            color: #d4d4d4;
        }

        /* Widget type specific styles */
        .widget-chart {
            min-height: 300px;
        }

        .widget-table {
            overflow-x: auto;
        }

        .widget-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .widget-table th,
        .widget-table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #3a3a3a;
        }

        .widget-table th {
            background: #1a1a1a;
            color: #f8f9fa;
        }

        .widget-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .stat-card {
            background: #1a1a1a;
            padding: 15px;
            border-radius: 4px;
            text-align: center;
        }

        .stat-card.double-width {
            grid-column: span 2;
        }

        .stat-card.double-width[style*="cursor: pointer"] {
            border: 1px solid rgba(230, 183, 108, 0.25);
            transition: border-color 0.2s ease, transform 0.2s ease;
        }

        .stat-card.double-width[style*="cursor: pointer"]:hover {
            border-color: rgba(230, 183, 108, 0.55);
            transform: translateY(-1px);
        }

        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            color: rgba(230, 183, 108, 0.9);
        }

        .stat-label {
            font-size: 0.9em;
            color: #6c757d;
            margin-top: 5px;
        }

        .stat-cycle-hint {
            margin-top: 6px;
            font-size: 0.75em;
            color: #8a8f98;
        }

        .stats-list {
            display: grid;
            gap: 4px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 2px 0;
            border-bottom: 1px solid #2a2a2a;
            font-size: 0.85em;
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .stat-item .stat-label {
            color: #6c757d;
            font-size: 0.9em;
            flex: 1;
            margin-right: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stat-item .stat-value {
            color: rgba(230, 183, 108, 0.9);
            font-weight: bold;
            font-size: 0.9em;
            min-width: 40px;
            text-align: right;
        }

        .widget-full-width {
            grid-column: 1 / -1;
            max-width: 100%;
        }

        .quest-list {
            border-top: 1px solid #3a3a3a;
            padding-top: 15px;
        }

        .quest-list h4 {
            color: #f8f9fa;
            margin: 0 0 12px 0;
            font-size: 1.1em;
        }

        .dashboard-buttons {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin: 20px 0;
            padding: 0 20px;
        }

        .dashboard-btn {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background: #2d2d2d;
            color: #f8f9fa;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.9em;
            transition: all 0.3s ease;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            font-family: inherit;
        }

        .dashboard-btn:hover {
            background: #3a3a3a;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        .dashboard-btn .btn-icon {
            margin-right: 8px;
            font-size: 1.1em;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            .dashboard-buttons {
                gap: 8px;
            }
            .dashboard-btn {
                padding: 6px 12px;
                font-size: 0.8em;
            }
        }

        .word-cloud-container {
            background: #1a1a1a;
            border-radius: 8px;
            padding: 20px;
            position: relative;
        }
        .widget-recent-words .widget-header h3 {
            color: #e6b76c;
        }
        .widget-recent-words .widget-content {
            color: #e6b76c;
        }
        .word-cloud-text {
            font-family: 'Arial', sans-serif;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        .word-cloud-text:hover {
            opacity: 0.7;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            z-index: 2000;
            padding: 24px;
            overflow: auto;
        }

        .modal-panel {
            max-width: 1200px;
            margin: 20px auto;
            background: #1f1f1f;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            padding: 16px;
            position: relative;
            color: #d4d4d4;
        }

        .modal-panel h3 {
            margin: 0 0 14px 0;
            color: #f8f9fa;
        }

        .modal-close-btn {
            position: absolute;
            right: 12px;
            top: 8px;
            background: transparent;
            border: none;
            color: #f8f9fa;
            font-size: 26px;
            line-height: 1;
            cursor: pointer;
        }

        .modal-table-wrap {
            max-height: 70vh;
            overflow: auto;
        }
    </style>
</head>
<body>
    <?php include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'navbar.php'); ?>

    <div class="container" style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 10px; margin-top:6px;">
        <div class="server-version-info" style="color:#6c757d; font-size:0.9em; font-family: 'Exo2', Arial, sans-serif;">
            Server: <?= h($serverVersionDisplay) ?>
            Plugin: <?= h($pluginVersionDisplay) ?>
        </div>
        <div class="social-links" style="display:flex; align-items:center; gap:12px;">
            <a href="https://www.youtube.com/@DwemerDynamics" target="_blank" rel="noopener noreferrer" class="social-link" title="Checkout our Youtube Channel">
                <img src="/StobeServer/ui/images/youtube.png" alt="YouTube" style="width:20px;height:20px;">
            </a>
            <a href="https://discord.gg/NDn9qud2ug" target="_blank" rel="noopener noreferrer" class="social-link" title="Join us on Discord">
                <img src="/StobeServer/ui/images/discord.png" alt="Discord" style="width:20px;height:20px;">
            </a>
            <a href="https://patreon.com/DwemerDynamics" target="_blank" rel="noopener noreferrer" class="social-link" title="Join our Patreon">
                <img src="/StobeServer/ui/images/patreon.png" alt="Patreon" style="width:20px;height:20px;">
            </a>
        </div>
    </div>

    <main class="container">
        <h1>Stobe Dashboard</h1>

        <div class="dashboard-buttons">
            <button onclick="window.open('https://dwemerdynamics.com/stobe/index.html', '_blank')" class="dashboard-btn">
                <span class="btn-icon">&#x1F4DA;</span> Dwemer Dynamics Wiki
            </button>
            <button onclick="window.open('https://docs.google.com/spreadsheets/d/1UtAR_r18wskmTMMsg8IlhVvr1Fn9tHvRJT8drH6RuzY/edit?gid=1257158105#gid=1257158105', '_blank')" class="dashboard-btn">
                <span class="btn-icon">🥇</span> AI/LLM Tier List
            </button>
            <button type="button" onclick="window.location.href=<?php echo htmlspecialchars(json_encode($webRoot . '/ui/quickstart.php'), ENT_QUOTES, 'UTF-8'); ?>" class="dashboard-btn">
                <span class="btn-icon" aria-hidden="true">&#x1F680;</span><span>Go to Quickstart</span>
            </button>
        </div>

        <?php require __DIR__ . '/tmpl/playthrough_home_controls.php'; ?>

        <div class="dashboard-container">
            <?= render_widget('Current Playthrough', $currentPlaythroughContent) ?>
            <?= render_widget('Recent Dialogue', $recentDialogueContent, 'table') ?>
            <?= render_widget('STOBE Stats', $statsContent, 'default') ?>
            <?= render_widget('Latest Diary Entry', $diaryContent, 'default', ['class' => 'widget-full-width']) ?>
            <?= render_widget('Recent Most Used Words', $wordWidgetContent, 'default', ['class' => 'widget-full-width widget-recent-words']) ?>
        </div>
        <?= $locationsTraveledModalHtml ?>

        <div class="text-center my-5">
            <div class="mt-4"><a href="https://c0da.es/" target="_blank" style="color:rgba(44,44,44,.1);font-size:.9em;transition:.5s" onmouseover="this.style.color='rgba(150,150,150,.3)'" onmouseout="this.style.color='rgba(44,44,44,.1)'">"world without wheel, charting zero deaths and echoes singing"</a></div>
        </div>
    </main>

    <script>
        const latestDiaryAudioEndpoint = <?= json_encode($webRoot . '/diary_audio.php', JSON_UNESCAPED_SLASHES) ?>;
        const latestDiaryAudio = new Audio();
        let latestDiaryAudioEntryId = 0;
        let latestDiaryAudioRequest = null;

        function stopLatestDiaryAudio(status = '') {
            if (latestDiaryAudioRequest) {
                latestDiaryAudioRequest.abort();
                latestDiaryAudioRequest = null;
            }
            latestDiaryAudio.pause();
            latestDiaryAudio.removeAttribute('src');
            latestDiaryAudio.load();
            latestDiaryAudioEntryId = 0;
            const button = document.getElementById('latestDiaryAudioButton');
            const statusElement = document.getElementById('latestDiaryAudioStatus');
            if (button) button.innerHTML = '&#9654; Play Audio';
            if (statusElement) statusElement.textContent = status;
        }

        async function toggleLatestDiaryAudio(entryId) {
            if (!entryId) {
                return;
            }
            if (latestDiaryAudioEntryId === entryId) {
                stopLatestDiaryAudio();
                return;
            }

            stopLatestDiaryAudio();
            latestDiaryAudioEntryId = entryId;
            latestDiaryAudioRequest = new AbortController();
            const button = document.getElementById('latestDiaryAudioButton');
            const statusElement = document.getElementById('latestDiaryAudioStatus');
            if (button) button.innerHTML = '&#9632; Stop Audio';
            if (statusElement) statusElement.textContent = 'Generating audio with the NPC voice...';

            try {
                const response = await fetch(latestDiaryAudioEndpoint, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({rowid: entryId}),
                    cache: 'no-store',
                    signal: latestDiaryAudioRequest.signal
                });
                const result = await response.json();
                if (!response.ok || !result.ok || !result.audio_url) {
                    throw new Error(result.error || 'Diary audio could not be generated.');
                }
                if (latestDiaryAudioEntryId !== entryId) {
                    return;
                }

                latestDiaryAudioRequest = null;
                latestDiaryAudio.src = result.audio_url;
                await latestDiaryAudio.play();
                if (statusElement) statusElement.textContent = 'Playing ' + (result.author || 'NPC') + '.';
            } catch (error) {
                if (error.name === 'AbortError') {
                    return;
                }
                stopLatestDiaryAudio(error.message || 'Diary audio failed.');
            }
        }

        latestDiaryAudio.addEventListener('ended', () => stopLatestDiaryAudio());

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'block';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        }

        window.addEventListener('click', function(event) {
            const overlays = document.querySelectorAll('.modal-overlay');
            overlays.forEach(function(overlay) {
                if (event.target === overlay) {
                    overlay.style.display = 'none';
                }
            });
        });

        const llmStatsCard = document.getElementById('llm-stats-card');
        if (llmStatsCard) {
            llmStatsCard.addEventListener('click', function() {
                const periods = ['24h', '72h', '1w', 'lifetime'];
                let currentIndex = 0;

                for (let i = 0; i < periods.length; i++) {
                    const statEl = document.getElementById('llm-stats-' + periods[i]);
                    if (statEl && statEl.style.display !== 'none') {
                        currentIndex = i;
                        break;
                    }
                }

                const currentStatEl = document.getElementById('llm-stats-' + periods[currentIndex]);
                const currentLabelEl = document.getElementById('llm-label-' + periods[currentIndex]);
                if (currentStatEl) currentStatEl.style.display = 'none';
                if (currentLabelEl) currentLabelEl.style.display = 'none';

                const nextIndex = (currentIndex + 1) % periods.length;
                const nextStatEl = document.getElementById('llm-stats-' + periods[nextIndex]);
                const nextLabelEl = document.getElementById('llm-label-' + periods[nextIndex]);
                if (nextStatEl) nextStatEl.style.display = 'inline';
                if (nextLabelEl) nextLabelEl.style.display = 'inline';
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

