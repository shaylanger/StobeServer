<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/playthrough_switching.php';
pas_http_guard(true);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/social_runtime.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405); header('Allow: POST'); echo json_encode(['status'=>'method_not_allowed']); exit;
    }
    $mode = stobeSocialMode();
    if ($mode === 'off') { echo json_encode(['status'=>'disabled']); exit; }
    $input = file_get_contents('php://input',false,null,0,SocialEventContract::MAX_BYTES+1);
    $event = SocialEventContract::validate($input);
    // A semantic consequence is server-owned. Clients only send objective raw facts.
    if ($event['event_kind'] === 'semantic') throw new InvalidArgumentException('Client cannot submit semantic effects');
    $result = (new SocialStore($GLOBALS['db']))->ingest($event,stobeSocialScope($GLOBALS['db']),$mode);
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (DomainException $error) {
    http_response_code(409); echo json_encode(['status'=>'scope_or_replay_conflict']);
} catch (InvalidArgumentException|JsonException|LengthException $error) {
    http_response_code(422); echo json_encode(['status'=>'invalid_event']);
} catch (Throwable $error) {
    http_response_code(503); stobeLogWarn('Social capture failed',['error'=>$error->getMessage()]);
    echo json_encode(['status'=>'unavailable']);
}
