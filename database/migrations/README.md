# Migration order and safety

The upgrade files target compatible earlier Inventory Manager schemas, not arbitrary schemas. First compare the target database with `../verify.sql` and inspect the migration-specific prerequisites. Back up and test a disposable copy before changing production. Never run the complete directory blindly.

Apply only migrations that are absent from the target schema, in this order (the order is explicit because lexicographic filename order does not represent dependencies):

1. `20261006_add_ordered_inventory_transactions.sql`
2. `20261008_add_employees_directory.sql`
3. `20261008_add_order_details_to_inventory_transactions.sql`
4. `20261009_add_stock_transaction_prices.sql`
5. `20261012_add_employee_asset_software_management.sql`
6. `20261012_add_order_confirmation_and_reminders.sql`
7. `20261012_fix_order_confirmation_columns.sql`
8. `20261012_add_rejected_order_status.sql`
9. `20261012_repair_order_reminder_recipient.sql`
10. `20261012_add_vendor_management.sql`
11. `20261012_enforce_unique_active_hardware_assets.sql`
12. `20261013_add_admin_managed_rbac.sql`
13. `20261013_add_user_auth_version.sql`
14. `20261014_add_credentials_module.sql`
15. `20261015_add_order_email_outbox.sql`
16. `20261016_add_asset_tag_generator.sql`
17. `20261016_add_hardware_serial_modes.sql`
18. `20261016_add_inventory_cost_layers_and_receipts.sql`
19. `20261018_add_inventory_cost_reconciliation.sql`
20. `20261019_add_login_attempt_limits.sql`
21. `20261019_add_credential_totp.sql`
22. `20261019_add_employee_hardware_valuation.sql`

The asset/hardware and valuation migrations require the tables created by earlier steps. In particular, 20261018 requires 20261016 cost layers; 20261019 employee hardware valuation requires the 20261018 reconciliations plus employee, assignment, asset, and hardware history tables.

Most files use `IF NOT EXISTS` checks, idempotent procedures, or `INSERT IGNORE` and are designed for repeat execution on a matching schema. Exceptions and cautions:

- `20261006_add_ordered_inventory_transactions.sql` uses a direct `ALTER` and is **non-repeatable**. Inspect the enum first and skip it if already present.
- `20261013_add_admin_managed_rbac.sql` safely creates rows with `INSERT IGNORE`, but it establishes role defaults. Review those defaults if the installation has custom policy.
- `20261018_add_inventory_cost_reconciliation.sql` and `20261019_add_employee_hardware_valuation.sql` are repeatable DDL/backfill migrations; the former creates immutable triggers, and the latter backfills only source-supported event history. Inspect the migration and effects on a copy before use.
- Some older MySQL versions do not support the MariaDB/MySQL `ADD COLUMN IF NOT EXISTS` syntax used in these migrations. Supported engines are MariaDB 10.6+ and MySQL 8.0.29+; preferred validation target is the project's MariaDB 11.8. Test your exact server before rollout.

SQL DDL may auto-commit. If a migration fails partway, stop, inspect `information_schema`, restore the pre-migration backup if needed, and resume only after correcting the exact partial state. The application has no migration ledger and does not apply migrations automatically.
