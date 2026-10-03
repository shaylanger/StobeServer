<?php
declare(strict_types=1);
if(getenv('STOBE_DB_NAME')!=='stobe_social_phase1_test')throw new RuntimeException('Dedicated social DB required');
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/social_runtime.php';
$db=$GLOBALS['db'];
$db->exec("INSERT INTO general_settings(id,value) VALUES('SOCIAL_RELATIONSHIP_MODE','enabled') ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value");
$base=getNpcData('Social B');$map=stobeGetNpcRelationshipMap($base);$before=(int)(stobeRelationshipEntryFor($base,'Social A')['aff']??0);
$dir=sys_get_temp_dir().'/social-race-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$worker=<<<'WORKER'
require 'lib/bootstrap.php';require 'lib/social_runtime.php';
$input=json_decode(file_get_contents($argv[1]),true);
while(!file_exists($argv[2]))usleep(1000);
if(!stobePersistNpcRelationshipMap('Social B',$input['map'],$input['base']))exit(1);
echo 'ok';
WORKER;
$process=[];
try {
 foreach([3,4] as $i=>$delta){
  $path="$dir/$i.json";file_put_contents($path,json_encode(['base'=>$base,'map'=>stobeApplyRelationshipUpdatesMap($map,[['target'=>'Social A','aff_delta'=>$delta]])['map']]));
  $pipes=[];$p=proc_open([PHP_BINARY,'-r',$worker,$path,"$dir/go"],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
  if(!is_resource($p))throw new RuntimeException('Worker startup');fclose($pipes[0]);$process[]=[$p,$pipes];
 }
 touch("$dir/go");
 foreach($process as [$p,$pipes]){ $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);if($exit!==0 || $out!=='ok')throw new RuntimeException('Concurrent writer failed: '.$err); }
 $after=(int)(stobeRelationshipEntryFor(getNpcData('Social B'),'Social A')['aff']??0);
 if($after!==$before+7)throw new RuntimeException("Concurrent updates lost: $before -> $after");
 // The store must not commit a transaction belonging to a caller.
 $db->exec('BEGIN');$store=new SocialStore($db);$store->rollback(150);
 if($db->transactionStatus()!==PGSQL_TRANSACTION_INTRANS)throw new RuntimeException('Nested store committed outer transaction');
 $db->exec('ROLLBACK');
 $db->exec('BEGIN');
 $event=json_decode($db->fetchOne("SELECT payload FROM social_event_inbox WHERE status='captured' LIMIT 1")['payload'],true);
 $event['campaign_id']='cap';$event['native_session_id']='cap';$event['event_id']='cap1';$event['incident_id']='cap1';$event['sequence']=1;
 $scope=['campaign_id'=>'cap','native_session_id'=>'cap','timeline_epoch'=>$event['timeline_epoch']];
 $store->ingest($event,$scope,'enabled');
 $db->exec("INSERT INTO social_incident(campaign_id,timeline_epoch,incident_id,state,game_ts,last_sequence) SELECT 'cap',$1,'pending'||g,'{\"phase\":\"pending_awareness\"}'::jsonb,100,1 FROM generate_series(1,256) g",[$event['timeline_epoch']]);
 $rejected=false;
 try{$store->pending($event,'victim',[]);}catch(LengthException $e){$rejected=true;}
 if(!$rejected)throw new RuntimeException('Pending state exceeded its cap');
 if($db->transactionStatus()!==PGSQL_TRANSACTION_INTRANS)throw new RuntimeException('Rejected nested operation broke outer transaction');
 $db->exec('ROLLBACK');
 $db->exec('BEGIN');
 $current=getNpcData('Social B');$currentMap=stobeGetNpcRelationshipMap($current);
 $maxMap=stobeApplyRelationshipUpdatesMap($currentMap,[['target'=>'Social A','aff_delta'=>200]])['map'];
 if(!stobePersistNpcRelationshipMap('Social B',$maxMap,$current))throw new RuntimeException('Cap fixture update');
 $event['campaign_id']='max';$event['native_session_id']='max';$event['event_id']='max1';$event['incident_id']='max1';
 $scope=['campaign_id'=>'max','native_session_id'=>'max','timeline_epoch'=>$event['timeline_epoch']];
 $store->ingest($event,$scope,'enabled');
 $belief=['responsible_entity'=>'helper','awareness'=>'verified_aid','confidence'=>'certain','conscious'=>false];
 $result=$store->apply($event,'victim','helper','lifesaving',$belief,fn($k)=>stobeSocialResolveEntity($k),'enabled');
 $evidence=$db->fetchOne("SELECT evidence FROM social_evidence WHERE campaign_id='max'");
 if($result['effect']['delta']!==0 || (json_decode($evidence['evidence']??'{}',true)['lifesaving']??0)!==1)throw new RuntimeException('Verified rescue trust lost at affinity cap');
 $db->exec('ROLLBACK');
 echo "7 concurrency/transaction/cap checks passed\n";
}finally{
 if($db->transactionStatus()!==PGSQL_TRANSACTION_IDLE)$db->exec('ROLLBACK');
 foreach(glob("$dir/*") as $f)unlink($f);rmdir($dir);
}
