<?php
// Run against a test database: STOBE_DB_NAME=stobe_test php tests/negotiation_phase1_db_regression.php
require_once __DIR__ . '/../lib/postgresql.class.php';
require_once __DIR__ . '/../lib/negotiation_phase1.php';
$GLOBALS['db'] = new sql();
stobeDealEnsureSchema();
$deal = [
  'parties'=>['npc'=>'STOBE Negotiation Test','player'=>'Shay'],
  'terms'=>[
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>100],
    ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']
  ],
  'context'=>['origin'=>'test']
];
$created = stobeDealCreate($deal);
if (empty($created['ok'])) throw new RuntimeException('Create failed: '.json_encode($created));
$id = $created['id'];
try {
  if (stobeDealTransition($id,'PROPOSED','COMPLETE',['world_verified'=>true])) throw new RuntimeException('Invalid leap');
  if (!stobeDealTransition($id,'PROPOSED','ACCEPTED')) throw new RuntimeException('Accept failed');
  // Performance and every outcome belong to the negotiation engine, which verifies
  // from game evidence. A caller can no longer assert completion or start performance.
  if (stobeDealTransition($id,'ACCEPTED','AWAITING_PERFORMANCE')) throw new RuntimeException('Ledger started performance');
  if (stobeDealTransition($id,'ACCEPTED','COMPLETE',['world_verified'=>true])) throw new RuntimeException('Caller-asserted completion accepted');
  $row = $GLOBALS['db']->fetchOne('SELECT status FROM stobe_social_contract WHERE contract_id=$1',[$id]);
  if (($row['status'] ?? '') !== 'ACCEPTED') throw new RuntimeException('State did not persist');
  if (!stobeDealTransition($id,'ACCEPTED','CANCELLED')) throw new RuntimeException('Cancel failed');
  echo "Deal database lifecycle OK\n";
} finally {
  $GLOBALS['db']->exec('DELETE FROM stobe_social_contract WHERE contract_id=$1',[$id]);
}
