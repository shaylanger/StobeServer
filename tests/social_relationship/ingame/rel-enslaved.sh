#!/bin/bash
# rel-enslaved.sh <outdir> [squad member A] [squad member B]   (WSL root; Kenshi running, harness 08AB6BF0+, Capture=1)
# REL on Testing-Save-Enslaved (copy kah-enslaved): which squad member is the slave is read from Stobe's own sweep
# ("first seen already enslaved"), then the templates are filled in and run:
#   p7-03 SR12 real (shadow)  -> p7-04 SR32 real liberator (shadow)  -> p7-05 free a non-squad slave + gate at low trust
#   (enabled)  -> p7-06 gate at affinity 80 without trust evidence (enabled). Mode is set back to off at the end.
# Guards: every non-squad faction within 150 gets `relation <member> 100` before p7-04 (logged); never kill.
O="${1:?outdir}"; A="${2:-Izumi}"; B="${3:-Daphnilis}"
I="$(cd "$(dirname "$0")" && pwd)"
S=/var/www/html/StobeServer
L=/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe.log
mkdir -p "$O"; LOG="$O/rel-enslaved.log"; : > "$LOG"
log(){ echo "$(date +%T) $*" | tee -a "$LOG"; }
insp(){ (cd $S && sudo -u www-data php tools/social_relationship_inspect.php "$@"); }
run(){ # template tag
  sed -e "s/__SLAVE__/$SLAVE/g" -e "s/__FREE__/$FREE/g" -e "s/__NS__/${NS:-none}/g" "$I/$1.txt" > "/tmp/$1.txt"
  stobe-auto run "/tmp/$1.txt" --csv "$O/$1.csv" > "$O/$1.out" 2>&1
  sleep 20
  insp --pair-effects --effects 40 --beliefs 20 --incidents 10 --interpret-log 60 --events 60 --check-shadow > "$O/$1.inspect.txt" 2>&1
  log "$1: $(tail -1 "$O/$1.out" | cut -c1-60)"
  local s; s=$(grep -a '^== ' "$O/$1.out" | tail -1)
  case "$s" in
    "== "*" 0 failed"*) echo "RESULT $1 PASS ${s#== }" ;;
    *) echo "RESULT $1 FAIL ${s:-no summary} | $(grep -a '^FAIL' "$O/$1.out" | head -2 | cut -c1-110 | tr '\n' ' ') log=$O/$1.out" ;;
  esac
}
# m26: the Slave Traders camp attacks whoever picks a slave's shackles (m18: 33 guards knocked Daphnilis out, nothing
# freed). KO every Slave Trader within 1000 m of the squad (filter before the 40 cap; passes until none is awake). Never kill.
koslavers(){
  local n=0 p h
  for p in 1 2 3 4 5 6; do
    # m33 S6: the knocked-out nearest 40 filled the 40 cap and an awake guard behind them (Jorek 4) KO'd the slave: list awake ones only (harness EAB24D54 !ko/!dead)
    stobe-auto chars 1000 "[slave traders]|[traders guild]|!ko|!dead" | tr '|' '\n' | grep -E '\[(Slave Traders|Traders Guild)\]' > "$O/slavers-$1.txt"
    grep -v -e ' KO' -e ' DEAD' "$O/slavers-$1.txt" | grep -o '#[0-9]*/[0-9]*' > "$O/slavers-$1.todo"
    [ -s "$O/slavers-$1.todo" ] || break
    while read -r h; do stobe-auto ko "$h" 1500 >> "$O/guards.txt" 2>&1; n=$((n + 1)); done < "$O/slavers-$1.todo"
    # m31: a KO shows in `chars` only after a frame (paused, every pass re-knocked the same nearest 40): 1 s of time
    stobe-auto speed 1 >/dev/null; sleep 1; stobe-auto speed 0 >/dev/null
  done
  log "$1: knocked out $n Slave Traders + Traders Guild (m32 S5: guild samurai KO'd the freed slave) for 1500 s ($(grep -c . "$O/slavers-$1.txt") listed, 40 = cap; awake left: $(grep -c . "$O/slavers-$1.todo"))"
}
gate(){ grep -a "REL recruitment gate blocked JoinParty" $S/log/stobeserver.log | grep -F "Rel Nima" | tail -2; }

# setup check: Stobe reads Capture only at launch (the coordinator sets Capture=0 after runs)
grep -a -q "SOCIAL_CAPTURE: enabled" "$L" || { log "FAIL setup: this Kenshi launch has Capture off (set StobeCustom.ini [SocialRelationships] Capture=1, relaunch)"
  echo "RESULT REL-p7-enslaved FAIL setup: Capture off in this launch (no 'SOCIAL_CAPTURE: enabled' in stobe.log)"; exit 4; }
insp --set-mode shadow >/dev/null
base=$(grep -a -c "" "$L")   # before the load: the squad's first sweep can land right after world-stable
stobe-auto load kah-enslaved >/dev/null; sleep 12; stobe-auto wait-world 240 >/dev/null
# m32: Stobe's sweep scans squad members near the player or the SELECTION only (REL_SQUAD_SWEEP_M22);
# after this load nothing is selected, so a squad slave held away from the player was never scanned
# (batch S/S2). Select each squad member in turn (A first) so both get into the sweep.
stobe-auto select "$A" >/dev/null 2>&1
stobe-auto speed 1 >/dev/null
for i in $(seq 1 100); do   # bounded poll (<=300 s)
  [ "$i" = 40 ] && stobe-auto select "$B" >/dev/null 2>&1
  tail -n +"$base" "$L" | grep -a "first seen already enslaved" > "$O/first-seen-enslaved.txt"
  grep -q -E "name=($A|$B) " "$O/first-seen-enslaved.txt" && break; sleep 3
done
stobe-auto speed 0 >/dev/null
if grep -q "name=$A " "$O/first-seen-enslaved.txt"; then SLAVE="$A"; FREE="$B"
elif grep -q "name=$B " "$O/first-seen-enslaved.txt"; then SLAVE="$B"; FREE="$A"
else log "FAIL SR12: neither $A nor $B was seen enslaved after the load (see first-seen-enslaved.txt)"
  echo "RESULT REL-p7-03-enslaved-real-load FAIL neither $A nor $B first seen enslaved log=$O/first-seen-enslaved.txt"; insp --set-mode off >/dev/null; exit 1; fi
# The non-squad slave must wear a pickable lock (shackles): a caged prisoner can't be freed by PICK_LOCK_ON_SHACKLES
# (batch O: the first one was in a prison cage). Harness: "lockpick_chance=" vs "<name> wears nothing locked".
# m32 S3: picked before p7-04, the slave freed itself once p7-04 knocked its master out (handle changed, unshackled),
# so p7-05 found nobody. Pick right before p7-05 (after its knockouts), from each slave's LATEST serial
# (first-seen / slave-state / handle-changed lines since the load), still shackled.
pick_ns(){
  NS=""; : > "$O/ns-candidates.txt"
  tail -n +"$base" "$L" | grep -a -E "first seen already enslaved serial=|EVENT_SCAN: slave state serial=|SOCIAL_IDENTITY: handle changed" |
    sed -E 's/ (chained=|\(no enslaved).*//' | sed -n -E 's/.*serial=([0-9]+) name=(.*)$/\2	\1/p; s/.*name=(.*) old=[0-9]+ new=([0-9]+).*/\1	\2/p' |
    grep -v -E "^($A|$B)	" | awk -F'	' '{last[$1]=$2; if(!($1 in ord)){ord[$1]=++n; nm[n]=$1}} END{for(i=1;i<=n;i++) print "#" last[nm[i]]}' | head -20 > "$O/ns-serials.txt"
  for c in $(cat "$O/ns-serials.txt"); do
    r=$(stobe-auto chance "$FREE" lockpick "$c" 2>&1 | tr -s '[:space:]' ' ' | cut -c1-160); echo "$c $r" >> "$O/ns-candidates.txt"
    case "$r" in *lockpick_chance=*) NS="$c"; break ;; esac
  done
  log "non-squad shackled slave=${NS:-none} ($(grep -c . "$O/ns-serials.txt") slaves, $(grep -c . "$O/ns-candidates.txt") checked: ns-candidates.txt)"
}
log "slave=$SLAVE free=$FREE"
stobe-auto chars 150 > "$O/chars-after-load.txt" 2>&1

run REL-p7-03-enslaved-real-load
stobe-auto chars 150 | tr '|' '\n' | grep -o '#[0-9]*/[0-9]* \[[^]]*\]' | grep -v '\[Nameless\]' | sort -u -t'[' -k2,2 > "$O/camp-factions.txt"
while read -r h f; do stobe-auto relation "$h" 100 >> "$O/guards.txt" 2>&1; done < "$O/camp-factions.txt"
# m16: the escape still turned the slavers on the freer (CAPTURE_ESCAPING_SLAVES): knock the camp's Slave Traders out
# for the freeing steps (never kill). Other camp factions (Outlaws) can be slaves themselves.
koslavers p7-04
log "guards: relation 100 for $(wc -l < "$O/camp-factions.txt") camp factions: $(cut -d' ' -f2- "$O/camp-factions.txt" | tr '\n' ' ')"
run REL-p7-04-enslaved-real-liberator
koslavers p7-05
pick_ns
if [ -n "$NS" ]; then
  insp --set-mode enabled >/dev/null
  g0=$(gate | wc -l)
  run REL-p7-05-enslaved-free-recruit
  gate > "$O/gate-low.txt"; log "gate at low trust: $(tail -1 "$O/gate-low.txt" | cut -c1-200)"
  insp --set-relation "Rel Nima" "$FREE" 80 > "$O/setrel-80.txt" 2>&1
  run REL-p7-06-enslaved-recruit-trusted
  gate > "$O/gate-80.txt"; log "gate at 80: $(tail -1 "$O/gate-80.txt" | cut -c1-200)"
else
  log "FAIL setup: no shackled (lockpickable) non-squad slave among the first-seen slaves: p7-05/06 not run (see ns-candidates.txt)"
  echo "RESULT REL-p7-05-enslaved-free-recruit FAIL setup: no shackled non-squad slave log=$O/ns-candidates.txt"
  echo "RESULT REL-p7-06-enslaved-recruit-trusted FAIL setup: no shackled non-squad slave log=$O/ns-candidates.txt"
fi
insp --set-mode off >/dev/null
grep -a -E "slave state|SOCIAL_CAPTURE: structured kind=(freed|enslaved)" "$L" | tail -n 20 > "$O/slavery-lines.txt"
log "done, mode off"
