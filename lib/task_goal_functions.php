<?php

function stobeTaskGoalEnsureSchema(): void
{
    static $done = false;
    if ($done) return;
    $db = $GLOBALS['db'] ?? null;
    if (!$db) throw new RuntimeException('Task-goal database unavailable');

    $sql = [
        "CREATE TABLE IF NOT EXISTS stobe_task_goal_runtime (
            goal_id TEXT PRIMARY KEY,
            actor_name TEXT NOT NULL,
            actor_serial BIGINT NOT NULL DEFAULT 0,
            kind TEXT NOT NULL,
            item_name TEXT NOT NULL DEFAULT '',
            target_name TEXT NOT NULL DEFAULT '',
            destination_name TEXT NOT NULL DEFAULT '',
            destination_x DOUBLE PRECISION,
            destination_y DOUBLE PRECISION,
            destination_z DOUBLE PRECISION,
            quantity INT NOT NULL DEFAULT 0,
            completed INT NOT NULL DEFAULT 0,
            store_after BOOLEAN NOT NULL DEFAULT FALSE,
            minimum_stock INT NOT NULL DEFAULT 0,
            max_spend INT NOT NULL DEFAULT 0,
            spent INT NOT NULL DEFAULT 0,
            explicit_authorization BOOLEAN NOT NULL DEFAULT FALSE,
            approved_purchase BOOLEAN NOT NULL DEFAULT FALSE,
            status TEXT NOT NULL DEFAULT 'ACTIVE',
            current_step TEXT NOT NULL DEFAULT '',
            reason TEXT NOT NULL DEFAULT '',
            created_game_ts BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )",
        "CREATE INDEX IF NOT EXISTS idx_stobe_task_goal_runtime_actor ON stobe_task_goal_runtime (LOWER(actor_name), updated_at DESC)",
    ];
    foreach ($sql as $q) {
        if ($db->exec($q) === false) throw new RuntimeException('Failed to initialize task-goal schema: '.$db->GetLastError());
    }
    $done = true;
}

function stobeTaskGoalAllowedKinds(): array
{
    return [
        'LOOT_AREA','LOOT_STORE','STORE','FETCH','DELIVER','RECOVER_GROUND',
        'MEDICAL_CLEANUP','IMPRISON_ALL','RELEASE_ALL','PATROL','STOCK',
        'BUY','SELL','GUARD','WAIT_FOR','BUILD_GOAL','REPAIR_GOAL',
    ];
}

function stobeTaskGoalQueue(
    string $actor,
    string $kind,
    string $item = '',
    string $target = '',
    string $destination = '',
    int $quantity = 0,
    bool $storeAfter = false,
    int $minimumStock = 0,
    int $maxSpend = 0,
    bool $explicitAuthorization = false,
    int $gameTs = 0
): array {
    stobeTaskGoalEnsureSchema();
    $actor = normalizeParticipantNameToken($actor);
    $kind = strtoupper(trim($kind));
    $clean = static fn(string $v, int $max=160): string =>
        substr(trim(preg_replace('/[\r\n\t|]+/', ' ', $v) ?? ''), 0, $max);
    $item = $clean($item);
    $target = $clean($target);
    $destination = $clean($destination);
    if ($actor === '' || !in_array($kind, stobeTaskGoalAllowedKinds(), true)) {
        return ['ok'=>false,'error'=>'invalid_task_goal'];
    }
    if (($kind === 'BUY' || $kind === 'SELL') && !$explicitAuthorization) {
        return ['ok'=>false,'error'=>'trade_requires_explicit_authorization'];
    }
    if ($kind === 'BUY' && $quantity < 1) {
        return ['ok'=>false,'error'=>'buy_quantity_required'];
    }
    if (in_array($kind, ['STOCK','FETCH','DELIVER'], true) && $quantity < 1) {
        return ['ok'=>false,'error'=>'quantity_required'];
    }

    $serial = function_exists('stobeResolveLiveParticipantSerial') ? stobeResolveLiveParticipantSerial($actor, true) : 0;
    if ($serial <= 0) return ['ok'=>false,'error'=>'actor_serial_unavailable'];

    $resolved = ['name'=>'','x'=>null,'y'=>null,'z'=>null];
    if ($destination !== '') {
        $resolved = stobeWorkGoalResolveDestination($destination);
        if ($resolved === false) return ['ok'=>false,'error'=>'destination_not_known','destination'=>$destination];
    }
    $destName = trim(strval($resolved['name'] ?? ''));
    $x = $resolved['x'] ?? null; $y = $resolved['y'] ?? null; $z = $resolved['z'] ?? null;
    if (in_array($kind, ['FETCH','BUY'], true) && strval($resolved['fallback'] ?? '') === 'person' && function_exists('stobeGoalPersonName')) { // items 76/82
        // Item 76: bring it to that person: a label only (no coordinates); KenshiFP hands it over on the walk-back.
        $destName = stobeGoalPersonName($destination);
    }

    $goalId = stobeWorkGoalId();
    $status = 'ACTIVE';
    $step = 'Accepted '.strtolower(str_replace('_',' ', $kind)).' goal';
    $db = $GLOBALS['db'];
    $db->exec(
        "INSERT INTO stobe_task_goal_runtime (
            goal_id,actor_name,actor_serial,kind,item_name,target_name,
            destination_name,destination_x,destination_y,destination_z,
            quantity,completed,store_after,minimum_stock,max_spend,spent,
            explicit_authorization,approved_purchase,status,current_step,created_game_ts
         ) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,0,$12,$13,$14,0,$15,$16,$17,$18,$19)",
        [$goalId,$actor,$serial,$kind,$item,$target,$destName,$x,$y,$z,
         max(0,min(1000,$quantity)),$storeAfter,max(0,min(1000,$minimumStock)),
         max(0,min(10000000,$maxSpend)),$explicitAuthorization,
         ($kind==='BUY' && $explicitAuthorization),$status,$step,max(0,$gameTs)]
    );

    $path='/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_task_goal.request';
    $line=implode("\t",[
        $goalId,(string)$serial,$actor,$kind,$item,$target,$destName,
        (string)max(0,min(1000,$quantity)),$storeAfter?'1':'0',
        (string)max(0,min(1000,$minimumStock)),(string)max(0,min(10000000,$maxSpend)),
        $x===null?'':strval($x),$y===null?'':strval($y),$z===null?'':strval($z),
        $explicitAuthorization?'1':'0',
    ])."\n";
    if (@file_put_contents($path,$line,FILE_APPEND|LOCK_EX)===false) {
        $db->exec("UPDATE stobe_task_goal_runtime SET status='BLOCKED', reason='Could not queue goal to KenshiFP', updated_at=NOW() WHERE goal_id=$1",[$goalId]);
        return ['ok'=>false,'error'=>'request_file_write_failed','goal_id'=>$goalId];
    }
    stobeLogInfo('Queued persistent task goal',['goal_id'=>$goalId,'actor'=>$actor,'kind'=>$kind,'item'=>$item,'target'=>$target,'destination'=>$destName]);
    return ['ok'=>true,'goal_id'=>$goalId,'kind'=>$kind,'actor'=>$actor];
}

function stobeTaskGoalSyncStatusFile(): void
{
    stobeTaskGoalEnsureSchema();
    $path=strval(getenv('STOBE_TASK_GOAL_STATUS_FILE') ?: '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_task_goal.status');
    if (!is_file($path) || filesize($path)<=0) return;
    $lines=@file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    $db=$GLOBALS['db'];
    foreach(array_slice($lines,0,80) as $line){
        $p=explode("\t",strval($line));
        if(count($p)<11)continue;
        [$id,$actor,$status,$kind,$item,$qty,$done,$target,$dest,$step,$reason]=array_slice($p,0,11);
        $maxSpend=intval($p[11]??0);$spent=intval($p[12]??0);$approved=intval($p[13]??0)>0;
        $status=strtoupper(trim($status));
        if(!in_array($status,['ACTIVE','COMPLETE','BLOCKED','CANCELLED','PAUSED','WAITING_APPROVAL'],true))continue;
        $existing=$db->fetchOne("SELECT goal_id FROM stobe_task_goal_runtime WHERE goal_id=$1",[trim($id)]);
        if($existing){
            $db->exec(
                "UPDATE stobe_task_goal_runtime SET status=$2,completed=$3,current_step=$4,reason=$5,
                        destination_name=CASE WHEN $6<>'' THEN $6 ELSE destination_name END,
                        max_spend=GREATEST(max_spend,$7),spent=$8,approved_purchase=$9,
                        updated_at=CASE WHEN status IS DISTINCT FROM $2 OR completed IS DISTINCT FROM $3 OR current_step IS DISTINCT FROM $4
                                          OR reason IS DISTINCT FROM $5 OR spent IS DISTINCT FROM $8 OR approved_purchase IS DISTINCT FROM $9
                                        THEN NOW() ELSE updated_at END
                 WHERE goal_id=$1",
                [trim($id),$status,max(0,intval($done)),substr(trim($step),0,700),substr(trim($reason),0,900),
                 substr(trim($dest),0,160),max(0,$maxSpend),max(0,$spent),$approved]
            );
        } else {
            // M24_F13: an ended goal missing from the DB was rolled back or pruned: don't resurrect it as fresh.
            if(stobeGoalStatusEnded($status))continue;
            // Includes automatic last-resort purchase approvals spawned by KenshiFP.
            $db->exec(
                "INSERT INTO stobe_task_goal_runtime (
                    goal_id,actor_name,kind,item_name,target_name,destination_name,
                    quantity,completed,max_spend,spent,approved_purchase,status,current_step,reason
                 ) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14)
                 ON CONFLICT (goal_id) DO NOTHING",
                [trim($id),normalizeParticipantNameToken($actor),strtoupper(trim($kind)),trim($item),trim($target),trim($dest),
                 max(0,intval($qty)),max(0,intval($done)),max(0,$maxSpend),max(0,$spent),$approved,$status,
                 substr(trim($step),0,700),substr(trim($reason),0,900)]
            );
        }
    }
}

function stobeTaskGoalFind(string $actor,string $selector='',array $statuses=['ACTIVE','PAUSED','WAITING_APPROVAL']): array|false
{
    stobeTaskGoalSyncStatusFile();
    $actor=normalizeParticipantNameToken($actor);$selector=trim($selector);
    $rows=$GLOBALS['db']->fetchAll(
        "SELECT * FROM stobe_task_goal_runtime WHERE LOWER(actor_name)=LOWER($1)
         ORDER BY updated_at DESC, created_at DESC LIMIT 30",[$actor]
    );
    foreach(($rows?:[]) as $r){
        if(!in_array(strtoupper(strval($r['status']??'')),$statuses,true))continue;
        if($selector==='')return $r;
        $hay=strtolower(implode(' ',[
            strval($r['kind']??''),strval($r['item_name']??''),strval($r['target_name']??''),strval($r['destination_name']??'')
        ]));
        if(str_contains($hay,strtolower($selector)))return $r;
    }
    return false;
}

function stobeTaskGoalControl(string $actor,string $command,string $selector='',string $argument=''): array
{
    stobeTaskGoalEnsureSchema();
    $command=strtoupper(trim($command));
    // Pick the goal the command can actually act on ("resume" means the paused one).
    $statuses=match($command){
        'APPROVE'=>['WAITING_APPROVAL'],
        'PAUSE'=>['ACTIVE'],
        'RESUME'=>['PAUSED'],
        default=>['ACTIVE','PAUSED','WAITING_APPROVAL'],
    };
    $goal=stobeTaskGoalFind($actor,$selector,$statuses);
    if(!$goal)return ['ok'=>false,'error'=>'no_matching_task_goal'];
    $id=strval($goal['goal_id']);
    $path='/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_task_goal.control';
    $line=$id."\t".$command.($argument!==''?"\t".$argument:'')."\n";
    if(@file_put_contents($path,$line,FILE_APPEND|LOCK_EX)===false)return ['ok'=>false,'error'=>'control_write_failed'];
    $newStatus=match($command){
        'PAUSE'=>'PAUSED','RESUME'=>'ACTIVE','CANCEL'=>'CANCELLED','APPROVE'=>'ACTIVE',default=>strval($goal['status'])
    };
    $GLOBALS['db']->exec("UPDATE stobe_task_goal_runtime SET status=$2,updated_at=NOW() WHERE goal_id=$1",[$id,$newStatus]);
    return ['ok'=>true,'goal_id'=>$id,'command'=>$command];
}

function stobeTaskGoalRetarget(string $actor,string $selector,string $destination): array
{
    $goal=stobeTaskGoalFind($actor,$selector,['ACTIVE','PAUSED']);
    if(!$goal)return ['ok'=>false,'error'=>'no_matching_task_goal'];
    $dest=stobeWorkGoalResolveDestination($destination);
    if($dest===false)return ['ok'=>false,'error'=>'destination_not_known'];
    $arg=str_replace('^',' ',trim(strval($dest['name']??$destination))).'^'.floatval($dest['x']).'^'.floatval($dest['y']).'^'.floatval($dest['z']);
    $path='/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_task_goal.control';
    @file_put_contents($path,strval($goal['goal_id'])."\tDESTINATION\t".$arg."\n",FILE_APPEND|LOCK_EX);
    $GLOBALS['db']->exec(
        "UPDATE stobe_task_goal_runtime SET destination_name=$2,destination_x=$3,destination_y=$4,destination_z=$5,updated_at=NOW() WHERE goal_id=$1",
        [strval($goal['goal_id']),strval($dest['name']??$destination),floatval($dest['x']),floatval($dest['y']),floatval($dest['z'])]
    );
    return ['ok'=>true,'goal_id'=>strval($goal['goal_id'])];
}

function stobeBuildTaskGoalStateBlock(string $npcName): string
{
    $npcName=normalizeParticipantNameToken($npcName);if($npcName==='')return '';
    try{
        stobeTaskGoalSyncStatusFile();
        $rows=$GLOBALS['db']->fetchAll(
            "SELECT goal_id,kind,item_name,target_name,destination_name,quantity,completed,status,
                    current_step,reason,max_spend,spent,
                    GREATEST(0,FLOOR(EXTRACT(EPOCH FROM (NOW()-updated_at))/60))::int AS age_min
             FROM stobe_task_goal_runtime WHERE LOWER(actor_name)=LOWER($1)
             ORDER BY CASE status WHEN 'WAITING_APPROVAL' THEN 0 WHEN 'ACTIVE' THEN 1 WHEN 'PAUSED' THEN 2 ELSE 3 END,
                      updated_at DESC LIMIT 8",[$npcName]
        );
    }catch(Throwable){return '';}
    if(!$rows)return '';
    $o=['<task_goals>',
        '  <rule>These are real persistent Kenshi goals. Never claim an incomplete or blocked goal succeeded.</rule>',
        '  <rule>WAITING_APPROVAL means spending Cats is forbidden until the player explicitly approves that purchase. Selling is never an automatic fallback.</rule>'];
    if(function_exists('stobeEndedGoalRules'))foreach(stobeEndedGoalRules() as $rule)$o[]='  <rule>'.$rule.'</rule>';
    $seen=[];
    foreach($rows as $r){
        // Item 121: ended goals are history; a newer goal of the same kind+item supersedes them.
        $st=strtoupper(trim(strval($r['status']??'')));
        $ended=function_exists('stobeGoalStatusEnded')&&stobeGoalStatusEnded($st);
        $key=strtolower(strval($r['kind']??'').'|'.trim(strval($r['item_name']??'')));
        if($ended&&isset($seen[$key]))continue;
        $seen[$key]=true;
        $attrs=' kind="'.stobePromptXmlEscape(strval($r['kind']??'')).'" status="'.stobePromptXmlEscape(strval($r['status']??'')).'"';
        $attrs.=' item="'.stobePromptXmlEscape(strval($r['item_name']??'')).'" requested="'.intval($r['quantity']??0).'" completed="'.intval($r['completed']??0).'"';
        if(trim(strval($r['target_name']??''))!=='')$attrs.=' target="'.stobePromptXmlEscape(strval($r['target_name'])).'"';
        if(trim(strval($r['destination_name']??''))!=='')$attrs.=' destination="'.stobePromptXmlEscape(strval($r['destination_name'])).'"';
        if($ended){
            $age=max(0,intval($r['age_min']??0));
            $o[]='  <goal'.$attrs.' ended_minutes_ago="'.$age.'">';
            $h=stobeEndedGoalHistoryNote($st,$age,strval($r['reason']??''));
            if($h!=='')$o[]='    <history>'.stobePromptXmlEscape($h).'</history>';
            $o[]='  </goal>';
            continue;
        }
        $o[]='  <goal'.$attrs.'>';
        if(trim(strval($r['current_step']??''))!=='')$o[]='    <current_step>'.stobePromptXmlEscape(strval($r['current_step'])).'</current_step>';
        if(trim(strval($r['reason']??''))!=='')$o[]='    <reason>'.stobePromptXmlEscape(strval($r['reason'])).'</reason>';
        if(intval($r['max_spend']??0)>0)$o[]='    <spending approved_or_requested_cap="'.intval($r['max_spend']).'" spent="'.intval($r['spent']??0).'" />';
        $o[]='  </goal>';
    }
    $o[]='</task_goals>';
    return implode("\n",$o);
}

/**
 * Bug 59: KenshiFP lists goals that just ended near the player in stobe_goal_report.request.
 * Turn each into a 'goal_report' directive so that NPC reports the result on the next
 * initiative turn. Returns the number of directives queued.
 */
function stobeGoalReportQueuePending(): int
{
    $path='/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_goal_report.request';
    if(!is_file($path)||filesize($path)<=0||!function_exists('stobeNegQueueDirective'))return 0;
    $fh=@fopen($path,'c+');
    if(!$fh)return 0;
    $lines=[];
    if(flock($fh,LOCK_EX)){
        $raw=stream_get_contents($fh);
        ftruncate($fh,0);fflush($fh);flock($fh,LOCK_UN);
        $lines=preg_split('/\r?\n/',strval($raw))?:[];
    }
    fclose($fh);
    if(!$lines)return 0;
    try{stobeWorkGoalSyncStatusFile();}catch(Throwable){}
    try{stobeTaskGoalSyncStatusFile();}catch(Throwable){}
    $player=function_exists('getSetting')?trim(strval(getSetting('PLAYER_NAME',''))):'';
    $player=$player!==''?ucfirst($player):'the player';
    $queued=0;
    foreach(array_slice($lines,0,8) as $line){
        $p=explode("\t",trim(strval($line)));
        if(count($p)<3||trim($p[0])==='')continue;
        [$id,$actor,$type]=[trim($p[0]),normalizeParticipantNameToken(trim($p[1])),trim($p[2])];
        if($type==='hunger'){
            if($actor==='')continue;
            $instruction="You're getting hungry and there's no food in your pack or in the base's storage. Tell {$player} briefly, in your own voice, that you're hungry and there's no food. You keep working for now. Don't claim you ate.";
            stobeNegQueueDirective($actor,'goal_report',$id,['instruction'=>$instruction,'actions'=>[]],false);
            stobeLogInfo('Hunger report queued',['actor'=>$actor,'id'=>$id]);
            $queued++;
            continue;
        }
        $row=$type==='task'
            ?$GLOBALS['db']->fetchOne("SELECT kind,item_name,quantity,completed,status,reason FROM stobe_task_goal_runtime WHERE goal_id=$1",[$id])
            :$GLOBALS['db']->fetchOne("SELECT 'WORK' AS kind,item_name,quantity,completed,status,reason FROM stobe_work_goal WHERE goal_id=$1",[$id]);
        if(!is_array($row)||$actor==='')continue;
        $status=strtoupper(strval($row['status']??''));
        $kindWord=strtolower(str_replace('_',' ',strval($row['kind']??'')));
        $itemName=strval($row['item_name']??'');
        $what=$kindWord==='work'
            ?'make '.intval($row['quantity']??0).' '.$itemName
            :trim($kindWord.' '.$itemName);
        $doneN=intval($row['completed']??0);$qty=intval($row['quantity']??0);
        $done=$qty>0?$doneN.'/'.$qty:$doneN.' taken';
        $reason=trim(strval($row['reason']??''));
        if($status==='COMPLETE'&&$doneN===0&&$kindWord!=='work'){ // bug 104: nothing done is not "done"
            $instruction="You tried the job {$player} gave you ({$what}) but found nothing to do: nothing matching was there. Tell {$player} plainly, in your own voice, that you found nothing. Don't claim you took, moved or finished anything.";
            stobeNegQueueDirective($actor,'goal_report',$id,['instruction'=>$instruction,'actions'=>[]],false);
            stobeLogInfo('Goal report queued',['actor'=>$actor,'goal_id'=>$id,'status'=>'COMPLETE_EMPTY']);
            $queued++;
            continue;
        }
        $instruction=match($status){
            'COMPLETE'=>"You just finished the job {$player} gave you ({$what}; {$done} done) and walked back to {$player}. Tell {$player} briefly, in your own voice, that it's done and ask what's next. Don't invent extra results.",
            'BLOCKED'=>"You had to stop the job {$player} gave you ({$what}; {$done} done) and walked back to {$player}. Tell {$player} briefly why: {$reason}. Ask what to do about it. Don't claim it succeeded.",
            'WAITING_APPROVAL'=>"Your job ({$what}) needs {$player}'s approval to spend Cats: {$reason}. Ask {$player} plainly whether to buy it.",
            'CANCELLED'=>"{$player} cancelled your job ({$what}); you're back with {$player}. Say briefly that you're ready for the next order.",
            default=>'',
        };
        if($instruction==='')continue;
        stobeNegQueueDirective($actor,'goal_report',$id,['instruction'=>$instruction,'actions'=>[]],false);
        stobeLogInfo('Goal report queued',['actor'=>$actor,'goal_id'=>$id,'status'=>$status]);
        $queued++;
    }
    return $queued;
}

function stobeAnyGoalControl(string $actor,string $command,string $selector='',int $quantity=0,string $destination=''): array
{
    $command=strtoupper(trim($command));
    if($command==='APPROVE'){
        return stobeTaskGoalControl($actor,'APPROVE',$selector);
    }
    if($command==='DECLINE'){
        $pending=stobeTaskGoalFind($actor,$selector,['WAITING_APPROVAL']);
        if(!$pending)return ['ok'=>false,'error'=>'no_pending_purchase'];
        $path='/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_task_goal.control';
        if(@file_put_contents($path,strval($pending['goal_id'])."\tCANCEL\n",FILE_APPEND|LOCK_EX)===false)
            return ['ok'=>false,'error'=>'control_write_failed'];
        $GLOBALS['db']->exec("UPDATE stobe_task_goal_runtime SET status='CANCELLED',updated_at=NOW() WHERE goal_id=$1",[strval($pending['goal_id'])]);
        return ['ok'=>true,'goal_id'=>strval($pending['goal_id']),'command'=>'DECLINE'];
    }

    // Items 138/139 (m51): PAUSE / RESUME / CANCEL / CLEAR act on every matching live goal (duplicates of
    // one order used to leave the running goal untouched), RESUME also matches an ACTIVE goal (Stobe
    // re-kicks it), "all"/"everything"/no selector picks every live goal, CLEAR also drops her jobs.
    if(in_array($command,['PAUSE','RESUME','CANCEL','CLEAR'],true)){
        return stobeAnyGoalControlMany($actor,$command,$selector);
    }
    $task=stobeTaskGoalFind($actor,$selector,['ACTIVE','PAUSED','WAITING_APPROVAL']);
    if($task){
        if($command==='DESTINATION')return stobeTaskGoalRetarget($actor,$selector,$destination);
        $arg=$command==='QUANTITY'?(string)max(1,min(1000,$quantity)):'';
        return stobeTaskGoalControl($actor,$command,$selector,$arg);
    }

    // Fall back to a production WorkGoal.
    stobeWorkGoalSyncStatusFile();
    $actor=normalizeParticipantNameToken($actor);
    $work=false;
    foreach(stobeWorkGoalLiveRows($actor,['ACTIVE','PAUSED']) as $r){
        if(stobeGoalSelectorMatches($selector,strval($r['item_name']??'').' '.strval($r['destination_name']??''))){$work=$r;break;}
    }
    if(!$work)return ['ok'=>false,'error'=>'no_matching_goal'];

    $id=strval($work['goal_id']);
    $path=stobeWorkGoalControlPath();
    $arg='';
    if($command==='QUANTITY'){
        if($quantity<1)return ['ok'=>false,'error'=>'quantity_required'];
        $arg=(string)max(1,min(1000,$quantity));
    }elseif($command==='DESTINATION'){
        $dest=stobeWorkGoalResolveDestination($destination);
        if($dest===false)return ['ok'=>false,'error'=>'destination_not_known'];
        $arg=str_replace('^',' ',trim(strval($dest['name']??$destination))).'^'.floatval($dest['x']).'^'.floatval($dest['y']).'^'.floatval($dest['z']);
        $GLOBALS['db']->exec(
            "UPDATE stobe_work_goal SET destination_name=$2,destination_x=$3,destination_y=$4,destination_z=$5,updated_at=NOW() WHERE goal_id=$1",
            [$id,strval($dest['name']??$destination),floatval($dest['x']),floatval($dest['y']),floatval($dest['z'])]
        );
    }else{
        return ['ok'=>false,'error'=>'unsupported_control'];
    }
    $line=$id."\t".$command."\t".$arg."\n";
    if(@file_put_contents($path,$line,FILE_APPEND|LOCK_EX)===false)return ['ok'=>false,'error'=>'control_write_failed'];
    return ['ok'=>true,'goal_id'=>$id,'command'=>$command,'goal_type'=>'work'];
}

/** Item 139: "all", "everything", "all your tasks, goals and jobs" or no selector = every live goal. */
function stobeGoalSelectorIsAll(string $selector): bool
{
    $s=strtolower(trim(preg_replace('/[\s,]+/',' ',$selector)??''));
    if($s===''||$s==='*')return true;
    if(preg_match('/^(?:all|any|everything|anything|every\s?thing)$/',$s))return true;
    return preg_match('/^(?:all|every|any)(?:\s+(?:of|your|my|her|his|the|current|those|these|and|goals?|tasks?|jobs?|work|orders?|errands?))+$/',$s)===1;
}

/** Item 138: a selector against a goal's item/target/destination text ("the bread goal" ~ "Bread"). */
function stobeGoalSelectorMatches(string $selector,string $hay): bool
{
    if(stobeGoalSelectorIsAll($selector))return true;
    $s=strtolower(trim($selector));
    $s=trim(preg_replace('/^(?:the|my|your|her|that|this)\s+/','',$s)??$s);
    $s=trim(preg_replace('/\s+(?:goals?|tasks?|jobs?|orders?)$/','',$s)??$s);
    $h=strtolower($hay);
    if($s===''||str_contains($h,$s))return true;
    $s1=preg_replace('/(?<=[a-z]{3})(?:es|s)$/','',$s)??$s;
    return $s1!==''&&str_contains($h,$s1);
}

/** Items 138/139: her work goals in the given statuses, ACTIVE first, oldest first. */
function stobeWorkGoalLiveRows(string $actor,array $statuses): array
{
    $rows=$GLOBALS['db']->fetchAll(
        "SELECT *,GREATEST(0,EXTRACT(EPOCH FROM (NOW()-updated_at)))::int AS age_s FROM stobe_work_goal
         WHERE LOWER(actor_name)=LOWER($1) AND status = ANY($2::text[])
         ORDER BY CASE status WHEN 'ACTIVE' THEN 0 WHEN 'PAUSED' THEN 1 ELSE 2 END,created_at ASC LIMIT 30",
        [normalizeParticipantNameToken($actor),'{'.implode(',',$statuses).'}']
    );
    return is_array($rows)?$rows:[];
}

/**
 * Items 138/139: PAUSE / RESUME / CANCEL / CLEAR on every matching live task and work goal of the actor.
 * RESUME takes PAUSED and ACTIVE goals (and BLOCKED ones of the last 30 min when an item is named);
 * CLEAR = CANCEL every goal + a CLEARJOBS line so Stobe empties her job list.
 */
function stobeAnyGoalControlMany(string $actor,string $command,string $selector=''): array
{
    $command=strtoupper(trim($command));
    $all=stobeGoalSelectorIsAll($selector);
    if($command==='CLEAR'){$selector='';$all=true;}
    $send=$command==='CLEAR'?'CANCEL':$command;
    $statuses=match($command){
        'PAUSE'=>['ACTIVE'],
        'RESUME'=>$all?['PAUSED','ACTIVE']:['PAUSED','ACTIVE','BLOCKED'],
        default=>['ACTIVE','PAUSED','WAITING_APPROVAL'],
    };
    $newStatus=match($send){'PAUSE'=>'PAUSED','RESUME'=>'ACTIVE',default=>'CANCELLED'};
    $actorKey=normalizeParticipantNameToken($actor);
    $ids=[];
    stobeTaskGoalSyncStatusFile();
    $taskRows=$GLOBALS['db']->fetchAll(
        "SELECT * FROM stobe_task_goal_runtime WHERE LOWER(actor_name)=LOWER($1) ORDER BY updated_at DESC,created_at DESC LIMIT 30",
        [$actorKey]
    );
    foreach(($taskRows?:[]) as $r){
        if(!in_array(strtoupper(strval($r['status']??'')),$statuses,true))continue;
        if($command==='RESUME'&&strtoupper(strval($r['status']??''))==='BLOCKED')continue;
        $hay=implode(' ',[strval($r['kind']??''),strval($r['item_name']??''),strval($r['target_name']??''),strval($r['destination_name']??'')]);
        if(!stobeGoalSelectorMatches($selector,$hay))continue;
        $id=strval($r['goal_id']);
        if(@file_put_contents(stobeTaskGoalControlPath(),$id."\t".$send."\n",FILE_APPEND|LOCK_EX)===false)return ['ok'=>false,'error'=>'control_write_failed'];
        $GLOBALS['db']->exec("UPDATE stobe_task_goal_runtime SET status=$2,updated_at=NOW() WHERE goal_id=$1",[$id,$newStatus]);
        $ids[]=$id;
    }
    stobeWorkGoalSyncStatusFile();
    foreach(stobeWorkGoalLiveRows($actorKey,$statuses) as $r){
        if(strtoupper(strval($r['status']??''))==='BLOCKED'&&intval($r['age_s']??0)>1800)continue;
        if(!stobeGoalSelectorMatches($selector,strval($r['item_name']??'').' '.strval($r['destination_name']??'')))continue;
        $id=strval($r['goal_id']);
        if(@file_put_contents(stobeWorkGoalControlPath(),$id."\t".$send."\n",FILE_APPEND|LOCK_EX)===false)return ['ok'=>false,'error'=>'control_write_failed'];
        $GLOBALS['db']->exec("UPDATE stobe_work_goal SET status=$2,updated_at=NOW() WHERE goal_id=$1",[$id,$newStatus]);
        $ids[]=$id;
    }
    $jobs=false;
    if($command==='CLEAR'){
        $serial=function_exists('stobeResolveLiveParticipantSerial')?intval(stobeResolveLiveParticipantSerial($actorKey,true)):0;
        $line="*\tCLEARJOBS\t".$serial.'^'.str_replace(["\t","\n","\r",'^'],' ',$actorKey)."\n";
        $jobs=@file_put_contents(stobeWorkGoalControlPath(),$line,FILE_APPEND|LOCK_EX)!==false;
    }
    if(!$ids&&!$jobs)return ['ok'=>false,'error'=>'no_matching_goal'];
    if(function_exists('stobeLogInfo'))stobeLogInfo('Goal control on every matching goal (items 138/139)',
        ['actor'=>$actorKey,'command'=>$command,'selector'=>$selector,'goal_ids'=>$ids,'jobs_cleared'=>$jobs]);
    return ['ok'=>true,'goal_id'=>$ids[0]??'','goal_ids'=>$ids,'command'=>$command,'jobs_cleared'=>$jobs];
}
