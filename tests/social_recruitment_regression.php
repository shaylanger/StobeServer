<?php
// REL phase 7 (recruitment gate, slave escape) regression.
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
require_once __DIR__ . '/../lib/relationship_manager.php';
require_once __DIR__ . '/../lib/social_recruitment.php';
$db = $GLOBALS['db']; $n = 0;
function ok(bool $c, string $what): void { global $n; if (!$c) throw new RuntimeException('FAIL: ' . $what); ++$n; }
function sql(string $s, array $p = []): mixed { $r = $GLOBALS['db']->exec($s, $p); if ($r === false) throw new RuntimeException('SQL failed: ' . $s); return $r; }
function affOf(string $a, string $b): int { return (int)(stobeRelationshipEntryFor(getNpcData($a), $b)['aff'] ?? 0); }
function setting(string $id, ?string $v): void {
    if ($v === null) sql('DELETE FROM general_settings WHERE id=$1', [$id]);
    else sql('INSERT INTO general_settings(id,value) VALUES($1,$2) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value', [$id, $v]);
}
$player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
const P = ['Rec Slave'=>[601,'Slaves',false], 'Rec Owner'=>[602,'Slavers',false], 'Rec Liberator'=>[603,'Nameless',true], 'Rec Slave Two'=>[604,'Slaves',false],
    'Rec Candidate'=>[611,'Blue',false]];
foreach (['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t) sql("DELETE FROM $t");
foreach (P as $name => [$serial]) {
    sql('DELETE FROM core_npc_master_history WHERE name=$1', [$name]); sql('DELETE FROM core_npc WHERE name=$1', [$name]);
    sql("INSERT INTO core_npc(name,extended_data,metadata) VALUES($1,'{}'::jsonb,jsonb_build_object('storage_id',$2::text))", [$name, 'hand_' . $serial]);
}
setting('SOCIAL_RELATIONSHIP_MODE', 'enabled');
$store = new SocialStore($db); $seq = 0;
function ent(string $name, ?bool $conscious = true): array {
    [$serial, $faction, $squad] = P[$name];
    return ['entity_key'=>"s7:4:$serial", 'serial'=>$serial, 'name'=>$name, 'storage_id'=>"hand_$serial", 'faction'=>$faction, 'in_player_faction'=>$squad, 'conscious'=>$conscious];
}
function send(string $kind, ?array $actor, ?array $target, array $facts, int $ts): array {
    global $seq, $store; ++$seq;
    $e = ['schema_version'=>1, 'event_id'=>"s7:4:$seq", 'incident_id'=>"s7:4:$seq", 'campaign_id'=>'camp7', 'timeline_epoch'=>'4', 'native_session_id'=>'s7',
        'sequence'=>$seq, 'game_ts'=>$ts, 'event_kind'=>$kind, 'origin'=>'gameplay', 'actor'=>$actor, 'target'=>$target,
        'state_before'=>[], 'state_after'=>[], 'witnesses'=>[], 'facts'=>['source'=>'structured'] + $facts];
    $r = $store->ingest($e, ['campaign_id'=>'camp7', 'timeline_epoch'=>'4', 'native_session_id'=>'s7'], 'enabled');
    if (($r['status'] ?? '') !== 'captured') throw new RuntimeException("FAIL: event $seq not captured");
    return $r;
}
function evidence(string $npc, string $culprit, string $component, int $delta = 10): void {
    global $n;
    sql("INSERT INTO social_effect(campaign_id,timeline_epoch,incident_id,observer_key,culprit_key,component,game_ts,delta,detail,applied,rules_version)
         VALUES('camp7','4',$1,'o','c',$2,1,$3,jsonb_build_object('observer_name',$4::text,'culprit_name',$5::text,'total',$3::int),true,'t')",
        ['ev:' . bin2hex(random_bytes(4)), $component, $delta, $npc, $culprit]);
}

// SR30: threshold + trust + grievance.
$d = fn(int $aff) => stobeSocialRecruitmentDecision('Rec Candidate', $player, $aff);
ok(!$d(90)['allowed'] && $d(90)['reason'] === 'no_trust_evidence', 'SR30 high affinity without trust evidence: no');
evidence('Rec Candidate', $player, 'exceptional_trade', 6);
ok(!$d(90)['allowed'], 'SR22/SR30 economic evidence never qualifies');
evidence('Rec Candidate', $player, 'lifesaving', 25);
ok(!$d(75)['allowed'] && $d(75)['reason'] === 'affinity_below_76', 'SR30 75 with lifesaving: no');
ok($d(76)['allowed'], 'SR30 76 with lifesaving: yes');
evidence('Rec Candidate', $player, 'enslavement', -70);
ok(!$d(95)['allowed'] && $d(95)['reason'] === 'severe_grievance', 'SR30 severe grievance blocks');
setting('SOCIAL_RECRUITMENT_OVERRIDE', 'true');
ok($d(10)['allowed'] && $d(10)['reason'] === 'override', 'SR31 documented forced override');
setting('SOCIAL_RECRUITMENT_OVERRIDE', null);
setting('SOCIAL_RELATIONSHIP_MODE', 'shadow');
ok($d(10)['allowed'] && $d(10)['reason'] === 'rel_not_enabled', 'SR33/SR41 legacy behaviour while REL is not enabled');
setting('SOCIAL_RELATIONSHIP_MODE', 'enabled');

// SR31: the gate sits on the dispatch path (chat + director configs, action normalizer), not only the prompt.
sql("DELETE FROM social_effect WHERE detail->>'observer_name'='Rec Candidate' AND component='enslavement'");
RelationshipManager::setRelationship('Rec Candidate', $player, 50);
$npc = getNpcData('Rec Candidate');
foreach (['chat', 'director'] as $type) {
    $config = stobeBuildActionConfigForNpc($type, $npc);
    if (!isAllowedActionCommand('JOIN_PARTY', $config['allowlist'] ?? [])) { ok(true, "SR31 $type: JoinParty not offered at all"); continue; }
    ok(!empty($config['disallow_join_party']), "SR31 $type config blocks JoinParty at affinity 50");
    ok(normalizeActionTagToken('JoinParty@', $config) === '', "SR31 $type dispatch drops JoinParty@");
}
RelationshipManager::setRelationship('Rec Candidate', $player, 80);
$npc = getNpcData('Rec Candidate');
$config = stobeBuildActionConfigForNpc('chat', $npc);
ok(empty($config['disallow_join_party']), 'SR30 trusted at 80: JoinParty allowed');
if (isAllowedActionCommand('JOIN_PARTY', $config['allowlist'] ?? [])) ok(normalizeActionTagToken('JoinParty@', $config) === 'JOIN_PARTY@', 'SR30 trusted dispatch passes');

// REL_THEFT_CAUGHT_M18 SR30 test switch: off = the LLM's own action; on + a join request = JoinParty, which the gate decides.
$GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] = 'Rec, join my squad right now.';
setting('SOCIAL_TEST_FORCE_JOIN_ATTEMPT', null);
ok(stobeSocialTestForceJoinAttempt('', 'Rec Candidate', 'chat') === '', 'SR30 switch off: nothing forced');
setting('SOCIAL_TEST_FORCE_JOIN_ATTEMPT', 'true');
ok(stobeSocialTestForceJoinAttempt('Talk@', 'Rec Candidate', 'chat') === 'JoinParty@', 'SR30 switch on: JoinParty forced');
ok(stobeSocialTestForceJoinAttempt('', 'Rec Candidate', 'director') === '', 'SR30 switch: chat only');
$GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] = 'Nice weather.';
ok(stobeSocialTestForceJoinAttempt('', 'Rec Candidate', 'chat') === '', 'SR30 switch: only on a join request');
$GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] = 'Rec, join my squad right now.';
$reply = '{"character":"Rec Candidate","message":"Maybe later.","action":"Talk"}';
$joinOffered = isAllowedActionCommand('JOIN_PARTY', stobeBuildActionConfigForNpc('chat', getNpcData('Rec Candidate'))['allowlist'] ?? []);
if ($joinOffered) {
    ok(stobeParseStructuredDialogueResponse($reply, 'chat')['action_tag'] === 'JOIN_PARTY@', 'SR30 forced join at 80 + lifesaving passes the gate');
    RelationshipManager::setRelationship('Rec Candidate', $player, 50);
    ok(stobeParseStructuredDialogueResponse($reply, 'chat')['action_tag'] === '', 'SR30 forced join at 50 is blocked by the gate');
} else ok(true, 'SR30 JoinParty not in the chat allowlist here');
setting('SOCIAL_TEST_FORCE_JOIN_ATTEMPT', null);
unset($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE']);

// SR32: slave escape. Unchained by a known liberator: chains_freed now; seen free after a game day: escape budget.
send('freed', ent('Rec Owner'), ent('Rec Slave'), ['owner_role'=>'owner', 'liberator'=>ent('Rec Liberator'), 'liberator_task'=>1], 1000);
$chains = affOf('Rec Slave', 'Rec Liberator');
ok($chains >= 8 && $chains <= 18, "SR32 chains freed ($chains)");
ok(!in_array('slave_escape', stobeSocialRecruitmentDecision('Rec Slave', 'Rec Liberator', 90)['trust'], true), 'SR32 chains alone are no completed escape');
send('recovered', null, ent('Rec Slave'), ['inventory'=>[], 'inventory_total'=>0], 1000 + 3600);
ok(affOf('Rec Slave', 'Rec Liberator') === $chains, 'SR32 not complete before the sustain window');
send('recovered', null, ent('Rec Slave'), ['inventory'=>[], 'inventory_total'=>0], 1000 + 86400 + 10);
$total = affOf('Rec Slave', 'Rec Liberator');
ok($total >= 15 && $total <= 65 && $total >= $chains, "SR32 completed escape: one budget 15..65 ($total)");
ok(in_array('slave_escape', stobeSocialRecruitmentDecision('Rec Slave', 'Rec Liberator', 90)['trust'], true), 'SR32 completed escape is trust evidence');
send('recovered', null, ent('Rec Slave'), ['inventory'=>[], 'inventory_total'=>0], 1000 + 86400 * 2);
ok(affOf('Rec Slave', 'Rec Liberator') === $total, 'SR32 escape counted once');
// Re-enslaved before the window: no completion. Chains off without a liberator: nobody credited.
send('freed', ent('Rec Owner'), ent('Rec Slave Two'), ['owner_role'=>'owner', 'liberator'=>ent('Rec Liberator'), 'liberator_task'=>1], 200000);
$c2 = affOf('Rec Slave Two', 'Rec Liberator');
send('enslaved', ent('Rec Owner'), ent('Rec Slave Two'), ['owner_role'=>'owner'], 200100);
send('recovered', null, ent('Rec Slave Two'), ['inventory'=>[], 'inventory_total'=>0], 200000 + 86400 + 10);
ok(affOf('Rec Slave Two', 'Rec Liberator') === $c2, 'SR32 recaptured: no completed escape');
$r = send('freed', ent('Rec Owner'), ent('Rec Slave'), ['owner_role'=>'owner'], 400000);
ok(($r['effects'][0]['status'] ?? '') === 'no_liberator', 'SR32 unknown liberator: nobody credited');

setting('SOCIAL_RELATIONSHIP_MODE', 'off');
echo "$n recruitment/escape regression checks passed\n";
