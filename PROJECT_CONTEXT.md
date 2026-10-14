# Inventory Manager — Project Context

Last updated: 2026-10-12

This is a handoff summary of the application as it exists in the current workspace. It intentionally contains no credentials, secret values, or copied environment configuration.

## Architecture

- **Runtime:** PHP 8.3 with Apache, MariaDB 11.8, and Docker Compose. The app and database run in separate services; Compose also runs an order-reminder worker. MariaDB data is persisted in a named volume. `/health.php` is the app health endpoint.
- **Application:** Server-rendered PHP pages under `app/`. Shared configuration, authentication/authorization, header/footer, and inventory helpers support the page handlers. Styling is primarily in the shared `app/style.css`.
- **Database:** `database/schema.sql` initializes a new database. Existing volumes do not automatically receive schema changes. SQL migrations are maintained under `database/migrations/` and are currently applied manually; there is no migration runner or migration ledger.
- **Stock model:** `inventory_transactions` records stock movements and is the source for current stock. Monthly snapshots support reporting. Assignment records retain employee identity snapshots; newer employee-directory relationships add stable employee record IDs.
- **Access control:** Admin, Editor, and Viewer roles are enforced in server-side handlers. Forms use CSRF protection. Audit Logs are admin-only and read-only. Preserve these checks when changing UI or workflows.
- **Supporting services:** PhpSpreadsheet supports Excel history exports. The reminder worker checks pending orders periodically; mail delivery depends on runtime configuration. A Jenkinsfile and a small test suite are present in the workspace.

## Functionality and changes present

- Dashboard summary cards, inventory/products, categories and subcategories, employee directory and employee details, hardware/software assignments, orders, history/export, users, and audit logs.
- Stock assignment flows check available stock and record stock-out and audit information. Order workflows support confirmation, rejection, and receipt states; rejection does not itself move stock.
- Dashboard statistic numbers are white (`#FFFFFF`) while the pink theme elements remain pink.
- Shared styling was extended for consistent action controls, forms, tables, cards, responsive layouts, keyboard focus, and reduced motion. Category, product, employee, and user action controls use shared grouping/status styles. Navigation collapses on narrow screens; wide tables can scroll within their containers.
- Order rejection UI and database support were added, including rejection details and audit handling. See the migration list below for the schema changes needed on existing databases.
- Docker/PHP configuration, Composer dependency files, health endpoint, employee/history/order pages, and supporting helpers are present in the current workspace.

## Database migrations

The repository currently contains these migrations:

1. `20261006_add_ordered_inventory_transactions.sql` — allows ordered inventory transaction types.
2. `20261008_add_employees_directory.sql` — creates the employee directory.
3. `20261008_add_order_details_to_inventory_transactions.sql` — adds order-related transaction fields and relationships.
4. `20261009_add_stock_transaction_prices.sql` — adds transaction price/vendor fields for schemas that need them.
5. `20261012_add_employee_asset_software_management.sql` — extends employee and assignment data and creates hardware assignment history, software installations, and software history.
6. `20261012_add_order_confirmation_and_reminders.sql` — adds order confirmation fields and reminder-attempt storage.
7. `20261012_add_rejected_order_status.sql` — adds rejection fields and supports the Rejected order status.
8. `20261012_fix_order_confirmation_columns.sql` — compatibility repair for order confirmation fields/status on existing databases.

For an existing database, inspect its current schema and migration state before applying anything. Some migrations overlap in fields they add, and migration files are not all uniformly idempotent. Back up first, apply only missing changes in a reviewed order, then verify the affected tables and app workflows. Do not assume editing `schema.sql` updates an already-initialized Docker volume.

## Known limitations and risks

- Current stock is derived using transaction timestamps. Backdated or future-dated movements can therefore affect which transaction is treated as latest; retain chronological transaction data and review any stock-model change carefully.
- History pages limit displayed rows, but Excel export is uncapped and keeps worksheet data in memory. Large exports may consume substantial memory.
- There is no migration ledger/automated migration runner, so deployed schema state can drift from the checked-in schema and migration files.
- The test suite is small and focuses on helper-level behavior (stock arithmetic/negative-stock protection, role helpers, CSRF, and flash behavior). It does not provide broad MariaDB integration, concurrency, or browser regression coverage.
- The current Git worktree contains many modified and untracked files, including application work, migrations, tests, and ZIP/archive artifacts. Confirm which files are intended source artifacts and review/commit a coherent set before release; do not remove archives or other untracked files without checking their purpose.
- Prior responsive verification exercised rendered page templates at 375, 430, 768, 1024, 1366, and 1920 px. It found no page-level horizontal overflow or tested action-control mismatch after CSS adjustments. Browser-level checks did not submit mutating forms, so business actions still need a safe end-to-end check.

## Recommended next steps

1. Review the worktree and establish the intended source-of-truth/commit boundary, especially for untracked archives and generated artifacts.
2. Compare each deployed database against `schema.sql` and the eight migrations; document which migrations have run per environment. Add a migration ledger/runner only with an explicit, reviewed deployment plan.
3. Add repeatable MariaDB integration coverage for order confirm/reject/receive, assignment stock checks, audit entries, and migration behavior. Add browser regression coverage for navigation, forms, tables, and key actions.
4. Safely test all role-specific workflows and action forms against a disposable database, including rejection/receipt and employee hardware/software history.
5. Address the uncapped history export with pagination/streaming or a documented export limit, and define how transaction dates should affect current-stock calculations.
6. Keep the shared design system as the default for future UI work; add page-specific CSS only when a verified layout needs it. Re-run viewport checks when shared styles change.
7. Review Composer dependencies, Docker/Jenkins build behavior, and deployment configuration as part of release preparation. Provide secret values only through deployment/runtime secret management, never in this document or source control.

## Verification snapshot

The last UI audit rebuilt and recreated the app container successfully; `/health.php` returned `{"status":"ok"}`. PHP lint and `git diff --check` passed for the files checked during that work. The responsive pass covered 66 page/viewport combinations across the listed widths. Mutating workflows were not exercised against live application data.
