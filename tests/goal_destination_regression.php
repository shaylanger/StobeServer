<?php
// Items 66/68: goal destinations without a stored base. Run ONLY against a test database:
//   STOBE_DB_NAME=stobe_test php tests/goal_destination_regression.php
require __DIR__ . '/../lib/bootstrap.php';

$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
if (strval(getenv('STOBE_DB_NAME')) !== 'stobe_test') { echo "refusing: STOBE_DB_NAME must be stobe_test\n"; exit(2); }

stobeWorkGoalEnsureSchema();
$oldPlayer = $db->fetchOne("SELECT value FROM general_settings WHERE id='PLAYER_NAME'");
register_shutdown_function(static function () use ($db, $oldPlayer): void {
    $db->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
    if (is_array($oldPlayer)) $db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [strval($oldPlayer['value'])]);
    $db->exec("DELETE FROM eventlog WHERE data='GdTest area'");
    $db->exec("DELETE FROM player_base_locations WHERE base_id='gdtest-fortress'");
    $db->exec("DELETE FROM player_bases WHERE base_id='gdtest-fortress'");
});
$db->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', 'GdTestPlayer')");
$db->exec("DELETE FROM player_base_locations WHERE LOWER(base_name) IN ('home','gdtest fortress')");
$GLOBALS['CACHE_PEOPLE'] = '["GdTestPlayer|hand_11111","GdTestMate|hand_22222"]';
$here = static fn($r): bool => is_array($r) && strval($r['name'] ?? 'x') === '' && array_key_exists('x', $r) && $r['x'] === null;

// 66: "Home" with no stored base (the run m1 case) and other base words
check('item 66: "Home" without a stored base -> here', $here(stobeWorkGoalResolveDestination('Home')), stobeWorkGoalResolveDestination('Home'));
check('item 66: "our base" -> here', $here(stobeWorkGoalResolveDestination('our base')));
check('item 66: "the outpost" -> here', $here(stobeWorkGoalResolveDestination('the outpost')));
check('item 66: an unknown faraway town still fails', stobeWorkGoalResolveDestination('Gdtest Nowhere City') === false);
// 66: a stored base still wins
$db->exec("INSERT INTO player_bases (base_id, name) VALUES ('gdtest-fortress', 'GdTest Fortress') ON CONFLICT DO NOTHING");
$db->exec("INSERT INTO player_base_locations (base_id, base_name, x, y, z) VALUES ('gdtest-fortress', 'GdTest Fortress', 10, 20, 30)");
$f = stobeWorkGoalResolveDestination('GdTest Fortress');
check('item 66: a stored base resolves to its position', is_array($f) && floatval($f['x']) === 10.0, $f);
$db->exec("DELETE FROM player_base_locations WHERE base_name='GdTest Fortress'");
$db->exec("DELETE FROM player_bases WHERE base_id='gdtest-fortress'");
// 66: the area the game last reported
$db->exec("INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location) VALUES ('info',$1,1000,'GdTest area','pending',$1,'','Gdtestshire, Border Zone')", [time()]);
check('item 66: the current area -> here', $here(stobeWorkGoalResolveDestination('Gdtestshire')));
$db->exec("DELETE FROM eventlog WHERE data='GdTest area'");

// 68: a person as destination (the run m1 TASK_GOAL@FETCH@...@Shay case)
check('item 68: the player name -> here (bring it back)', $here(stobeWorkGoalResolveDestination('GdTestPlayer')));
check('item 68: "me" -> here', $here(stobeWorkGoalResolveDestination('me')));
check('item 68: a live participant -> here', $here(stobeWorkGoalResolveDestination('GdTestMate')));
check('item 68: fallback reason is person', stobeGoalDestinationFallback('GdTestPlayer') === 'person');
// 68 part 2: the run m1 swap TASK_GOAL@FETCH@Shay@mead@1@General Camp Storage Chest@0
check('item 68: FETCH target=player, destination=chest -> swapped',
    stobeTaskGoalNormalizeTargetDestination('FETCH', 'GdTestPlayer', 'General Camp Storage Chest') === ['General Camp Storage Chest', 'GdTestPlayer']);
check('item 68: the right order stays', stobeTaskGoalNormalizeTargetDestination('FETCH', 'General Camp Storage Chest', 'GdTestPlayer') === ['General Camp Storage Chest', 'GdTestPlayer']);
check('item 68: other kinds untouched', stobeTaskGoalNormalizeTargetDestination('GUARD', 'GdTestPlayer', 'Storage Chest') === ['GdTestPlayer', 'Storage Chest']);
check('item 68: a container as destination is never a base lookup -> here', $here(stobeWorkGoalResolveDestination('General Camp Storage Chest')));
// 73: agreed fetch without an action (run m2, 06:32:03)
$npc73 = ['inventory'=>'', 'equipment'=>''];
$line73 = 'Malzin, fetch the mead from the general camp storage chest and bring it to me.';
$reply73 = "Mead from the storage chest. Right - I'll go dig it out, assuming nobody's already drunk it.";
check('item 73/76: the run m2 pair -> TASK_GOAL@FETCH from the chest, handed to the player ("to me")',
    stobeInferFetchFromAgreedRequest($line73, $npc73, [], $reply73, true) === 'TASK_GOAL@FETCH@General Camp Storage Chest@mead@1@me@0',
    stobeInferFetchFromAgreedRequest($line73, $npc73, [], $reply73, true));
check('item 73: a number is kept ("get me" = to the player)', stobeInferFetchFromAgreedRequest('Get me 3 bread from the storage box.', $npc73, [], 'On it.', true) === 'TASK_GOAL@FETCH@Storage Box@bread@3@me@0');
check('item 76: plain "fetch X from the chest" keeps no destination', stobeInferFetchFromAgreedRequest('Fetch the mead from the storage chest.', $npc73, [], 'On it.', true) === 'TASK_GOAL@FETCH@Storage Chest@mead@1@@0');
check('item 76: "me" as person destination is the player', stobeGoalPersonName('me') === 'GdTestPlayer' && stobeGoalPersonName('to GdTestMate') === 'GdTestMate');
check('item 73: a refusal -> nothing', stobeInferFetchFromAgreedRequest($line73, $npc73, [], "No. Get it yourself.", true) === '');
check('item 73: only a question -> nothing', stobeInferFetchFromAgreedRequest($line73, $npc73, [], 'The mead?', true) === '');
check('item 73: an existing TASK_GOAL -> nothing', stobeInferFetchFromAgreedRequest($line73, $npc73, ['TASK_GOAL@FETCH@General Camp Storage Chest@mead@1@@0'], $reply73, true) === '');
check('item 73: not a container (a town) -> nothing', stobeInferFetchFromAgreedRequest('Fetch the mead from Squin.', $npc73, [], "I'll go.", true) === '');
// 74: agreed purchase without an action (run m2, 06:56:48)
$known74 = static fn(string $n): bool => $n === 'Apothecary Abia';
$line74 = 'Malzin, go buy a Standard First Aid Kit from Apothecary Abia.';
$reply74 = "Apothecary Abia, Standard First Aid Kit. I'll see what she's asking for it - assuming she's still got stock this late.";
check('item 74: the run m2 pair -> TASK_GOAL@BUY from the trader',
    stobeInferBuyFromAgreedRequest($line74, $npc73, [], $reply74, true, $known74) === 'TASK_GOAL@BUY@Apothecary Abia@Standard First Aid Kit@1@@0',
    stobeInferBuyFromAgreedRequest($line74, $npc73, [], $reply74, true, $known74));
check('item 74: a number is kept', stobeInferBuyFromAgreedRequest('Buy 2 bread from Apothecary Abia.', $npc73, [], 'Will do.', true, $known74) === 'TASK_GOAL@BUY@Apothecary Abia@bread@2@@0');
check('item 74: an unknown trader -> nothing', stobeInferBuyFromAgreedRequest('Go buy a kit from Nobody Here.', $npc73, [], "I'll go.", true, $known74) === '');
check('item 74: a refusal -> nothing', stobeInferBuyFromAgreedRequest($line74, $npc73, [], "No, I'm not wasting cats on that.", true, $known74) === '');
check('item 74: a question from the player -> nothing', stobeInferBuyFromAgreedRequest('Could you buy a kit from Apothecary Abia?', $npc73, [], "Sure.", true, $known74) === '');
check('item 74: an action already there -> nothing', stobeInferBuyFromAgreedRequest($line74, $npc73, ['TASK_GOAL@BUY@Apothecary Abia@Standard First Aid Kit@1@@0'], $reply74, true, $known74) === '');
check('item 74: a non-faction NPC -> nothing', stobeInferBuyFromAgreedRequest($line74, $npc73, [], $reply74, false, $known74) === '');
// 75: the model's MOVE_TO toward the goal's trader is dropped (run m2: "Could not identify that destination")
check('item 75: MOVE_TO@Apothecary Abia is dropped next to the inferred BUY goal',
    stobeDropMovesCoveredByGoal(['MOVE_TO@Apothecary Abia', 'EQUIP_ITEM@Hat'], 'TASK_GOAL@BUY@Apothecary Abia@Standard First Aid Kit@1@@0') === ['EQUIP_ITEM@Hat']);
check('item 75: a move elsewhere stays',
    stobeDropMovesCoveredByGoal(['MOVE_TO@Squin'], 'TASK_GOAL@BUY@Apothecary Abia@Kit@1@@0') === ['MOVE_TO@Squin']);
check('item 73: a non-faction NPC -> nothing', stobeInferFetchFromAgreedRequest($line73, $npc73, [], $reply73, false) === '');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
