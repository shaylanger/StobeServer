# StobeServer setup and validation

This is a PHP application; there is no native server binary to compile. Use PHP 8.2+, Apache, PostgreSQL with pgvector, and the extensions/services required by the selected speech/model connectors. The recommended deployed environment is DwemerDistro/WSL. See [README](../README.md) for the existing installation and UTF-8 database repair workflow.

## Work from the source root

The public checkout includes `tools/bootstrap-database.php`, `tools/create-stobe-db-wsl.ps1`, `debug/run_db_updates.php`, and `.github/workflows/pr-tests.yml`. Maintainer monorepo deployment scripts are separate tools, not commands bundled in this checkout.

For syntax validation without starting the application:

```bash
php -l main.php
php -l lib/bootstrap.php
```

Replace those paths with every PHP file you change. Lint does not establish database, provider or HTTP correctness.

## Disposable database checks

Use a separate UTF-8 database with pgvector and a role authorized to create the test schema. Set `STOBE_DB_HOST`, `STOBE_DB_NAME` (for example `stobe_test`), `STOBE_DB_USER`, and `STOBE_DB_PASSWORD` to that disposable instance in your process environment. Do not run tests against the live playthrough or enable `STOBE_ALLOW_LIVE_TEST_DB` to bypass the test guard.

After confirming those values point to disposable data, from the repository root:

```bash
php tools/bootstrap-database.php
php tests/chat_flow_regression.php
```

Bootstrap runs schema updates and writes database state. Individual regression tests may create, alter or delete data. Read the relevant test before running it. The authoritative full CI test list and PostgreSQL/PHP setup are in [.github/workflows/pr-tests.yml](../.github/workflows/pr-tests.yml); use its current requirements rather than a copied test list. Use the relevant existing regression for your change.

For schema or playthrough changes, also follow [AGENTS.md](../AGENTS.md): verify repeatable upgrades, preserved global data, rollback, and old-save behavior with disposable databases. For request changes, exercise actual HTTP paths and response contracts. For model/speech changes, report whether external services were actually called.

## Deployment and packaging

Only deploy when requested. Preserve live database data, environment configuration, extension directories, profiles, voices, logs and generated files. Update application files using the installation's established updater and its preservation rules; avoid a blanket mirror/delete. Ship `AGENTS.md`, `docs/agent-guide.md`, and this file together with the existing documentation.

Check the deployed revision, PHP syntax, endpoint behavior and required worker health after a requested deployment. A successful lint or source archive check is not proof of deployment, a working provider, or Kenshi gameplay.
