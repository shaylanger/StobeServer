<?php
// NPC info panel (player view): disclosed facts are parsed/stored per listener and NPC storage id,
// the panel shows deals/relationship/facts for the SPEAKING character only, and hides the stored
// backstory, goals and relationship notes. No LLM call anywhere in the view.
// Run ONLY against a test database:  STOBE_DB_NAME=stobe_test php tests/npc_player_view_regression.php
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/npc_player_view.php';

$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
if (strval(getenv('STOBE_DB_NAME')) !== 'stobe_test') { echo "refusing: STOBE_DB_NAME must be stobe_test\n"; exit(2); }

$serial = 990417;
$sid = 'hand_' . $serial;
stobeNpcFactEnsureSchema();
$cleanup = static function () use ($db, $sid, $serial): void {
    $db->exec("DELETE FROM stobe_npc_learned_fact WHERE npc_storage_id=$1 OR learner_name LIKE 'RNPV%'", [$sid]);
    $db->exec("DELETE FROM core_npc WHERE name LIKE 'RNPV%'");
    $db->exec("DELETE FROM stobe_social_contract WHERE npc_serial=$1", [$serial]);
};
$cleanup();
register_shutdown_function($cleanup);

// 1. parse
$p = stobeNpcFactParseDisclosed('{"updates":[],"disclosed":[{"fact":"grew up in Stack","category":"background"},{"fact":"was a caravan guard","category":"occupation"},{"fact":"third one dropped","category":"history"}]}');
check('parse keeps at most 2', count($p) === 2, $p);
check('parse keeps category', ($p[1]['category'] ?? '') === 'occupation', $p);
$p = stobeNpcFactParseDisclosed('{"updates":[],"disclosed":["likes fishing a lot"]}');
check('parse accepts plain strings, default category', count($p) === 1 && $p[0]['category'] === 'background', $p);
$p = stobeNpcFactParseDisclosed('{"updates":[],"disclosed":[{"fact":"knows a hidden route","category":"secret_plan"},{"fact":"hm"}]}');
check('parse: unknown category -> background, too-short dropped', count($p) === 1 && $p[0]['category'] === 'background', $p);
check('parse: no disclosed key -> none', stobeNpcFactParseDisclosed('{"updates":[]}') === []);
check('parse: garbage -> none', stobeNpcFactParseDisclosed('not json') === []);

// 2. record
$npcData = ['name' => 'RNPV Gorlo', 'metadata' => json_encode(['storage_id' => $sid])];
$facts = [['fact' => 'grew up in Stack', 'category' => 'background'], ['fact' => 'was a caravan guard', 'category' => 'occupation']];
check('record stores 2', stobeNpcFactRecordDisclosed($npcData, 'RNPV Gorlo', 'RNPVBeak', $facts, 'I grew up in Stack...', 5000) === 2);
check('record dedupes the same fact', stobeNpcFactRecordDisclosed($npcData, 'RNPV Gorlo', 'RNPVBeak', [['fact' => 'Grew up in  Stack!', 'category' => 'background']], 'x', 5100) === 0);
check('record needs a storage id', stobeNpcFactRecordDisclosed(['name' => 'RNPV Gorlo', 'metadata' => '{}'], 'RNPV Gorlo', 'RNPVBeak', $facts, 'x', 5000) === 0);
check('record ignores self as learner', stobeNpcFactRecordDisclosed($npcData, 'RNPV Gorlo', 'RNPV Gorlo', $facts, 'x', 5000) === 0);
check('facts are per learner', count(stobeNpcFactsFor($sid, 'RNPVAvarek')) === 0 && count(stobeNpcFactsFor($sid, 'rnpvbeak')) === 2);

// 3. view
$db->exec(
    "INSERT INTO core_npc (name, faction, backstory, goals, occupation, metadata, extended_data, gamets_last_updated, created_at, updated_at)
     VALUES ('RNPV Gorlo', 'Traders Guild', 'SECRET_BACKSTORY he murdered his brother', 'SECRET_GOAL rob the bar', 'SECRET_BIO_OCC smuggler',
             $1::jsonb, $2::jsonb, 5000, NOW(), NOW())",
    [json_encode(['storage_id' => $sid]),
     json_encode(['relationships' => ['RNPVBeak' => ['aff' => 40, 'type' => 'friend', 'note' => 'SECRET_NOTE owes me']]])]
);
$terms = [['by' => 'player', 'kind' => 'GIVE_CATS', 'amount' => 200, 'status' => 'PENDING'],
          ['by' => 'npc', 'kind' => 'STOP_ATTACK', 'status' => 'VERIFIED']];
$db->exec(
    "INSERT INTO stobe_social_contract (contract_id, npc_name, npc_serial, player_name, status, terms, term_state, kind, deadline_gamets)
     VALUES ('rnpv-1', 'RNPV Gorlo', $1, 'RNPVBeak', 'AWAITING_PERFORMANCE', $2::jsonb, $2::jsonb, 'combat', 12200)",
    [$serial, json_encode($terms)]
);
$db->exec(
    "INSERT INTO stobe_social_contract (contract_id, npc_name, npc_serial, player_name, status, terms, term_state, kind, updated_at)
     VALUES ('rnpv-2', 'RNPV Gorlo', $1, 'RNPVBeak', 'BREACHED_NPC', $2::jsonb, $2::jsonb, 'trade', NOW() - interval '2 hours')",
    [$serial, json_encode([['by' => 'npc', 'kind' => 'GIVE_ITEM', 'item' => 'Hashish', 'status' => 'UNMET']])]
);

$v = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000,
                             'live_activity' => 'Walking', 'live_faction' => 'Traders Guild [Allied]', 'trader' => true]);
$t = $v['text'];
check('view: no LLM bio/backstory/goals/notes', !str_contains($t, 'SECRET_'), $t);
check('view: faction from live game, tag stripped', str_contains($t, "Faction: Traders Guild\n"), $t);
check('view: occupation from what they told', str_contains($t, 'was a caravan guard (they told you)'), $t);
check('view: active deal with progress', str_contains($t, 'Agreed, in progress') && str_contains($t, 'Progress: 1/2 terms done'), $t);
check('view: outstanding payment', str_contains($t, 'Outstanding: RNPVBeak owes RNPV Gorlo 200 Cats'), $t);
check('view: deadline in game hours', str_contains($t, 'Deadline: in 2 game h'), $t);
check('view: broken deal in history', str_contains($t, 'Broken by RNPV Gorlo') && str_contains($t, 'Hashish'), $t);
check('view: live activity shown', str_contains($t, 'Doing now: Walking'), $t);
check('view: relationship of the speaker', str_contains($t, "RELATIONSHIP WITH RNPVBEAK\nFriendly (friend)"), $t);
check('view: told facts marked unverified', str_contains($t, 'They told you (not verified):') && str_contains($t, '- grew up in Stack'), $t);

$o = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVAvarek', 'gamets' => 5000]);
$t2 = $o['text'];
check('other speaker: no deals, neutral, no facts', str_contains($t2, 'No deals.') && str_contains($t2, 'No opinion of RNPVAvarek yet') && str_contains($t2, 'Nothing learned about their past yet.') && !str_contains($t2, 'Stack'), $t2);
check('other speaker: stored faction labelled last known', str_contains($t2, 'Faction: Traders Guild (last known)'), $t2);
check('other speaker: occupation unknown (no trader flag, no bio)', str_contains($t2, 'Occupation: unknown'), $t2);

// renamed NPC: same storage id/serial, new name keeps deals and facts
$r = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Renamed', 'speaker' => 'RNPVBeak', 'gamets' => 5000]);
check('renamed NPC keeps deals + facts', str_contains($r['text'], 'RNPV Renamed') && str_contains($r['text'], 'grew up in Stack') && str_contains($r['text'], '200 Cats'), $r['text']);

// 4. evaluator wiring: prompt asks for disclosed facts, a first-person background line triggers the evaluation
$src = file_get_contents(__DIR__ . '/../lib/chat_helper_functions.php');
check('evaluator prompt asks for disclosed facts', str_contains($src, 'Also return \\"disclosed\\"'));
check('evaluator records disclosed facts', str_contains($src, 'stobeNpcFactRecordDisclosed('));
check('background line triggers evaluation', stobeRelationshipTurnNeedsConnectorEvaluation('hello', 'I grew up in Stack, before the war.', 'chat', 1) === true);

// 5. rollback prunes facts learned after the load cutoff, keeps older ones
$db->exec("INSERT INTO stobe_npc_learned_fact (npc_storage_id, npc_name, learner_name, category, fact, fact_key, game_ts)
           VALUES ($1,'RNPV Gorlo','RNPVBeak','history','future fact','rnpv-future',99000000)", [$sid]);
$counts = stobePlaythroughPruneFutureTimeline(98000000);
check('rollback prunes future facts', intval($counts['npc_facts'] ?? -1) >= 1 && count(stobeNpcFactsFor($sid, 'RNPVBeak')) === 2, $counts['npc_facts'] ?? null);

echo "npc_player_view_regression: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
