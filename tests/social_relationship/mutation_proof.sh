#!/bin/bash
# Mutation proof (REL phases 2-7): each mutation must make its regression suite fail; the unmutated tree passes.
# Usage: bash tests/social_relationship/mutation_proof.sh   (works on a /tmp copy; needs the stobe_social_phase1_test DB)
SRC=/root/stobe-work/social-phase1/server
rm -rf /tmp/mut && rsync -a --exclude .git --exclude log "$SRC/" /tmp/mut/ && mkdir -p /tmp/mut/log
run() { bash "$SRC/tests/social_relationship/runtest.sh" "tests/$1.php" /tmp/mut | tail -1; }
BAD=0
reset() { rsync -a "$SRC/lib/" /tmp/mut/lib/; }
# mutate <label> <suite> <file> <old> <new>
mutate() {
  python3 - "/tmp/mut/lib/$3" "$4" "$5" <<'PY' || { echo "$1: ANCHOR MISSING"; return; }
import sys; p,o,n=sys.argv[1:4]; s=open(p).read()
assert o in s; open(p,'w').write(s.replace(o,n,1))
PY
  local out; out=$(run "$2"); echo "$1: $out"; reset
  case "$out" in *FAIL*|*Exception*) ;; *) BAD=1 ;; esac
}
mutate "M1 combat: no retaliation model" social_combat_regression social_interpreter.php \
  "if (\$state['initiator'] !== \$a['entity_key']) return [['status'=>'defence', 'basis'=>'retaliation'" "if (false) return [['status'=>'defence', 'basis'=>'retaliation'"
mutate "M2 combat: no ally defence" social_combat_regression social_interpreter.php \
  "} elseif (\$ally = \$this->aggressorAgainstAllyOf(\$event, \$b, \$a)) {" "} elseif (false) {"
mutate "M3 combat: no escalation budget" social_combat_regression social_store.php \
  "\$group = \$context['escalation_group'] ?? null;" "\$group = null;"
mutate "M4 KO: blame the objective taker" social_unconscious_regression social_interpreter.php \
  "\$believedThief = \$knownThief ?? \$remembered;" "\$believedThief = \$knownThief ?? ((\$ko['state']['objective']['transfers'][0]['taker'] ?? null) ?: \$remembered);"
mutate "M5 KO: enslavement charged while unconscious" social_unconscious_regression social_interpreter.php \
  "if ((\$victim['conscious'] ?? null) === true) {" "if (true) {"
mutate "M6 KO: no squad exemption" social_unconscious_regression social_interpreter.php \
  "if (\$missing > 0 && \$squadOnly) {" "if (false) {"
mutate "M7 KO: used-up items count as stolen" social_unconscious_regression social_interpreter.php \
  "foreach (\$moved as \$key => \$qty) \$missing +=" "foreach (\$baseline as \$key => \$qty) \$missing +="
mutate "M8 care: healing your own victim earns trust" social_care_regression social_care.php \
  "if (\$this->helperCausedHarm(\$event, \$p, \$r)) return" "if (false) return"
mutate "M9 care: no per-episode aid budget" social_care_regression social_care.php \
  "\$incident, ['escalation_group'=>self::AID_GROUP])];" "\$incident . ':' . \$event['sequence'], [])];"
mutate "M10 care: a drop counts as a rescue" social_care_regression social_care.php \
  "if (\$place === 1 || \$place === 2)" "if (\$place === 0 || \$place === 1 || \$place === 2)"
mutate "M11 care: routine squad food earns trust" social_care_regression social_care.php \
  "if (self::squadPair(\$donor, \$e) && \$class !== 'survival_food')" "if (false)"
mutate "M12 property: undetected theft blamed" social_property_regression social_property.php   "if ((\$facts['caught'] ?? null) !== true) return" "if (false) return"
mutate "M13 property: no economic day budget" social_property_regression social_property.php   "return ['repeat_count'=>(int)(\$rows[0]['n'] ?? 0), 'economic_day_gain'=>max(0, (int)(\$rows[0]['gain'] ?? 0))];" "return [];"
mutate "M14 property: shared purse credits the seller" social_property_regression social_property.php   "if (\$spent > 0 && \$gained <= 0) return" "if (false) return"
mutate "M15 agreements: legacy delta kept when enabled" social_property_regression negotiation_engine.php   "if (stobeSocialAgreementOutcome(\$deal, \$player, \$status, is_array(\$state) ? \$state : [])) \$delta = 0;" "stobeSocialAgreementOutcome(\$deal, \$player, \$status, is_array(\$state) ? \$state : []);"
mutate "M16 witnesses: nearby counts as witnessed" social_witness_regression social_interpreter.php   "if (!\$who || (\$w['conscious'] ?? null) !== true || (\$w['perceived'] ?? null) !== true) continue;" "if (!\$who) continue;"
mutate "M17 witnesses: every witness treated as a close friend" social_witness_regression social_interpreter.php   "if (\$toVictim < \$from) continue;" ""
mutate "M18 dialogue: evaluator re-scores the fight" social_witness_regression social_dialogue.php   "\$update['aff_delta'] = \$recent ? 0 : max(" "\$update['aff_delta'] = max("
mutate "M19 recruitment: gate only in the prompt, not the dispatch" social_recruitment_regression chat_helper_functions.php   "if (\$command === 'JOIN_PARTY' && boolval(\$config['disallow_join_party'] ?? false)) {" "if (false) {"
mutate "M20 escape: completes without the sustain window" social_recruitment_regression social_interpreter.php   "if (\$event['game_ts'] - (int)\$state['freed_ts'] < \$sustain ||" "if ("
for t in social_combat_regression social_unconscious_regression social_care_regression social_property_regression social_witness_regression social_recruitment_regression; do
  out=$(run $t); echo "unmutated $t: $out"; case "$out" in *passed*) ;; *) BAD=1 ;; esac
done
exit ${BAD:-0}
