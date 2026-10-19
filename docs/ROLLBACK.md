# Rollback

## Application-only rollback

Keep the prior release and config in place until the new release is verified. To roll back Ubuntu, restore only the edited VirtualHost file, test it, and reload gracefully:

```sh
sudo cp -a /etc/apache2/sites-available/it-password-manager.ditinus.com.conf.before-inventory-manager /etc/apache2/sites-available/it-password-manager.ditinus.com.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

For Plesk, change the document root back to the prior release and restore its previous PHP/environment settings. Keep TLS certificate paths unchanged.

## Database rollback

Migrations are forward-only and some DDL auto-commits. Do not run reverse SQL that drops tables or columns in an attempt to undo production migration. Stop app writes and workers, preserve a post-change backup, then restore a verified pre-migration backup to a new database. Validate the restored database and point the prior code release to it during a controlled maintenance window. This replacement discards all writes made after the backup; reconcile them first if they must be kept.

Do not drop `user_credential_totp`, credentials, cost layers, inventory transactions, assignments, receipts, outbox, or audit history as a rollback shortcut. Preserve private attachments and the exact encryption key with the database backup. Document which key version was used.
