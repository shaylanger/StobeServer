<?php
// REL phase 2 (combat) regression: structured attack/harm events through SocialStore::ingest.
// Oracles are pinned rule ranges and directions, never the function under test.
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
$db = $GLOBALS['db']; $n = 0;
function ok(bool $c, string $what): void { global $n; if (!$c) throw new RuntimeException('FAIL: ' . $what); ++$n; }
function sql(string $s, array $p = []): mixed { $r = $GLOBALS['db']->exec($s, $p); if ($r === false) throw new RuntimeException('SQL failed: ' . $s); return $r; }
function affOf(string $a, string $b): int { return (int)(stobeRelationshipEntryFor(getNpcData($a), $b)['aff'] ?? 0); }
function rows(string $t): int { return (int)pg_fetch_result(sql("SELECT count(*) FROM $t"), 0, 0); }

const NAMES = ['Combat Alpha'=>[101,'Red'], 'Combat Bravo'=>[102,'Blue'], 'Combat Charlie'=>[103,'Blue'], 'Combat Delta'=>[104,'Green'], 'Combat Echo'=>[105,'Grey'], 'Combat Foxtrot'=>[106,'Yellow']];
foreach (['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t) sql("DELETE FROM $t");
foreach (NAMES as $name => [$serial, $faction]) {
    sql('DELETE FROM core_npc_master_history WHERE name=$1', [$name]); sql('DELETE FROM core_npc WHERE name=$1', [$name]);
    sql("INSERT INTO core_npc(name,extended_data,metadata) VALUES($1,'{}'::jsonb,jsonb_build_object('storage_id',$2::text))", [$name, 'hand_' . $serial]);
}
sql("DELETE FROM core_npc WHERE name IN ('Zed [Combat Grunt]')");
sql("INSERT INTO core_npc(name,extended_data,metadata) VALUES('Zed [Combat Grunt]','{}'::jsonb,'{}'::jsonb)");
sql("INSERT INTO general_settings(id,value) VALUES('SOCIAL_RELATIONSHIP_MODE','enabled') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");

$scope = ['campaign_id'=>'camp2', 'timeline_epoch'=>'7', 'native_session_id'=>'sess2'];
$store = new SocialStore($db);
$seq = 0;
function ent(string $name, ?bool $conscious = true, ?string $storage = null): array {
    [$serial, $faction] = NAMES[$name] ?? [900, 'None'];
    return ['entity_key'=>"sess2:7:$serial", 'serial'=>$serial, 'name'=>$name, 'storage_id'=>$storage ?? "hand_$serial",
        'faction'=>$faction, 'in_player_faction'=>false, 'conscious'=>$conscious];
}
function ev(string $kind, ?array $actor, ?array $target, array $facts, int $ts): array {
    global $seq; ++$seq;
    return ['schema_version'=>1, 'event_id'=>"sess2:7:$seq", 'incident_id'=>"sess2:7:$seq", 'campaign_id'=>'camp2', 'timeline_epoch'=>'7',
        'native_session_id'=>'sess2', 'sequence'=>$seq, 'game_ts'=>$ts, 'event_kind'=>$kind, 'origin'=>'gameplay',
        'actor'=>$actor, 'target'=>$target, 'state_before'=>[], 'state_after'=>[], 'witnesses'=>[], 'facts'=>['source'=>'structured'] + $facts];
}
function send(array $e, string $mode = 'enabled'): array { global $store, $scope; return $store->ingest($e, $scope, $mode); }
[$A, $B, $C, $D] = [ent('Combat Alpha'), ent('Combat Bravo'), ent('Combat Charlie'), ent('Combat Delta')];

// SR02: A attacks B -> B->A loss in the aggression range; A->B untouched.
$first = ev('attack', $A, $B, ['victim_targeting_actor'=>false], 1000);
$r = send($first);
ok($r['status'] === 'captured', 'attack captured');
$ba = affOf('Combat Bravo', 'Combat Alpha');
ok($ba >= -15 && $ba <= -8, "SR02 aggression range ($ba)");
ok(affOf('Combat Alpha', 'Combat Bravo') === 0, 'SR02 attacker unchanged');
// SR35: transport retry of the same event is a duplicate, no second effect.
ok(send($first)['status'] === 'duplicate' && affOf('Combat Bravo', 'Combat Alpha') === $ba, 'SR35 retry dedup');

// SR03: B hits back -> no aggression penalty A->B, no ledger row with A as observer.
send(ev('attack', $B, $A, ['victim_targeting_actor'=>true], 1010));
send(ev('attack', $B, $A, ['victim_targeting_actor'=>true], 1012));
ok(affOf('Combat Alpha', 'Combat Bravo') === 0, 'SR03 retaliation costs nothing');
ok((int)pg_fetch_result(sql("SELECT count(*) FROM social_effect WHERE observer_key='sess2:7:101'"), 0, 0) === 0, 'SR03 no ledger for the aggressor');
// The same A attack again in the same encounter: no second aggression charge.
send(ev('attack', $A, $B, ['victim_targeting_actor'=>true], 1015));
ok(affOf('Combat Bravo', 'Combat Alpha') === $ba, 'SR05 repeated hits same encounter no stacking');

// SR05: injury -> KO escalate one budget; final within the KO band, never the stacked sum.
send(ev('harm', $A, $B, ['level'=>'injury', 'attribution'=>'attacker_list'], 1020));
$injury = affOf('Combat Bravo', 'Combat Alpha');
ok($injury <= $ba && $injury >= -28, "SR05 injury escalation ($injury)");
send(ev('harm', $A, ent('Combat Bravo', false), ['level'=>'knockout', 'attribution'=>'defeated_by'], 1030));
$ko = affOf('Combat Bravo', 'Combat Alpha');
ok($ko <= -25 && $ko >= -40, "SR05 KO budget, not stacked ($ko)");
send(ev('harm', $A, ent('Combat Bravo', false), ['level'=>'knockout', 'attribution'=>'defeated_by'], 1031));
ok(affOf('Combat Bravo', 'Combat Alpha') === $ko, 'SR05 duplicate KO poll/hook applies once');
ok(affOf('Combat Alpha', 'Combat Bravo') === 0, 'SR05 attacker still unchanged');

// SR07: the defender (B) maims the aggressor (A): a separate grievance A->B, once.
send(ev('harm', $B, $A, ['level'=>'maiming', 'limb'=>'left arm', 'attribution'=>'defeated_by'], 1040));
$grievance = affOf('Combat Alpha', 'Combat Bravo');
ok($grievance >= -20 && $grievance <= -8, "SR07 defensive maiming grievance ($grievance)");
send(ev('harm', $B, $A, ['level'=>'maiming', 'limb'=>'left arm', 'attribution'=>'defeated_by'], 1041));
ok(affOf('Combat Alpha', 'Combat Bravo') === $grievance, 'SR07 duplicate limb event once');
ok(pg_fetch_result(sql("SELECT component FROM social_effect WHERE observer_key='sess2:7:101'"), 0, 0) === 'defensive_maiming', 'SR07 no aggression tag on defender');
// Defender's ordinary harm costs nothing.
$before = affOf('Combat Alpha', 'Combat Bravo');
send(ev('harm', $B, $A, ['level'=>'injury', 'attribution'=>'attacker_list'], 1042));
ok(affOf('Combat Alpha', 'Combat Bravo') === $before, 'SR03 defender injury no penalty');

// SR04: C (Blue, B's ally) defends B by attacking A -> no A->C penalty.
send(ev('attack', $C, $A, ['victim_targeting_actor'=>false], 1050));
ok(affOf('Combat Alpha', 'Combat Charlie') === 0, 'SR04 defender of a friend not an aggressor');
// Late joiner D (Green, unrelated) attacks B -> B->D aggression.
send(ev('attack', $D, $B, ['victim_targeting_actor'=>false], 1055));
$bd = affOf('Combat Bravo', 'Combat Delta');
ok($bd >= -15 && $bd <= -8, "SR04 late joiner is an aggressor ($bd)");
// C (B's ally) attacks D, who is attacking B: defence, no D->C penalty.
send(ev('attack', $C, $D, ['victim_targeting_actor'=>false], 1060));
ok(affOf('Combat Delta', 'Combat Charlie') === 0, 'SR04 defending a friend against the late joiner');
// Unrelated pair: F (Yellow, nobody's ally) attacks D -> D->F aggression, not mislabeled as defence.
send(ev('attack', ent('Combat Foxtrot'), $D, ['victim_targeting_actor'=>false], 1062));
$df = affOf('Combat Delta', 'Combat Foxtrot');
ok($df >= -15 && $df <= -8, "SR04 unrelated pair keeps its own origin ($df)");

// Harm without an observed encounter, and unknown attacker: never scored.
send(ev('harm', $A, $D, ['level'=>'knockout', 'attribution'=>'defeated_by'], 1070));
ok(affOf('Combat Delta', 'Combat Alpha') === 0, 'no invented intent without an encounter');
$effects = rows('social_effect');
send(ev('harm', null, $D, ['level'=>'injury', 'attribution'=>'none'], 1071));
ok(rows('social_effect') === $effects, 'unknown attacker recorded only');

// Latent: attack on an unconscious victim -> no change now, pending fact stored.
$E0 = ent('Combat Echo', false);
send(ev('attack', $A, $E0, ['victim_targeting_actor'=>false], 1080));
ok(affOf('Combat Echo', 'Combat Alpha') === 0, 'unconscious victim not charged immediately');
$pend = pg_fetch_result(sql("SELECT state->'pending'->'sess2:7:105' FROM social_incident WHERE state->>'pair' LIKE '%sess2:7:105%'"), 0, 0);
ok(is_string($pend) && str_contains($pend, 'aggression'), 'latent aggression remembered');
// Unknown consciousness never becomes knowledge.
send(ev('attack', $D, ent('Combat Echo', null), ['victim_targeting_actor'=>false], 1085));
ok(affOf('Combat Echo', 'Combat Delta') === 0, 'unknown consciousness no effect');

// SR06: a second distinct assault after the idle window is a new incident, weighted up to 1.15.
$prev = affOf('Combat Bravo', 'Combat Alpha');
send(ev('attack', $A, $B, ['victim_targeting_actor'=>false], 1030 + 2000));
$delta = affOf('Combat Bravo', 'Combat Alpha') - $prev;
ok($delta <= -9 && $delta >= -18, "SR06 second distinct assault ($delta)");

// SR34: generic template name and a reused serial with another stored storage id: no effect.
$grunt = ['entity_key'=>'sess2:7:300', 'serial'=>300, 'name'=>'Combat Grunt', 'storage_id'=>'hand_300', 'faction'=>'Grey', 'in_player_faction'=>false, 'conscious'=>true];
$count = rows('social_evidence');
send(ev('attack', $grunt, $C, ['victim_targeting_actor'=>false], 4000));
ok(affOf('Combat Charlie', 'Combat Grunt') === 0, 'SR34 generic culprit name not written');
$imposter = ent('Combat Delta', true, 'hand_999');
send(ev('attack', $A, $imposter, ['victim_targeting_actor'=>false], 4010));
ok(affOf('Combat Delta', 'Combat Alpha') === 0, 'SR34 reused serial / other storage id not attributed');

// Death closes the encounter; later harm in it is not scored.
send(ev('harm', null, $C, ['level'=>'death'], 4020));
ok((int)pg_fetch_result(sql("SELECT count(*) FROM social_incident WHERE state->>'phase'='active' AND state->'parties' ? 'sess2:7:103'"), 0, 0) === 0, 'death closes encounters');

// SR41: shadow mode records would-be effects but never changes affinity.
$shadowBefore = affOf('Combat Echo', 'Combat Charlie');
send(ev('attack', $C, ent('Combat Echo'), ['victim_targeting_actor'=>false], 5000), 'shadow');
ok(affOf('Combat Echo', 'Combat Charlie') === $shadowBefore, 'SR41 shadow no affinity');
ok((int)pg_fetch_result(sql("SELECT count(*) FROM social_effect WHERE NOT applied AND observer_key='sess2:7:105'"), 0, 0) >= 1, 'SR41 shadow ledger row');
// Switching to enabled cannot replay the shadow incident (same encounter: duplicate).
send(ev('attack', $C, ent('Combat Echo'), ['victim_targeting_actor'=>false], 5005));
ok(affOf('Combat Echo', 'Combat Charlie') === $shadowBefore, 'SR41 no retroactive backlog after enabling');
// Setup-origin events are never scored.
$setup = ev('attack', $D, $A, ['victim_targeting_actor'=>false], 6000); $setup['origin'] = 'setup';
ok(send($setup)['status'] === 'setup' && affOf('Combat Alpha', 'Combat Delta') === 0, 'setup events not scored');
// Category flag off: no combat effects.
sql("INSERT INTO general_settings(id,value) VALUES('SOCIAL_CATEGORY_COMBAT','false') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
send(ev('attack', $D, $A, ['victim_targeting_actor'=>false], 6100));
ok(affOf('Combat Alpha', 'Combat Delta') === 0, 'category combat off');
sql("DELETE FROM general_settings WHERE id='SOCIAL_CATEGORY_COMBAT'");
// R4 stays out while enabled.
ok(stobeRelationshipOnAttack('Combat Alpha: Initiated attack (talking to: Combat Bravo)') === [], 'R4 mutually exclusive');
// SR43: bounded per event: an attack writes at most one incident (+ one checkpoint per touched incident).
ok(rows('social_incident') < 40 && rows('social_checkpoint') < 80, 'bounded incident growth');

sql("UPDATE general_settings SET value='off' WHERE id='SOCIAL_RELATIONSHIP_MODE'");
echo "$n combat regression checks passed\n";
