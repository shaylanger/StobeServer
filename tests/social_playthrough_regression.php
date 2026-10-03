<?php
declare(strict_types=1);
if(getenv('STOBE_DB_NAME')!=='stobe_social_phase1_test')throw new RuntimeException('Dedicated social DB required');
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/playthrough_schema.php';
require_once __DIR__.'/../lib/playthrough_migrations.php';
$conn=ptp_connect();if(!$conn)throw new RuntimeException('No fixture connection');
$n=0;
function q(string $s,array $p=[]): mixed {global $conn;$r=pg_query_params($conn,$s,$p);if(!$r)throw new RuntimeException(pg_last_error($conn));return $r;}
function ck(bool $v,string $s): void {global $n;if(!$v)throw new RuntimeException($s);$n++;}
function restore(string $schema,array $tables): void {q('SELECT stobe_meta.restore_playthrough_upgraded($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',[$schema,json_encode($tables)]);}
$tables=pts_playthrough_tables();$social=array_values(array_filter($tables,fn($t)=>str_starts_with($t,'social_')));
ck(count($social)===6,'all social tables enrolled');
ck(pts_update_playthrough_policy($conn),'policy comments first');ck(pts_update_playthrough_policy($conn),'policy comments second');
foreach($social as $t)ck(pg_fetch_result(q('SELECT obj_description($1::regclass)',[$t]),0,0)==='Playthrough Manager Backed Up','comment '.$t);
q('BEGIN');
try{
 q("CREATE TABLE social_fixture_unmanaged(k text PRIMARY KEY,v integer)");q("INSERT INTO social_fixture_unmanaged VALUES('sentinel',99)");
 q("INSERT INTO general_settings(id,value) VALUES('SOCIAL_TEST_GLOBAL','keep') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
 q("INSERT INTO conf_opts(id,value) VALUES('SOCIAL_TEST_MIXED','A') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
 q("UPDATE social_effect SET delta=17");
 q('SELECT stobe_meta.capture_playthrough($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',['stobe_profile_social_a',json_encode($tables)]);
 q("UPDATE social_effect SET delta=23");q("UPDATE conf_opts SET value='B' WHERE id='SOCIAL_TEST_MIXED'");
 q('SELECT stobe_meta.capture_playthrough($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',['stobe_profile_social_b',json_encode($tables)]);
 restore('stobe_profile_social_a',$tables);
 ck(pg_fetch_result(q('SELECT MAX(delta) FROM social_effect'),0,0)==='17','A effects restored');
 ck(pg_fetch_result(q("SELECT value FROM conf_opts WHERE id='SOCIAL_TEST_MIXED'"),0,0)==='A','A mixed state restored');
 restore('stobe_profile_social_b',$tables);ck(pg_fetch_result(q('SELECT MAX(delta) FROM social_effect'),0,0)==='23','B effects restored');
 restore('stobe_profile_social_a',$tables);ck(pg_fetch_result(q('SELECT MAX(delta) FROM social_effect'),0,0)==='17','A again restored');
 ck(pg_fetch_result(q("SELECT value FROM general_settings WHERE id='SOCIAL_TEST_GLOBAL'"),0,0)==='keep','global config preserved');
 ck(pg_fetch_result(q('SELECT v FROM social_fixture_unmanaged'),0,0)==='99','unmanaged data preserved');
 $old=array_values(array_diff($tables,$social));
 q('SELECT stobe_meta.capture_playthrough($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',['stobe_profile_social_old',json_encode($old)]);
 $manifest=json_decode(pg_fetch_result(q("SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname='stobe_profile_social_old'"),0,0),true);
 $manifest['table_policy_version']=4;foreach($social as $t)unset($manifest['migrations'][$t]);
 q('COMMENT ON SCHEMA stobe_profile_social_old IS '.pg_escape_literal($conn,json_encode($manifest)));
 $stage=pg_fetch_result(q('SELECT stobe_meta.prepare_playthrough($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',['stobe_profile_social_old',json_encode($tables)]),0,0);
 foreach($social as $t)ck(pg_fetch_result(q('SELECT COUNT(*) FROM '.pg_escape_identifier($conn,$stage).'.'.pg_escape_identifier($conn,$t)),0,0)==='0','old social state initialized empty '.$t);
 q('SELECT stobe_meta.restore_playthrough($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',[$stage,json_encode($tables)]);q('DROP SCHEMA '.pg_escape_identifier($conn,$stage).' CASCADE');ck(pg_fetch_result(q('SELECT COUNT(*) FROM social_effect'),0,0)==='0','old save no borrowed social evidence');
 // A damaged new-policy snapshot must reject rather than use old-save initialization.
 q('SELECT stobe_meta.capture_playthrough($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',['stobe_profile_social_bad',json_encode($old)]);
 q('SAVEPOINT bad_save');
 $r=@pg_query_params($conn,'SELECT stobe_meta.prepare_playthrough($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',['stobe_profile_social_bad',json_encode($tables)]);
 ck($r===false,'new-policy missing social table rejected');q('ROLLBACK TO SAVEPOINT bad_save');
 // New playthrough uses an empty private schema and preserves global/unmanaged state.
 require_once __DIR__.'/../lib/playthrough_home.php';
 require_once __DIR__.'/../lib/playthrough_fresh.php';
 $stage=pg_fetch_result(q('SELECT stobe_meta.prepare_playthrough($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',['stobe_profile_social_a',json_encode($tables)]),0,0);
 pth_prepare_fresh($conn,$stage);
 foreach($social as $t)ck(pg_fetch_result(q('SELECT COUNT(*) FROM '.pg_escape_identifier($conn,$stage).'.'.pg_escape_identifier($conn,$t)),0,0)==='0','fresh state empty '.$t);
 q('SELECT stobe_meta.restore_playthrough($1,ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))',[$stage,json_encode($tables)]);
 ck(pg_fetch_result(q("SELECT value FROM general_settings WHERE id='SOCIAL_TEST_GLOBAL'"),0,0)==='keep','fresh activation preserves global');
 ck(pg_fetch_result(q('SELECT v FROM social_fixture_unmanaged'),0,0)==='99','fresh activation preserves unmanaged');
 echo "$n social snapshot/migration checks passed\n";
}finally{q('ROLLBACK');pg_close($conn);}
