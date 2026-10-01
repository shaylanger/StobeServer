<?php
/**
 * Negotiation admin (command line only).
 *
 *   php tools/negotiation_admin.php phases              show NEGOTIATION_PHASE_2..8
 *   php tools/negotiation_admin.php phase 6 off         switch a phase off (or on)
 *   php tools/negotiation_admin.php deals [n]           latest deals with per-term status
 *   php tools/negotiation_admin.php deal <contract_id>  one deal with all evidence
 *   php tools/negotiation_admin.php directives          pending NPC directives
 *   php tools/negotiation_admin.php tick                run verification now
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';
stobeNegEnsureSchema();
$db = $GLOBALS['db'];
$cmd = $argv[1] ?? 'deals';

$phaseNames = [
    2=>'durable truce (verify ceasefire, re-issue if it breaks)',
    3=>'multi-turn bargaining + negotiation partner lock',
    4=>'NPC-initiated surrender offers',
    5=>'NPC requests for help',
    6=>'deals outside combat (loans, promises, item-for-Cats)',
    7=>'consequences (affinity, memories, reputation, angry reaction to non-payment)',
    8=>'tolls/ransom/safe passage + rare personality-based betrayal',
];

if ($cmd === 'phases') {
    foreach ($phaseNames as $n => $label) {
        printf("NEGOTIATION_PHASE_%d  %-3s  %s\n", $n, stobeNegPhaseEnabled($n) ? 'on' : 'off', $label);
    }
    exit;
}
if ($cmd === 'phase') {
    $n = intval($argv[2] ?? 0);
    $on = in_array(strtolower(strval($argv[3] ?? '')), ['on','true','1','yes'], true);
    if (!isset($phaseNames[$n])) { fwrite(STDERR, "phase must be 2-8\n"); exit(1); }
    $db->exec("DELETE FROM general_settings WHERE id=$1", ['NEGOTIATION_PHASE_' . $n]);
    $db->exec("INSERT INTO general_settings (id, value) VALUES ($1, $2)", ['NEGOTIATION_PHASE_' . $n, $on ? 'true' : 'false']);
    printf("NEGOTIATION_PHASE_%d is now %s\n", $n, $on ? 'on' : 'off');
    exit;
}
if ($cmd === 'tick') {
    stobeNegTick();
    echo "tick done\n";
    exit;
}
if ($cmd === 'directives') {
    foreach ($db->fetchAll("SELECT id, npc_name, kind, contract_id, created_unix, consumed_unix, outcome, payload FROM stobe_negotiation_directive ORDER BY id DESC LIMIT 20") ?: [] as $d) {
        printf("#%d %-22s %-14s age=%ds consumed=%s %s\n  %s\n", $d['id'], $d['npc_name'], $d['kind'], time() - intval($d['created_unix']),
            intval($d['consumed_unix']) > 0 ? 'yes' : 'no', $d['outcome'], $d['payload']);
    }
    exit;
}
$where = '1=1';
$params = [];
$limit = 10;
if ($cmd === 'deal') {
    $where = 'contract_id=$1';
    $params = [strval($argv[2] ?? '')];
} elseif (isset($argv[2])) {
    $limit = max(1, min(50, intval($argv[2])));
}
$rows = $db->fetchAll("SELECT * FROM stobe_social_contract WHERE $where ORDER BY updated_at DESC LIMIT $limit", $params) ?: [];
foreach ($rows as $r) {
    printf("%s  %-20s %-22s kind=%s by=%s rounds=%d updated=%s\n", $r['contract_id'], $r['npc_name'], $r['status'], $r['kind'], $r['proposer'], $r['rounds'], $r['updated_at']);
    $state = stobeNegDecode($r['term_state']);
    $terms = count($state) > 0 ? $state : stobeNegDecode($r['terms']);
    foreach ($terms as $t) {
        printf("    %-6s %-14s %-24s %s\n", $t['by'] ?? '', $t['kind'] ?? '',
            trim(($t['amount'] ?? '') . ' ' . ($t['item'] ?? '') . ' ' . ($t['text'] ?? '')), $t['status'] ?? '-');
        if ($cmd === 'deal') {
            foreach ($t['evidence'] ?? [] as $e) echo '           evidence: ' . json_encode($e) . "\n";
        }
    }
    $b = stobeNegDecode($r['betrayal']);
    if (!empty($b['considered'])) echo '    betrayal: ' . json_encode($b) . "\n";
}
