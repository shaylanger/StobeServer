# StobeServer

For AI assistants and coding agents, start with [AGENTS.md](AGENTS.md), then the [architecture and custom-extension guide](docs/agent-guide.md) and [setup and validation guide](docs/building.md). These files ship with the server; preserve their relative paths in application packages.

PHP backend server for the Stobe AI Framework for Kenshi. 
It is the Kenshi-side counterpart to HerikaServer patterns (event routing, prompt building, connector model), with Stobe-specific schema and game logic.

## Runtime Topology

1. Kenshi + `Stobe.dll` sends HTTP events to StobeServer.
2. StobeServer routes events through `main.php` and `processor/*`.
3. Prompt/context is built from DB state and recent event history.
4. LLM connectors generate responses.
5. Responses are streamed back to the plugin and optional TTS is produced/cached.

## Background Processor

StobeServer now includes a Herika-style background processor:

- `service/start.sh`: daemon loop with heartbeat listener (`127.0.0.1:12346`).
- `service/manager.php`: periodic cycle tick for non-interactive workloads.
- UI auto-start/status hooks in `ui/index.php` and `ui/home.php`.

The processor is intended for periodic maintenance workloads that should not
add latency to foreground chat responses:

- Middle-term memory summarization (`stobeMaybeRunMiddleTermCycle`).
- Regular memory packing/summarization (`stobeMaybeRunRegularMemoryCycle`).
- Dynamic profile refresh (`stobeMaybeRunDynamicProfileCycle`).

## Key Paths

- `main.php`, `stream.php`, `chat.php`, `gamedata.php`, `stt.php`: main HTTP entry points.
- `lib/bootstrap.php`: canonical runtime bootstrap and shared includes.
- `lib/settings.php`: `general_settings` read/write helpers.
- `lib/chat_helper_functions.php`: prompt assembly, world knowledge retrieval, response shaping.
- `processor/`: event processors (`chat.php`, `rechat.php`, `bored.php`, `combat.php`, `context.php`, `location.php`, etc).
- `connector/`: LLM connectors.
- `tts/`, `stt/`: speech connectors.
- `ui/`: dashboard and admin pages.
- `data/schema.sql`: schema + default settings.
- `log/`: runtime logs.
- `soundcache/`: TTS audio cache.

## Settings Model

StobeServer is DB-config driven:

- Runtime settings are stored in `general_settings`.
- There is no runtime `conf.php`/INI settings model for server behavior.
- Global Settings UI reads/writes those DB keys.



## Requirements

- PHP 8.2+ with Apache
- PostgreSQL with pgvector
- DwemerDistro/WSL runtime (recommended deployment target)

## Database Setup

Create or update a local WSL database with:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\create-stobe-db-wsl.ps1
```

StobeServer requires a UTF-8 PostgreSQL database because NPC profiles, event
history, prompts, memories, and structured JSON can contain Unicode. Older
builds could inherit `SQL_ASCII` from the PostgreSQL template. Normal
DwemerDistro updates now detect and migrate those databases before applying
schema updates. The server returns a database-upgrade maintenance response
instead of continuing against an incompatible or incomplete schema.

To run or retry the complete repair manually:

```bash
sudo php /var/www/html/StobeServer/debug/run_db_updates.php
```

The updater invokes the UTF-8 migration when required, creates a safety dump
and a disconnected rollback database, restores all Stobe application schemas
into UTF-8, verifies every table row count and sequence position, and then
replays pending database updates.

## PR Submissions

Building AI systems is complex, and changes can unintentionally affect other connected systems. Before opening a pull request, follow the repository PR template and make sure the change has been discussed with either `RANGROO` or `tyler.maister` in Discord. When adding new features, prefer making them optional or toggleable where practical.

### Biography voice filters

Biography imports and exports accept an optional `tts_filter_preset` column. Choose a trusted preset from the biography editor's Voice Filter list; custom audio filter expressions are not accepted. The preset seeds newly created NPCs only and does not overwrite existing NPC choices. Use `none` to disable filtering explicitly. Older imports without the column preserve saved presets.
Stobe uses presets from selected biography traits: unique traits take priority over random traits, then personality, backstory, speechstyle, occupation, appearance, and goals. Blank means no new preset; `none` explicitly prevents a lower-priority trait from supplying one.
