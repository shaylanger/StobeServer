<?php
$enginePath = __DIR__ . DIRECTORY_SEPARATOR . "../../";
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
header('Content-Type: application/json');

try {
    $name = trim((string)($_GET['name'] ?? ''));
    if ($name === '') {
        echo json_encode(['ok'=>false,'error'=>'Missing NPC name']);
        exit;
    }
    stobeWorkGoalSyncStatusFile();
    stobeTaskGoalSyncStatusFile();
    stobeWorkGoalEnsureSchema();
    stobeTaskGoalEnsureSchema();

    $work = $GLOBALS['db']->fetchAll(
        "SELECT goal_id,'work' AS goal_type,'PRODUCTION' AS kind,actor_name,item_name,
                '' AS target_name,destination_name,quantity,status,completed,current_step,reason,
                0 AS max_spend,0 AS spent,created_at,updated_at
         FROM stobe_work_goal WHERE LOWER(actor_name)=LOWER($1)",[$name]
    );
    $tasks = $GLOBALS['db']->fetchAll(
        "SELECT goal_id,'task' AS goal_type,kind,actor_name,item_name,target_name,destination_name,
                quantity,status,completed,current_step,reason,max_spend,spent,created_at,updated_at
         FROM stobe_task_goal_runtime WHERE LOWER(actor_name)=LOWER($1)",[$name]
    );
    $rows = array_merge(is_array($work)?$work:[],is_array($tasks)?$tasks:[]);
    $rank=['WAITING_APPROVAL'=>0,'ACTIVE'=>1,'PAUSED'=>2,'BLOCKED'=>3,'COMPLETE'=>4,'CANCELLED'=>5];
    usort($rows,static function($a,$b)use($rank){
        $ra=$rank[strtoupper(strval($a['status']??''))]??9;
        $rb=$rank[strtoupper(strval($b['status']??''))]??9;
        if($ra!==$rb)return $ra<=>$rb;
        return strcmp(strval($b['updated_at']??''),strval($a['updated_at']??''));
    });
    echo json_encode(['ok'=>true,'goals'=>array_slice($rows,0,30)]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
