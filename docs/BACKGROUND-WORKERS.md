# Background workers

The current application has three CLI entry points:

| Worker | Work | Recommended schedule |
| --- | --- | --- |
| `app/bin/process_asset_tag_jobs.php --once` | One batch of a queued asset-tag job (default batch 250). | Every minute; repeat while large jobs are pending. |
| `app/bin/process_order_email_outbox.php` | Delivers queued order email notifications with bounded retries. | Every minute on shared/Plesk hosting; every 30 seconds with systemd timer if desired. |
| `app/bin/send_order_reminders.php` | Sends pending-order reminders and records attempts. | Every 6 hours; each recipient/order is limited to one successful reminder per 7 days. |

No worker should use a development DB in production. Use the same protected `APP_ENV_FILE` config as the web app, the same release, and the production DB. CLI does not force HTTPS or use browser sessions. `SMTP_*`, `ADMIN_EMAILS`, `APP_URL`, and DB settings are read from the protected environment. No email is sent if no valid admin recipients are configured.

## Ubuntu cron

Install `flock` (from `util-linux`) and create the crontab as the web-service account. `flock` prevents overlaps for each task:

```cron
* * * * * /usr/bin/flock -n /run/lock/inventory-asset-tags.lock /usr/bin/php /var/www/html/php/inventory-credential-new/app/bin/process_asset_tag_jobs.php --once >> /var/log/inventory-manager/asset-tags.log 2>&1
* * * * * /usr/bin/flock -n /run/lock/inventory-email-outbox.lock /usr/bin/php /var/www/html/php/inventory-credential-new/app/bin/process_order_email_outbox.php >> /var/log/inventory-manager/email-outbox.log 2>&1
17 */6 * * * /usr/bin/flock -n /run/lock/inventory-order-reminders.lock /usr/bin/php /var/www/html/php/inventory-credential-new/app/bin/send_order_reminders.php >> /var/log/inventory-manager/order-reminders.log 2>&1
```

Make the log directory private and writable by the scheduled-task account. Logrotate should retain a bounded history, such as 14 days. Alert on nonzero exit status and terminal outbox failures. Run `--test-email recipient@example.com` only when intentionally verifying SMTP; it sends one email and does not change order history.

For higher asset job throughput use a systemd service running the default continuous asset worker, with `Environment=APP_ENV_FILE=/etc/inventory-manager/production.env`, `User=www-data`, `Restart=always`, `NoNewPrivileges=true`, and a service-specific lock. Do not run both the continuous service and the asset cron task.

## Plesk Scheduled Tasks

Create these domain tasks using the PHP 8.3 CLI binary and the extracted release path:

```sh
/usr/bin/flock -n /var/www/vhosts/it-password-manager.ditinus.com/private/asset-tags.lock /opt/plesk/php/8.3/bin/php /var/www/vhosts/it-password-manager.ditinus.com/inventory-credential-new/app/bin/process_asset_tag_jobs.php --once
/usr/bin/flock -n /var/www/vhosts/it-password-manager.ditinus.com/private/email-outbox.lock /opt/plesk/php/8.3/bin/php /var/www/vhosts/it-password-manager.ditinus.com/inventory-credential-new/app/bin/process_order_email_outbox.php
/usr/bin/flock -n /var/www/vhosts/it-password-manager.ditinus.com/private/order-reminders.lock /opt/plesk/php/8.3/bin/php /var/www/vhosts/it-password-manager.ditinus.com/inventory-credential-new/app/bin/send_order_reminders.php
```

Set task intervals to every minute, every minute, and every six hours respectively. Paths vary by subscription; use Plesk's actual PHP 8.3 CLI path. Set `APP_ENV_FILE` in the task's environment. If `flock` is unavailable, use Plesk's non-overlap/locking feature if offered; otherwise leave interval tasks disabled until the provider supplies a supported locking method. Do not configure a task that loops forever.

Workers write concise progress to stdout/stderr and PHP errors to the host's protected PHP log. Outbox failure details are redacted before DB logging. Protect scheduler logs and rotate them. Never run application workers from both Docker and a standalone/Plesk host against the same database.
