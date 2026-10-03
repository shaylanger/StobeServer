<?php
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
require_once __DIR__ . '/social_relationship_unit.php';
$db=$GLOBALS['db'];
function q(string $sql,array $p=[]): mixed { $r=$GLOBALS['db']->exec($sql,$p);if($r===false)throw new RuntimeException('Test SQL failed');return $r; }
function countRows(string $table): int {return (int)pg_fetch_result(q("SELECT count(*) FROM $table"),0,0);}
function aff(string $a,string $b): int {return (int)(stobeRelationshipEntryFor(getNpcData($a),$b)['aff'] ?? 0);}
foreach(['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t)q("DELETE FROM $t");
foreach(['Social A','Social B'] as $name){ q('DELETE FROM core_npc_master_history WHERE name=$1',[$name]);q('DELETE FROM core_npc WHERE name=$1',[$name]);q('INSERT INTO core_npc(name,extended_data,metadata) VALUES($1,\'{}\'::jsonb,jsonb_build_object(\'storage_id\',$2::text))',[$name,$name==='Social A'?'helper':'victim']); }
q("INSERT INTO general_settings(id,value) VALUES('SOCIAL_RELATIONSHIP_MODE','enabled') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
$scope=['campaign_id'=>'campaign','timeline_epoch'=>'1','native_session_id'=>'session'];
$store=new SocialStore($db);$e=fixture();
check($store->ingest($e,$scope,'off')['status']==='disabled' && countRows('social_event_inbox')===0,'off no durable side effect');
check($store->ingest($e,$scope,'enabled')['status']==='captured','capture');
check($store->ingest($e,$scope,'enabled')['status']==='duplicate','retry dedup');
$bad=$e;$bad['facts']['message']='changed'; rejects(fn()=>$store->ingest($bad,$scope,'enabled'),'payload conflict');
$bad=$e;$bad['timeline_epoch']='2'; rejects(fn()=>$store->ingest($bad,$scope,'enabled'),'stale epoch');
$resolve=fn($key)=>stobeSocialResolveEntity($key);
$b=['responsible_entity'=>'helper','awareness'=>'verified_aid','confidence'=>'certain','conscious'=>false,'note'=>'Treated critical wounds'];
$r=$store->apply($e,'victim','helper','lifesaving',$b,$resolve,'enabled');
check($r['status']==='enabled' && aff('Social B','Social A')>=20,'directed canonical write');
check(aff('Social A','Social B')===0,'no symmetric write');
check(countRows('social_effect')===1 && countRows('social_evidence')===1 && countRows('social_belief')===1,'atomic durable state');
check($store->apply($e,'victim','helper','lifesaving',$b,$resolve,'enabled')['status']==='duplicate','effect dedup');
$e2=fixture(2);$e2['game_ts']=200;$store->ingest($e2,$scope,'enabled');
$unknown=$b;$unknown['awareness']='unknown';check($store->apply($e2,'victim','helper','theft',$unknown,$resolve,'enabled')['status']==='pending_awareness','latent negative');
$store->pending($e2,'victim',[['kind'=>'missing_property','remembered_ko_actor'=>'helper']]);
$pending=$db->fetchOne('SELECT state FROM social_incident WHERE incident_id=$1',[$e2['incident_id']]);
check(count(json_decode($pending['state'],true)['pending']['victim'])===1,'pending survives storage');
$e3=fixture(3);$store->ingest($e3,$scope,'enabled');$harm=$b;$harm['awareness']='directly_experienced';$harm['conscious']=true;
$store->apply($e3,'victim','helper','serious_assault',$harm,$resolve,'enabled');
$before=countRows('social_effect');
$e4=fixture(4);$store->ingest($e4,$scope,'shadow');$old=aff('Social B','Social A');
$store->apply($e4,'victim','helper','lifesaving',$b,$resolve,'shadow');
check(aff('Social B','Social A')===$old,'shadow leaves affinity unchanged');
$store2=new SocialStore($db);check($store2->ingest($e4,$scope,'shadow')['status']==='duplicate','restart duplicate durable');
$late=fixture(0);$late['event_id']='late';$late['incident_id']='late';check($store->ingest($late,$scope,'enabled')['status']==='late','late data preserved unscored');
$setup=fixture(5);$setup['origin']='setup';check($store->ingest($setup,$scope,'enabled')['status']==='setup','setup separated');
$e6=fixture(6);$store->ingest($e6,$scope,'enabled');
q("INSERT INTO general_settings(id,value) VALUES('SOCIAL_CATEGORY_AID','false') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
$disabledCount=countRows('social_effect');
check($store->apply($e6,'victim','helper','meaningful_aid',$b,$resolve,'enabled')['status']==='category_disabled' && countRows('social_effect')===$disabledCount,'category flag no effect');
q("DELETE FROM general_settings WHERE id='SOCIAL_CATEGORY_AID'");
// Inject a failing canonical timeline trigger; failed effect must leave no partial map/ledger.
q("CREATE OR REPLACE FUNCTION social_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''fixture history failure''; END;'");
q('CREATE TRIGGER social_test_failure BEFORE INSERT ON core_npc_master_history FOR EACH ROW EXECUTE FUNCTION social_test_fail()');
$old=aff('Social B','Social A');$ledger=countRows('social_effect');
rejects(fn()=>$store->apply($e6,'victim','helper','meaningful_aid',$b,$resolve,'enabled'),'failed snapshot rolls back');
q('DROP TRIGGER social_test_failure ON core_npc_master_history');q('DROP FUNCTION social_test_fail()');
check(aff('Social B','Social A')===$old && countRows('social_effect')===$ledger,'failure atomicity');
$base=getNpcData('Social B');$baseMap=stobeGetNpcRelationshipMap($base);
$u1=stobeApplyRelationshipUpdatesMap($baseMap,[['target'=>'Social A','aff_delta'=>3]]);
$u2=stobeApplyRelationshipUpdatesMap($baseMap,[['target'=>'Social A','aff_delta'=>4]]);
check(stobePersistNpcRelationshipMap('Social B',$u1['map'],$base),'first stale writer');
check(stobePersistNpcRelationshipMap('Social B',$u2['map'],$base),'second stale writer');
check(aff('Social B','Social A')===$old+7,'parallel-base deltas preserved');
setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS','600');
stobePlaythroughRestoreRelationshipStates(150);$store->rollback(150);
check(countRows('social_effect')===1 && countRows('social_evidence')===1,'rollback effect and evidence');
check(countRows('social_event_inbox')===2,'rollback removes future, retains late diagnostic');
check(aff('Social B','Social A')>=20,'relationship timeline restored');
require_once __DIR__ . '/../lib/playthrough_policy.php';
foreach(['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint'] as $t)check(in_array($t,pts_playthrough_tables(),true),'save policy '.$t);
check(stobeRelationshipOnAttack('Social A: Initiated attack (talking to: Social B)')===[],'R4 mutual exclusion');
echo "$n total unit/integration checks passed\n";
