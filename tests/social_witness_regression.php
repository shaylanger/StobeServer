<?php
// REL phase 6 (witnesses, dialogue) regression. Oracles: pinned share bands of the victim's effect.
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
require_once __DIR__ . '/../lib/relationship_manager.php';
require_once __DIR__ . '/../lib/social_dialogue.php';
$db = $GLOBALS['db']; $n = 0;
function ok(bool $c, string $what): void { global $n; if (!$c) throw new RuntimeException('FAIL: ' . $what); ++$n; }
function sql(string $s, array $p = []): mixed { $r = $GLOBALS['db']->exec($s, $p); if ($r === false) throw new RuntimeException('SQL failed: ' . $s); return $r; }
function one(string $s, array $p = []): mixed { $r = sql($s, $p); return pg_num_rows($r) ? pg_fetch_result($r, 0, 0) : null; }
function affOf(string $a, string $b): int { return (int)(stobeRelationshipEntryFor(getNpcData($a), $b)['aff'] ?? 0); }
function entryOf(string $a, string $b): ?array { return stobeRelationshipEntryFor(getNpcData($a), $b); }

const P = ['Wit Victim'=>[501,'Blue',false], 'Wit Attacker'=>[502,'Red',false], 'Wit Zero'=>[510,'Grey',false], 'Wit Friendly'=>[511,'Grey',false],
    'Wit Fond'=>[512,'Grey',false], 'Wit Devoted'=>[513,'Grey',false], 'Wit Bonded'=>[514,'Grey',false], 'Wit Sleeper'=>[515,'Grey',false],
    'Wit Blind'=>[516,'Grey',false], 'Wit Friend Of Fond'=>[517,'Grey',false], 'Wit Healer'=>[520,'Hub',false], 'Wit Patient'=>[521,'Blue',false]];
foreach (['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t) sql("DELETE FROM $t");
foreach (P as $name => [$serial]) {
    sql('DELETE FROM core_npc_master_history WHERE name=$1', [$name]); sql('DELETE FROM core_npc WHERE name=$1', [$name]);
    sql("INSERT INTO core_npc(name,extended_data,metadata) VALUES($1,'{}'::jsonb,jsonb_build_object('storage_id',$2::text))", [$name, 'hand_' . $serial]);
}
sql("INSERT INTO general_settings(id,value) VALUES('SOCIAL_RELATIONSHIP_MODE','enabled') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
foreach (['Wit Friendly'=>31, 'Wit Fond'=>56, 'Wit Devoted'=>76, 'Wit Bonded'=>91, 'Wit Sleeper'=>91, 'Wit Blind'=>91] as $w => $aff) RelationshipManager::setRelationship($w, 'Wit Victim', $aff);
RelationshipManager::setRelationship('Wit Friend Of Fond', 'Wit Fond', 91);
RelationshipManager::setRelationship('Wit Bonded', 'Wit Patient', 91);
RelationshipManager::setRelationship('Wit Bonded', 'Wit Zero', 91);
$store = new SocialStore($db); $seq = 0;
function ent(string $name, ?bool $conscious = true): array {
    [$serial, $faction, $squad] = P[$name];
    return ['entity_key'=>"s6:3:$serial", 'serial'=>$serial, 'name'=>$name, 'storage_id'=>"hand_$serial", 'faction'=>$faction, 'in_player_faction'=>$squad, 'conscious'=>$conscious];
}
function wit(string $name, bool $conscious = true, bool $perceived = true): array { return ['entity'=>ent($name, $conscious), 'conscious'=>$conscious, 'perceived'=>$perceived]; }
function send(string $kind, ?array $actor, ?array $target, array $facts, int $ts, array $witnesses): array {
    global $seq, $store; ++$seq;
    $e = ['schema_version'=>1, 'event_id'=>"s6:3:$seq", 'incident_id'=>"s6:3:$seq", 'campaign_id'=>'camp6', 'timeline_epoch'=>'3', 'native_session_id'=>'s6',
        'sequence'=>$seq, 'game_ts'=>$ts, 'event_kind'=>$kind, 'origin'=>'gameplay', 'actor'=>$actor, 'target'=>$target,
        'state_before'=>[], 'state_after'=>[], 'witnesses'=>$witnesses, 'facts'=>['source'=>'structured'] + $facts];
    $r = $store->ingest($e, ['campaign_id'=>'camp6', 'timeline_epoch'=>'3', 'native_session_id'=>'s6'], 'enabled');
    if (($r['status'] ?? '') !== 'captured') throw new RuntimeException("FAIL: event $seq not captured");
    return $r;
}
$all = [wit('Wit Zero'), wit('Wit Friendly'), wit('Wit Fond'), wit('Wit Devoted'), wit('Wit Bonded'), wit('Wit Sleeper', false, false), wit('Wit Blind', true, false), wit('Wit Friend Of Fond')];

// SR24: the attacker's first strike, seen by friends of the victim with 0/31/56/76/91.
send('attack', ent('Wit Attacker'), ent('Wit Victim'), ['victim_targeting_actor'=>false], 1000, $all);
$t = affOf('Wit Victim', 'Wit Attacker');
ok($t <= -8 && $t >= -15, "victim effect ($t)");
$share = fn(string $w) => affOf($w, 'Wit Attacker');
ok(entryOf('Wit Zero', 'Wit Attacker') === null || $share('Wit Zero') === 0, 'SR24 indifferent witness (0): nothing');
foreach (['Wit Friendly'=>[0.15, 0.25], 'Wit Fond'=>[0.25, 0.40], 'Wit Devoted'=>[0.40, 0.55], 'Wit Bonded'=>[0.55, 0.70]] as $w => [$lo, $hi]) {
    $d = $share($w);
    ok($d <= (int)round($t * $lo) && $d >= (int)round($t * $hi) - 1 && $d < 0, "SR24 $w share of $t ($d)");
}
ok(abs($share('Wit Bonded')) >= abs($share('Wit Friendly')), 'SR24 closer friends feel it more');
// SR25: asleep/KO or not perceiving: nothing (nearby is not witnessed).
ok(entryOf('Wit Sleeper', 'Wit Attacker') === null, 'SR25 unconscious witness: nothing');
ok(entryOf('Wit Blind', 'Wit Attacker') === null, 'SR25 occluded / did not perceive: nothing');
// SR26: single hop: a friend of a witness (not of the victim) gets nothing; echoes never echo.
ok(entryOf('Wit Friend Of Fond', 'Wit Attacker') === null, 'SR26 no friend-of-friend cascade');
ok((int)one("SELECT count(*) FROM social_effect WHERE component LIKE 'witness_witness%'") === 0, 'SR26 echoes never echo');
// Escalation inside the encounter: the witness share grows with the harm, never stacks.
$bondedBefore = $share('Wit Bonded');
send('harm', ent('Wit Attacker'), ent('Wit Victim', false), ['level'=>'knockout', 'attribution'=>'defeated_by', 'inventory'=>[], 'inventory_total'=>0], 1010, $all);
$tKo = affOf('Wit Victim', 'Wit Attacker');
$bondedAfter = $share('Wit Bonded');
ok($bondedAfter < $bondedBefore && $bondedAfter >= (int)round($tKo * 0.70) - 1, "witness share escalates with the KO, not stacked ($bondedAfter vs victim $tKo)");
// Once per witness/incident/component: a repeated KO fact changes nothing.
send('harm', ent('Wit Attacker'), ent('Wit Victim', false), ['level'=>'knockout', 'attribution'=>'defeated_by', 'inventory'=>[], 'inventory_total'=>0], 1011, $all);
ok($share('Wit Bonded') === $bondedAfter, 'once per witness and incident');
// Positive echo at half rate: a friend seeing the patient saved.
send('attack', ent('Wit Attacker'), ent('Wit Patient'), ['victim_targeting_actor'=>false], 1100, []);
$aidFacts = ['before_known'=>true, 'before_health'=>-0.7, 'before_blood'=>0.3, 'before_bleed'=>0.6, 'after_known'=>true, 'after_health'=>-0.2, 'after_blood'=>0.3, 'after_bleed'=>0.0, 'conscious_before'=>false];
send('aid', ent('Wit Healer'), ent('Wit Patient', false), $aidFacts, 1200, [wit('Wit Bonded')]);
$saved = affOf('Wit Patient', 'Wit Healer');
$seen = affOf('Wit Bonded', 'Wit Healer');
ok($saved >= 20 && $seen >= (int)round($saved * 0.55 * 0.5) && $seen <= (int)round($saved * 0.70 * 0.5) + 1, "positive echo at half rate ($seen of $saved)");
// Witness category flag off: no echoes.
sql("INSERT INTO general_settings(id,value) VALUES('SOCIAL_CATEGORY_WITNESS','false') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
send('attack', ent('Wit Attacker'), ent('Wit Zero'), ['victim_targeting_actor'=>false], 1300, [wit('Wit Bonded')]);
ok(affOf('Wit Zero', 'Wit Attacker') < 0, 'victim still charged with witnesses off');
ok((int)one("SELECT count(*) FROM social_effect WHERE observer_key='s6:3:514' AND component LIKE 'witness_%' AND game_ts=1300") === 0, 'witness category off');
sql("DELETE FROM general_settings WHERE id='SOCIAL_CATEGORY_WITNESS'");

// SR27: dialogue evaluator deltas: clamped to the insult band; nothing for a pair REL already scored.
$filtered = stobeSocialFilterDialogueUpdates('Wit Victim', [['target'=>'Wit Attacker', 'aff_delta'=>-10], ['target'=>'Wit Zero', 'aff_delta'=>-15], ['target'=>'Wit Friendly', 'aff_delta'=>9, 'type'=>'friend']]);
ok($filtered[0]['aff_delta'] === 0, 'SR27 fight already counted: dialogue adds nothing');
ok($filtered[1]['aff_delta'] === -8 && $filtered[2]['aff_delta'] === 3 && $filtered[2]['type'] === 'friend', 'SR27 dialogue band -8..+3, type kept');
sql("UPDATE general_settings SET value='shadow' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
ok(stobeSocialFilterDialogueUpdates('Wit Victim', [['target'=>'Wit Attacker', 'aff_delta'=>-10]])[0]['aff_delta'] === -10, 'SR27 shadow: legacy dialogue unchanged');

sql("UPDATE general_settings SET value='off' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
echo "$n witness/dialogue regression checks passed\n";
