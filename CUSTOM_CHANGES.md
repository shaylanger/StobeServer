# Custom Changes vs Upstream STOBE

This fork is based on Dwemer-Dynamics/StobeServer.

This file is a high-level orientation for the custom Kenshi/STOBE work in this fork. It intentionally summarizes the major behavioral changes rather than duplicating the detailed Git history, tests, and implementation notes.

## Major custom systems and behavior

### Natural voice interaction and action-aware dialogue
- Expanded the voice interaction flow around normal conversation, action-request mode, and forced-command mode.
- Added stronger target selection/name routing and safeguards around acting on the intended NPC.
- Expanded structured actions so natural dialogue can result in real Kenshi actions instead of narrative-only claims.
- Added action-truth safeguards so an NPC does not treat a queued/failed physical action as already completed.

### Persistent autonomous goals and tasks
- Added persistent single-NPC work goals for finite production/gathering requests.
- Added broader task goals for looting, storing/fetching/delivering items, medical/battle cleanup, prisoners, stock maintenance, construction/repair, guarding, waiting, and patrol.
- Added goal lifecycle controls such as pause, resume, cancel, modify, and retarget.
- Added purchase approval/fallback logic so buying can be used as a last resort without silently spending the player's Cats.
- Added server-side goal state/prompt context so NPCs can discuss real progress/blockers rather than inventing completion.

### Negotiation and social contracts
- Added a persistent typed negotiation/deal system with structured terms, contract state, follow-up directives, and outcome handling.
- Expanded player-initiated combat/social bargaining, counters, payments, item terms, truces, and deal consequences.
- Added relationship/reputation consequences and support for intentional NPC betrayal only when represented as an actual deal outcome.
- Added multiple rounds of regression fixes around payment timing, counter/offer parsing, directives, combat windows, weapon/property trust, and deal state recovery.
- Negotiation remains an actively tested system; some edge cases and native action outcomes still require real in-game verification.

### Lifelike NPC continuity, memory, and relationships
- Expanded selective memory/context for injuries, rescues, betrayals, promises, participants, places, and other salient events.
- Added/expanded emotional carryover, relationship-aware context, persistent preferences/opinions, and lifelike continuity callbacks.
- Improved relationship handling so the player relationship is preserved and visible in the relationship editor.
- Added foundations for better playthrough-grounded history while keeping verified game facts separate from conversational claims.

### Property, trust, and equipment behavior
- Added stronger rules around ownership, trust, barter, temporary loans, and equipped gear.
- Added serial parsing fixes for live NPC identifiers such as hand_<serial>.
- Added safeguards that treat live equipment/world state as authoritative over old dialogue/action history.
- Added truthful handling for failed real-action queues and premature completion claims.

### LLM/provider and prompt behavior
- Added/changed provider routing and model-specific handling for the custom local/cloud setup.
- Added OpenRouter provider pinning and reasoning-token handling used by this playthrough.
- Reordered the chat prompt to keep stable content first and added prompt-cache keys for providers that support cache reuse.
- Added audit fields for cached tokens, cache key, and service tier.

### Latency and observability work
- Added request/stage timing throughout chat, STT, LLM streaming/TTFT, prompt construction, and PocketTTS.
- Added detailed pre-LLM stage instrumentation to identify local latency before provider inference.
- Reduced redundant MiniMe topic work after confirming only the primary extracted topic was used by retrieval.
- Avoided the roughly 800 ms trader-inventory polling path for non-traders.
- Added/expanded logging so latency, cache behavior, and action execution can be correlated across the stack.

## Important design principles

- Real game state is authoritative. Model text alone is never proof that an action happened.
- Physical actions should be verified where practical before being treated as completed history.
- Persistent tasks/goals are currently designed around the NPC receiving the request, not multi-NPC delegation.
- Buying is a fallback; automatic selling/financing is intentionally constrained.
- New lifelike/history systems should remain grounded in observed playthrough events and avoid NPC omniscience.

## Companion native code

A significant part of the custom behavior is implemented outside this PHP repo in the companion native projects (custom STOBE DLL and KenshiFP). Those sources and the project/Claude workspace are tracked in:

shaylanger/Kenshi-Stobe-Custom

See that repository's CUSTOM_CHANGES.md for the native/game-side summary.

## Status / caveats

This is an active personal fork, not a polished upstream release. Some systems are mature enough for regular play while others are still undergoing regression and live-save testing. In particular, negotiation edge cases, persistent task execution, and low-level native inventory/action behavior should be validated against current game state before assuming success.

For exact implementation history, use git log, git show, and file diffs. This document is only the architectural summary.
