# Production package validation report

Report scope: checked-out repository snapshot used to build this package. Date: 2026-10-19. This is not a live-server deployment report.

## Completed checks

- `php -l` on all PHP files under `app/`: passed using PHP 8.4.24 CLI (PHP 8.3 target-specific runtime not installed here).
- `php tests/run.php`: passed, 702 checks. Coverage includes stock calculations, receipt workflows/RBAC/idempotency, credential encryption compatibility helpers and permission controls, audit behavior, inventory cost allocation/valuation, employee hardware event valuation, history detail rendering, and notification helpers.
- SQL source review: current `database/schema.sql` includes the application tables for inventory, users/RBAC, credentials and TOTP, orders/outbox, assets/assignment history, audit, and cost reconciliation. The archive's `fresh-install.sql` is generated from this complete schema.
- Sensitive packaging exclusions: the packaging allowlist excludes `.env`, Git metadata, existing ZIPs, test artifacts, logs, backups, and temporary files.

## Not run / blocked in this environment

- Fresh SQL import and migration replay against disposable DBs: local MariaDB socket is unavailable and Docker daemon access returned permission denied. No schema-level pass is claimed.
- Database-backed integration tests (credential TOTP route, exports, outbox, cost valuation, receipt/autofill, assignment/return, history/audit): blocked by unavailable disposable DB/server.
- HTTP authorization/browser checks: not run; no Apache/web server or browser test environment is available here.
- Composer dependency install: Composer is not installed in the workspace and network access is restricted. The locked manifests are included; `vendor/` must be installed on the target.
- Apache vhost config validation: `apache2ctl` is not installed. The documented directives require validation against the actual host before reload.
- PHP 8.3 runtime check: only PHP 8.4.24 CLI is available.

## Release gate

Because disposable SQL, migration, database-backed security/workflow, browser, PHP 8.3, Composer, and Apache checks were not completed, this package is **not certified production-ready**. Run those checks on an isolated staging host and record their outputs before production cutover. No production data/server was changed.
