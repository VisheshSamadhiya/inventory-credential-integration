# Inventory Manager

A server-rendered inventory application built with PHP 8.3, Apache, MariaDB 11.8, and Docker Compose. It manages products, categories, quantity-based stock, employees, assignments, stock history, and administrator-visible audit logs.

## Architecture

- `app/` contains the PHP page handlers, shared authentication/configuration, inventory helpers, and CSS.
- MariaDB stores users, products, categories, employees, assignments, stock transactions, monthly snapshots, assets, and audit entries.
- `inventory_transactions` is the source used to calculate current stock. Assignment rows retain employee name, ID, and department snapshots.
- `inventory_months` contains recalculated summaries. Normal stock workflows write the transaction, audit entry, and summary in a database transaction.
- `database/schema.sql` initializes an empty MariaDB data directory. Existing databases are upgraded manually with reviewed SQL files in `database/migrations/`; startup does not run migrations.
- PhpSpreadsheet generates the History `.xlsx` export.

## Requirements

- Docker Engine and Docker Compose v2
- For local checks: PHP 8.3+, Composer 2, and Node.js
- For Jenkins checks: the same tools and a Docker-capable agent

## Local setup

1. Copy `.env.example` to `.env` and replace both placeholder database passwords with strong local values.
2. Start the existing services:

   ```sh
   docker compose config -q
   docker compose up -d --build
   docker compose ps
   ```

3. Open `http://localhost:8090`. On an empty database only, `setup.php` creates the first admin. Remove or otherwise restrict initial setup access before exposing a new deployment.

The MariaDB data directory is stored in the named `inventory_db_data` volume. `docker compose down` preserves it. Do not use `docker compose down -v` for routine restart or deployment.

## Environment configuration

`.env` is local-only and is excluded from Git and Docker build contexts. Keep `.env.example` placeholders only. In Jenkins, inject the values from Jenkins Credentials; do not store secrets in a Jenkinsfile or image.

| Variable | Purpose |
| --- | --- |
| `APP_ENV` | `local` enables detailed development errors; use `production` to suppress error details and enable Secure session cookies (HTTPS required). |
| `APP_NAME`, `APP_URL`, `APP_PORT` | Display name and local endpoint configuration. |
| `APP_URL_ACCESSIBLE_INTERNAL` | Set `true` only when email recipients can reach an internal network URL in `APP_URL`; defaults to `false`. |
| `SESSION_IDLE_TIMEOUT` | Authenticated idle timeout in seconds; defaults to 1800, minimum 300. |
| `APP_IMAGE`, `IMAGE_TAG` | Compose image name and tag for controlled builds/deployments. |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Application database connection. |
| `MYSQL_ROOT_PASSWORD` | MariaDB administrative password. |

The application and MariaDB currently use UTC for transaction timestamps. Changing the application timezone alone is not supported because the database stores `DATETIME` values without timezone metadata.

## Credentials integration

The isolated `codex/credentials-integration` checkout adds a Credentials module that shares Inventory Manager users, employees, software records, RBAC, and audit logs. Credential permissions are separate and default off for non-admins. Review [the integration and migration guide](docs/CREDENTIALS-INTEGRATION.md) before applying [the additive credentials migration](database/migrations/20261014_add_credentials_module.sql). The guide includes ID mapping, encryption checks, and rollback instructions. Production data import and cutover remain approval-gated.

## Database and migrations

For a fresh installation, Compose mounts `database/schema.sql` into MariaDB's initialization directory. MariaDB runs these initialization files only when its data directory is empty; editing `schema.sql` does not upgrade an existing volume.

For an existing installation:

1. Inspect the live schema and determine which migration is actually needed.
2. Take and verify a database backup.
3. Review the selected migration SQL.
4. Apply that migration explicitly, then verify the affected schema and application workflow.

The existing migrations are:

- `20261006_add_ordered_inventory_transactions.sql`: updates the transaction type enum for older schemas.
- `20261008_add_employees_directory.sql`: creates the employee directory table if absent; existing assignment snapshots are retained.
- `20261012_add_rejected_order_status.sql`: adds the rejected state to ordered transactions.
- `20261012_repair_order_reminder_recipient.sql`: safely adds recipient tracking to existing reminder history tables without deleting or rewriting prior attempts.
- `20261012_add_vendor_management.sql`: creates vendor master records and adds nullable vendor links and order-time snapshots while preserving legacy vendor text.
- `20261013_add_admin_managed_rbac.sql`: adds role defaults and per-user permission profiles, then seeds the existing Editor and Viewer access policy. It does not update existing users or business records.
- `20261013_add_user_auth_version.sql`: adds an additive account security version used to invalidate existing PHP file sessions after password, username, role, or status changes. Existing users and password hashes are unchanged.

Apply the vendor migration to an existing Docker MariaDB database with:

```sh
docker compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < database/migrations/20261012_add_vendor_management.sql
```

There is no automatic migration runner or migration ledger. Do not apply the enum migration repeatedly without checking the current column definition. The vendor migration is repeatable. No migration should delete historical transactions, assignments, employees, or users.

## Roles and permissions

Admin access is fixed at full application and system-management access. Admins can set defaults for Editor and Viewer roles and set Full Access, Custom Access, No Access, or role-default inheritance per non-admin user. Defaults are seeded to preserve the established behavior: Editors can manage stock, products, categories, assignments and hardware while viewing Employees, Orders and Vendors; Viewers are read-only. Users, Audit Logs and permission management cannot be granted to non-admin accounts.

Permission edits are stored in MariaDB and take effect on the user’s next authenticated request. Each edit is recorded in Audit Logs with the actor, target, prior mode and permissions, and new mode and permissions. Authorization is checked centrally and again in each page, POST handler, and AJAX search endpoint. A hidden control is only a usability detail; the server-side check is the security boundary.

Admins can edit user details from the Users table. Password reset is optional, requires a matching confirmation and a password of at least 12 characters, and audit records never contain passwords. Password resets and username, role, or status changes increment the target account's `auth_version`, signing out its existing PHP file sessions on their next request. The `auth_version` migration is additive and repeatable. To roll back only this migration after deploying code that no longer uses it, run `ALTER TABLE users DROP COLUMN auth_version;`; this disables account-wide session invalidation and does not alter users or password hashes.

### Existing database migration and rollback

Back up the database before applying the additive RBAC migration. For the Compose database, a backup can be written outside the project with:

```sh
docker compose exec -T db sh -lc 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' > /safe/path/inventory-manager-before-rbac.sql
```

Review the migration, then apply it explicitly:

```sh
docker compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < database/migrations/20261013_add_admin_managed_rbac.sql
```

Verify the three RBAC tables and seeded `editor` / `viewer` rows before deploying the updated application. The migration uses `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE`, so rerunning it does not overwrite permission settings changed by an Admin.

Rollback removes only the new permission configuration tables, in foreign-key order:

```sql
DROP TABLE IF EXISTS user_permissions;
DROP TABLE IF EXISTS user_permission_profiles;
DROP TABLE IF EXISTS role_permission_defaults;
```

Rollback discards Admin-managed permission profiles and role defaults. It does not delete users, audit entries, employees, assets, inventory, orders, or assignment history. Restore the pre-migration backup if rollback must also restore a previous application version.

## Workflows

### Products and stock

Products are quantity-based. Stock additions, removals, opening balances, orders, and adjustments are stored as inventory transactions with previous and resulting stock. Negative resulting stock is rejected by application workflows. The product row is locked during stock-changing operations to serialize concurrent changes.

Current stock is derived from the latest `transaction_at` transaction. Because transaction timestamps can be entered manually, backdated or future-dated movements can affect which transaction is treated as current. Use chronological timestamps. A different historical-entry policy requires a reviewed change to the stock model; existing transaction history must not be rewritten to work around it.

### Assignments

Employees are managed on Users. Assignment submission locks and validates the employee and product, checks stock, then writes the assignment snapshot, stock-out transaction, audit record, and monthly summary in one transaction. Failed writes roll back together.

### History and audit

History supports product/action/search/time filters and exports the same matching records to Excel. The page displays the latest 250 matches. Excel export currently has no row cap and holds worksheet data in application memory; very large exports should be monitored. Audit Logs are read-only in the application UI and admin-only.

## Testing and quality checks

Run the current fast checks from the project root:

```sh
php tests/run.php
find app tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node tests/check-inline-js.js
composer validate --no-check-publish
docker compose config -q
docker compose build app
docker compose ps
```

The PHP test runner covers stock arithmetic, negative-stock protection, every catalog capability across Admin, Editor and Viewer defaults and Full/Custom/No Access modes, unknown-permission denial, CSRF acceptance/rejection, flash consumption, order email rendering, and preview/send command behavior. It does not replace authenticated database integration, concurrency, or browser tests. Do not run write-workflow tests against a database containing real development or production data; use a separately provisioned test database and test credentials.

## Health check

`/health.php` returns `200` with `{"status":"ok"}` only if PHP can reach MariaDB, otherwise `503` with a generic response. The app Compose healthcheck polls this endpoint. It does not expose credentials or database error details.

```sh
curl --fail http://localhost:8090/health.php
docker compose ps
```

## Jenkins and deployment

The included `Jenkinsfile` runs validation, syntax checks, tests, Docker Compose validation, and a tagged app image build. It does not deploy automatically. Configure the Jenkins agent with PHP 8.3+, Composer 2, Node, Docker, and Compose; provide Compose environment variables through Jenkins Credentials or job-level secret bindings.

For deployment, use a reviewed release tag and this order:

1. Back up the database and application source.
2. Build and publish the immutable app image tag.
3. Apply only reviewed migrations after the backup is verified.
4. Set `IMAGE_TAG` to the release and restart only the app service.
5. Confirm Compose health and the application endpoint.
6. On an app-only failure, restore the previous image tag and restart the app. Database rollback is a separate recovery operation and must not be attempted by blindly replaying an older schema.

No production deployment is triggered by the Jenkins pipeline.

## Backup and restore

Treat database backups as sensitive because they contain application records. Store them encrypted with access limited to operators. Keep environment secrets in a password manager or Jenkins Credentials, separate from source and database dumps.

### Database backup

This writes a consistent SQL dump from the existing MariaDB service. It does not stop the application or change the database:

```sh
umask 077
mkdir -p backups
backup_file="backups/inventory-manager-$(date +%Y%m%d-%H%M%S).sql"
docker compose exec -T db sh -lc 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --skip-add-drop-table --routines --triggers "$MARIADB_DATABASE"' > "$backup_file"
test -s "$backup_file"
sha256sum "$backup_file" > "$backup_file.sha256"
```

### Application source backup

Create an archive outside the project directory. It includes the current working tree but excludes Git internals, local secrets, dependencies, and logs:

```sh
umask 077
tar --exclude='./.git' --exclude='./.env' --exclude='./vendor' --exclude='./app/vendor' --exclude='./storage/logs' --exclude='./backups' -czf "../inventory-manager-source-$(date +%Y%m%d-%H%M%S).tar.gz" .
```

Back up `storage/uploads` separately if it contains user data. Keep a copy of the matching `.env` values in an approved encrypted secret store, not in the archive.

### Restore verification

Never test a restore by importing over the live `inventory_manager` database. Provision a separate, empty MariaDB instance/database and verify the checksum first:

```sh
sha256sum -c backups/inventory-manager-YYYYMMDD-HHMMSS.sql.sha256
mariadb -h "$RESTORE_DB_HOST" -u "$RESTORE_DB_USER" -p "$RESTORE_DB_NAME" < backups/inventory-manager-YYYYMMDD-HHMMSS.sql
mariadb -h "$RESTORE_DB_HOST" -u "$RESTORE_DB_USER" -p "$RESTORE_DB_NAME" -e 'SHOW TABLES; SELECT COUNT(*) AS users FROM users; SELECT COUNT(*) AS products FROM products; SELECT COUNT(*) AS transactions FROM inventory_transactions;'
```

Restore application source to a new directory, not over a running checkout. Recreate environment configuration from the secret store, point a test app at the restored database, and verify login, inventory counts, assignments, and History before planning any production cutover. Retain the original database and volume until the restored deployment has been explicitly approved.

## Troubleshooting

- **App waits for MariaDB:** inspect `docker compose ps` and `docker compose logs db`; do not remove the named volume.
- **Port 8090 is occupied:** set a different `APP_PORT` in `.env` and run `docker compose config -q` before starting.
- **Database connection fails:** verify the `DB_*` values match the existing service configuration without printing the secret values.
- **Existing database did not change after editing schema.sql:** expected for a populated data directory; review and apply a safe migration explicitly.
- **App health is unhealthy:** check app and database logs, then query `/health.php`; the endpoint intentionally omits internal failure details.

## Security and maintenance notes

- Use HTTPS in production and set `APP_ENV=production`; this enables Secure session cookies and generic error responses.
- Keep PHP, Composer dependencies, and the base image patched; review dependency advisories before releases.
- Keep database port 3306 private. Compose publishes only the app port.
- Do not expose `setup.php` after initial setup. The initial-user flow is intended only for a controlled first launch.
- Stock history and employee assignment snapshots are operational records. Do not edit or delete them to correct a current balance; use a new, reasoned stock adjustment.
- Before adding a migration or changing transaction chronology, back up the database and test against a separate copy.
# Weekly order reminders

The `order-reminders` service checks for Pending orders every six hours. It sends a reminder once an order has been Pending for seven days, then at most once every seven days per recipient after a successful delivery while it remains Pending. Failed deliveries do not suppress retries and are logged independently for each recipient.

Configure `ADMIN_EMAILS` as a comma-separated list and set `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, and `SMTP_FROM` in `.env`. SMTP uses authenticated STARTTLS with certificate verification enabled. Keep real credentials out of `.env.example` and source control.

To verify SMTP without querying orders or writing reminder history, run:

```sh
docker compose exec order-reminders php /var/www/html/bin/send_order_reminders.php --test-email recipient@example.com
```

## Order status email reports

Both email features use the same responsive, branded HTML template and include a plain-text alternative. The immediate report queries MariaDB for every ordered transaction with Pending, Confirmed, Rejected, or NULL status, with matching status counts, including a distinct NULL Status count. It does not modify orders or inventory.

Preview the complete report without sending email (writes only to the app container's temporary directory):

```sh
docker compose exec app php /var/www/html/bin/send_order_reminders.php --preview /tmp/order-report-preview.html
```

Send one report immediately to each valid address in `ADMIN_EMAILS`:

```sh
docker compose exec app php /var/www/html/bin/send_order_reminders.php --send-report
```

Set `APP_URL` to an HTTP(S) URL recipients can reach. The View Orders button is omitted for localhost, private IPs, and local/internal hostnames. If the URL is on an internal network that recipients can access, set `APP_URL_ACCESSIBLE_INTERNAL=true` explicitly. Delivery errors are written to container logs with SMTP credentials redacted.

### Repair and deploy email delivery

Apply the repeatable reminder-history migration, then rebuild and recreate only the application and reminder services. The database service and named volume are not recreated:

```sh
docker compose exec -T db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < database/migrations/20261012_repair_order_reminder_recipient.sql
docker compose build app order-reminders
docker compose up -d --force-recreate app order-reminders
```

Generate and verify an HTML preview without SMTP credentials or `ADMIN_EMAILS`:

```sh
docker compose exec app php /var/www/html/bin/send_order_reminders.php --preview /tmp/order-report-preview.html
docker compose exec app test -s /tmp/order-report-preview.html && echo "Preview exists and is non-empty"
```

Verify both containers received the same script and that each has report recipients configured. These commands print only a configured/not-configured result and script hashes; they do not print SMTP settings:

```sh
sha256sum app/bin/send_order_reminders.php
docker compose exec -T app php -r 'echo "ADMIN_EMAILS=" . ((getenv("ADMIN_EMAILS") ?: "") !== "" ? "configured" : "missing") . PHP_EOL; echo hash_file("sha256", "/var/www/html/bin/send_order_reminders.php"), PHP_EOL;'
docker compose exec -T order-reminders php -r 'echo "ADMIN_EMAILS=" . ((getenv("ADMIN_EMAILS") ?: "") !== "" ? "configured" : "missing") . PHP_EOL; echo hash_file("sha256", "/var/www/html/bin/send_order_reminders.php"), PHP_EOL;'
docker compose exec -T db sh -lc 'mariadb -N -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "SHOW COLUMNS FROM order_reminder_attempts; SHOW INDEX FROM order_reminder_attempts;"'
```

Send one real report to every valid recipient in `ADMIN_EMAILS`:

```sh
docker compose exec app php /var/www/html/bin/send_order_reminders.php --send-report
```
