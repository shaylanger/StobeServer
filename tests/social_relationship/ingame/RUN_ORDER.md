# REL in-game scenarios: run order

Owner: REL builder. Runner: the coordinator (`stobe-auto run <file> --csv out.csv`).
Server checks run in the **live tree** (`cd /var/www/html/StobeServer`) after the merge:
`php tools/social_relationship_inspect.php ...` (read-only unless `--set-mode`/`--purge-all`).
`<SHAY>`/`<BANDIT>` = the serials the scenario printed in its `@set` lines (with or without `#`).

## Switches (how to set and restore)

| Switch | Where | Set | Restore |
|---|---|---|---|
| Native capture | `D:\Steam\steamapps\common\Kenshi\RE_Kenshi\mods\Stobe\StobeCustom.ini`, section `[SocialRelationships]`, key `Capture` | add `[SocialRelationships]` + `Capture=1` **before launch** (read once at game start; stobe.log then shows `SOCIAL_CAPTURE: enabled`) | `Capture=0` or delete the section; relaunch |
| Server mode | DB table `general_settings`, id `SOCIAL_RELATIONSHIP_MODE` (`off` default/unset, `shadow`, `enabled`) | `php tools/social_relationship_inspect.php --set-mode shadow` (prints the previous value) | `php tools/social_relationship_inspect.php --set-mode off` |
| Category flags (later phases) | `general_settings` ids `SOCIAL_CATEGORY_COMBAT`, `_AID`, `_CARRY`, `_SLAVERY`, `_PROPERTY`, `_ECONOMY`, `_AGREEMENTS`, `_DIALOGUE` | unset = on; `false` turns one off | delete the row |
| R4 (item 55) | `RELATIONSHIP_FIGHTS_COUNT` | unchanged; R4 runs in `off`/`shadow`, REL replaces it only in `enabled` | - |

The server mode is global (general_settings is configuration, not saved per playthrough) and is read
per request: no restart. **Always end a REL batch with `--set-mode off`** and Capture=0.

## Playthrough Saves off (live setup, run m4)

Without Playthrough Saves (`stobe_meta.settings` `PLAYTHROUGH_AUTO_SWITCH` not true) the game gets no campaign id.
Since the m4 fix the game then sends campaign `legacy`; the server accepts `legacy` only while switching is
off, takes the load id/client from the event and refuses an older load after a newer one (stale).
inspect shows `"session": {"status": "playthrough_saves_off", "campaign_id": "legacy", "load_id": <newest load>}`
and all checks (`--expect-pair`, `--pair-effects`, ...) use that newest load. stobe.log shows once:
`SOCIAL_CAPTURE: no playthrough campaign id (Playthrough Saves off): using campaign 'legacy'`.

## Keeping test data out of real data

- Shadow mode never writes affinity (`social_effect.applied` stays false; checked by `--check-shadow`).
- Social rows only exist in the six `social_*` tables; with the feature off nothing else reads them.
- Rows carry the loaded save's campaign id and load id; loading an older save (fixture reload) prunes
  later rows through the playthrough rollback, like relationships.
- After the batch: `php tools/social_relationship_inspect.php --purge-all --yes` empties the six tables
  (they hold only test data while REL is not enabled for normal play). Run it after `--set-mode off`.
- Enabled mode (phase 2+) does change affinity: run it only on a `kah-*` fixture copy; relationships
  follow the loaded save, so reloading the fixture (or Shay loading her own save later) rolls the
  relationship maps back to that save's game time (NEVER_CLEAR_RELATIONSHIP_DATA=false on live).

## Chained files (run with "keep", no fixture reload before them)
- `REL-p2-04-repeat-assault.txt` right after `REL-p2-01-player-first-strike.txt` (uses its bandit).
- `REL-p1-04-reload-stale.txt` after `REL-p1-03-shadow-capture.txt` (it reloads by itself).
Every other file starts from a fresh fixture load. Take the inspect before the next reload: a reload prunes later social rows.

## Phase 1 smoke gate (one launch with Capture=0, then one launch with Capture=1)

| Order | File | Launch | Server mode before | Check after |
|---|---|---|---|---|
| 1 | `REL-p1-01-capture-off.txt` | any normal batch launch (Capture=0) | off | `grep -c SOCIAL_CAPTURE stobe.log` = 0; inspect: mode off, all counts 0 |
| 2 | `REL-p1-02-capture-on-server-off.txt` | relaunch with Capture=1 | off | inspect: all counts 0 |
| 3 | `REL-p1-03-shadow-capture.txt` | same launch | `--set-mode shadow` | `inspect --events 40 --check-shadow --expect-pair <SHAY> <BANDIT> combat` exit 0 |
| 4 | `REL-p1-04-reload-stale.txt` | same launch (keep) | shadow | `inspect --events 20 --check-stale --check-shadow --expect-pair <SHAY2> <BANDIT2> combat` exit 0; load_id higher than in 3 |
| end | - | - | `--set-mode off`, `--purge-all --yes`; Capture=0 | - |

## Phase 2 combat (Delivery 2 build; one launch with Capture=1)

Named NPCs are needed: generic template names ("Hungry Bandit") are never written into relationship
maps. Each scenario renames its bandit (`setname`) and talks to it once (`stobe_say`) so the server has
a profile; `@set TNAME`/`ANAME`/`BNAME` print the final names for the checks.

| Order | File | Server mode before | Check after (live tree) |
|---|---|---|---|
| 1 | `REL-p2-01-player-first-strike.txt` (fresh) | `--set-mode shadow` | `--pair-effects --check-shadow --expect-effect "<TNAME>" "Shay" -40 -8 --expect-none "Shay" "<TNAME>" --expect-none "Malzin" "<TNAME>"` |
| 2 | `REL-p2-04-repeat-assault.txt` (keep) | shadow | `--pair-effects --effects 20 --check-shadow`: two aggression rows for `<TNAME>` -> Shay, the second in [-18,-9]; report both `time` lines |
| 3 | `REL-p2-02-npc-attacks-squad.txt` (fresh) | shadow | `--pair-effects --check-shadow --expect-effect "Malzin" "<TNAME>" -40 -8 --expect-none "<TNAME>" "Malzin" --expect-none "<TNAME>" "Shay"` |
| 4 | `REL-p2-03-npc-vs-npc.txt` (fresh) | shadow | `--pair-effects --check-shadow --expect-effect "<BNAME>" "<ANAME>" -40 -8 --expect-none "<ANAME>" "<BNAME>"` |
| 5 | `REL-p2-05-enabled-affinity.txt` (fresh, kah-* copy) | `--set-mode enabled` | `--relation "<TNAME>" "Malzin"` aff in [-40,-8]; `--relation "Malzin" "<TNAME>"` none/0 (R4 off); then `--set-mode off` and reload the fixture |
| end | - | `--set-mode off`, `--purge-all --yes`, Capture=0 | - |

## Phase 3 unconscious perception (Delivery 3 build; one launch with Capture=1, mode shadow)

| Order | File | Check after (live tree) |
|---|---|---|
| 1 | `REL-p3-01-ko-loot-inferred.txt` (fresh) | `--pair-effects --beliefs 20 --incidents 10 --check-shadow --expect-effect "<TNAME>" "Shay" -85 -30`; `<TNAME>` -> Malzin has no theft-class component |
| 2 | `REL-p3-02-unknown-ko.txt` (fresh) | `--pair-effects --incidents 10 --interpret-log 20 --check-shadow --expect-none "<TNAME>" "Malzin" --expect-none "<TNAME>" "Shay"` |
| 3 | `REL-p3-03-enslaved-while-ko.txt` (fresh) | `--pair-effects --incidents 10 --check-shadow --expect-effect "<TNAME>" "<SNAME>" -90 -56 --expect-effect "<TNAME>" "Shay" -40 -8` |
| 4 | `REL-p3-04-reload-mid-ko.txt` (fresh; saves `kah-rel-ko`) | `--incidents 10 --pair-effects --check-shadow --interpret-log 20 --expect-none "<TNAME>" "Malzin"`; no theft row for `<TNAME>`; delete the `kah-rel-ko` save afterwards |
| end | - | `--set-mode off`, `--purge-all --yes`, Capture=0 |

## Phase 4 aid, carry, food (Delivery 4 build; Capture=1, mode shadow)

| Order | File | Check after (live tree) |
|---|---|---|
| 1 | `REL-p4-01-first-aid.txt` (Crafting base, fresh) | `--pair-effects --effects 20 --check-shadow --expect-effect "<ONAME>" "Malzin" 1 35`; second treatment adds 0; copy the two `kind=aid` log lines (probe 10) |
| 2 | `REL-p4-02-carry-to-bed.txt` (Crafting base, fresh) | `--pair-effects --incidents 10 --check-shadow --expect-effect "<CNAME>" "Malzin" 8 35` |
| 3 | `REL-p4-03-carry-to-cage.txt` (Crafting base, fresh) | `--pair-effects --incidents 10 --interpret-log 30 --check-shadow --expect-effect "<KNAME>" "Malzin" -40 -20`; `<UNAME>` has no rows |
| 4 | `REL-p4-04-food.txt` (auto-home, fresh) | `--pair-effects --interpret-log 30 --check-shadow --expect-effect "<HNAME>" "Shay" 2 5 --expect-none "<FNAME>" "Shay"` |

Carry events come from the world poller, which skips the player actor (Shay); squad mates (Malzin) are covered.

## Phase 5 property, economy, agreements (Delivery 5 build; Capture=1, mode shadow)

| Order | File / procedure | Check after (live tree) |
|---|---|---|
| 1 | `REL-p5-01-trade.txt` (Trader, fresh) | `--interpret-log 20 --pair-effects --check-shadow`; report the trade status/ratio and the `kind=trade` log line (probe 14) |
| 2 | `REL-p5-02-gift.txt` (auto-home, fresh) | `--pair-effects --interpret-log 20 --check-shadow --expect-effect "<GNAME>" "Shay" 1 4` |
| 3 | Deal kept (auto-home, fresh): `tools/automation/scenarios.sh surrender`, accept with the full name (`stobe-say say "<NAME>" "<NAME>, deal."`), pay as the deal says, wait for COMPLETE (`negotiation_admin.php deals 3`) | `--interpret-log 20 --effects 20`: an `agreement` line with component `kept_coercive_deal`, result `shadow`; the legacy +4 still applied (shadow) |
| 4 | Deal broken by attacking after acceptance (fresh): as 3, then `stobe-auto attack Shay "<NAME>"` before paying | `--interpret-log 20`: `agreement` lines `broken_promise` and `betrayal` for that contract (BREACHED_PLAYER with player_broke_truce) |

Theft (SR13/SR14) has no in-game row: the engine "caught" signal is not proven, so native sends `caught: null`
and REL scores no theft (by design); see DELIVERY.md probe 16.

When a check fails, add `--interpret-log 40 --events 60` to the report: each structured event logs its
interpretation (`SOCIAL_INTERPRET` in `log/relationship_worker.log`) with statuses such as
`unresolved_identity`, `no_encounter`, `defence`, `pending_awareness`, `duplicate`.

Pass = every step PASS and every check exit 0. A failure becomes a REL bug row (owner REL); save
stobe.log and `inspect --events 100 --effects 100` output with it.
