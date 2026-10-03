<?php
/**
 * Social relationship (REL) diagnostics, command line only. Read-only unless a write flag is given.
 *
 *   php tools/social_relationship_inspect.php                 summary JSON (mode, session, counts per load)
 *   php tools/social_relationship_inspect.php --events 20     + the last 20 inbox events (kind, roles, status)
 *   php tools/social_relationship_inspect.php --effects 20    + the last 20 effects (observer -> culprit, delta, applied)
 *   php tools/social_relationship_inspect.php --since "2026-10-03 01:00"   only rows created since then (server time)
 *   php tools/social_relationship_inspect.php --check-shadow  exit 1 unless no effect was applied (shadow/off proof)
 *   php tools/social_relationship_inspect.php --check-stale   exit 1 if an older load's event arrived after a newer load's first event
 *   php tools/social_relationship_inspect.php --expect-pair 123 456 [kind]   exit 1 unless the CURRENT load (session campaign/load/client)
 *                                                  captured an event with actor serial 123 and target serial 456
 *   php tools/social_relationship_inspect.php --pair-effects         + per observer->culprit name: summed delta, components, applied (current load)
 *   php tools/social_relationship_inspect.php --expect-effect "Observer" "Culprit" -40 -8   exit 1 unless that summed delta (current load) is in range
 *   php tools/social_relationship_inspect.php --expect-none "Observer" "Culprit"   exit 1 if any non-zero effect exists for that pair (current load)
 *   php tools/social_relationship_inspect.php --relation "Observer" "Target"   the stored affinity (relationship map) of observer toward target
 *   php tools/social_relationship_inspect.php --beliefs 20         what observers believe (believed culprit only), current campaign
 *   php tools/social_relationship_inspect.php --incidents 20       combat/ko/slavery incidents (phase, remembered attacker, transfers, enslaver)
 *   php tools/social_relationship_inspect.php --interpret-log 30    last 30 SOCIAL_INTERPRET lines from log/relationship_worker.log
 *   php tools/social_relationship_inspect.php --set-mode off|shadow|enabled   prints the previous mode (restore with it)
 *   php tools/social_relationship_inspect.php --purge-all --yes            empties the six social tables (test data only)
 *
 * DB: STOBE_DB_NAME (default stobe), like every server tool.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';

$args = array_slice($argv, 1);
$opt = static function (string $name) use ($args): ?string {
    $i = array_search($name, $args, true);
    return $i === false ? null : strval($args[$i + 1] ?? '');
};
$has = static fn(string $name): bool => in_array($name, $args, true);
$db = $GLOBALS['db'];
$tables = ['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'];
$q = static function (string $sql, array $p = []) use ($db): array {
    $r = $db->exec($sql, $p);
    if ($r === false) throw new RuntimeException('Query failed: ' . $sql);
    return pg_fetch_all($r) ?: [];
};
$setting = static function (string $id) use ($db): ?string {
    $row = $db->fetchOne('SELECT value FROM general_settings WHERE id=$1', [$id]);
    return $row ? strval($row['value']) : null;
};

if (($mode = $opt('--set-mode')) !== null) {
    if (!in_array($mode, ['off','shadow','enabled'], true)) { fwrite(STDERR, "mode must be off|shadow|enabled\n"); exit(2); }
    $previous = $setting('SOCIAL_RELATIONSHIP_MODE');
    $db->exec("INSERT INTO general_settings(id,value) VALUES('SOCIAL_RELATIONSHIP_MODE',$1) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value", [$mode]);
    echo json_encode(['previous'=>$previous ?? '(unset = off)','now'=>stobeSocialMode()]), "\n";
    exit(0);
}
if ($has('--purge-all')) {
    if (!$has('--yes')) { fwrite(STDERR, "--purge-all needs --yes\n"); exit(2); }
    $before = [];
    foreach ($tables as $t) $before[$t] = (int)($q("SELECT count(*) AS n FROM $t")[0]['n'] ?? 0);
    $db->exec('TRUNCATE ' . implode(',', $tables));
    echo json_encode(['purged'=>$before]), "\n";
    exit(0);
}

$since = $opt('--since');
$sinceSql = $since !== null ? ' AND created_at >= $1::timestamptz' : '';
$sinceParams = $since !== null ? [$since] : [];
$sessionRow = $db->fetchOne("SELECT value FROM stobe_meta.settings WHERE key='PLAYTHROUGH_SESSION'");
$session = json_decode(strval($sessionRow['value'] ?? '{}'), true) ?: [];
try {
    $sc = stobeSocialScope($db);
    $scopeOut = ['status'=>stobeSocialPlaythroughSwitching($db) ? ($session['status'] ?? null) : 'playthrough_saves_off',
        'campaign_id'=>$sc['campaign_id'], 'load_id'=>$sc['timeline_epoch'], 'client_id'=>$sc['native_session_id']];
} catch (Throwable $e) {
    $scopeOut = ['status'=>$session['status'] ?? 'none', 'campaign_id'=>$session['character_id'] ?? null, 'load_id'=>$session['load_id'] ?? null,
        'client_id'=>$session['client_id'] ?? null, 'note'=>$e->getMessage()];
}
$out = [
    'db' => getenv('STOBE_DB_NAME') ?: 'stobe',
    'mode' => stobeSocialMode(),
    'settings' => [
        'SOCIAL_RELATIONSHIP_MODE' => $setting('SOCIAL_RELATIONSHIP_MODE'),
        'RELATIONSHIP_FIGHTS_COUNT' => $setting('RELATIONSHIP_FIGHTS_COUNT'),
        'NEVER_CLEAR_RELATIONSHIP_DATA' => $setting('NEVER_CLEAR_RELATIONSHIP_DATA'),
    ],
    // "session" = the scope new events must match: the handshake with Playthrough Saves on, else the
    // newest load of the shared "legacy" campaign (Playthrough Saves off).
    'session' => $scopeOut,
    'counts' => [],
    'loads' => [],
];
foreach ($tables as $t) $out['counts'][$t] = (int)($q("SELECT count(*) AS n FROM $t")[0]['n'] ?? 0);
$out['loads'] = $q("SELECT campaign_id, timeline_epoch, native_session_id, sum(n) AS events,
        jsonb_object_agg(status, n) AS by_status, min(first_at) AS first_at, max(last_at) AS last_at, max(max_seq) AS max_sequence
    FROM (SELECT campaign_id, timeline_epoch, native_session_id, status, count(*) AS n,
            min(created_at) AS first_at, max(created_at) AS last_at, max(sequence) AS max_seq
          FROM social_event_inbox WHERE true$sinceSql GROUP BY 1,2,3,4) s GROUP BY 1,2,3 ORDER BY min(first_at)", $sinceParams);
foreach ($out['loads'] as &$load) $load['by_status'] = json_decode($load['by_status'], true);
unset($load);
$out['kinds'] = $q("SELECT payload->>'event_kind' AS kind, payload->'facts'->>'source' AS source, count(*) AS n
    FROM social_event_inbox WHERE true$sinceSql GROUP BY 1,2 ORDER BY 3 DESC", $sinceParams);
$out['effects'] = [
    'applied' => (int)($q('SELECT count(*) AS n FROM social_effect WHERE applied')[0]['n'] ?? 0),
    'shadow' => (int)($q('SELECT count(*) AS n FROM social_effect WHERE NOT applied')[0]['n'] ?? 0),
];
$out['incidents_by_phase'] = $q("SELECT state->>'phase' AS phase, count(*) AS n FROM social_incident GROUP BY 1 ORDER BY 1");
if (($n = $opt('--events')) !== null) {
    $out['events'] = $q("SELECT created_at, timeline_epoch, sequence, game_ts, status, payload->>'event_kind' AS kind,
            payload->'actor'->>'name' AS actor, payload->'actor'->>'serial' AS actor_serial,
            payload->'target'->>'name' AS target, payload->'target'->>'serial' AS target_serial,
            left(payload->'facts'->>'message', 120) AS message, payload->'facts'->>'level' AS level
        FROM social_event_inbox WHERE true$sinceSql ORDER BY created_at DESC, sequence DESC LIMIT " . max(1, min(500, intval($n))), $sinceParams);
}
if (($n = $opt('--effects')) !== null) {
    $out['effect_rows'] = $q("SELECT incident_id, observer_key, culprit_key, component, game_ts, delta, applied,
            detail->>'reason' AS reason, detail->>'new_affinity' AS would_be_affinity, rules_version
        FROM social_effect ORDER BY game_ts DESC LIMIT " . max(1, min(500, intval($n))));
}
$exit = 0;
if ($has('--check-shadow')) {
    $out['check_shadow'] = $out['effects']['applied'] === 0 ? 'pass' : 'FAIL: affinity effects were applied';
    if ($out['effects']['applied'] !== 0) $exit = 1;
}
if ($has('--check-stale')) {
    // A load's events must all arrive before the next load's first event (+5 s queue grace).
    $bad = $q("SELECT a.timeline_epoch AS old_load, b.timeline_epoch AS new_load, count(*) AS late_rows
        FROM social_event_inbox a JOIN (SELECT campaign_id, timeline_epoch, min(created_at) AS first_at FROM social_event_inbox GROUP BY 1,2) b
          ON a.campaign_id=b.campaign_id AND a.timeline_epoch::bigint < b.timeline_epoch::bigint AND a.created_at > b.first_at + interval '5 seconds'
        GROUP BY 1,2");
    $out['check_stale'] = $bad ? ['FAIL' => $bad] : 'pass';
    if ($bad) $exit = 1;
}
$current = [strval($scopeOut['campaign_id'] ?? ''), strval($scopeOut['load_id'] ?? '')];
$pairSql = "SELECT detail->>'observer_name' AS observer, detail->>'culprit_name' AS culprit, sum(delta) AS delta,
        string_agg(component || ':' || delta, ',' ORDER BY game_ts) AS components, bool_or(applied) AS applied
    FROM social_effect WHERE campaign_id=\$1 AND timeline_epoch=\$2 GROUP BY 1,2 ORDER BY 1,2";
if ($has('--pair-effects')) $out['pair_effects'] = $q($pairSql, $current);
$pairDelta = static function (string $observer, string $culprit) use ($q, $pairSql, $current): ?int {
    foreach ($q($pairSql, $current) as $row) if (strcasecmp(strval($row['observer']), $observer) === 0 && strcasecmp(strval($row['culprit']), $culprit) === 0) return (int)$row['delta'];
    return null;
};
if (($i = array_search('--expect-effect', $args, true)) !== false) {
    [$o, $c, $min, $max] = [strval($args[$i+1] ?? ''), strval($args[$i+2] ?? ''), (int)($args[$i+3] ?? 0), (int)($args[$i+4] ?? 0)];
    $d = $pairDelta($o, $c);
    $pass = $d !== null && $d >= min($min, $max) && $d <= max($min, $max);
    $out['expect_effect'] = ($pass ? 'pass' : 'FAIL') . ": $o -> $c delta=" . ($d === null ? 'none' : $d) . " expected [$min,$max]";
    if (!$pass) $exit = 1;
}
if (($i = array_search('--expect-none', $args, true)) !== false) {
    [$o, $c] = [strval($args[$i+1] ?? ''), strval($args[$i+2] ?? '')];
    $d = $pairDelta($o, $c);
    $pass = $d === null || $d === 0;
    $out['expect_none'] = ($pass ? 'pass' : 'FAIL') . ": $o -> $c delta=" . ($d === null ? 'none' : $d);
    if (!$pass) $exit = 1;
}
if (($i = array_search('--relation', $args, true)) !== false) {
    $npc = getNpcData(strval($args[$i+1] ?? ''));
    $out['relation'] = ['observer'=>$args[$i+1] ?? '', 'target'=>$args[$i+2] ?? '', 'entry'=>$npc ? stobeRelationshipEntryFor($npc, strval($args[$i+2] ?? '')) : 'observer not found'];
}
if (($n = $opt('--beliefs')) !== null) {
    // What each observer believes (the prompt-visible side); objective culprits never appear here.
    $out['beliefs'] = $q("SELECT b.incident_id, b.observer_key, b.belief->>'responsible_entity' AS believed, b.belief->>'kind' AS kind,
            b.belief->>'awareness' AS awareness, b.belief->>'confidence' AS confidence, b.belief->>'note' AS note, b.game_ts
        FROM social_belief b WHERE b.campaign_id=\$1 ORDER BY b.game_ts DESC LIMIT " . max(1, min(500, intval($n))), [$current[0]]);
}
if (($n = $opt('--incidents')) !== null) {
    $out['incidents'] = $q("SELECT timeline_epoch, incident_id, state->>'kind' AS kind, state->>'phase' AS phase,
            COALESCE(state->'victim'->>'name', state->>'pair') AS subject, state->'remembered'->>'name' AS remembered,
            state->>'initiator' AS initiator, state->>'basis' AS basis, jsonb_array_length(COALESCE(state->'objective'->'transfers','[]'::jsonb)) AS transfers,
            state->'enslaved_by'->'owner'->>'name' AS enslaved_by, game_ts
        FROM social_incident WHERE campaign_id=\$1 ORDER BY game_ts DESC LIMIT " . max(1, min(500, intval($n))), [$current[0]]);
}
if (($n = $opt('--interpret-log')) !== null) {
    $file = dirname(__DIR__) . '/log/relationship_worker.log';
    $lines = is_file($file) ? preg_grep('/SOCIAL_INTERPRET/', file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    $out['interpret_log'] = array_values(array_slice($lines ?: [], -max(1, min(500, intval($n)))));
}
if (($i = array_search('--expect-pair', $args, true)) !== false) {
    $actor = ltrim(strval($args[$i + 1] ?? ''), '#'); $target = ltrim(strval($args[$i + 2] ?? ''), '#');
    $kind = $args[$i + 3] ?? null; if ($kind !== null && str_starts_with($kind, '--')) $kind = null;
    $rows = $q("SELECT payload->>'event_kind' AS kind, status, count(*) AS n FROM social_event_inbox
        WHERE campaign_id=$1 AND timeline_epoch=$2 AND native_session_id=$3
          AND payload->'actor'->>'serial'=$4 AND payload->'target'->>'serial'=$5" . ($kind !== null ? " AND payload->>'event_kind'=\$6" : '') . ' GROUP BY 1,2',
        array_merge([strval($scopeOut['campaign_id'] ?? ''), strval($scopeOut['load_id'] ?? ''), strval($scopeOut['client_id'] ?? ''), $actor, $target], $kind !== null ? [$kind] : []));
    $out['expect_pair'] = $rows ? ['pass' => $rows] : 'FAIL: no event for this pair in the current load';
    if (!$rows) $exit = 1;
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($exit);
