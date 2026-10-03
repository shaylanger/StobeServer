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
Liberator (probe 20), sleeping witness (SR25), theft caught
(probe 17), recruitment gate exercised by the LLM, repeat assault with a non-hostile victim, a longer frame-time
A/B window (Capture 0 vs 1, 10 min), enabled-mode balance review (Shay).

## Final report (draft, 2026-10-03, after run m13)

| Row | Class | State | Open |
|---|---|---|---|
| SR01 | offline only / by construction | offline-pass |  |
| SR02 | pass in game | game-pass (m4, m5, m8, m13: p2-01 Rel Vorn->Shay aggression -15 + KO -21) |  |
| SR03 | pass in game | game-pass (m4: no row for the retaliating side in p2-02/p2-03) |  |
| SR04 | pass in game | game-pass (m4: Shay joining to defend Malzin not charged; many defending_ally encounters in Hub fights) | ally = same faction or both player faction |
| SR05 | pass in game | game-pass (m4, m8, m11: Malzin's KO of Rel Vorn charged as a joined assault, -40) |  |
| SR06 | partial / rerun pending | partial: offline-pass; game m8: second encounter started by the now-hostile bandit (retaliation, correctly no new row); a clean repeat needs a non-hostile victim | consent/duel and accident signals do not exist |
| SR07 | blocked / open | offline-pass; game blocked | needs a real limb loss caused in a fight (harness damage/kill have no attacker); probabilistic in game |
| SR08 | partial / rerun pending | partial: game-pass for the KO part (m13 p3-01: KO by Shay with the inventory baseline, Rel Tam -> Shay -38, waking resolved); loot: probe 6 answered (LOOT_TARGET on a KO'd NPC moves nothing), rerun with a harness transfer |  |
| SR09 | blocked / open | offline-pass (evidence adapter); game blocked | no in-game source of better evidence until witnesses/sensing (phase 6) |
| SR10 | pass in game | game-pass (m8 p3-02: KO without attacker, transfer latent, waking = no_known_culprit, nobody blamed) |  |
| SR11 | pass in game | game-pass (m13 p3-03: Rel Vash -> Rel Grell enslavement -86 on waking although his KO was not seen; owner = shackle owner, probe 7) |  |
| SR12 | offline only / by construction | offline-pass; game as SR11 (m13 pass) |  |
| SR13 | partial / rerun pending | offline-pass; needs-game: native now reports caught from the owner's senses (probe 17) | probe 17: owner SensoryData canISeeThisGuy(taker) at the transfer = caught; no steal action driver in the harness |
| SR14 | blocked / open | offline-pass (petty/ordinary/near-total/starving-food severity, returned property once); game blocked | same blocker as SR13; item value not used (counts/share only) |
| SR15 | pass in game | game-pass (m5 meaningful +11, m8 lifesaving +29 Rel Ona -> Malzin while unconscious) |  |
| SR16 | partial / rerun pending | offline-pass; game: second treatment in the same episode not yet observed (p4-01 rerun on auto-home) |  |
| SR17 | offline only / by construction | offline-pass (own harm / ally harm then healing earns nothing) | staged self-harm by the patient: harness damage has no attacker, so it is unattributed; real self-harm path not testable |
| SR18 | pass in game | game-pass (m11 +9, m13 +8: Rel Cobb -> Malzin safe_rescue after LIFT_PERSON + PUT_SOMEONE_IN_BED); m13: the placed fact had no actor (bed flag seen 5 ms before the drop): native fallback to the current carrier | carry by the player actor (Shay) is not captured |
| SR19 | pass in game | game-pass (m11 -31, m13 -33: Rel Kade -> Malzin imprisonment after LIFT_PERSON + PUT_IN_CAGE) | ground drop / unknown placer: offline only |
| SR20 | partial / rerun pending | partial: offline-pass (squad carry/caging/inventory/equipment exempt, critical rescue positive) | in-game squad looting of a conscious mate not scripted |
| SR21 | partial / rerun pending | partial: m13 the food handed to Rel Hask is captured (food_items, recipient_hunger); he did not eat at 1.50 within 8 s, rerun makes him eat at 1.20; full recipient not_hungry (m8, m13 Rel Fenn) |  |
| SR22 | pass in game | game-pass (m8 fair trade, m11 +3 / m13 +2 gift Rel Gav -> Rel Dona) |  |
| SR23 | offline only / by construction | offline-pass; game: character seller resolved (m8); shop storage / purse not seen in game |  |
| SR24 | pass in game | game-pass (m8, m9, m13: Rel Wren -6/-7/-8 of the victim's share) |  |
| SR25 | blocked / open | open: m13 Rel Sorn -9 again; her witness entry said task 290, prone 0 (5 s after the floor-sleep order she was not asleep by our check); native now also reads StateBroadcastData::isSleeping (logged as "sleeping"); scenario waits 15 s and checks the entry | m13 rerun with the m13 native patch |
| SR26 | offline only / by construction | offline-pass (single hop, pre-event affinity, once per witness/incident) |  |
| SR27 | partial / rerun pending | partial: offline-pass (dialogue band -8..+3, no re-scoring of a mechanical outcome); insult witnesses not implemented | insult heard by a friend: needs dialogue listener data as witnesses |
| SR28 | partial / rerun pending | unchanged by REL (surrender trigger has no affinity term; REL does not touch negotiation); needs-game for the -85 case | needs a scenario with a stored -85 affinity + low health (scenarios.sh surrender); no REL code involved |
| SR29 | partial / rerun pending | partial: offline-pass (kept promise, honored coercive deal minimal, betrayal after accepted surrender, legacy delta replaced only when enabled); needs-game | 'dishonest hated enemy can betray' is the negotiation engine's existing rule (unchanged) |
| SR30 | partial / rerun pending | game inconclusive (m8 p7-01: no JoinParty attempted by the NPC, not recruited) | LLM-dependent |
| SR31 | offline only / by construction | offline-pass (chat + director configs, dispatch normalizer; autonomy never offers JoinParty; override) | native ACT_JOIN_PARTY fallback paths not audited in game |
| SR32 | partial / rerun pending | partial: m13 Rel Xan again "first seen already enslaved": Stobe's world event sweep starts 45 s (real time) after a load and he was shackled ~25 s after; scenario now waits 50 s; liberator (probe 20) still unseen | probe 20 liberator |
| SR33 | offline only / by construction | offline-pass by construction (REL gate only on STOBE's JoinParty path; vanilla recruitment untouched) | vanilla recruit in game not run |
| SR34 | partial / rerun pending | partial: game-pass for named NPC / Shay / Malzin binding (m4); offline-pass generic name + serial reuse | rename alias and same-name NPCs need game data |
| SR35 | partial / rerun pending | partial: offline-pass (retry dedup, duplicate KO/limb once, second wake once) | out-of-order delivery beyond the late-event rule not tested |
| SR36 | offline only / by construction | offline-pass |  |
| SR37 | partial / rerun pending | partial: offline-pass (restart mid-KO, KO carried across reload) | crash between writes: atomicity covered by the integration test only; carry/escape: later phases |
| SR38 | pass in game | game-pass (m9 p3-04 39/0: KO, loot latent, reload; plus p1-04 stale checks) |  |
| SR39 | partial / rerun pending | partial: offline-pass (A/B/A switch, old snapshot upgrade, fresh) | export/import not tested |
| SR40 | partial / rerun pending | partial: offline-pass + game-pass stale check (m4) | NEVER_CLEAR_RELATIONSHIP_DATA=true not tested |
| SR41 | pass in game | game-pass (m4: p1-01 Capture=0 inert, p1-02 server off stores nothing, every shadow run check-shadow pass; p2-05 enabled applied) | R4 exclusion in enabled mode: --relation check not run in m4 |
| SR42 | partial / rerun pending | partial: offline-pass (beliefs/notes name only the inferred culprit; witnesses learn only what they saw) | prompt-side belief injection not implemented (notes only) |
| SR43 | partial / rerun pending | partial: m13 soaks A (Capture=0) and B (Capture=1, shadow) 30/0, no queue overflow, check-shadow 0; fps window 157 s: avg 54.5 vs 54.8 (99.5%), worst frame 348.5 vs 323.5 ms (107.7%); memory inconclusive (both fell during the run; B fell 137 MB less); a longer measured window is requested | longer A/B window (10 min at speed 1, fps reset after warm-up, A-B-A-B) |
| SR44 | partial / rerun pending | partial: offline runner resumable steps + manifest; game runner resume is the coordinator's harness | phase 8 game runner not REL-owned |

Totals: blocked / open: 4, offline only / by construction: 8, partial / rerun pending: 19, pass in game: 13

Frame-time gate (m13, 157 s windows): fps 99.5% (gate >= 95%) and worst frame 107.7% (gate <= 110%) pass;
memory is inconclusive (both runs fell, B 137 MB less; start already 111 MB apart). Final sign-off needs a longer window:
10 min at speed 1 after a 60 s warm-up, `fps` reset after the warm-up, runs A-B-A-B to see the noise.

Release recommendation: keep `shadow` for normal play until the longer frame-time window and the open rows close;
`enabled` is ready for a supervised balance session on a fixture copy (combat, KO/theft inference, aid/rescue/cage,
gifts/trade, witnesses, deals, slavery on waking). Sleeping witnesses (SR25) and escape/liberator (SR32) are still open:
switch `SOCIAL_CATEGORY_WITNESS=false` (and keep SR32 in mind for slavery) if they are not closed before real enabled play.
