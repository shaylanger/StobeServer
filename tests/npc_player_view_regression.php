<?php
// NPC info panel (biography card): disclosed facts are parsed/stored per listener and NPC storage id;
// the card shows looks/job/faction/relationship/deals for the SPEAKING character only; the growing bio is
// written (stubbed LLM here) only from what the NPC said to that listener + told facts, cached, regenerated
// only on new dialogue (max once per 60 s); the stored backstory/goals/personality/notes never leak.
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
stobeNpcBioEnsureSchema();
$cleanup = static function () use ($db, $sid, $serial): void {
    $db->exec("DELETE FROM stobe_npc_learned_fact WHERE npc_storage_id=$1 OR learner_name LIKE 'RNPV%'", [$sid]);
    $db->exec("DELETE FROM core_npc WHERE name LIKE 'RNPV%'");
    $db->exec("DELETE FROM stobe_social_contract WHERE npc_serial=$1", [$serial]);
    $db->exec("DELETE FROM stobe_npc_bio WHERE npc_storage_id=$1", [$sid]);
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

// 3. card view (bio LLM stubbed: every prompt is captured, never a real call)
$GLOBALS['STOBE_NPC_BIO_LLM_CALLS'] = [];
$GLOBALS['STOBE_NPC_BIO_LLM'] = static function (array $messages) {
    $GLOBALS['STOBE_NPC_BIO_LLM_CALLS'][] = $messages;
    return "Bio: \"She grew up in Stack and guarded caravans before settling here. She likes fishing.\"";
};
$bioCalls = static fn(): int => count($GLOBALS['STOBE_NPC_BIO_LLM_CALLS']);
$db->exec("DELETE FROM stobe_npc_bio WHERE npc_storage_id=$1", [$sid]);
$db->exec("DELETE FROM eventlog WHERE people LIKE $1", ['%|' . $sid . '"%']);
$db->exec(
    "INSERT INTO core_npc (name, faction, race, gender, appearance, backstory, goals, occupation, personality, metadata, extended_data, gamets_last_updated, created_at, updated_at)
     VALUES ('RNPV Gorlo', 'Traders Guild', 'Greenlander', 'female', 'Female Greenlander with a older look. Build appears short. Keeps visible facial hair.',
             'SECRET_BACKSTORY he murdered his brother', 'SECRET_GOAL rob the bar', 'SECRET_BIO_OCC smuggler', 'SECRET_PERSONALITY cruel',
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

// helper unit checks
check('title split', stobeNpcViewSplitTitle('Jora 2 [Slavemonger Guard]') === ['Jora 2', 'Slavemonger Guard']);
check('clean line strips speaker + addressee', stobeNpcBioCleanLine('RNPV Gorlo: I grew up in Stack. (talking to: RNPVBeak)') === 'I grew up in Stack.');
$looks = stobeNpcViewLooks(['race' => 'Greenlander', 'gender' => 'female', 'appearance' => 'Female Greenlander with a older look. Build appears short.']);
check('race line', stobeNpcViewRaceLine($looks) === 'Greenlander woman, older', stobeNpcViewRaceLine($looks));
check('about: natural sentence from the mechanical appearance', stobeNpcViewAbout($looks, 'Trader', 'Traders Guild') === 'A short, older Greenlander woman. She works as a trader and is with the Traders Guild.', stobeNpcViewAbout($looks, 'Trader', 'Traders Guild'));
$hive = stobeNpcViewLooks(['race' => 'Midland Hive Prince', 'gender' => 'male', 'appearance' => 'Male Midland Hive Prince with a young look. Build appears average.']);
check('hive: no gender noun, average build dropped', stobeNpcViewAbout($hive, '', '') === 'A young Midland Hive Prince.' && stobeNpcViewRaceLine($hive) === 'Midland Hive Prince, young', stobeNpcViewAbout($hive, '', ''));
$odd = stobeNpcViewLooks(['race' => '', 'gender' => '', 'appearance' => 'tall figure with a old scar']);
check('about: free-form appearance tidied', stobeNpcViewAbout($odd, '', '') === 'Tall figure with an old scar.', stobeNpcViewAbout($odd, '', ''));
check('clean reply: label/quotes/think removed, max 6 sentences',
    stobeNpcBioCleanReply("<think>x</think>Bio: \"A. B. C. D. E. F. G.\"") === 'A. B. C. D. E. F.', stobeNpcBioCleanReply("<think>x</think>Bio: \"A. B. C. D. E. F. G.\""));

// 3a. first open, no conversation: basics + empty bio state, no LLM call
$db->exec("DELETE FROM stobe_npc_learned_fact WHERE npc_storage_id=$1", [$sid]);
$v = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000,
                             'live_activity' => 'Walking', 'live_faction' => 'Traders Guild [Allied]', 'trader' => true]);
$t = $v['text'];
check('card: basics on first open', $v['name'] === 'RNPV Gorlo' && $v['job'] === 'Trader' && $v['faction'] === 'Traders Guild'
    && $v['race_line'] === 'Greenlander woman, older' && $v['relation_label'] === 'Friendly (friend)' && $v['relation_line'] === 'They like you.', $v);
check('card: about from visible info', $v['about'] === 'A short, older Greenlander woman with facial hair. She works as a trader and is with the Traders Guild.', $v['about']);
check('card: empty bio state', $v['bio_state'] === 'empty' && str_contains($t, "WHAT YOU'VE LEARNED\n" . STOBE_NPC_BIO_EMPTY) && $v['talked_line'] === "You haven't talked yet", $t);
check('card: no LLM call without dialogue', $bioCalls() === 0);
check('card: active deal with progress', str_contains($t, 'Agreed, in progress') && str_contains($t, 'Progress: 1/2 terms done'), $t);
check('card: outstanding payment', str_contains($t, 'Outstanding: RNPVBeak owes RNPV Gorlo 200 Cats'), $t);
check('card: deadline in game hours', str_contains($t, 'Deadline: in 2 game h'), $t);
check('card: broken deal in history', str_contains($t, 'Broken by RNPV Gorlo') && str_contains($t, 'Hashish'), $t);
check('card: right now section', str_contains($t, "RIGHT NOW\n") && str_contains($t, 'Doing now: Walking'), $t);
check('card: section order', preg_match("/ABOUT THEM.*WHAT YOU'VE LEARNED.*DEALINGS WITH RNPVBEAK.*RIGHT NOW/s", $t) === 1, $t);
check('card: no hidden profile', !str_contains(json_encode($v), 'SECRET_'), $t);

// 3b. conversation: phase 1 (no LLM) says pending, phase 2 generates, then cache hits
$people = json_encode(['RNPV Gorlo|' . $sid, 'RNPVBeak|hand_990418']);
$peopleYou = json_encode(['RNPVBeak|hand_990418', 'RNPV Gorlo|' . $sid]);
$ins = static function (string $type, string $data, int $ts, string $pp) use ($db): void {
    $db->exec("INSERT INTO eventlog (type, data, gamets, localts, ts, people) VALUES ($1,$2,$3,EXTRACT(epoch FROM now())::bigint,$3,$4)", [$type, $data, $ts, $pp]);
};
$ins('inputtext', 'RNPVBeak: Where are you from? (Talking to RNPV Gorlo)', 4000, $peopleYou);
$ins('chat', 'RNPV Gorlo: I grew up in Stack, then guarded caravans. (talking to: RNPVBeak)', 4010, $people);
$ins('chat', 'RNPV Gorlo: Somebody else entirely. (talking to: RNPVAvarek)', 4020, json_encode(['RNPV Gorlo|' . $sid, 'RNPVAvarek|hand_1', 'RNPVBeak|hand_990418']));
stobeNpcFactRecordDisclosed(['name' => 'RNPV Gorlo', 'metadata' => json_encode(['storage_id' => $sid])], 'RNPV Gorlo', 'RNPVBeak',
    [['fact' => 'likes fishing', 'category' => 'interest']], 'x', 4010);
$p1 = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000]);
check('phase 1: pending + stale, no LLM call', $p1['bio_state'] === 'pending' && $p1['bio_stale'] === 1 && $bioCalls() === 0 && str_contains($p1['text'], 'Updating...'), $p1['bio_state']);
check('talked line + first met', $p1['talked_line'] === 'Talked 1 time, first met less than an hour ago', $p1['talked_line']);
$p2 = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000, 'bio' => 1]);
check('phase 2: bio generated once', $p2['bio_state'] === 'updated' && $bioCalls() === 1 && str_starts_with($p2['bio'], 'She grew up in Stack') && str_contains($p2['text'], 'She grew up in Stack'), [$p2['bio_state'], $p2['bio']]);
$prompt = json_encode($GLOBALS['STOBE_NPC_BIO_LLM_CALLS'][0]);
check('prompt has what they said + told facts', str_contains($prompt, 'I grew up in Stack') && str_contains($prompt, 'likes fishing'), $prompt);
check('prompt never holds the hidden profile', !str_contains($prompt, 'SECRET_'), $prompt);
check('prompt skips lines said to someone else', !str_contains($prompt, 'Somebody else entirely'), $prompt);
$p3 = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000, 'bio' => 1]);
$p4 = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000, 'why' => 'periodic']);
check('cache hit: no new LLM call', $p3['bio_state'] === 'cached' && $p4['bio_state'] === 'cached' && $p4['bio_stale'] === 0 && $bioCalls() === 1, [$p3['bio_state'], $p4['bio_state'], $bioCalls()]);
// new dialogue inside the 60 s window: throttled (cached text, no call); after the window: stale -> regenerated
$ins('chat', 'RNPV Gorlo: My sister runs a bar in Squin. (talking to: RNPVBeak)', 4100, $people);
$p5 = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000, 'bio' => 1]);
check('throttled within 60 s', $p5['bio_state'] === 'cached' && $bioCalls() === 1, $p5['bio_state']);
$db->exec("UPDATE stobe_npc_bio SET attempted_at = NOW() - interval '2 minutes' WHERE npc_storage_id=$1", [$sid]);
$p6 = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000]);
$p7 = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000, 'bio' => 1]);
check('new dialogue -> stale, then regenerated', $p6['bio_state'] === 'pending' && $p7['bio_state'] === 'updated' && $bioCalls() === 2
    && str_contains(json_encode($GLOBALS['STOBE_NPC_BIO_LLM_CALLS'][1]), 'sister runs a bar'), [$p6['bio_state'], $p7['bio_state']]);
// LLM failure keeps the old bio
$GLOBALS['STOBE_NPC_BIO_LLM'] = static fn(array $m) => false;
$ins('chat', 'RNPV Gorlo: I hate the rain. (talking to: RNPVBeak)', 4200, $people);
$db->exec("UPDATE stobe_npc_bio SET attempted_at = NOW() - interval '2 minutes' WHERE npc_storage_id=$1", [$sid]);
$p8 = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVBeak', 'gamets' => 5000, 'bio' => 1]);
check('LLM failure keeps the cached bio', $p8['bio_state'] === 'cached' && str_starts_with($p8['bio'], 'She grew up in Stack'), $p8['bio_state']);

$o = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Gorlo', 'speaker' => 'RNPVZed', 'gamets' => 5000]);
$t2 = $o['text'];
check('other speaker: no deals, neutral, empty bio', str_contains($t2, 'No deals with RNPVZed yet.') && $o['relation_label'] === 'Neutral'
    && $o['bio_state'] === 'empty' && str_contains($t2, STOBE_NPC_BIO_EMPTY) && !str_contains($t2, 'Stack'), $t2);
check('other speaker: stored faction labelled last known', str_contains($t2, 'Faction: Traders Guild (last known)'), $t2);
check('other speaker: job unknown (no trader flag, no title, hidden occupation unused)', $o['job'] === 'unknown', $o['job']);

// renamed NPC: same storage id/serial, new name keeps deals and bio
$r = stobeNpcPlayerViewText(['serial' => $serial, 'name' => 'RNPV Renamed', 'speaker' => 'RNPVBeak', 'gamets' => 5000]);
check('renamed NPC keeps deals + bio', $r['name'] === 'RNPV Renamed' && str_contains($r['text'], 'grew up in Stack') && str_contains($r['text'], '200 Cats'), $r['text']);

// 4. evaluator wiring: prompt asks for disclosed facts, a first-person background line triggers the evaluation
$src = file_get_contents(__DIR__ . '/../lib/chat_helper_functions.php');
check('evaluator prompt asks for disclosed facts', str_contains($src, 'Also return \\"disclosed\\"'));
check('evaluator records disclosed facts', str_contains($src, 'stobeNpcFactRecordDisclosed('));
check('background line triggers evaluation', stobeRelationshipTurnNeedsConnectorEvaluation('hello', 'I grew up in Stack, before the war.', 'chat', 1) === true);
$api = file_get_contents(__DIR__ . '/../ai_npcs.php');
check('endpoint returns the structured card', str_contains($api, "array_merge(['ok' => true, 'key' => strval(\$payload['key'] ?? '')], \$view)"));

// 5. rollback prunes facts and bios from after the load cutoff, keeps older ones
$db->exec("INSERT INTO stobe_npc_learned_fact (npc_storage_id, npc_name, learner_name, category, fact, fact_key, game_ts)
           VALUES ($1,'RNPV Gorlo','RNPVBeak','history','future fact','rnpv-future',99000000)", [$sid]);
$db->exec("UPDATE stobe_npc_bio SET source_gamets=99000000 WHERE npc_storage_id=$1", [$sid]);
$nf = count(stobeNpcFactsFor($sid, 'RNPVBeak'));
$counts = stobePlaythroughPruneFutureTimeline(98000000);
check('rollback prunes future facts', intval($counts['npc_facts'] ?? -1) >= 1 && count(stobeNpcFactsFor($sid, 'RNPVBeak')) === $nf - 1, $counts['npc_facts'] ?? null);
check('rollback prunes future bios', intval($counts['npc_bio'] ?? -1) >= 1 && stobeNpcBioCache($sid, 'RNPVBeak') === false, $counts['npc_bio'] ?? null);

$db->exec("DELETE FROM eventlog WHERE people LIKE $1", ['%|' . $sid . '"%']);
$db->exec("DELETE FROM stobe_npc_bio WHERE npc_storage_id=$1", [$sid]);
echo "npc_player_view_regression: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
