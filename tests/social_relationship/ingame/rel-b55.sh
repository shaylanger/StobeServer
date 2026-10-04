#!/bin/bash
# rel-b55.sh <outdir> [block ...]   B 55 (fights and relationships) in-game rows. WSL root, unattended.
# Needs: Kenshi running on a kah-* copy of auto-home with the harness on (stobe-say on before launch);
#        StobeCustom.ini [SocialRelationships] Capture=1; server with B 55 (mode off = "fights", the live default).
#        Block "bleed" reads the vitals Stobe sends with the recovered fact (b55_recovered_vitals_native.py, built in).
# Blocks (default: all, in this order): mode squad wild spar close treat fade chat bleed accident deal
#   mode   inspect says ingest_mode=fights, fight_rules=b55
#   squad  Shay attacks + KOs a named bandit: his entry for Shay drops in the KO band (-40..-50 once, x1.0), Shay's
#          entry for him unchanged, no R4 line ("a fight counts (R4)")                                   (items 7, R4 retired)
#   wild   two named bandits fight 300 m away, nobody of the squad sees it: "ignored", no effect       (item 5)
#   spar   spar consent (--add-spar) then attack + KO: "sparring", no effect                               (item 3)
#   close  bandit set to Fond (60) toward Shay, Shay attacks: drop >= 20 (aggression x2.0)               (item 7)
#   treat  Shay attacks and KOs a bandit, then bandages him: treated_relief row, his entry rises, stays < 0 (item 8)
#   fade   SOCIAL_GRUDGE_FADE_DAYS=0.05 (72 game min): light fight, wait-game 90, a second fight (ingest tick):
#          the first bandit's entry is back to 0                                                           (item 1)
#   chat   KO a bandit, let him wake, talk kindly: "SOCIAL_DIALOGUE filtered ... fight_cooldown" (if the evaluator
#          proposed a gain) and the <memory> line in his prompt                                           (items 4, 6)
#   bleed  KO + blood 25 % + deep wound, wake: critical_harm row (-55..-65 band)                         (item 7)
#   accident  (harness b3ae609+ `hit`) Shay's hit KOs Malzin, no combat event: Malzin -> Shay drops 0.25 x KO x closeness,
#          unchanged after a 90 game-min fade window; then an injury hit inside a real fight vs a bandit (item 7)
#   deal   rel-surrender.sh kept with SOCIAL_TEST_FORCE_FIRST_STRIKE=Shay: deal_forgiveness row               (item 2)
# Output: <outdir>/verdicts.txt (one VERDICT line per block) + per-block inspect/log files. Leaves switches off,
# reloads auto-home at the end.
O=${1:-/mnt/c/KenshiTestRuns/rel-b55}; shift
BLOCKS=${*:-mode squad wild spar close treat fade chat bleed accident deal}
I=$(cd "$(dirname "$0")" && pwd)
S=/var/www/html/StobeServer
L=/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe.log
SL=$S/log/stobeserver.log
WL=$S/log/relationship_worker.log
CL=$S/log/context_sent_to_llm.log
mkdir -p "$O"
insp(){ (cd $S && sudo -u www-data php tools/social_relationship_inspect.php "$@"); }
aff(){ insp --relation "$1" "$2" 2>/dev/null | grep -o '"aff": *-\?[0-9]*' | head -1 | grep -o -- '-\?[0-9]*$'; }
v(){ echo "VERDICT $*" | tee -a "$O/verdicts.txt"; }
lines(){ grep -a -c "" "$1" 2>/dev/null || echo 0; }
since(){ tail -n +"$(( $2 + 1 ))" "$1" 2>/dev/null; }   # since <file> <linecount>: lines added after the mark
off(){ for s in SOCIAL_GRUDGE_FADE_DAYS SOCIAL_TEST_FORCE_FIRST_STRIKE SOCIAL_TEST_INJECT_DIALOGUE_GAIN; do insp --set-switch $s off >/dev/null 2>&1; done; }
trap off EXIT
fresh(){ local m; m=$(lines $L); FRESH_SWEEP=0
  stobe-auto load auto-home >/dev/null; sleep 12; stobe-auto wait-world 240 >/dev/null; sleep 8
  # m23: Stobe's NPC event sweep starts 45 s (wall clock) after the world is stable; a KO before it is never reported
  for i in $(seq 1 45); do since $L $m | grep -a -q "HOOK_LOAD_PROBE: NPC world event sweep complete" && { FRESH_SWEEP=1; break; }; sleep 2; done
  stobe-auto speed 0 >/dev/null; stobe-auto select Shay >/dev/null
  stobe-auto hunger Shay 280 >/dev/null; stobe-auto hunger Malzin 280 >/dev/null
  stobe-auto teleport Malzin Shay dist 300 >/dev/null; }   # m22: at 25 m she joined and took the KO credit
# bandit <name> [dist]: spawns a Hungry Bandit (Drifters) near Shay, names him, talks once so his profile exists; echoes the handle
bandit(){ local name="$1" dist="${2:-20}" h=""
  stobe-auto spawn "Hungry Bandit" Drifters near Shay dist "$dist" count 1 >/dev/null
  sleep 1
  h=$(stobe-auto chars 80 | tr '|' '\n' | grep 'Hungry Bandit' | grep -v -e ' DEAD' -e ' KO' | grep -o '#[0-9]*/[0-9]*' | head -1)
  [ -n "$h" ] || return 1
  stobe-auto setname "$h" "$name" >/dev/null
  stobe-say speed 1 >/dev/null
  stobe-say say "$name" "Hello there. Who are you?" --wait 30 >/dev/null 2>&1
  for i in $(seq 1 15); do insp --relation "$name" Shay 2>/dev/null | grep -q 'observer not found' || break; sleep 2; done
  stobe-auto speed 0 >/dev/null
  # m23: names are reused across runs and a spawned NPC's stored entries can outlive a reload (Rel Fenn kept -15): start at 0
  insp --set-relation "$name" Shay 0 >/dev/null 2>&1; insp --set-relation "$name" Malzin 0 >/dev/null 2>&1
  [ "$(aff "$name" Shay)" = 0 ] || { echo "SETUP baseline $name -> Shay is '$(aff "$name" Shay)', not 0" >&2; return 2; }
  echo "$h"; }
# fightko <handle>: Shay attacks (legs crippled so he stays in reach), then a KO right after the attack (run m5 recipe)
fightko(){ local h="$1"
  stobe-auto damage "$h" left_leg 110 >/dev/null; stobe-auto damage "$h" right_leg 110 >/dev/null
  stobe-auto attack Shay "$h" >/dev/null; stobe-say speed 1 >/dev/null; sleep 6
  # m23: the KO must land inside the 10 s recent-attacker window (14 s after the attack = attribution none): attack again, KO at once
  stobe-auto teleport "$h" Shay dist 2 >/dev/null; stobe-auto attack Shay "$h" >/dev/null; sleep 1; stobe-auto ko "$h" "${2:-60}" >/dev/null
  for i in $(seq 1 20); do stobe-auto where "$h" | grep -q ' KO' && break; sleep 1; done
  stobe-auto speed 0 >/dev/null; }
# fightlight <handle>: a few seconds of Shay attacking, then he is sent 300 m away (aggression, maybe injury, no KO)
fightlight(){ local h="$1"
  stobe-auto teleport "$h" Shay dist 3 >/dev/null; stobe-auto attack Shay "$h" >/dev/null
  stobe-say speed 1 >/dev/null; sleep "${2:-5}"; stobe-auto speed 0 >/dev/null
  stobe-auto teleport "$h" Shay dist 300 >/dev/null; }
away(){ stobe-auto teleport "$1" Shay dist 300 >/dev/null 2>&1; }
: > "$O/verdicts.txt"
# Standalone B55 test starts from its documented mode-off baseline (fights stays live).
insp --set-mode off >/dev/null

for B in $BLOCKS; do case $B in
mode)
  insp > "$O/mode.json" 2>&1
  grep -q '"ingest_mode": "fights"' "$O/mode.json" && grep -q '"fight_rules": "b55"' "$O/mode.json" \
    && v "mode: PASS fights mode, b55 rules" || v "mode: FAIL (see mode.json)"
  ;;
squad)
  fresh; s0=$(lines $SL)
  h=$(bandit "Rel Rook") || { v "squad: FAIL no bandit"; continue; }
  fightko "$h"; stobe-say speed 1 >/dev/null; sleep 15; stobe-auto speed 0 >/dev/null
  insp --pair-effects --interpret-log 40 --log-filter "Rel Rook" > "$O/squad.inspect.txt" 2>&1
  r=$(aff "Rel Rook" Shay); s=$(aff Shay "Rel Rook"); r4=$(since $SL $s0 | grep -a -c "a fight counts (R4)")
  if [ -n "$r" ] && [ "$r" -le -40 ] && [ "$r" -ge -58 ] && [ "${s:-0}" = 0 ] && [ "$r4" = 0 ]; then
    v "squad: PASS Rel Rook -> Shay $r (KO band -40..-50, x1.15 on a repeat), Shay -> Rel Rook ${s:-none}, R4 lines 0"
  else v "squad: FAIL Rel Rook -> Shay '${r:-none}', Shay -> Rel Rook '${s:-none}', R4 lines $r4 (squad.inspect.txt)"; fi
  away "$h" ;;
wild)
  fresh
  a=$(bandit "Rel Arn" 20) || { v "wild: FAIL no bandit"; continue; }
  b=$(bandit "Rel Bek" 25) || { v "wild: FAIL no 2nd bandit"; continue; }
  # m23: not a player-allied faction (Traders Guild made Shay defend Bek -> squad involved); Dust Bandits so Arn fights him
  stobe-auto faction "$b" "Dust Bandits" >/dev/null
  a="Rel Arn"; b="Rel Bek"   # m22: names; the re-resolved handle came back empty and Shay was teleported instead
  stobe-auto teleport Malzin Shay dist 2 >/dev/null   # the squad stays together, far from the pair
  w0=$(lines $WL)
  stobe-auto teleport "$a" Shay dist 200 >/dev/null; stobe-auto teleport "$b" "$a" dist 3 >/dev/null
  stobe-auto order "$a" UNPROVOKED_FOCUSED_MELEE_ATTACK target "$b" >/dev/null
  stobe-say speed 1 >/dev/null; sleep 25; stobe-auto speed 0 >/dev/null
  since $WL $w0 | grep -a "SOCIAL_INTERPRET" | grep -a -e "Rel Arn" -e "Rel Bek" > "$O/wild.log.txt"
  insp --pair-effects > "$O/wild.inspect.txt" 2>&1
  e1=$(aff "Rel Bek" "Rel Arn"); e2=$(aff "Rel Arn" "Rel Bek")
  # M24_F12: theft_caught = Shay/Malzin saw the bandits' burglary in the home, not the fight
  if grep -a -v '"kind":"theft_caught"' "$O/wild.log.txt" | grep -a -q -e '"actor":"Shay"' -e '"target":"Shay"' -e '"actor":"Malzin"' -e '"target":"Malzin"'; then
    v "wild: SETUP FAIL the squad joined the fight (Shay/Malzin in wild.log.txt): not a wild fight"
  elif grep -q '"ignored"' "$O/wild.log.txt" && [ "${e1:-0}" = 0 ] && [ "${e2:-0}" = 0 ]; then v "wild: PASS ignored, no entries"
  elif [ ! -s "$O/wild.log.txt" ]; then v "wild: INCONCLUSIVE no structured attack between them reached the server (wild.log.txt empty)"
  else v "wild: FAIL Bek->Arn '${e1:-none}' Arn->Bek '${e2:-none}' (wild.log.txt)"; fi
  ;;
spar)
  fresh
  h=$(bandit "Rel Sparr") || { v "spar: FAIL no bandit"; continue; }
  insp --add-spar "Shay" "Rel Sparr" > "$O/spar.setup.txt" 2>&1
  w0=$(lines $WL)
  fightko "$h"; stobe-say speed 1 >/dev/null; sleep 10; stobe-auto speed 0 >/dev/null
  since $WL $w0 | grep -a "SOCIAL_INTERPRET" | grep -a "Rel Sparr" > "$O/spar.log.txt"
  r=$(aff "Rel Sparr" Shay)
  grep -q '"sparring"' "$O/spar.log.txt" && [ "${r:-0}" = 0 ] && v "spar: PASS sparring, Rel Sparr -> Shay ${r:-none}" \
    || v "spar: FAIL Rel Sparr -> Shay '${r:-none}' (spar.log.txt, spar.setup.txt)"
  away "$h" ;;
close)
  fresh
  h=$(bandit "Rel Cora") || { v "close: FAIL no bandit"; continue; }
  insp --set-relation "Rel Cora" Shay 60 > "$O/close.setup.txt" 2>&1
  r0=$(aff "Rel Cora" Shay)
  fightlight "$h" 4; sleep 5
  r=$(aff "Rel Cora" Shay)
  insp --effects 10 --interpret-log 20 --log-filter "Rel Cora" > "$O/close.inspect.txt" 2>&1
  if [ "$r0" = 60 ] && [ -n "$r" ] && [ $((60 - r)) -ge 20 ]; then v "close: PASS Fond 60 -> $r (drop $((60 - r)) >= 20 = x2.0)"
  else v "close: FAIL before '${r0:-none}' after '${r:-none}' (close.inspect.txt)"; fi ;;
treat)
  fresh
  h=$(bandit "Rel Tam") || { v "treat: FAIL no bandit"; continue; }
  fightko "$h" 120
  stobe-auto damage "$h" chest 120 >/dev/null; stobe-auto blood "$h" 40% >/dev/null
  r0=$(aff "Rel Tam" Shay); w0=$(lines $WL)
  stobe-auto give Shay "Basic First Aid Kit" 3 >/dev/null
  stobe-auto teleport Shay "$h" dist 2 >/dev/null
  stobe-auto order Shay FIRST_AID_ORDER target "$h" >/dev/null
  stobe-say speed 2 >/dev/null
  for i in $(seq 1 75); do stobe-auto hp "$h" | grep -q '(bandaged' && break; sleep 2; done
  for i in $(seq 1 90); do since $WL $w0 | grep -a "SOCIAL_INTERPRET" | grep -a "Rel Tam" | grep -a -q -e '"kind":"aid"' -e treated_relief && break; sleep 2; done
  stobe-auto teleport Shay "$h" dist 40 >/dev/null; stobe-say speed 1 >/dev/null; sleep 10; stobe-auto speed 0 >/dev/null
  since $WL $w0 | grep -a "SOCIAL_INTERPRET" | grep -a "Rel Tam" > "$O/treat.log.txt"
  r=$(aff "Rel Tam" Shay)
  if grep -q "treated_relief" "$O/treat.log.txt" && [ -n "$r" ] && [ "$r" -gt "${r0:-0}" ] && [ "$r" -lt 0 ]; then v "treat: PASS $r0 -> $r (15-30 % off, still negative)"
  else v "treat: FAIL before '${r0:-none}' after '${r:-none}' (treat.log.txt; no 'aid' fact = treatment never finished)"; fi
  away "$h" ;;
fade)
  fresh
  insp --set-switch SOCIAL_GRUDGE_FADE_DAYS 0.05 > "$O/fade.setup.txt" 2>&1
  h=$(bandit "Rel Fenn") || { v "fade: FAIL no bandit"; continue; }
  fightlight "$h" 4; sleep 3
  r0=$(aff "Rel Fenn" Shay)
  stobe-say speed 3 >/dev/null; stobe-auto wait-game 90 > "$O/fade.wait.txt" 2>&1; stobe-auto speed 0 >/dev/null
  t=$(bandit "Rel Tick") && fightlight "$t" 3   # any new fight = an ingest: runs the fade pass
  sleep 8
  r=$(aff "Rel Fenn" Shay)
  insp --effects 20 > "$O/fade.inspect.txt" 2>&1
  insp --set-switch SOCIAL_GRUDGE_FADE_DAYS off >> "$O/fade.setup.txt" 2>&1
  if [ -n "$r0" ] && [ "$r0" -lt 0 ] && [ "$r" = 0 ] && grep -q grudge_fade "$O/fade.inspect.txt"; then v "fade: PASS Rel Fenn -> Shay $r0 -> 0 after 90 game min (fade days 0.05)"
  elif grep -q -e serious_assault -e critical_harm -e '"maiming"' "$O/fade.inspect.txt"; then v "fade: INCONCLUSIVE the light fight became a KO or worse ($r0): never fades by rule"
  else v "fade: FAIL $r0 -> '${r:-none}' (fade.inspect.txt, fade.wait.txt)"; fi ;;
chat)
  fresh
  h=$(bandit "Rel Mira") || { v "chat: FAIL no bandit"; continue; }
  fightko "$h" 30
  stobe-say speed 1 >/dev/null
  for i in $(seq 1 60); do stobe-auto where "$h" | grep -q ' KO' || break; sleep 2; done
  stobe-auto speed 0 >/dev/null
  stobe-auto teleport "$h" Shay dist 5 >/dev/null
  # rule 4 is mechanical: inject a +3 evaluator gain before the fight filter (the live model gave -4 in m23: nothing to block)
  insp --set-switch SOCIAL_TEST_INJECT_DIALOGUE_GAIN 3 > "$O/chat.setup.txt" 2>&1
  w0=$(lines $WL); s0=$(lines $SL); c0=$(lines $CL)
  stobe-say say "Rel Mira" "Mira, I'm sorry about the fight. No hard feelings, friend? You fought well." --wait 40 > "$O/chat.say.txt" 2>&1
  sleep 20
  insp --set-switch SOCIAL_TEST_INJECT_DIALOGUE_GAIN off >> "$O/chat.setup.txt" 2>&1
  { since $WL $w0; since $SL $s0; } | grep -a -e "SOCIAL_DIALOGUE filtered" -e "SOCIAL_TEST_INJECT_DIALOGUE_GAIN" | grep -a "Rel Mira" > "$O/chat.filtered.txt"
  # the stance block is sent as markdown (prompt_formatting.php): "## Memory" + the line; the raw <memory> form is kept as a fallback
  since $CL $c0 | grep -a -A2 -e '^## Memory' -e '<memory>' | grep -a -e "Shay .* in a fight (day" | head -3 > "$O/chat.memory.txt"
  m="memory line: $(grep -c . "$O/chat.memory.txt")"
  inj=$(grep -a -c "SOCIAL_TEST_INJECT_DIALOGUE_GAIN" "$O/chat.filtered.txt")
  if [ "$inj" = 0 ]; then v "chat: SETUP FAIL the injected +3 never reached the evaluator path (no injection log line; chat.setup.txt, chat.say.txt), $m"
  elif grep -q "fight_cooldown" "$O/chat.filtered.txt" && [ -s "$O/chat.memory.txt" ]; then v "chat: PASS injected +3 blocked (fight_cooldown), $m"
  else v "chat: FAIL cooldown filter $(grep -c fight_cooldown "$O/chat.filtered.txt"), $m (chat.filtered.txt, chat.memory.txt)"; fi
  away "$h" ;;
bleed)
  fresh
  h=$(bandit "Rel Vex") || { v "bleed: FAIL no bandit"; continue; }
  l0=$(lines $L); w0=$(lines $WL)
  fightko "$h" 40
  # M24_F12: chest 100 + blood 30 % bled him out before waking (m22 F). A deep arm wound that doesn't bleed instead:
  # about -70 % (native "health" = worst part ratio <= -0.3 on waking = near death); blood watchdog while KO.
  d=$(stobe-auto damage "$h" right_arm 1 bleed 0); echo "$d" > "$O/bleed.setup.txt"
  idx=$(echo "$d" | grep -o 'part [0-9]*' | grep -o '[0-9]*$'); fl=$(echo "$d" | grep -o 'flesh [-0-9.]* -> [-0-9.]*' | awk '{print $4}')
  mx=$(stobe-auto hp "$h" | grep -o " $idx:[-0-9.]*/[0-9.]*" | head -1 | cut -d/ -f2)
  cut=$(awk -v f="$fl" -v m="$mx" 'BEGIN{ if (m > 0) printf "%.1f", f + 0.7 * m }')
  [ -n "$cut" ] && stobe-auto damage "$h" right_arm "$cut" bleed 0 >> "$O/bleed.setup.txt"
  ratio=$(stobe-auto hp "$h" | tee -a "$O/bleed.setup.txt" | grep -o " $idx:[-0-9.]*/[0-9.]*" | head -1 | awk -F'[:/]' '{ if ($3 > 0) printf "%d", 100 * $2 / $3 }')
  if [ -z "$ratio" ] || [ "$ratio" -gt -50 ] || [ "$ratio" -le -95 ]; then
    v "bleed: SETUP FAIL arm wound ${ratio:-?}% (want -50..-95; bleed.setup.txt)"; stobe-auto speed 0 >/dev/null; away "$h"; continue; fi
  stobe-say speed 3 >/dev/null
  for i in $(seq 1 80); do hl=$(stobe-auto hp "$h"); echo "$hl" | grep -q ' KO' || break
    bf=$(echo "$hl" | grep -o 'blood=[0-9.]*/[0-9.]*' | head -1 | awk -F'[=/]' '{ if ($3 > 0) printf "%d", 100 * $2 / $3 }')
    if [ -n "$bf" ] && [ "$bf" -lt 30 ]; then stobe-auto blood "$h" 40% >/dev/null; echo "watchdog: blood $bf% -> 40%" >> "$O/bleed.setup.txt"; fi
    sleep 2; done
  sleep 10; stobe-auto speed 0 >/dev/null
  since $L $l0 | grep -a "structured kind=recovered" | head -2 > "$O/bleed.stobe.txt"
  since $WL $w0 | grep -a "SOCIAL_INTERPRET" | grep -a "Rel Vex" > "$O/bleed.log.txt"
  r=$(aff "Rel Vex" Shay)
  if grep -q critical_harm "$O/bleed.log.txt" && [ -n "$r" ] && [ "$r" -le -55 ]; then v "bleed: PASS critical_harm, Rel Vex -> Shay $r"
  elif grep -a -q -e '"level":"death"' -e '"kind":"death"' "$O/bleed.log.txt" || stobe-auto where "$h" | grep -q ' DEAD'; then v "bleed: INCONCLUSIVE Rel Vex died before waking (bleed.log.txt)"
  elif ! grep -q '"known"' "$O/bleed.stobe.txt"; then v "bleed: FAIL no recovered fact with vitals in stobe.log after the KO: he never woke in the window? (bleed.stobe.txt)"
  else v "bleed: FAIL Rel Vex -> Shay '${r:-none}' (bleed.log.txt, bleed.stobe.txt: blood on waking above 0.5 / no bleeding?)"; fi
  away "$h" ;;
deal)
  insp --set-switch SOCIAL_TEST_FORCE_FIRST_STRIKE Shay > "$O/deal.setup.txt" 2>&1
  w0=$(lines $WL)
  SHAY_FIRST=1 bash "$I/rel-surrender.sh" kept "$O" > "$O/deal.surrender.txt" 2>&1
  insp --set-switch SOCIAL_TEST_FORCE_FIRST_STRIKE off >> "$O/deal.setup.txt" 2>&1
  since $WL $w0 | grep -a "SOCIAL_INTERPRET" | grep -a "Rel Krag" > "$O/deal.log.txt"
  last=$(tail -1 "$O/deal.surrender.txt")
  if grep -q '"component":"deal_forgiveness"' "$O/deal.log.txt" && grep -q '"result":"fights"' "$O/deal.log.txt"; then v "deal: PASS deal_forgiveness applied ($last)"
  elif ! grep -a '"observer":"Rel Krag"' "$O/deal.log.txt" | grep -a -q '"status":"fights"'; then v "deal: INCONCLUSIVE Rel Krag holds no fight penalty toward Shay (Shay's blows were defence; $last)"
  elif grep -q "nothing_to_forgive" "$O/deal.log.txt"; then v "deal: INCONCLUSIVE kept deal but no fight penalty of Rel Krag toward Shay ($last)"
  else v "deal: FAIL no deal_forgiveness ($last; deal.log.txt)"; fi ;;
accident)
  # Squad friendly fire without an attack (harness `hit`, b3ae609+): Shay's hit KOs Malzin -> knockout harm credited
  # to Shay, no combat event -> server: accident = 0.25 x KO range (-40..-50) x Malzin's closeness to Shay, never fades.
  # Then an injury inside a real fight against a bandit (Malzin is in that fight too, so Stobe emits the injury)
  # Shay is the hitter: the player has no NPC profile (core_npc row), so she can't hold a REL grudge; Malzin can..
  fresh
  if [ "$FRESH_SWEEP" != 1 ]; then v "accident KO: SETUP FAIL no 'NPC world event sweep complete' in stobe.log 90 s after the load"
    v "accident injury: SETUP FAIL (no post-load event sweep)"; continue; fi
  insp --set-switch SOCIAL_GRUDGE_FADE_DAYS 0.05 > "$O/accident.setup.txt" 2>&1
  stobe-auto teleport Malzin Shay dist 2 >/dev/null
  p0=$(aff Malzin Shay); p0=${p0:-0}
  m=$(awk -v a="$p0" 'BEGIN{m=1.0; if(a>=31)m=1.3; if(a>=56)m=2.0; if(a>=76)m=2.5; if(a>=91)m=3.0; print m}')
  w0=$(lines $WL); l0=$(lines $L)
  stobe-say speed 1 >/dev/null
  stobe-auto hit Shay Malzin head 130 > "$O/accident.hit.txt" 2>&1   # m22: 80 left head 19.9, no KO
  sleep 8; stobe-auto speed 0 >/dev/null
  p1=$(aff Malzin Shay); p1=${p1:-0}; da=$((p1 - p0))
  lo=$(awk -v m="$m" 'BEGIN{x=-13*m; print (x<-100)?-100:int(x-0.5)}'); hi=$(awk -v m="$m" 'BEGIN{print int(-10*m+0.5)}')
  since $L $l0 | grep -a -e "structured kind=harm" -e "\[EVENT\] combat" | head -6 > "$O/accident.stobe.txt"
  since $WL $w0 | grep -a "SOCIAL_INTERPRET" | grep -a "Malzin" > "$O/accident.log.txt"
  ko=$(grep -o 'ko=[a-z]*' "$O/accident.hit.txt" | head -1)
  d=$(grep -a '"component":"accident"' "$O/accident.log.txt" | grep -a -o '"delta":-\?[0-9]*' | grep -o -- '-\?[0-9]*$' | awk '{s+=$1} END{print s+0}')
  combat=$(grep -a -c "\[EVENT\] combat: Shay" "$O/accident.stobe.txt")
  # wake Malzin (m23: head -30.6 kept her down for the whole block): protect heals + clears the KO, then off again;
  # then fade check: 90 game min (> 0.05 game days) + a fight as ingest tick: the accidental KO must stay
  stobe-auto protect Malzin on >/dev/null; stobe-say speed 1 >/dev/null
  for i in $(seq 1 20); do stobe-auto where Malzin | grep -q ' KO' || break; sleep 2; done
  stobe-auto protect Malzin off >/dev/null; stobe-auto speed 0 >/dev/null
  stobe-say speed 3 >/dev/null; stobe-auto wait-game 90 > "$O/accident.wait.txt" 2>&1; stobe-auto speed 0 >/dev/null
  t=$(bandit "Rel Tock") && fightlight "$t" 3
  sleep 8
  p2=$(aff Malzin Shay); p2=${p2:-0}
  insp --set-switch SOCIAL_GRUDGE_FADE_DAYS off >> "$O/accident.setup.txt" 2>&1
  if [ "$ko" != "ko=yes" ]; then v "accident KO: INCONCLUSIVE hit did not KO Malzin ($ko; accident.hit.txt)"
  elif grep -q '"component":"accident"' "$O/accident.log.txt" && [ "$d" -le "$hi" ] && [ "$d" -ge "$lo" ] && [ "$p2" = "$p1" ] && [ "$combat" = 0 ]; then
    v "accident KO: PASS Malzin -> Shay $p0 -> $p1 (accident d=$d in $lo..$hi, stored $da = 0.25 x KO x$m), no combat event, unchanged after fade window ($p2)"
  else v "accident KO: FAIL d=$d (want $lo..$hi, x$m) after-fade=$p2 (want $p1) combat_events=$combat (accident.log.txt, accident.stobe.txt)"; fi
  # injury inside a real fight against a third party
  b=$(bandit "Rel Brawl") || { v "accident injury: SETUP FAIL no bandit"; continue; }
  if stobe-auto where Malzin | grep -q ' KO'; then v "accident injury: SETUP FAIL Malzin still KO after protect (no fight possible)"; away "$b"; continue; fi
  stobe-auto chars 80 | tr '|' '\n' | grep -q "Rel Brawl" || { v "accident injury: SETUP FAIL bandit lost the name Rel Brawl (renamed)"; away "$b"; continue; }
  stobe-auto teleport "$b" Shay dist 3 >/dev/null
  stobe-auto attack Shay "$b" >/dev/null; stobe-auto attack Malzin "$b" >/dev/null; stobe-auto attack "$b" Malzin >/dev/null
  stobe-say speed 1 >/dev/null; sleep 5
  q0=$(aff Malzin Shay); q0=${q0:-0}; w0=$(lines $WL)
  stobe-auto teleport Malzin Shay dist 2 >/dev/null
  stobe-auto hit Shay Malzin left_arm 30 > "$O/accident.hit2.txt" 2>&1
  sleep 6; stobe-auto speed 0 >/dev/null; away "$b"
  q1=$(aff Malzin Shay); q1=${q1:-0}
  lo2=$(awk -v m="$m" 'BEGIN{print int(-8*m-0.5)}')
  since $WL $w0 | grep -a "SOCIAL_INTERPRET" | grep -a "Malzin" > "$O/accident.log2.txt"
  # m22: d=-9 stored vs accident delta -7: Malzin's fight dialogue moved the stored value too; judge the accident effect
  d2=$(grep -a '"component":"accident"' "$O/accident.log2.txt" | grep -a -o '"delta":-\?[0-9]*' | grep -o -- '-\?[0-9]*$' | awk '{s+=$1} END{print s+0}')
  if grep -q '"component":"accident"' "$O/accident.log2.txt" && [ "$d2" -lt 0 ] && [ "$d2" -ge "$lo2" ]; then
    v "accident injury: PASS in a real fight, Malzin -> Shay $q0 -> $q1 (accident d=$d2 in $lo2..-1: 0.25 x injury x$m)"
  elif [ ! -s "$O/accident.log2.txt" ]; then v "accident injury: FAIL no harm event for a hit inside a real fight (Malzin was up and fighting; accident.hit2.txt)"
  else v "accident injury: FAIL d=$d2 (want $lo2..-1) (accident.log2.txt)"; fi ;;
*) v "$B: unknown block" ;;
esac; done

off
stobe-auto select Shay >/dev/null 2>&1
stobe-auto load auto-home >/dev/null; sleep 12; stobe-auto wait-world 240 >/dev/null
echo "rel-b55 done: $O/verdicts.txt (switches off)"
