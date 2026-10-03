# STOBE relationship system: isolated Phase 1 handoff

Phase 1 framework is implemented and passes the offline gates. The DLL is built privately and is **not installed**. No game session, live Apache deployment, active save, installed DLL, shared harness session, or other agent's source was changed. Gameplay interpretation and real game validation remain later work.

## Saved locations

- Original audit and full phased plan: `C:\KenshiModding\STOBE_relationship_system_audit_and_implementation_plan.md`.
- Private server: WSL distro `DwemerAI4Skyrim3`, `/root/stobe-work/social-phase1/server`.
- Private native workspace: `/root/stobe-work/social-phase1/native-workspace`.
- Both branches: `feature/social-phase1`. Source commits and incremental Git bundles are recorded under `C:\KenshiModding\isolated\relationships-phase1`.
- Private DLL: `C:\KenshiModding\isolated\relationships-phase1\build\out\Stobe.dll`.
- Disposable database: `stobe_social_phase1_test`. Never use the existing `stobe-tests` wrapper: it targets another agent's shared `stobe_test` database.
- Test results: `/root/stobe-work/social-phase1/test-results/manifest.json`, individual logs, and the copied Windows `test-results` folder.

## Implemented framework

Versioned bounded raw envelope, explicit unknown states, native serial/session identity, authenticated campaign/load/client binding, canonical deduplication hashes, setup separation, late-event diagnostics, durable incident/pending belief/effect/evidence/checkpoint tables, deterministic rule ranges, awareness/blame filtering, and a directed canonical affinity writer. Transactions lock a campaign and affected NPC rows; nested callers retain transaction ownership. Maps/history/ledger commit together. A stale-base merge preserves concurrent deltas.

Six social tables participate in playthrough capture, restoration, rollback, fresh playthroughs and old-save initialization. A new-policy snapshot missing required tables rejects. Per-incident checkpoints avoid full-scope copies on every event. Pending incident state is capped at 256 per campaign/load and cannot be overwritten by late interpretation. Verified aid remains recorded as evidence even when affinity is already 100.

`SOCIAL_RELATIONSHIP_MODE` is `off` by default; `shadow` captures and diagnoses without affinity changes; `enabled` allows **internal semantic adapters** to apply effects. The client cannot submit semantic effects. R4 is suppressed in enabled mode; legacy behavior remains when off/shadow. Category flags are `SOCIAL_CATEGORY_COMBAT`, `AID`, `CARRY`, `SLAVERY`, `PROPERTY`, `ECONOMY`, `AGREEMENTS`, `DIALOGUE` (all enabled by default within an active mode).

Native `[SocialRelationships] Capture=0` defaults off. Capture is read from `StobeCustom.ini`. Opting in submits recognized raw event kinds through the existing queue; old prose events continue. Actual native capture, authenticated headers and hook timing require the first in-game smoke gate.

## Explicit limits

Raw events do not automatically score relationships. Phase 1 has pure semantic/rule boundaries, not the combat, rescue, theft, witness, slavery, deal or recruitment gameplay interpreters. The native envelope deliberately has no invented conscious state or witnesses. Serial keys are session-local; persistent identity must be resolved from unique stored identity before scoring, and real telemetry bindings remain later work.

The rule engine's pure recruitment predicate is not yet enforced at game action dispatch paths. Surrender/escape/witness propagation are not implemented gameplay features. Pending facts are only accepted through an internal adapter. Full-system retention/performance, restart while carrying/KO, export/import, NEVER_CLEAR semantics and all actual Kenshi action outcomes are not fully validated by these tests.

The current isolated native baseline contains the standalone KAH bridge. The earlier audit described an integrated `stobe-auto` backend from older notes. Before testing, reconcile the actual installed harness and choose a compatible adapter; do not replace the other agent's harness/backend implicitly.

## Automated offline validation

Run in WSL:

```bash
cd /root/stobe-work/social-phase1/server
STOBE_DB_NAME=stobe_social_phase1_test python3 tests/run_social_phase1.py
```

The runner refuses another source path or database, writes an atomic manifest after each step, records failures, and resets social mode off afterward. It reruns these small stateful suites from fixtures rather than reusing previous database-dependent passes. It never installs or controls the game.

Covered gates: contract/rule unit and 600 property assertions; raw capture/replay and history-write failure rollback; save A/B/A, old/new schema and fresh activation; actual concurrent PHP writers, nested transactions and pending cap; loopback HTTP receipt/scope/malformed/oversized requests; legacy parser/stance/rollback regressions; native portable rebuild/CTest; native-to-PHP JSON contract.

Full scenario definitions are in `tests/social_relationship/scenarios.json`; all 44 are explicitly BLOCKED for full-system acceptance. Partial Phase 1 evidence is mapped without claiming gameplay passes. `validate_scenarios.py` checks completeness and blocks incomplete scenarios. These definitions need scenario-specific fixtures, real actions and independent expected-state assertions as later interpreters land. They are not a working game executor yet.

Rebuild the private DLL without installing:

```bash
python3 /root/stobe-work/social-phase1/server/tools/automation/build_social_private.py
```

This copies only the isolated native source into the private Windows build folder, runs its build script, and emits hashes. Existing SDK/Boost/compiler inputs are read from `C:\StobeBuild` and `C:\StobeBuildTools`; outputs remain private. The build itself does not require the game to close because it does not touch installed DLLs.

## Next gate: first-phase in-game test

Coordinate an exclusive harness/game window with the other Kenshi agent. Freeze current installed/server/source hashes and preserve backups. Prepare a fixture save paired to a disposable server playthrough/database. Reconcile the installed harness baseline and bind test configuration to the staged endpoint. Only then install the chosen isolated DLL/server revision for the test window.

Smoke-test capture off, then shadow: confirm ready session receipt, raw native events, correct campaign/load/client identity, serial/name fields, queue retries and diagnostic persistence. Reload the paired fixture, confirm stale events reject, and check legacy dialogue/events continue. Run disabled compatibility checks with the old client. A shadow event must not alter affinity or produce authoritative evidence.

Archive logs and exact revisions, restore the paired fixture/configuration and installed artifacts, and release the harness lease. Native sensing/identity probes then unblock Phase 2 combat work. No in-game smoke test has been run or authorized as part of this isolated build.
