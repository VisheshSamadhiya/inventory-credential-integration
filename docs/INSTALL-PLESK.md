# Plesk deployment

These steps use the Plesk UI and domain's PHP 8.3 handler; root access and Docker are not required. Use a staging subdomain first when available.

## Upload and document root

1. In **Websites & Domains → File Manager**, upload `inventory-credential-manager-production.zip` to a private release directory outside `httpdocs` if the plan permits it, then extract it.
2. The archive has `app/`, `database/`, `docs/`, and `composer.json` at its root. Set the domain's document root to the extracted `app/` directory under **Hosting Settings**. Keep `database/`, `docs/`, SQL files, Composer manifests, and the private environment file outside the document root.
3. If Plesk requires the project root in `httpdocs`, keep only the contents of `app/` there and store SQL/docs/config/private storage outside `httpdocs`. Never copy `.env.production` into the document root.
4. Select PHP 8.3 and the FPM or Apache handler offered by the subscription. The application does not require Docker. Enable PDO MySQL, OpenSSL, Sodium, JSON, mbstring, XML, GD, ZIP, cURL, and BCMath in **PHP Settings**. Verify with a temporary `phpinfo()` page only if necessary, then delete it immediately.

## Dependencies

Use Plesk's Composer extension/CLI for the release root and run `composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader`. If Composer is unavailable, ask the host to enable it or install dependencies in a compatible staging environment with the committed lock file and upload `vendor/`; do not omit PhpSpreadsheet/PHPMailer/AWS SDK. Confirm `vendor/` is outside the public root or blocked from HTTP access.

## Database

Create a MySQL/MariaDB database and dedicated user in **Databases**. Import `database/fresh-install.sql` only into an empty new database using **Import Dump**. Run `database/verify.sql` in phpMyAdmin or the Plesk database tool. For an existing database, first export a backup and follow [UPGRADE-EXISTING.md](UPGRADE-EXISTING.md), applying only required migrations in order.

## Secrets and writable paths

Create a private directory such as `/var/www/vhosts/it-password-manager.ditinus.com/private/inventory-manager/` using File Manager, outside `httpdocs`. Put a copy of `.env.production.example` there as `production.env`; set DB details, canonical HTTPS `APP_URL`, `APP_ENV=production`, `APP_FORCE_HTTPS=true`, the existing encryption key if upgrading, private session and attachment paths, and SMTP details if needed. Give the PHP handler read access but no public URL. Use Plesk **PHP Settings → Additional directives** (Apache handler) to set `APP_ENV_FILE` to the absolute file path; with PHP-FPM, use Plesk's environment-variable settings if available. If the plan does not permit an environment variable, use the supported subscription-level PHP configuration method with the host. Do not place secrets in a PHP file under `httpdocs`.

Create private writable directories for sessions and attachments, for example under the domain's `private/` tree, and make them writable by the domain's PHP system user only. Set `SESSION_SAVE_PATH` and `CREDENTIAL_ATTACHMENT_PATH` to those exact paths. Do not make the whole project writable.

## HTTPS and setup

Enable/retain the domain's existing Let's Encrypt or uploaded certificate in **SSL/TLS Certificates**, then enable **Redirect visitors from HTTP to HTTPS**. `APP_FORCE_HTTPS=true` provides an application redirect as a second check. Visit `setup.php` once on a new empty database to create the first administrator; setup is closed after the first user exists.

## Scheduled Tasks

Under **Websites & Domains → Scheduled Tasks**, select the domain's PHP 8.3 CLI binary and use the extracted release path. Run the asset worker with `--once` every minute, the email outbox worker every minute, and reminders every six hours. Examples are in [BACKGROUND-WORKERS.md](BACKGROUND-WORKERS.md). Use `flock -n` if available. If Plesk does not provide `flock`, configure non-overlapping tasks and an exclusive per-task lock using the host's supported scheduler; do not launch duplicate long-running loops.

## Verify

Check that `/database/`, `/docs/`, `/vendor/`, `.env` names, backups, logs, and dotfiles cannot be fetched. Verify login, role restrictions, inventory receipts, employee asset assignment/return, valuation, history export, credential access, TOTP enforcement, and worker logs. See [SECURITY-CHECKLIST.md](SECURITY-CHECKLIST.md) and [PRODUCTION-TEST-REPORT.md](PRODUCTION-TEST-REPORT.md).
