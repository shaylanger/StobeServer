<?php
require_once __DIR__ . '/../lib/negotiation_phase1.php';
$deal = [
    'parties'=>['npc'=>'Hungry Bandit','player'=>'Shay'],
    'terms'=>[
        ['kind'=>'GIVE_ITEM','by'=>'player','to'=>'npc','item'=>'Dried Meat','quantity'=>3],
        ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']
    ]
];
assert(stobeDealValidate($deal)['ok'] === true);
$bad = $deal;
$bad['terms'][0]['to'] = 'player';
assert(stobeDealValidate($bad)['error'] === 'self_transfer');
$bad = $deal;
$bad['terms'][1]['kind'] = 'TELEPORT';
assert(stobeDealValidate($bad)['error'] === 'invalid_term');
assert(stobeDealTransition('unused','PROPOSED','COMPLETE',['world_verified'=>true]) === false);
assert(stobeDealTransition('unused','AWAITING_PERFORMANCE','COMPLETE',[]) === false);
echo "Deal validation/state guards OK\n";