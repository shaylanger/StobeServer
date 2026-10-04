#!/bin/bash
# rel-m18.sh <outdir> (BLOCKS env: see below): REL SR13/SR14 (owned-item theft seen/unseen), SR09 (KO loot witnessed), SR07 (sever),
# SR06 (forced first strike) and SR30 (forced join attempt). Fixture auto-home, Capture=1.
# Needs: Stobe with pending-fixes/rel_theft_caught_native.py, harness e8b4688+ (drop ... owned), server 2fbd947+.
# Each block: set mode/switches, load (if fresh), run, inspect + VERDICT line. Leaves mode off and both switches off.
O=${1:-/mnt/c/KenshiTestRuns/rel-m18}
I=$(cd "$(dirname "$0")" && pwd)
S=/var/www/html/StobeServer
L=/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe.log
SL=$S/log/stobeserver.log
mkdir -p "$O"
# M22_F7_READY: stobe_ready / stobe_log_lines (bounded poll for the Stobe NPC event sweep after a load)
source /mnt/c/KenshiModding/tests/ingame/stobe/stobe-ready.sh || { echo "VERDICT rel-m18: SETUP FAIL no stobe-ready.sh"; exit 4; }
insp(){ (cd $S && sudo -u www-data php tools/social_relationship_inspect.php "$@"); }
# fresh <row>: load auto-home, wait for the world, then for Stobe's NPC event sweep (it starts 45 s after the last
# world transition: events before that are only baselined, m22). Not ready in 150 s = SETUP FAIL for <row>.
fresh(){ local b; b=$(stobe_log_lines)
  stobe-auto load auto-home >/dev/null; sleep 12; stobe-auto wait-world 240 >/dev/null
  stobe_ready 150 "$b" || { v "$1: SETUP FAIL $STOBE_READY_WHY"; exit 4; }; }
run(){ # run <file> -> $O/<base>.out/.csv, prints the runner's last line
  local b; b=$(basename "$1" .txt)
  stobe-auto run "$I/$1" --csv "$O/$b.csv" > "$O/$b.out" 2>&1
  sleep 20
  echo "$b: $(tail -1 "$O/$b.out" | cut -c1-80)"
}
val(){ grep -o "$1=.*" "$O/$2.out" | head -1 | sed "s/$1=//"; }
v(){ echo "VERDICT $*" | tee -a "$O/verdicts.txt"; }
off(){ insp --set-mode off >/dev/null; insp --set-switch SOCIAL_TEST_FORCE_FIRST_STRIKE off >/dev/null; insp --set-switch SOCIAL_TEST_FORCE_JOIN_ATTEMPT off >/dev/null; }
trap off EXIT
: > "$O/verdicts.txt"
# BLOCKS="sr13 sr13u sr09 sr07 sr06 sr30" (default all): run only these blocks (each loads fresh itself;
# sr13u expects the shadow mode sr13 sets, so it sets it too when run alone).
BLOCKS=${BLOCKS:-sr13 sr13u sr09 sr07 sr06 sr30}
want(){ case " $BLOCKS " in *" $1 "*) return 0;; esac; return 1; }

# --- SR13/SR14 seen (shadow) ---
if want sr13; then
insp --set-mode shadow >/dev/null; fresh "SR13 seen"
run REL-p5-03-theft-owned-seen.txt
T=$(val TNAME REL-p5-03-theft-owned-seen); [ -z "$T" ] && T="Rel Tess"
insp --pair-effects --interpret-log 60 --log-filter "$T" --check-shadow > "$O/p5-03.inspect.txt" 2>&1
# M23_F8_REL: the game's theft dialog event (Stobe m23) counts like HUNT_MY_THIEF
grep -a "theft hunt\|theft dialog\|kind=theft_caught\|kind=item_gain" $L | tail -8 > "$O/p5-03.stobe.txt"
grep -q "petty_theft\|\"theft\"" "$O/p5-03.inspect.txt" && v "SR13 seen: PASS theft row $T -> Shay" || v "SR13 seen: FAIL/INCONCLUSIVE no theft row (hunt/dialog lines: $(grep -c 'theft hunt\|theft dialog' "$O/p5-03.stobe.txt"))"
grep -q "property_returned" "$O/p5-03.inspect.txt" && v "SR14 returned: PASS" || v "SR14 returned: FAIL no property_returned"
fi

# --- SR13 unseen (shadow) ---
if want sr13u; then
insp --set-mode shadow >/dev/null; fresh "SR13 unseen"
run REL-p5-04-theft-owned-unseen.txt
T=$(val TNAME REL-p5-04-theft-owned-unseen); [ -z "$T" ] && T="Rel Tess"
insp --pair-effects --interpret-log 40 --log-filter "$T" --expect-none "$T" "Shay" > "$O/p5-04.inspect.txt" 2>&1 && v "SR13 unseen: PASS no blame" || v "SR13 unseen: FAIL $T blames Shay"
grep -a "theft hunt .*thief=Shay" $L | tail -3 > "$O/p5-04.hunts.txt"
fi

# --- SR09 KO loot witnessed (shadow) ---
if want sr09; then
insp --set-mode shadow >/dev/null; fresh SR09
run REL-p3-06-ko-loot-witnessed.txt
V=$(val VNAME REL-p3-06-ko-loot-witnessed); [ -z "$V" ] && V="Rel Vale"
insp --pair-effects --incidents 10 --interpret-log 60 --log-filter "$V" --check-shadow > "$O/p3-06.inspect.txt" 2>&1
if grep -q '"known_thief"' "$O/p3-06.inspect.txt" || grep -q "known_thief" "$O/p3-06.inspect.txt"; then v "SR09: known thief recorded (check $V -> Malzin theft, $V -> Shay no theft in p3-06.inspect.txt)"; else v "SR09: FAIL no known_thief"; fi
fi

# --- SR07 sever (shadow) ---
if want sr07; then
insp --set-mode shadow >/dev/null; fresh SR07
run REL-p3-05b-defensive-limb-loss-sever.txt
insp --pair-effects --effects 20 --interpret-log 40 --check-shadow > "$O/p3-05b.inspect.txt" 2>&1
grep -q "defensive_maiming" "$O/p3-05b.inspect.txt" && v "SR07: PASS defensive_maiming" || v "SR07: FAIL no defensive_maiming"
fi

# --- SR06 forced first strike (shadow): p2-01 then p2-04b on the same bandit ---
if want sr06; then
insp --set-mode shadow >/dev/null; fresh SR06
run REL-p2-01-player-first-strike.txt
insp --set-switch SOCIAL_TEST_FORCE_FIRST_STRIKE Shay > "$O/p2-04b.switch.txt"
run REL-p2-04b-repeat-assault-forced.txt
insp --set-switch SOCIAL_TEST_FORCE_FIRST_STRIKE off >> "$O/p2-04b.switch.txt"
insp --pair-effects --effects 20 --interpret-log 40 --log-filter "Rel Vorn" --check-shadow > "$O/p2-04b.inspect.txt" 2>&1
grep -a "SOCIAL_TEST_FORCE_FIRST_STRIKE" $SL | tail -3 > "$O/p2-04b.forced.txt"
# M24_F12: PASS/FAIL. Rel Vorn -> Shay aggression in >= 2 distinct combat incidents (min of the pair's aggression parts and
# the distinct combat incidents carrying an aggression row).
n=$(php -r '$j = json_decode(file_get_contents($argv[1]), true) ?: []; $p = 0; $i = [];
  foreach ($j["pair_effects"] ?? [] as $r) if (($r["observer"] ?? "") === "Rel Vorn" && ($r["culprit"] ?? "") === "Shay") $p = substr_count($r["components"] ?? "", "aggression:");
  foreach ($j["effect_rows"] ?? [] as $r) if (($r["component"] ?? "") === "aggression" && strpos($r["incident_id"] ?? "", "combat:") === 0) $i[$r["incident_id"]] = 1;
  echo min($p, count($i));' "$O/p2-04b.inspect.txt" 2>/dev/null)
fs=$(grep -c '"attacker":"Shay","victim":"Rel Vorn"' "$O/p2-04b.forced.txt")
if [ "${n:-0}" -ge 2 ]; then v "SR06: PASS Rel Vorn -> Shay aggression in $n combat incidents (forced first strike lines $fs)"
elif [ "$fs" = 0 ]; then v "SR06: SETUP FAIL the forced first strike never fired for Shay -> Rel Vorn (aggression incidents ${n:-0}, p2-04b.forced.txt)"
else v "SR06: FAIL Rel Vorn -> Shay aggression in ${n:-0} combat incidents, need 2 (p2-04b.inspect.txt)"; fi
fi

# --- SR30 forced join attempt (ENABLED) ---
if want sr30; then
insp --set-mode enabled >/dev/null; fresh SR30
insp --set-switch SOCIAL_TEST_FORCE_JOIN_ATTEMPT true > "$O/p7-01.switch.txt"
run REL-p7-01b-recruit-forced-low.txt
grep -a "SOCIAL_TEST_FORCE_JOIN_ATTEMPT\|REL recruitment gate blocked JoinParty" $SL | tail -4 > "$O/p7-01b.log.txt"
grep -q "gate blocked JoinParty" "$O/p7-01b.log.txt" && v "SR30 low trust: PASS gate blocked" || v "SR30 low trust: FAIL no gate block (see p7-01b.log.txt)"
insp --set-relation "Rel Rook" "Shay" 80 >> "$O/p7-01.switch.txt"
insp --add-trust "Rel Rook" "Shay" lifesaving 25 >> "$O/p7-01.switch.txt"
B=$(grep -ac "gate blocked JoinParty" $SL)
run REL-p7-01c-recruit-forced-trusted.txt
grep -a "SOCIAL_TEST_FORCE_JOIN_ATTEMPT\|REL recruitment gate blocked JoinParty" $SL | tail -4 > "$O/p7-01c.log.txt"
grep -a "ACTION_EXEC: JOIN_PARTY" $L | tail -3 >> "$O/p7-01c.log.txt"
A=$(grep -ac "gate blocked JoinParty" $SL)
grep -q "== .* 0 failed\|passed, 0 failed" "$O/REL-p7-01c-recruit-forced-trusted.out" && [ "$A" = "$B" ] && v "SR30 trusted: PASS joined, no new block" || v "SR30 trusted: FAIL (blocks $B -> $A, see p7-01c.log.txt and .out)"
fi

off
stobe-auto select Shay >/dev/null 2>&1
stobe-auto load auto-home >/dev/null; sleep 12; stobe-auto wait-world 240 >/dev/null
echo "rel-m18 done: $O/verdicts.txt (mode off, switches off)"
