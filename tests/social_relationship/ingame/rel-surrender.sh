#!/bin/bash
# rel-surrender.sh <kept|breach> <outdir> [hate]   (WSL, root; Kenshi running with the harness; Capture=1)
# REL SR28/SR29 (RUN_ORDER phase 5 rows 3-4), unattended. Per attempt (max 2):
#   reload auto-home -> Malzin KO'd 40 m away (as scenarios.sh surrender) -> one raider, renamed (Rel Krag / Rel Brak)
#   -> [hate: --set-relation "<NAME>" "Shay" -85, read back] -> fight until he swings at Shay -> health 25
#   -> wait for his surrender offer (PROPOSED/COUNTERED) -> Shay accepts ("<NAME>, deal.")
#   -> kept: speed 2, nobody attacks; breach: once ACCEPTED/AWAITING_PERFORMANCE, `attack Shay <raider>`
#   -> wait for the final deal status -> raider sent 300 away -> inspect.
# Output: <outdir>/REL-p5-surrender-<mode>[-hate].{log,deals.txt,relation.txt,inspect.txt}; last line PASS|FAIL <reason>.
# Pass:  kept: final COMPLETE + interpret-log agreement component kept_coercive_deal (result shadow, delta 0..2);
#        breach: final BREACHED_PLAYER + agreement lines broken_promise AND betrayal for that contract;
#        hate (SR28): also the -85 readback before the offer and an offer at all (no affection gate).
mode="${1:?kept|breach}"; O="${2:?outdir}"; hate="${3:-}"
S=/var/www/html/StobeServer
L=/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe.log
mkdir -p "$O"
tag="REL-p5-surrender-$mode${hate:+-hate}"
[ "$mode" = kept ] && NAME="Rel Krag" || NAME="Rel Brak"
LOG="$O/$tag.log"; : > "$LOG"
log(){ echo "$(date +%T) $*" | tee -a "$LOG"; }
insp(){ (cd $S && sudo -u www-data php tools/social_relationship_inspect.php "$@"); }
deals(){ (cd /tmp && sudo -u www-data php $S/tools/negotiation_admin.php deals "${1:-5}" 2>/dev/null); }
dealline(){ deals 6 | grep -E "^deal-" | grep -F "$deal" | head -1; }
dealblock(){ deals 6 | awk -v d="$deal" '/^deal-/{on=($1==d)} on'; }
finish(){ stobe-auto speed 0 >/dev/null
  [ -n "${r:-}" ] && stobe-auto teleport "$r" Shay dist 300 >/dev/null 2>&1
  stobe-auto teleport Malzin Shay dist 6 >/dev/null 2>&1
  sleep 20
  deals 4 > "$O/$tag.deals.txt"
  insp --interpret-log 80 --log-filter "$NAME" --pair-effects --effects 20 --check-shadow > "$O/$tag.inspect.txt" 2>&1
  log "$1"; exit 0; }

setup(){ # one attempt; sets r (handle) and deal; returns 1 when no offer came
  stobe-auto load auto-home >/dev/null; sleep 12; stobe-auto wait-world 240 >/dev/null; sleep 8
  stobe-auto speed 0 >/dev/null
  stobe-auto select Shay >/dev/null
  stobe-auto hunger Shay 280 >/dev/null; stobe-auto hunger Malzin 280 >/dev/null
  stobe-auto where Shay | grep -q " KO" && { log "Shay KO after load"; return 1; }
  base=$(grep -a -c "" "$L")
  stobe-auto teleport Malzin Shay dist 40 >/dev/null; stobe-auto ko Malzin 150 >/dev/null
  stobe-auto spawn "Bandit Raiders (weakened) 1" "Starving Bandits" near Shay dist 4 count 1 target Shay size 0.1 >/dev/null
  sleep 1
  r=""
  for s in $(stobe-auto chars 150 | tr '|' '\n' | grep 'Starving Bandits' | grep -v -e ' DEAD' -e ' KO' | grep -o '#[0-9]*/[0-9]*'); do
    if [ -z "$r" ]; then r="$s"; else stobe-auto teleport "$s" Shay dist 3000 >/dev/null; fi
  done
  [ -n "$r" ] || { log "no raider spawned"; return 1; }
  stobe-auto teleport "$r" Shay dist 3 >/dev/null
  out=$(stobe-auto setname "$r" "$NAME"); log "setname: $out"
  echo "$out" | grep -q -- "-> $NAME" || { log "setname failed"; return 1; }
  stobe-say speed 1 >/dev/null
  if [ -n "$hate" ]; then
    ok=""
    for i in $(seq 1 15); do # his profile appears once Stobe has synced him (a few s of game time)
      insp --set-relation "$NAME" "Shay" -85 >> "$O/$tag.relation.txt" 2>&1 && { ok=1; break; }
      sleep 2
    done
    insp --relation "$NAME" "Shay" > "$O/$tag.relation-before.txt" 2>&1
    [ -n "$ok" ] && grep -q '"aff": -85' "$O/$tag.relation-before.txt" || { log "set-relation -85 failed"; return 1; }
    log "relation $NAME -> Shay = -85"
  fi
  for i in $(seq 1 15); do # until he really swings at Shay
    if [ "${SHAY_FIRST:-0}" = 1 ]; then stobe-auto attack Shay "$r" >/dev/null; sleep 2; stobe-auto attack "$r" Shay >/dev/null   # B55 deal: Shay strikes first
    else stobe-auto attack "$r" Shay >/dev/null; stobe-auto attack Shay "$r" >/dev/null; fi
    sleep 4
    tail -n +"$base" "$L" | grep -a -F "[EVENT] combat: $NAME" | grep -a -q -- "-> Shay" && break
  done
  for i in $(seq 1 10); do
    tail -n +"$base" "$L" | grep -a "\[EVENT\] combat_start" | grep -a -q -F "$NAME" && break
    sleep 2
  done
  stobe-auto health "$r" 25 >/dev/null
  deal=""
  for i in $(seq 1 12); do
    sleep 5
    deal=$(deals 3 | grep -E "^deal-" | grep -F "$NAME" | grep -E "PROPOSED|COUNTERED" | awk '{print $1}' | head -1)
    [ -n "$deal" ] && break
  done
  stobe-auto speed 0 >/dev/null
  [ -n "$deal" ] || { log "no surrender offer within 60 s"; return 1; }
  log "offer $deal"
  return 0
}

for attempt in 1 2; do
  log "attempt $attempt mode=$mode hate=${hate:-no}"
  setup && break
  deal=""
done
[ -n "${deal:-}" ] || finish "FAIL no_offer (2 attempts)${hate:+: SR28 hated enemy made no surrender offer}"

stobe-auto teleport Malzin Shay dist 6 >/dev/null
stobe-say speed 1 >/dev/null
stobe-say say "$NAME" "$NAME, deal." --wait 20 >> "$LOG" 2>&1
st=""
for i in $(seq 1 30); do # accepted (or already done)
  st=$(dealline | grep -o -E 'ACCEPTED|AWAITING_PERFORMANCE|COMPLETE|BREACHED_PLAYER|BREACHED_NPC|IMPOSSIBLE|CANCELLED|EXPIRED|PROPOSED|COUNTERED' | head -1)
  case "$st" in ACCEPTED|AWAITING_PERFORMANCE|COMPLETE|BREACHED_*|IMPOSSIBLE|CANCELLED|EXPIRED) break;; esac
  sleep 2
done
log "after accept: $st"
case "$st" in ACCEPTED|AWAITING_PERFORMANCE|COMPLETE) ;; *) finish "FAIL not_accepted ($st)";; esac
if [ "$mode" = breach ] && [ "$st" != COMPLETE ]; then
  # m16: an attack 9 s after the accept was undone by Stobe's personal-truce guard (STOP_ATTACK guard_seconds=20,
  # "PERSONAL_TRUCE: reapplied ... orders_cleared=1"), so Shay never hit him and SPARE was verified after 120 s.
  # Wait out the guard, put him next to Shay and attack until a hit lands (the engine needs real harm).
  stobe-say speed 1 >/dev/null; sleep 25
  hb=$(grep -a -c "" "$L")
  for i in $(seq 1 12); do
    stobe-auto teleport "$r" Shay dist 2 >/dev/null; stobe-auto attack Shay "$r" >> "$LOG" 2>&1
    sleep 4
    tail -n +"$hb" "$L" | grep -a -E "\[EVENT\] (major_damage|knockout|death): $NAME .*Shay|\[EVENT\] knockout: $NAME" | head -1 | grep -q . && break
  done
  log "Shay attacks $NAME after acceptance: $(tail -n +"$hb" "$L" | grep -a -E "\[EVENT\] (combat|major_damage|knockout): .*$NAME|PERSONAL_TRUCE" | head -4 | cut -c1-160 | tr '
' ';')"
elif [ "$mode" = breach ]; then
  finish "FAIL deal already COMPLETE before the attack"
fi
[ "$mode" = kept ] && stobe-say speed 2 >/dev/null
nudged=""
for i in $(seq 1 100); do # final status, max ~300 s
  st=$(dealline | grep -o -E 'COMPLETE|BREACHED_PLAYER|BREACHED_NPC|IMPOSSIBLE|CANCELLED|EXPIRED' | head -1)
  [ -n "$st" ] && break
  # m16: the payment ran 3.3 s before the server's dispatch time (accept reply streams GIVE_CATS at once; the
  # verifier looks from dispatched_unix-3), so it was never verified and a reissue waited for him to speak again
  # (engine bug, reported). Bug 126 sends the reissue when he next speaks: make him speak once.
  if [ -z "$nudged" ] && dealblock | grep -E "GIVE_CATS|GIVE_ITEM" | grep -q REISSUE_QUEUED; then
    nudged=1; log "payment REISSUE_QUEUED: Shay asks for it (reissue path)"
    stobe-say say "$NAME" "$NAME, the cats. Now." --wait 20 >> "$LOG" 2>&1
  fi
  stobe-auto where Shay | grep -q " KO" && { log "Shay KO"; break; }
  sleep 3
done
log "final: ${st:-none}"
stobe-auto speed 0 >/dev/null
stobe-auto teleport "$r" Shay dist 300 >/dev/null 2>&1
sleep 20
contract="${deal#deal-}"  # the log line has the contract id (with or without the deal- prefix)
want="kept_coercive_deal"; [ "$mode" = breach ] && want="broken_promise betrayal"
miss=""
for c in $want; do
  grep -a "SOCIAL_INTERPRET" $S/log/relationship_worker.log | grep -F "\"kind\":\"agreement\"" | grep -F "$contract" | grep -q -F "\"component\":\"$c\"" || miss="$miss $c"
done
exp=COMPLETE; [ "$mode" = breach ] && exp=BREACHED_PLAYER
dealblock > "$O/$tag.dealterms.txt"
if [ "$st" = "$exp" ] && [ -z "$miss" ]; then finish "PASS final=$st components=$want${nudged:+ (payment needed the reissue)}"; fi
finish "FAIL final=${st:-none} (want $exp) missing:${miss:- none}"
