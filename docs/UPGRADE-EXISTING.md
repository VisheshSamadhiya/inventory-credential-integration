# Upgrade an existing installation

This package does not include an automatic or universal migration runner. Never apply the full SQL directory by wildcard. The existing DB schema may be ahead of the migrations and `schema.sql` is only for empty databases.

## Before migration

1. Put the current application in maintenance mode or schedule a short write freeze. Stop scheduled workers so no job races with DDL or a code switch.
2. Confirm application and DB versions, storage paths, PHP extensions, and the existing `CREDENTIAL_ENCRYPTION_KEY`. Keep the old key byte-for-byte unchanged; do not copy it into the ZIP or deployment notes.
3. Take a complete database backup, including triggers/routines, and a separate copy of private credential attachments. Test restoring both into an isolated location.
4. Create a disposable database from a recent backup. Apply the chosen migrations there in the exact order in [migrations/README.md](../database/migrations/README.md). Compare table/column/index/foreign-key definitions with `database/verify.sql`, check the report queries, and exercise write/read flows before production.
5. Identify each migration already represented in the live schema. `20261006_add_ordered_inventory_transactions.sql` is non-repeatable and must be skipped when its enum member already exists. Avoid rerunning `20261019_add_employee_hardware_valuation.sql` casually: it is idempotent by design but performs history backfills and requires review of results.

## Apply and verify

Apply one reviewed file at a time with a database client connected to the intended schema. Save each command output. DDL can auto-commit, so a failed file may leave partial state. Stop on any error and inspect `information_schema` before deciding whether to restore or repair.

After migration, run `database/verify.sql`, compare business row counts and audit-history ranges with the pre-migration backup, and run credential decryption checks using the existing key. Check users, encrypted credential ciphertext, TOTP ciphertext/status, orders, inventory transactions, receipt references, assignments, cost allocations, asset tags, and audit log counts. Do not print decrypted credentials or TOTP secrets into logs. Confirm unsupported historical costs remain unavailable.

Deploy code only after the migrated copy passes review. Keep old application files, configuration, and database backup until validation is complete. For rollback options see [ROLLBACK.md](ROLLBACK.md).
