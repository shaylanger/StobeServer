<?php
require_once __DIR__ . '/../lib/postgresql.class.php';
require_once __DIR__ . '/../lib/negotiation_phase1.php';
$GLOBALS['db'] = new sql();
$npc = 'STOBE Negotiation Test';
$player = 'Shay';
$terms = [
 ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>100],
 ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']
];
$raw = json_encode(['deal_decision'=>'ACCEPT','deal_terms'=>json_encode($terms)]);
$out = stobeDealCaptureResponse($raw,$npc,$player,[], 'I will pay 100 Cats.');
if (empty($out['ok']) || ($out['decision'] ?? '') !== 'ACCEPT') throw new RuntimeException(json_encode($out));
$id = $out['id'];
try {
 $row = $GLOBALS['db']->fetchOne('SELECT status,terms,conflict_context FROM stobe_social_contract WHERE contract_id=$1',[$id]);
 if (($row['status'] ?? '') !== 'ACCEPTED') throw new RuntimeException('Acceptance not stored');
 if (json_decode($row['terms'],true) != $terms) throw new RuntimeException('Terms changed');
 // A second, empty ACCEPT (duplicate reply) confirms the deal on the table instead of failing.
 $dup = stobeDealCaptureResponse(json_encode(['deal_decision'=>'ACCEPT','deal_terms'=>'']),$npc,$player,[], 'I will pay 100 Cats.');
 if (empty($dup['ok']) || ($dup['id'] ?? '') !== $id || empty($dup['already_active'])) throw new RuntimeException('Duplicate accept not merged: ' . json_encode($dup));
 // With no deal on the table, an empty combat ACCEPT is still invalid.
 $invalid = stobeDealCaptureResponse(json_encode(['deal_decision'=>'ACCEPT','deal_terms'=>'[]']),'STOBE Negotiation Nobody',$player,[], 'offer', 'combat');
 if (($invalid['error'] ?? '') !== 'missing_npc_ceasefire') throw new RuntimeException('Invalid deal accepted: ' . json_encode($invalid));
 echo "Structured negotiation capture OK\n";
} finally {
 $GLOBALS['db']->exec('DELETE FROM stobe_social_contract WHERE contract_id=$1',[$id]);
}
