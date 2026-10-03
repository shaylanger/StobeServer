<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/social_event_contract.php';
require_once __DIR__ . '/../lib/social_rules.php';
$n = 0;
function check(bool $ok,string $name): void { global $n; if (!$ok) throw new RuntimeException($name); ++$n; }
function rejects(callable $f,string $name): void { try {$f();} catch (Throwable $e) {check(true,$name);return;} check(false,$name); }
function fixture(int $seq=1): array {
 return ['schema_version'=>1,'event_id'=>'e'.$seq,'campaign_id'=>'campaign','timeline_epoch'=>'1','native_session_id'=>'session','incident_id'=>'incident'.$seq,
 'sequence'=>$seq,'game_ts'=>$seq*100,'origin'=>'gameplay','event_kind'=>'healing','actor'=>['entity_key'=>'helper','serial'=>1,'name'=>'Helper'],
 'target'=>['entity_key'=>'victim','serial'=>2,'name'=>'Victim'],'state_before'=>['conscious'=>false],'state_after'=>[], 'facts'=>[], 'witnesses'=>[]];
}
$e=fixture(); check(SocialEventContract::validate($e)['target']['serial']===2,'serial preserved');
foreach (['schema_version'=>2,'game_ts'=>'100','sequence'=>-1,'campaign_id'=>'bad key','event_kind'=>'nope','origin'=>'unknown'] as $k=>$v) { $bad=$e;$bad[$k]=$v; rejects(fn()=>SocialEventContract::validate($bad),'reject '.$k); }
$bad=$e;$bad['state_before']['conscious']='false'; rejects(fn()=>SocialEventContract::validate($bad),'conscious bool strict');
$bad=$e;$bad['witnesses']=array_fill(0,33,[]); rejects(fn()=>SocialEventContract::validate($bad),'witness cap');
$bad=$e;$bad['facts']=['huge'=>str_repeat('x',70000)]; rejects(fn()=>SocialEventContract::validate($bad),'byte cap');
$reordered=array_reverse($e,true); check(SocialEventContract::hash($e)===SocialEventContract::hash($reordered),'canonical duplicate hash');
$b=['responsible_entity'=>'helper','awareness'=>'verified_aid','confidence'=>'certain','conscious'=>false];
check(SocialPerception::permits($b,true),'aid while unconscious');
check(!SocialPerception::permits($b,false),'no negative while unconscious');
$b['awareness']='directly_experienced';$b['conscious']=true;
check(SocialPerception::permits($b,false),'known direct harm');
check(!SocialPerception::permits($b,false,true),'squad control exempt');
$unknown=$b;$unknown['awareness']='unknown'; check(!SocialPerception::permits($unknown,false),'unknown awareness');
$unknown=$b;$unknown['confidence']='suspected'; check(!SocialPerception::permits($unknown,false),'suspicion alone conservative');
$beliefs=SocialPerception::onWake([['kind'=>'missing_property','remembered_ko_actor'=>'A','objective_thief'=>'C']],['conscious'=>true,'confirmed_missing_property'=>true]);
check($beliefs[0]['responsible_entity']==='A','inferred KO blame');
check(!str_contains(json_encode(SocialPerception::prompt($beliefs)),'objective_thief'),'hidden truth omitted');
$beliefs=SocialPerception::onWake([['kind'=>'missing_property','remembered_ko_actor'=>'A']],['conscious'=>true,'confirmed_missing_property'=>true,'known_thief'=>'C']);
check($beliefs[0]['responsible_entity']==='C','better evidence wins');
$beliefs=SocialPerception::onWake([['kind'=>'enslavement','remembered_ko_actor'=>'A']],['conscious'=>true,'enslaved'=>true,'known_enslaver'=>'C']);
check($beliefs[0]['responsible_entity']==='C','slaver attribution separate');
check(!SocialPerception::onWake([['kind'=>'missing_property','remembered_ko_actor'=>'A']],['conscious'=>false,'confirmed_missing_property'=>true]),'wake required');
$r=new SocialRules();
$d=$r->calculate('i','B','serious_assault',$b);
check($d['delta']>=-40 && $d['delta']<=-25,'assault bounds independent oracle');
check($d===$r->calculate('i','B','serious_assault',$b),'deterministic repeated calculation');
check($r->calculate('i','B','routine_healing',$b,['repeat_count'=>1])['delta']===0,'same treatment farming');
$aff=0;for($i=0;$i<10;$i++)$aff += $r->calculate('life'.$i,'B','lifesaving',$b,['affinity'=>$aff,'repeat_count'=>$i])['delta'];
check($aff===100,'ten separate lifesaving incidents powerful');
$aff=10;$day=0;for($i=0;$i<6;$i++){ $delta=$r->calculate('trade'.$i,'B','exceptional_trade',$b,['affinity'=>$aff,'repeat_count'=>$i,'economic_day_gain'=>$day])['delta'];$aff+=$delta;$day+=$delta; }
check($aff<=16,'six exceptional same-day trades bounded');
check($r->calculate('trade','B','exceptional_trade',$b,['affinity'=>96])['delta']===0,'economic cap never decreases existing relationship');
check(!SocialRules::recruitment(100,['trade'],[]),'economic affection alone no recruit');
check(SocialRules::recruitment(76,['slave_escape'],[]),'escape trust can qualify');
check(!SocialRules::recruitment(75,['lifesaving'],[]),'below threshold');
check(!SocialRules::recruitment(90,['lifesaving'],['severe_unresolved']),'grievance gate');
check(SocialRules::recruitment(0,[],[],true),'vanilla exemption');
check($r->calculate('disabled','B','serious_assault',$b,['category_enabled'=>false])['delta']===0,'category disabled');
foreach (range(1,200) as $seed) {
 $unknown=$b;$unknown['awareness']='unknown';
 check($r->calculate('prop'.$seed,'B','serious_assault',$unknown,['affinity'=>($seed%201)-100])['delta']===0,'unknown harm property');
 check($r->calculate('prop'.$seed,'B','theft',$b,['squad_control'=>true])['delta']===0,'squad control property');
 $d=$r->calculate('prop'.$seed,'B','lifesaving',$b,['affinity'=>($seed%201)-100,'repeat_count'=>$seed]);
 check($d['new_affinity']>=-100 && $d['new_affinity']<=100 && $d['delta']>=0,'bounded aid property');
}
if (isset($argv[1])) { $native=SocialEventContract::validate(file_get_contents($argv[1])); check($native['actor']['serial']===1 && $native['facts']['message']==="quote\"\n",'native PHP cross-language contract'); }
if (isset($argv[2])) { $native=SocialEventContract::validate(file_get_contents($argv[2]));
 check($native['facts']['source']==='structured' && $native['event_kind']==='attack','native structured kind');
 check($native['actor']['storage_id']==='hand_11' && $native['actor']['in_player_faction']===true && $native['actor']['conscious']===true,'native structured actor');
 check($native['target']['conscious']===null && $native['target']['storage_id']===null && $native['target']['name']==='Vorl [Dust "Bandit"]','native structured unknowns stay null');
 check($native['facts']['victim_targeting_actor']===false && $native['facts']['inventory']===['Dried Meat'=>3],'native structured facts'); }
echo "$n unit contract/rule checks passed\n";
