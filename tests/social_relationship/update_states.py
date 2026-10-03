#!/usr/bin/env python3
"""Record honest per-row REL states in scenarios.json (offline evidence vs in-game need vs blocker).
Usage: python3 tests/social_relationship/update_states.py  (edits server/tests/social_relationship/scenarios.json in place)"""
import json, pathlib
P = pathlib.Path(__file__).with_name('scenarios.json')
doc = json.loads(P.read_text())
OFF_P1 = ['social_relationship_unit', 'social_relationship_integration', 'social_playthrough_regression', 'social_concurrency_regression', 'social_http_regression']
STATES = {
 # id: (state, offline tests, in-game scenarios, open blockers / probes)
 'SR01': ('offline-pass', ['social_relationship_unit'], [], []),
 'SR02': ('offline-pass; needs-game', ['social_combat_regression'], ['REL-p2-01-player-first-strike', 'REL-p2-02-npc-attacks-squad', 'REL-p2-05-enabled-affinity'], ['probe 3: player/NPC profile binding (storage_id+name)']),
 'SR03': ('offline-pass; needs-game', ['social_combat_regression'], ['REL-p2-01-player-first-strike', 'REL-p2-02-npc-attacks-squad', 'REL-p2-03-npc-vs-npc'], ['probe 2: attackingYou fires for NPC/NPC fights']),
 'SR04': ('offline-pass; needs-game', ['social_combat_regression'], ['REL-p2-02-npc-attacks-squad', 'REL-p2-03-npc-vs-npc'], ['ally = same faction or both player faction; affinity-based friends not yet used']),
 'SR05': ('offline-pass; needs-game', ['social_combat_regression'], ['REL-p2-01-player-first-strike'], []),
 'SR06': ('partial: offline-pass for distinct assault; consent/duel and accident blocked', ['social_combat_regression'], ['REL-p2-04-repeat-assault'],
          ['no native consent/duel signal', 'no native accidental/friendly-fire hit signal (unknown intent is not invented as accident)', 'probe 4: encounter idle window calibration']),
 'SR07': ('offline-pass; game blocked', ['social_combat_regression'], [], ['needs a real limb loss caused in a fight (harness damage/kill have no attacker); probabilistic in game']),
 'SR34': ('partial: offline-pass (generic name, reused serial/storage mismatch)', ['social_combat_regression'], ['REL-p2-01-player-first-strike'], ['rename alias and same-name NPCs need game data']),
 'SR35': ('partial: offline-pass (retry dedup, duplicate KO/limb once)', ['social_relationship_integration', 'social_combat_regression'], ['REL-p1-03-shadow-capture'], ['out-of-order KO/theft/wake: phase 3']),
 'SR36': ('offline-pass', ['social_concurrency_regression', 'social_relationship_integration'], [], []),
 'SR38': ('partial: offline-pass (rollback of effects/evidence/checkpoints)', ['social_relationship_integration'], ['REL-p1-04-reload-stale'], ['during-KO / after-loot rollback: phase 3']),
 'SR39': ('partial: offline-pass (A/B/A switch, old snapshot upgrade, fresh)', ['social_playthrough_regression'], [], ['export/import not tested']),
 'SR40': ('partial: offline-pass (stale epoch, failed write)', ['social_relationship_integration', 'social_http_regression'], ['REL-p1-04-reload-stale'], ['NEVER_CLEAR_RELATIONSHIP_DATA=true path not tested']),
 'SR41': ('offline-pass; needs-game', ['social_relationship_integration', 'social_combat_regression'], ['REL-p1-01-capture-off', 'REL-p1-02-capture-on-server-off', 'REL-p1-03-shadow-capture', 'REL-p2-05-enabled-affinity'], ['old DLL with new server: covered by the HTTP test only']),
 'SR43': ('partial: offline-pass (pending cap 256, bounded incident growth per event)', ['social_concurrency_regression', 'social_combat_regression'], [], ['large combat flood / retention not measured']),
}
LATER = {'SR08':3,'SR09':3,'SR10':3,'SR11':3,'SR12':3,'SR13':5,'SR14':5,'SR15':4,'SR16':4,'SR17':4,'SR18':4,'SR19':4,'SR20':4,'SR21':4,'SR22':5,'SR23':5,
         'SR24':6,'SR25':6,'SR26':6,'SR27':6,'SR28':7,'SR29':5,'SR30':7,'SR31':7,'SR32':7,'SR33':7,'SR37':8,'SR42':6,'SR44':8}
import sys
extra = json.loads(sys.argv[1]) if len(sys.argv) > 1 else {}
for s in doc['scenarios']:
    sid = s['id']
    if sid in extra:
        st, off, game, block = extra[sid]
    elif sid in STATES:
        st, off, game, block = STATES[sid]
    else:
        st, off, game, block = ('not-started (phase %d)' % LATER.get(sid, 8), [], [], ['later phase'])
    s['rel_state'] = st
    s['offline_tests'] = off
    s['ingame_scenarios'] = game
    s['open_blockers'] = block
P.write_text(json.dumps(doc, indent=1, ensure_ascii=False) + '\n')
print('updated', len(doc['scenarios']), 'rows')
