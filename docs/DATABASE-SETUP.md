# Database setup

## Supported database features

The application uses InnoDB foreign keys, transactions, `ENUM`, `JSON` functions in queries, CTE-free aggregation/subqueries, `INSERT IGNORE`, `REGEXP`, `SIGNAL`, stored procedures for guarded additive DDL, triggers for immutable cost reconciliation rows, and `information_schema`. The tested project baseline is MariaDB 11.8. Use MariaDB 10.6+ or MySQL 8.0.29+ and validate the exact version using a disposable database first. Do not use MySQL 5.7 or older MariaDB releases.

## Fresh install

1. Create a dedicated empty database with `utf8mb4` and a dedicated application user. Grant the app user only `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE` on this database. Some shared hosts combine or restrict these grants; confirm all schema objects can be imported before production.
2. Import `database/fresh-install.sql` while connected to that empty database. It creates tables and role policy defaults, but no users, employees, inventory, transactions, credentials, TOTP enrollments, cost layers, or assignments.
3. Run `database/verify.sql`. The missing-table query must return no rows. The business-table counts must all be zero. Role permission defaults are reference policy, not business records.
4. Configure the app and open `setup.php` once to create the first administrator. Create a long unique password. After setup, confirm the application reports setup already complete to unauthenticated visitors.

Do not import `fresh-install.sql` over an existing database. It is not an upgrade script.

## Existing installation

Follow [UPGRADE-EXISTING.md](UPGRADE-EXISTING.md) and [migrations/README.md](../database/migrations/README.md). Select only migrations whose columns/tables/constraints are missing. Dependencies are ordered there. The app never runs SQL migrations automatically. If the schema is a prior version not listed in that file, stop and reconcile it against a restored disposable copy before applying anything.

## Backup and restore

Use a database account permitted to dump the target database and keep the dump outside the web root. On Ubuntu:

```sh
sudo install -d -m 0700 -o root -g root /var/backups/inventory-manager
sudo mariadb-dump --single-transaction --routines --triggers --events inventory_manager \
  | sudo tee "/var/backups/inventory-manager/before-$(date -u +%Y%m%dT%H%M%SZ).sql" >/dev/null
sudo chmod 0600 /var/backups/inventory-manager/*.sql
```

For Plesk, use **Databases → Export Dump** and download it to encrypted administrative storage, or use the hosting provider's supported CLI backup. Verify the dump is nonempty and readable before migration. Do not put dumps in `httpdocs`.

Restore to a newly created empty database first, and verify counts and application behavior before choosing a production restore. Ubuntu example:

```sh
sudo mariadb -e 'CREATE DATABASE inventory_manager_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
sudo mariadb inventory_manager_restore < /var/backups/inventory-manager/BEFORE-MIGRATION.sql
```

Point an isolated application release at the restore database for review. A production rollback restore replaces the target database contents; take another backup first and perform the restore only during an approved maintenance window. Never restore into a database that contains newer accepted transactions.
