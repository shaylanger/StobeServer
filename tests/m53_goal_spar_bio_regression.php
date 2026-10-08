<?php
// m53 (2026-10-07 batch m53-5090-a): item 139 CANCEL takes a BLOCKED goal; item 141 "enough" after a spar the NPC
// started with ATTACK@player sends STOP_FIGHT (squad rejoin); NPC panel NP11 bio lines found by the "Name:" prefix
// (Stobe logs replies with the player's nearby list as people), NP14 a bio being written reads 'pending'.
// Run ONLY against a test database:  STOBE_DB_NAME=stobe_test php tests/m53_goal_spar_bio_regression.php
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/npc_player_view.php';
require_once __DIR__ . '/../lib/social_fights.php';
require_once __DIR__ . '/../lib/negotiation_engine.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
if (strval(getenv('STOBE_DB_NAME')) !== 'stobe_test') { echo "refusing: STOBE_DB_NAME must be stobe_test\n"; exit(2); }
$db = $GLOBALS['db'];
$tmp = sys_get_temp_dir() . '/m53fix-' . getmypid();
putenv("STOBE_WORK_GOAL_STATUS_FILE=$tmp/work.status");
putenv("STOBE_TASK_GOAL_STATUS_FILE=$tmp/task.status");
putenv("STOBE_WORK_GOAL_CONTROL_FILE=$tmp/work.control");
putenv("STOBE_TASK_GOAL_CONTROL_FILE=$tmp/task.control");
putenv("STOBE_NEG_ACTION_REQUEST=$tmp/action.request");
$actor = 'M53GoalAvarek';
$sid = 'hand_990531';
$player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
$sparrer = 'M53Sparrer';
$cleanup = static function () use ($db, $actor, $tmp, $sid, $sparrer, $player): void {
    $db->exec("DELETE FROM stobe_work_goal WHERE actor_name=$1", [$actor]);
    $db->exec("DELETE FROM eventlog WHERE data LIKE 'M53%' OR people LIKE $1 OR data LIKE $2", ['%|' . $sid . '"%', '%(talking to: M53%']);
    $db->exec("DELETE FROM stobe_npc_bio WHERE npc_storage_id=$1", [$sid]);
    setConfOpt(stobeSocialSparKey($sparrer, $player), '');
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
};
register_shutdown_function($cleanup);
$cleanup();
@mkdir($tmp);
file_put_contents("$tmp/work.status", '');
file_put_contents("$tmp/task.status", '');

// ---------------------------------------------------------------- 139: CANCEL a BLOCKED goal
stobeWorkGoalEnsureSchema();
$db->exec(
    "INSERT INTO stobe_work_goal (goal_id, actor_name, actor_serial, item_name, quantity, destination_name, status, completed, current_step, created_game_ts, created_at, updated_at)
     VALUES ('wg-m53-a',$1,1780395520,'Bread',1,'','BLOCKED',0,'',0,NOW() - interval '120 seconds', NOW() - interval '60 seconds')", [$actor]);
$r = stobeAnyGoalControl($actor, 'CANCEL', 'the bread', 0, '');
$c = is_file("$tmp/work.control") ? strval(file_get_contents("$tmp/work.control")) : '';
$st = $db->fetchOne("SELECT status FROM stobe_work_goal WHERE goal_id='wg-m53-a'");
check('139: "cancel the bread" on a BLOCKED goal cancels it (was no_matching_goal)',
    !empty($r['ok']) && str_contains($c, "wg-m53-a\tCANCEL") && strval($st['status'] ?? '') === 'CANCELLED', [$r, $c, $st]);

// ---------------------------------------------------------------- 141: spar started with ATTACK@player, "enough" -> STOP_FIGHT
$GLOBALS['CACHE_PEOPLE'] = json_encode([$player . '|hand_990530', $sparrer . '|hand_990532']);
$GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] = "$sparrer, let's spar. Attack me, come on.";
stobeSocialSparStopGuard($sparrer, 'ATTACK@' . $player, 'But fine - you asked.', 'chat');
check('141: ATTACK@player after "let\'s spar" records the spar', stobeSocialSparActive($sparrer, $player, stobeSocialNowGameTs()));
@unlink("$tmp/action.request");
$GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] = "Enough $sparrer, stop, we're done sparring.";
$ok = stobeSocialSparStopGuard($sparrer, '', 'Good. My arms were getting tired of swinging at a wall anyway.', 'chat');
$req = is_file("$tmp/action.request") ? strval(file_get_contents("$tmp/action.request")) : '';
check('141: "enough, stop" with a words-only reply sends STOP_FIGHT for her', $ok && str_starts_with($req, "990532\tSTOP_FIGHT"), [$ok, $req]);
check('141: the spar is over after the stop', !stobeSocialSparActive($sparrer, $player, stobeSocialNowGameTs()));
@unlink("$tmp/action.request");
$GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] = "Stop following me.";
$ok = stobeSocialSparStopGuard($sparrer, '', 'Fine.', 'chat');
check('141: "stop" without a spar sends nothing', !$ok && !is_file("$tmp/action.request"));
$GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] = "Attack me!";
stobeSocialSparStopGuard($sparrer, 'ATTACK@' . $player, 'Fine.', 'chat');
check('141: an attack without a spar proposal is not a spar', !stobeSocialSparActive($sparrer, $player, stobeSocialNowGameTs()));

// ---------------------------------------------------------------- NP11: lines found by the "Name:" prefix
$npc = 'M53Stranger 1';
$nearby = json_encode([$player . '|hand_990530', 'M53Captain|hand_990533']);   // the speaking NPC not in people
$ins = static function (string $type, string $data, int $ts, string $pp) use ($db): void {
    $db->exec("INSERT INTO eventlog (type, data, gamets, localts, ts, people) VALUES ($1,$2,$3,EXTRACT(epoch FROM now())::bigint,$3,$4)", [$type, $data, $ts, $pp]);
};
$ins('chat', "$player: Where are you from? (talking to: $npc)", 9000, json_encode([$player . '|hand_990530', $npc . '|' . $sid]));
$ins('inputtext', "$player: Where are you from? (talking to: $npc)", 9000, $nearby);
$ins('chat', "$npc: Worked salvage out in the ruins. (talking to: $player)", 9001, $nearby);
$ins('chat', "$npc: Old machines, mostly. (talking to: $player)", 9001, $nearby);
$ins('chat', "M53Captain: Move along. (talking to: $player)", 9002, json_encode([$player . '|hand_990530', $npc . '|' . $sid]));
$d = stobeNpcBioDialogue($sid, $player, 400, [$npc]);
$who = array_map(static fn($l) => $l['who'] . ':' . $l['text'], $d['lines']);
check('NP11: the NPC\'s replies count as hers (people = the player\'s nearby list, she is not in it)', $d['npc_lines'] === 2, $who);
check('NP11: a bystander\'s line is not the player\'s, the player\'s line is counted once', count($d['lines']) === 3 && !in_array('you:Move along.', $who, true), $who);

// ---------------------------------------------------------------- NP14: a bio being written right now reads pending
stobeNpcBioEnsureSchema();
$db->exec("INSERT INTO stobe_npc_bio (npc_storage_id, npc_name, learner_name, bio, source_rowid_max, fact_count, backstory_included, attempted_at, updated_at)
           VALUES ($1,$2,$3,'Confided bio.',1,0,1,NOW(),NOW() - interval '5 minutes')", [$sid, $npc, $player]);
$res = stobeNpcBioFor($sid, $npc, $player, false, $d, [], false, true, '');
check('NP14: below the tier while a regeneration runs: bio hidden, state pending (was empty)', $res['bio'] === '' && $res['state'] === 'pending', $res);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
