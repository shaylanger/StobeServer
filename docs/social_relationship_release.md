# STOBE stateful relationships (REL): release notes, install manifest, rollback recipe

Status 2026-10-03: phases 1-7 built, merged live in shadow mode; phase 8 offline parts done.
The authoritative row-by-row state lives in `tests/social_relationship/scenarios.json` (`rel_state`).

## What it does (one paragraph)
Native Stobe.dll reports objective facts (attacks, harm levels, knockouts with inventory, recoveries, item
transfers and unmatched gains, enslavement/freeing with owner and liberator, first-aid sessions with measured
vitals, carry/placement, meals, purchases, witnesses with the game's own sensing). The server turns them into
directed relationship effects through one locked writer: victim -> attacker only, retaliation free, one harm
budget per encounter, latent facts for the unconscious resolved on waking with inferred blame, outcome-based
aid/rescue/food credit with anti-farming budgets, theft only when caught, capped economics, deal outcomes,
witness echoes for friends who really saw it, a dialogue guard, a recruitment gate, slave escapes.

## Switches
| Setting | Where | Values | Default |
|---|---|---|---|
| Native capture | `RE_Kenshi\mods\Stobe\StobeCustom.ini` `[SocialRelationships] Capture` | 0/1, read at game start | 0 |
| Mode | `general_settings.SOCIAL_RELATIONSHIP_MODE` | off / shadow / enabled | off |
| Categories | `general_settings.SOCIAL_CATEGORY_{COMBAT,AID,CARRY,SLAVERY,PROPERTY,ECONOMY,AGREEMENTS,DIALOGUE,WITNESS,RECRUITMENT}` | `false` turns one off | on |
| Recruitment override | `general_settings.SOCIAL_RECRUITMENT_OVERRIDE` | true = gate lifted | unset |
| R4 (item 55) | `RELATIONSHIP_FIGHTS_COUNT` | used while REL is not enabled | unchanged |
| Rules | `data/social_relationship_rules.json` (`version` phase8-v1) | ranges, thresholds, retention | - |
Tool: `php tools/social_relationship_inspect.php --set-mode off|shadow|enabled` (prints the previous value).

## Install manifest (what a release consists of)
1. Server: branch `feature/social-phase1` merged into `stobe` (lib/social_*.php, social_event.php,
   data/social_relationship_*.{json,sql}, tools/social_relationship_inspect.php, small hooks in
   lib/chat_helper_functions.php, lib/negotiation_engine.php, lib/relationship_manager.php, playthrough policy 5).
2. DB: `php debug/run_db_updates.php` creates the six `social_*` tables (version 202610020001); playthrough
   API 9 reinstalls its SQL functions on first use.
3. Native: Stobe.dll from `/root/STOBE-src` with the REL patches (SocialEventProtocol.{h,cpp} in the build
   SOURCES list). Record the DLL SHA256 next to the server commit in the run log.
4. Settings: Capture=1 only when REL should run; mode `shadow` first, `enabled` after the in-game gates.
5. Verify: `php tools/social_relationship_inspect.php` -> `mode`, `session` (with Playthrough Saves off:
   `playthrough_saves_off`, campaign `legacy`), counts growing during play; `--check-shadow` exit 0 in shadow.

## Rollback recipe
- Instant, no data change: `--set-mode off` (server answers `disabled`, nothing is written; R4 and the legacy
  deal/dialogue paths come back). Then `Capture=0` at the next game start.
- Remove REL data: `--purge-all --yes` empties the six tables (nothing else reads them).
- Undo affinity written in enabled mode: relationships follow the loaded save (NEVER_CLEAR_RELATIONSHIP_DATA
  =false): load a save from before enabling, or restore the relationship history snapshot.
- Remove the code: revert the merge commit on `stobe` (the tables can stay; they are unmanaged by legacy code)
  and rebuild Stobe.dll from the source without the REL patches.

## Offline validation (phase 8)
`STOBE_DB_NAME=stobe_social_phase1_test python3 tests/run_social_phase1.py` (isolated tree): contract/unit/
property, integration, save/migration, concurrency, combat, unconscious, care, property/agreements, witness/
dialogue, recruitment/escape, scope (Playthrough Saves off), HTTP, inspect tool, legacy relationship/stance/
rollback, legacy negotiation (190), mutation proof (20 mutations, each must fail its suite), perf/soak bench,
native portable + cross-language contract.

Perf/soak bench (6000 facts, 4.2 game days, shadow): p50 5.9 ms, p95 12.2 ms, max 18 ms per fact; retention
keeps raw facts/checkpoints/finished incidents to a 3-game-day window, never the ledger, beliefs or waiting
(latent) incidents. In-game m9: up to ~4700 attack facts in one load during Hub raids.

## In-game gates still open (see RUN_ORDER.md and scenarios.json)
Carry to bed/cage (needs harness `build`), slavery capture (probe 21), liberator (probe 20), theft caught
(probe 17), recruitment gate exercised by the LLM, repeat assault with a non-hostile victim, soak and frame-time
A/B (Capture 0 vs 1), enabled-mode balance review (Shay).
