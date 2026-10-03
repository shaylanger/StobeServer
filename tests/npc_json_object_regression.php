<?php
// Item 101: extended_data/metadata are written as JSON objects; relationship writes survive an old array row.
require __DIR__ . '/../lib/bootstrap.php';
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
check('empty array -> {}', stobeJsonObjectString([]) === '{}');
check('"[]" -> {}', stobeJsonObjectString('[]') === '{}');
check('[{}] -> {}', stobeJsonObjectString([[]]) === '{}' && stobeJsonObjectString('[{}]') === '{}');
check('object kept', stobeJsonObjectString(['a' => 1]) === '{"a":1}');
check('list of objects merged', stobeJsonObjectString([['a' => 1], ['b' => 2]]) === '{"a":1,"b":2}');
$db = $GLOBALS['db'];
$name = 'Item101 Camp Npc';
$db->exec("DELETE FROM core_npc_master WHERE name=$1", [$name]);
storeNpcProfile($name, ['name' => $name, 'extended_data' => [], 'metadata' => []], ['history_reason' => 'test']);
$row = $db->fetchOne("SELECT jsonb_typeof(extended_data) AS e, jsonb_typeof(metadata) AS m FROM core_npc_master WHERE name=$1", [$name]);
check('new NPC: extended_data and metadata are objects', is_array($row) && $row['e'] === 'object' && $row['m'] === 'object', $row);
$db->exec("UPDATE core_npc_master SET extended_data='[]'::jsonb WHERE name=$1", [$name]); // an old bad row
$npc = getNpcData($name);
$ok = stobePersistNpcRelationshipMap($name, ['Shay' => ['aff' => 80, 'type' => 'platonic', 'tier' => 'Devoted', 'note' => 'test']], is_array($npc) ? $npc : false);
$row = $db->fetchOne("SELECT jsonb_typeof(extended_data) AS e, extended_data->'relationships'->'Shay'->>'aff' AS aff FROM core_npc_master WHERE name=$1", [$name]);
check('relationship write on an array row succeeds and leaves an object', $ok !== false && is_array($row) && $row['e'] === 'object' && $row['aff'] === '80', [$ok, $row]);
$db->exec("DELETE FROM core_npc_master WHERE name=$1", [$name]);
echo "pass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);