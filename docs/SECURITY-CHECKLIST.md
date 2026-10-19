# Security checklist

## Before go-live

- Set `APP_ENV=production`, HTTPS `APP_URL`, and `APP_FORCE_HTTPS=true`. Confirm TLS is active before login. Secure session cookies, HTTP-only cookies, strict session mode, SameSite=Lax, idle expiry, and session ID regeneration are configured in PHP.
- Store `production.env`, DB credentials, encryption key, backups, logs, session files, and credential attachments outside the document root. Give the PHP user read-only access to config and write access only to required private storage.
- Set `CREDENTIAL_ENCRYPTION_KEY` to the existing key for upgrades. For new installs generate a random 32-byte base64 key, back it up in a restricted secrets manager, and do not include it in source control or ZIPs.
- Set a dedicated database user with only app/schema privileges for its own database. Restrict remote DB network access. Enforce TLS for DB connections if the DB is remote and the provider supports it.
- Install Composer dependencies from `composer.lock` with `--no-dev`; do not expose `vendor/`, manifests, SQL, docs, logs, or backups.
- Ensure Apache/Plesk has directory indexes disabled and `.htaccess` is honored, or apply equivalent vhost denies. Verify direct HTTP requests for `.env.production`, `.env`, SQL, logs, ZIPs, `vendor/autoload.php`, and `/bin/` are denied.
- Create the first Admin once, set a unique 12+ character password, review Admin-managed permissions, and grant credential module capabilities explicitly. Keep at least two active admins to avoid account lockout.
- Require credential TOTP only through Admin account controls. Admin enrollment/reset routes are server-side admin checks; regular users cannot reset or configure another account. Credential TOTP gates credential resources only and does not grant module permissions. Preserve the encryption key needed for both credential and TOTP ciphertext.
- Confirm credential scope checks, department grants, direct URL/API authorization, recent password reauthentication before reveals, CSRF checks, and audit events. Use a non-admin test account to verify denial.
- Keep cost unknown where acquisition evidence is absent. Review valuation reconciliation actions and their audit rows; do not substitute current product prices for missing historical costs.
- Configure SMTP with verified TLS and least-privilege credentials. Protect worker environment and logs. Verify lock files prevent overlap and worker processes use production-only configuration.
- Back up database and private attachments, encrypt backups, restrict access, and test restore. Monitor PHP/Apache/database logs and disk usage.

## Encryption-key rotation

Do not replace the key as a routine deployment step. If compromise is suspected, restrict access and preserve the old key securely. Use a controlled maintenance procedure: take an encrypted backup; deploy code capable of reading old and writing new ciphertext (or an isolated audited one-off re-encryption utility); decrypt/re-encrypt credential and TOTP secret columns in a transaction or verifiable batches; verify row counts and decryptability without printing plaintext; preserve the old key until restore tests succeed; then remove it from active configuration. Re-encrypt attachments only if their content itself is encrypted by the application (currently local/S3 attachments are stored as uploaded files and depend on filesystem/object-store encryption controls). Record approvals and audit evidence. Never rotate by simply changing the environment value: existing values would become unreadable.

## Known limits

The repository does not include a complete browser-driven production acceptance suite. SQL and DB integration need to be run on a disposable instance of the target database version before release. Plesk's available environment-variable and scheduler controls vary by subscription. See [PRODUCTION-TEST-REPORT.md](PRODUCTION-TEST-REPORT.md).
