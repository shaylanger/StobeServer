<?php
// REL phase 4 (aid, carry, food) regression through SocialStore::ingest. Pinned rule ranges are the oracle.
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
$db = $GLOBALS['db']; $n = 0;
function ok(bool $c, string $what): void { global $n; if (!$c) throw new RuntimeException('FAIL: ' . $what); ++$n; }
function sql(string $s, array $p = []): mixed { $r = $GLOBALS['db']->exec($s, $p); if ($r === false) throw new RuntimeException('SQL failed: ' . $s); return $r; }
function affOf(string $a, string $b): int { return (int)(stobeRelationshipEntryFor(getNpcData($a), $b)['aff'] ?? 0); }
function entryOf(string $a, string $b): ?array { return stobeRelationshipEntryFor(getNpcData($a), $b); }

const P = ['Care Medic'=>[301,'Hub',false], 'Care Brute'=>[302,'Red',false], 'Care Brute Pal'=>[303,'Red',false], 'Care Patient'=>[311,'Blue',false],
    'Care Patient Two'=>[312,'Blue',false], 'Care Patient Three'=>[313,'Blue',false], 'Care Bandaged'=>[314,'Blue',false], 'Care Carrier'=>[321,'Grey',false], 'Care Carried'=>[322,'Blue',false],
    'Care Carried Two'=>[323,'Blue',false], 'Care Carried Three'=>[324,'Blue',false], 'Care Carried Four'=>[325,'Blue',false],
    'Care Squad A'=>[331,'Nameless',true], 'Care Squad B'=>[332,'Nameless',true], 'Care Squad C'=>[333,'Nameless',true],
    'Care Donor'=>[341,'Hub',false], 'Care Hungry'=>[342,'Blue',false], 'Care Full'=>[343,'Blue',false]];
foreach (['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t) sql("DELETE FROM $t");
foreach (P as $name => [$serial]) {
    sql('DELETE FROM core_npc_master_history WHERE name=$1', [$name]); sql('DELETE FROM core_npc WHERE name=$1', [$name]);
    sql("INSERT INTO core_npc(name,extended_data,metadata) VALUES($1,'{}'::jsonb,jsonb_build_object('storage_id',$2::text))", [$name, 'hand_' . $serial]);
}
sql("INSERT INTO general_settings(id,value) VALUES('SOCIAL_RELATIONSHIP_MODE','enabled') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
$store = new SocialStore($db); $seq = 0;
function ent(string $name, ?bool $conscious = true): array {
    [$serial, $faction, $squad] = P[$name];
    return ['entity_key'=>"sess4:9:$serial", 'serial'=>$serial, 'name'=>$name, 'storage_id'=>"hand_$serial", 'faction'=>$faction, 'in_player_faction'=>$squad, 'conscious'=>$conscious];
}
function send(string $kind, ?array $actor, ?array $target, array $facts, int $ts): array {
    global $seq, $store; ++$seq;
    $e = ['schema_version'=>1, 'event_id'=>"sess4:9:$seq", 'incident_id'=>"sess4:9:$seq", 'campaign_id'=>'camp4', 'timeline_epoch'=>'9', 'native_session_id'=>'sess4',
        'sequence'=>$seq, 'game_ts'=>$ts, 'event_kind'=>$kind, 'origin'=>'gameplay', 'actor'=>$actor, 'target'=>$target,
        'state_before'=>[], 'state_after'=>[], 'witnesses'=>[], 'facts'=>['source'=>'structured'] + $facts];
    $r = $store->ingest($e, ['campaign_id'=>'camp4', 'timeline_epoch'=>'9', 'native_session_id'=>'sess4'], 'enabled');
    if (($r['status'] ?? '') !== 'captured') throw new RuntimeException("FAIL: event $seq ($kind @$ts) not captured: " . ($r['status'] ?? '?'));
    return $r;
}
function vit(string $pre, float $h, float $b, float $l): array { return [$pre.'known'=>true, $pre.'health'=>$h, $pre.'blood'=>$b, $pre.'bleed'=>$l]; }
function aid(string $provider, string $recipient, array $before, array $after, ?bool $consciousBefore, int $ts): array {
    return send('aid', ent($provider), ent($recipient, $consciousBefore), vit('before_', ...$before) + vit('after_', ...$after) + ['conscious_before'=>$consciousBefore, 'seconds'=>20, 'item'=>'Basic First Aid Kit', 'supplier_serial'=>0], $ts);
}
function attack(string $a, string $b, int $ts): void { send('attack', ent($a), ent($b), ['victim_targeting_actor'=>false], $ts); }

// SR15: unconscious, critically bleeding patient: lifesaving credited to the medic (verified while unconscious).
attack('Care Brute', 'Care Patient', 1000);
aid('Care Medic', 'Care Patient', [-0.6, 0.3, 0.5], [-0.3, 0.3, 0.0], false, 1100);
$life = affOf('Care Patient', 'Care Medic');
ok($life >= 20 && $life <= 35, "SR15 lifesaving while unconscious ($life)");
// SR16: more treatment of the same injury episode adds nothing.
aid('Care Medic', 'Care Patient', [-0.3, 0.3, 0.0], [-0.1, 0.3, 0.0], true, 1200);
aid('Care Medic', 'Care Patient', [-0.1, 0.3, 0.0], [0.2, 0.3, 0.0], true, 1300);
ok(affOf('Care Patient', 'Care Medic') === $life, 'SR16 same episode no farming');
// Unmeasured treatment earns nothing.
attack('Care Brute', 'Care Patient Three', 1400);
send('aid', ent('Care Medic'), ent('Care Patient Three'), ['before_known'=>false, 'after_known'=>false, 'conscious_before'=>true], 1450);
ok(entryOf('Care Patient Three', 'Care Medic') === null, 'unmeasured aid no credit');
// Routine healing: small band; upgrading to meaningful in the same episode charges only the difference.
aid('Care Medic', 'Care Patient Three', [0.5, 0.9, 0.0], [0.52, 0.9, 0.0], true, 1460);
$routine = affOf('Care Patient Three', 'Care Medic');
ok($routine >= 1 && $routine <= 3, "routine healing band ($routine)");
aid('Care Medic', 'Care Patient Three', [0.4, 0.9, 0.3], [0.6, 0.9, 0.0], true, 1470);
$meaningful = affOf('Care Patient Three', 'Care Medic');
ok($meaningful >= 5 && $meaningful <= 12, "meaningful aid total, not stacked ($meaningful)");
// Run m4: first aid bandages wounds while flesh barely moves; graded by wound points newly covered.
attack('Care Brute', 'Care Bandaged', 1480);
$bv = fn(float $h, float $b, float $w, float $u) => ['health'=>$h, 'blood'=>$b, 'bleed'=>0.0, 'wound'=>$w, 'untreated'=>$u];
function aidW(array $before, array $after, ?bool $cb, int $ts): array {
    $f = ['conscious_before'=>$cb, 'seconds'=>30, 'item'=>'Basic First Aid Kit', 'supplier_serial'=>0];
    foreach (['before_'=>$before, 'after_'=>$after] as $pre => $v) { $f[$pre.'known'] = true; foreach ($v as $k => $x) $f[$pre.$k] = $x; }
    return send('aid', ent('Care Medic'), ent('Care Bandaged', $cb), $f, $ts);
}
aidW($bv(-0.41, 0.30, 230, 230), $bv(-0.42, 0.31, 232, 136), false, 1485);
$band = affOf('Care Bandaged', 'Care Medic');
ok($band >= 5 && $band <= 12, "bandaging 40% of a near-fatal wound: meaningful aid ($band)");
aidW($bv(-0.42, 0.31, 232, 136), $bv(-0.42, 0.33, 232, 20), false, 1490);
$band2 = affOf('Care Bandaged', 'Care Medic');
ok($band2 >= 20 && $band2 <= 35, "finishing the bandaging: lifesaving total, not stacked ($band2)");
$r = aidW($bv(0.844, 1.0, 20, 20), $bv(0.849, 1.0, 19, 18), true, 1495);
ok(affOf('Care Bandaged', 'Care Medic') === $band2, 'tiny top-up adds nothing (same episode)');
$r = send('aid', ent('Care Medic'), ent('Care Full'), ['before_known'=>true, 'before_health'=>0.844, 'before_blood'=>1.0, 'before_bleed'=>0.0,
    'after_known'=>true, 'after_health'=>0.849, 'after_blood'=>1.0, 'after_bleed'=>0.0, 'conscious_before'=>true], 1496);
ok(($r['effects'][0]['status'] ?? '') === 'no_verified_improvement', 'town noise: +0.5% flesh is no treatment');

// SR17: the attacker patching his own victim, or his ally doing it, earns no trust.
$before = affOf('Care Patient', 'Care Brute');
$r = aid('Care Brute', 'Care Patient', [-0.6, 0.3, 0.5], [-0.3, 0.3, 0.0], true, 1500);
ok(($r['effects'][0]['status'] ?? '') === 'no_trust_from_own_harm' && affOf('Care Patient', 'Care Brute') === $before, 'SR17 self-inflicted harm then healing: no trust');
$r = aid('Care Brute Pal', 'Care Patient', [-0.6, 0.3, 0.5], [-0.3, 0.3, 0.0], true, 1510);
ok(($r['effects'][0]['status'] ?? '') === 'no_trust_from_own_harm' && entryOf('Care Patient', 'Care Brute Pal') === null, 'SR17 accomplice loop: no trust');

// SR18: an outsider picking up a conscious person: known suspicion.
send('carry_start', ent('Care Carrier'), ent('Care Carried'), vit('', 0.2, 0.8, 0.0), 3000);
$sus = affOf('Care Carried', 'Care Carrier');
ok($sus >= -12 && $sus <= -5, "SR18 known outsider pickup suspicion ($sus)");
// Unconscious pickup: nothing until the outcome; bed = safe rescue.
send('carry_start', ent('Care Carrier'), ent('Care Carried Two', false), vit('', 0.1, 0.8, 0.0), 3100);
ok(entryOf('Care Carried Two', 'Care Carrier') === null, 'SR18 unconscious pickup not charged');
send('carry_end', ent('Care Carrier'), ent('Care Carried Two', false), ['in_something'=>0, 'chained'=>false, 'dead'=>false] + vit('', 0.1, 0.8, 0.0), 3150);
ok(entryOf('Care Carried Two', 'Care Carrier') === null, 'SR19 a drop alone is no rescue');
send('placed', ent('Care Carrier'), ent('Care Carried Two', false), ['place'=>'bed'] + vit('', 0.1, 0.8, 0.0), 3160);
$bed = affOf('Care Carried Two', 'Care Carrier');
ok($bed >= 8 && $bed <= 18, "SR18 safe bed outcome ($bed)");
send('placed', ent('Care Carrier'), ent('Care Carried Two', false), ['place'=>'bed'] + vit('', 0.1, 0.8, 0.0), 3170);
ok(affOf('Care Carried Two', 'Care Carrier') === $bed, 'SR18 outcome counted once');
// Near death at pickup -> lifesaving class.
send('carry_start', ent('Care Carrier'), ent('Care Carried Three', false), vit('', -0.8, 0.3, 0.4), 3200);
send('carry_end', ent('Care Carrier'), ent('Care Carried Three', false), ['in_something'=>1, 'chained'=>false, 'dead'=>false] + vit('', -0.8, 0.3, 0.4), 3250);
$saved = affOf('Care Carried Three', 'Care Carrier');
ok($saved >= 20 && $saved <= 35, "SR18 lifesaving rescue class ($saved)");
// SR19: carried into a prison while unconscious: learned and charged on waking; unknown placer: nothing.
send('harm', null, ent('Care Carried Four', false), ['level'=>'knockout', 'attribution'=>'none', 'inventory'=>[], 'inventory_total'=>0], 3290);
send('carry_start', ent('Care Carrier'), ent('Care Carried Four', false), vit('', 0.1, 0.8, 0.0), 3300);
send('carry_end', ent('Care Carrier'), ent('Care Carried Four', false), ['in_something'=>0, 'chained'=>false, 'dead'=>false] + vit('', 0.1, 0.8, 0.0), 3310);
send('placed', ent('Care Carrier'), ent('Care Carried Four', false), ['place'=>'prison'] + vit('', 0.1, 0.8, 0.0), 3315);
ok(entryOf('Care Carried Four', 'Care Carrier') === null, 'SR19 caged while unconscious: not yet');
send('recovered', null, ent('Care Carried Four'), ['inventory'=>[], 'inventory_total'=>0], 3400);
$cage = affOf('Care Carried Four', 'Care Carrier');
ok($cage >= -40 && $cage <= -20, "SR19 imprisonment learned on waking ($cage)");
$effects = (int)pg_fetch_result(sql('SELECT count(*) FROM social_effect'), 0, 0);
send('placed', null, ent('Care Carried'), ['place'=>'prison'], 3500);
ok((int)pg_fetch_result(sql('SELECT count(*) FROM social_effect'), 0, 0) === $effects, 'SR19 unknown captor: no invented blame');

// SR20: squad members carrying/caging each other: no penalties; routine bedding nothing; critical rescue positive.
send('carry_start', ent('Care Squad A'), ent('Care Squad B'), vit('', 0.3, 0.9, 0.0), 4000);
send('carry_end', ent('Care Squad A'), ent('Care Squad B'), ['in_something'=>1, 'chained'=>false, 'dead'=>false] + vit('', 0.3, 0.9, 0.0), 4010);
ok(entryOf('Care Squad B', 'Care Squad A') === null, 'SR20 squad carry + routine bedding: nothing');
send('carry_start', ent('Care Squad A'), ent('Care Squad C', false), vit('', -0.9, 0.3, 0.5), 4100);
send('carry_end', ent('Care Squad A'), ent('Care Squad C', false), ['in_something'=>1, 'chained'=>false, 'dead'=>false] + vit('', -0.9, 0.3, 0.5), 4110);
ok(affOf('Care Squad C', 'Care Squad A') >= 20, 'SR20 critical squad rescue positive');
send('carry_start', ent('Care Squad C'), ent('Care Squad B'), vit('', 0.3, 0.9, 0.0), 4200);
send('carry_end', ent('Care Squad C'), ent('Care Squad B'), ['in_something'=>2, 'chained'=>false, 'dead'=>false] + vit('', 0.3, 0.9, 0.0), 4210);
ok(entryOf('Care Squad B', 'Care Squad C') === null, 'SR20 squad caging exempt');

// SR21: food handed to a hungry person and eaten -> food_aid once per day; full, own food, squad routine: nothing.
send('item_transfer', ent('Care Hungry'), ent('Care Donor'), ['items'=>['Dried Meat'=>3], 'to_ground'=>false, 'food_items'=>['Dried Meat'=>3], 'recipient_hunger'=>1.4], 5000);
send('eat', ent('Care Hungry'), null, ['items'=>['Dried Meat'=>1], 'hunger_before'=>1.4, 'hunger_after'=>1.9], 5100);
$fed = affOf('Care Hungry', 'Care Donor');
ok($fed >= 2 && $fed <= 5, "SR21 needed food then eaten ($fed)");
send('eat', ent('Care Hungry'), null, ['items'=>['Dried Meat'=>1], 'hunger_before'=>1.6, 'hunger_after'=>2.0], 5200);
ok(affOf('Care Hungry', 'Care Donor') === $fed, 'SR21 repeated supply same day no farming');
send('eat', ent('Care Hungry'), null, ['items'=>['Bread'=>1], 'hunger_before'=>1.0, 'hunger_after'=>1.4], 5300);
ok(affOf('Care Hungry', 'Care Donor') === $fed, 'SR21 own food earns the donor nothing');
send('item_transfer', ent('Care Full'), ent('Care Donor'), ['items'=>['Dried Meat'=>1], 'to_ground'=>false, 'food_items'=>['Dried Meat'=>1], 'recipient_hunger'=>2.6], 5400);
send('eat', ent('Care Full'), null, ['items'=>['Dried Meat'=>1], 'hunger_before'=>2.6, 'hunger_after'=>2.9], 5500);
ok(entryOf('Care Full', 'Care Donor') === null, 'SR21 full recipient: nothing');
// Extreme hunger in a new day: survival food upgrades the budget.
send('item_transfer', ent('Care Hungry'), ent('Care Donor'), ['items'=>['Dried Meat'=>2], 'to_ground'=>false, 'food_items'=>['Dried Meat'=>2], 'recipient_hunger'=>0.6], 90000);
send('eat', ent('Care Hungry'), null, ['items'=>['Dried Meat'=>1], 'hunger_before'=>0.6, 'hunger_after'=>1.2], 90100);
$surv = affOf('Care Hungry', 'Care Donor') - $fed;
ok($surv >= 6 && $surv <= 12, "SR21 survival food ($surv)");
// Squad routine supply: nothing unless extreme.
send('item_transfer', ent('Care Squad B'), ent('Care Squad A'), ['items'=>['Dried Meat'=>2], 'to_ground'=>false, 'food_items'=>['Dried Meat'=>2], 'recipient_hunger'=>1.5], 96000);
$r = send('eat', ent('Care Squad B'), null, ['items'=>['Dried Meat'=>1], 'hunger_before'=>1.5, 'hunger_after'=>2.0], 96100);
ok(($r['effects'][0]['status'] ?? '') === 'squad_routine_supply' && entryOf('Care Squad B', 'Care Squad A') === null, 'SR21 routine squad supply: nothing');
// Food taken from an unconscious body is no gift.
send('harm', null, ent('Care Patient Three', false), ['level'=>'knockout', 'attribution'=>'none', 'inventory'=>['Raw Meat'=>1], 'inventory_total'=>1], 97000);
send('item_transfer', ent('Care Hungry'), ent('Care Patient Three', false), ['items'=>['Raw Meat'=>1], 'to_ground'=>false, 'food_items'=>['Raw Meat'=>1], 'recipient_hunger'=>0.5], 97010);
$before = affOf('Care Hungry', 'Care Patient Three');
$r = send('eat', ent('Care Hungry'), null, ['items'=>['Raw Meat'=>1], 'hunger_before'=>0.5, 'hunger_after'=>1.0], 97020);
ok(($r['effects'][0]['status'] ?? '') === 'own_food' && affOf('Care Hungry', 'Care Patient Three') === $before, 'food from an unconscious body is no gift');

// SR16: ten distinct lifesaving episodes can reach Bonded (>= 91).
for ($i = 0; $i < 10; ++$i) {
    attack('Care Brute', 'Care Patient Two', 200000 + $i * 5000);
    aid('Care Medic', 'Care Patient Two', [-0.7, 0.3, 0.6], [-0.2, 0.3, 0.0], false, 200100 + $i * 5000);
}
ok(affOf('Care Patient Two', 'Care Medic') >= 91, 'SR16 ten distinct lifesaving rescues reach >= 91 (' . affOf('Care Patient Two', 'Care Medic') . ')');
sql("UPDATE general_settings SET value='off' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
echo "$n care regression checks passed\n";
