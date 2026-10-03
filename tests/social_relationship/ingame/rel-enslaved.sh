#!/bin/bash
# rel-enslaved.sh <outdir> [squad member A] [squad member B]   (WSL root; Kenshi running, harness 08AB6BF0+, Capture=1)
# REL on Testing-Save-Enslaved (copy kah-enslaved): which squad member is the slave is read from Stobe's own sweep
# ("first seen already enslaved"), then the templates are filled in and run:
#   p7-03 SR12 real (shadow)  -> p7-04 SR32 real liberator (shadow)  -> p7-05 free a non-squad slave + gate at low trust
#   (enabled)  -> p7-06 gate at affinity 80 without trust evidence (enabled). Mode is set back to off at the end.
# Guards: every non-squad faction within 150 gets `relation <member> 100` before p7-04 (logged); never kill.
O="${1:?outdir}"; A="${2:-Izumi}"; B="${3:-Daphnilis}"
I=/root/stobe-work/social-phase1/server/tests/social_relationship/ingame
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
}
gate(){ grep -a "REL recruitment gate blocked JoinParty" $S/log/stobeserver.log | grep -F "Rel Nima" | tail -2; }

insp --set-mode shadow >/dev/null
stobe-auto load kah-enslaved >/dev/null; sleep 12; stobe-auto wait-world 240 >/dev/null; sleep 8
base=$(grep -a -c "" "$L")
stobe-auto speed 1 >/dev/null; sleep 55   # Stobe's NPC sweep starts ~45 s after a load
stobe-auto speed 0 >/dev/null
tail -n +"$base" "$L" | grep -a "first seen already enslaved" > "$O/first-seen-enslaved.txt"
if grep -q "name=$A " "$O/first-seen-enslaved.txt"; then SLAVE="$A"; FREE="$B"
elif grep -q "name=$B " "$O/first-seen-enslaved.txt"; then SLAVE="$B"; FREE="$A"
else log "FAIL SR12: neither $A nor $B was seen enslaved after the load (see first-seen-enslaved.txt)"; insp --set-mode off >/dev/null; exit 1; fi
NS=$(grep -v -E "name=($A|$B) " "$O/first-seen-enslaved.txt" | head -1 | grep -o 'serial=[0-9]*' | sed 's/serial=/#/')
log "slave=$SLAVE free=$FREE non-squad slave=${NS:-none} ($(grep -c . "$O/first-seen-enslaved.txt") first-seen slaves)"
stobe-auto chars 150 > "$O/chars-after-load.txt" 2>&1

run REL-p7-03-enslaved-real-load
stobe-auto chars 150 | tr '|' '\n' | grep -o '#[0-9]*/[0-9]* \[[^]]*\]' | grep -v '\[Nameless\]' | sort -u -t'[' -k2,2 > "$O/camp-factions.txt"
while read -r h f; do stobe-auto relation "$h" 100 >> "$O/guards.txt" 2>&1; done < "$O/camp-factions.txt"
# m16: the escape still turned the slavers on the freer (CAPTURE_ESCAPING_SLAVES); knock the Slave Traders near the
# squad out for the freeing steps (never kill). Other camp factions (Outlaws) can be slaves themselves.
stobe-auto chars 150 | tr '|' '
' | grep '\[Slave Traders\]' | grep -v -e ' KO' -e ' DEAD' | grep -o '#[0-9]*/[0-9]*' > "$O/slavers.txt"
while read -r h; do stobe-auto ko "$h" 300 >> "$O/guards.txt" 2>&1; done < "$O/slavers.txt"
log "slavers knocked out for 300 s: $(wc -l < "$O/slavers.txt")"
log "guards: relation 100 for $(wc -l < "$O/camp-factions.txt") camp factions: $(cut -d' ' -f2- "$O/camp-factions.txt" | tr '\n' ' ')"
run REL-p7-04-enslaved-real-liberator
if [ -n "$NS" ]; then
  insp --set-mode enabled >/dev/null
  g0=$(gate | wc -l)
  run REL-p7-05-enslaved-free-recruit
  gate > "$O/gate-low.txt"; log "gate at low trust: $(tail -1 "$O/gate-low.txt" | cut -c1-200)"
  insp --set-relation "Rel Nima" "$FREE" 80 > "$O/setrel-80.txt" 2>&1
  run REL-p7-06-enslaved-recruit-trusted
  gate > "$O/gate-80.txt"; log "gate at 80: $(tail -1 "$O/gate-80.txt" | cut -c1-200)"
else
  log "no non-squad slave seen: p7-05/06 skipped"
fi
insp --set-mode off >/dev/null
grep -a -E "slave state|SOCIAL_CAPTURE: structured kind=(freed|enslaved)" "$L" | tail -n 20 > "$O/slavery-lines.txt"
log "done, mode off"
