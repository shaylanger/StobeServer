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

## Phase 1 smoke gate (one launch with Capture=0, then one launch with Capture=1)

| Order | File | Launch | Server mode before | Check after |
|---|---|---|---|---|
| 1 | `REL-p1-01-capture-off.txt` | any normal batch launch (Capture=0) | off | `grep -c SOCIAL_CAPTURE stobe.log` = 0; inspect: mode off, all counts 0 |
| 2 | `REL-p1-02-capture-on-server-off.txt` | relaunch with Capture=1 | off | inspect: all counts 0 |
| 3 | `REL-p1-03-shadow-capture.txt` | same launch | `--set-mode shadow` | `inspect --events 40 --check-shadow --expect-pair <SHAY> <BANDIT> combat` exit 0 |
| 4 | `REL-p1-04-reload-stale.txt` | same launch (keep) | shadow | `inspect --events 20 --check-stale --check-shadow --expect-pair <SHAY2> <BANDIT2> combat` exit 0; load_id higher than in 3 |
| end | - | - | `--set-mode off`, `--purge-all --yes`; Capture=0 | - |

Pass = every step PASS and every check exit 0. A failure becomes a REL bug row (owner REL); save
stobe.log and `inspect --events 100 --effects 100` output with it.
