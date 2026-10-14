# Credential Manager integration

The integration checkout keeps Inventory Manager's login, employees, software records, MariaDB database, navigation, and Admin-managed RBAC. Credential rows live in `credentials` and are shared by `credentials.php` and Employee Details → Software. The new schema migration is additive and does not insert employees or grant credential capabilities to Editor/Viewer defaults.

## Identity mapping

Before importing source data, take verified backups of both databases and retain the original `CREDENTIAL_ENCRYPTION_KEY` in the existing secret store. Do not rotate or print the key during the migration.

- Employee IDs map by exact Credential Manager `employee_code` to Inventory Manager `employees.employee_id`. A missing or ambiguous match must be manually mapped in `credential_migration_mappings`; the import never creates an employee.
- Department IDs map to `credential_departments` by case-insensitive, trimmed department name, or to an explicitly reviewed `manual` mapping. Inventory employees remain the canonical employee directory; their existing department text is not rewritten.
- User IDs map by exact username to Inventory Manager `users.id`, or to an explicitly reviewed `manual` mapping. Source passwords, roles, and TOTP values are not copied; the Inventory Manager account remains the only login.
- Credential creator, owner, password-history, access-log, audit-log, and attachment uploader references use those user mappings when an exact match exists; unmatched historical actors remain null on the live foreign key while their source IDs/status remain in the migration mapping table. The import stops if a credential’s linked employee cannot be mapped; an unmatched actor alone does not block preserving its credential/history/log row. The source `user_department_permissions` rows are copied as credential department grants. These grants do not grant `credentials.*` capabilities; an Admin still grants the new capabilities in Users.
- Existing encrypted password and password-history values are copied byte-for-byte. The migration tool verifies each value can be decrypted with the configured key but never prints or stores plaintext. It understands both current AES-256-GCM rows and legacy Sodium secretbox rows.
- Existing S3 attachment metadata stays marked as S3 and keeps its object key. Configure AWS SDK credentials, `AWS_REGION`, and `AWS_S3_BUCKET` before enabling migrated attachment access. New uploads use the persistent private local attachment volume.

## Review and import

The script defaults to a read-only dry run. It requires the credential source DB connection variables (`SOURCE_DB_HOST`, `SOURCE_DB_PORT`, `SOURCE_DB_NAME`, `SOURCE_DB_USER`, `SOURCE_DB_PASSWORD`) and the existing `CREDENTIAL_ENCRYPTION_KEY`. Apply the additive target schema first, then review exact ID mappings and resolve every employee reference used by a credential. Review unmatched users; their historical actor references are retained as unmapped rows and do not become logins. For a manual mapping, insert a reviewed row into `credential_migration_mappings` with `source_system='credential-manager'`, the matching `source_entity` and `source_id`, the canonical Inventory Manager `target_id`, and `status='manual'`.

```sh
php database/migrate_credentials.php
```

This prints counts and mapping status only. It does not write to either database. Do not use `--apply` until the owner approves production data migration and cutover. After approval and verified backups, `--apply` also requires `ALLOW_CREDENTIAL_DATA_IMPORT=YES`; it wraps target writes in a transaction. Keep the original Credential Manager database and key unchanged through verification and the cutover decision.

## Migration verification

After a reviewed import, compare source and target counts for active credentials, password versions, access logs, audit logs, attachments, and explicit department grants. Check all `credential_migration_mappings` rows for `pending` or `unmapped` status. Confirm Inventory employee/user counts are unchanged. Confirm the source key still decrypts every imported current/history ciphertext by running the dry-run verifier against the target rows in a secured maintenance session; inspect only counts, never values.

Then verify in the application with test accounts: the separate Credentials menu entry, each `credentials.*` capability, department boundaries, employee detail visibility, create/edit/delete, password-history entries, recent-password re-authentication and reveal logging, attachment upload/download/delete, and optional software links. A credential with no software assignment remains valid.

## Rollback

The data import is transactional until commit. Before cutover, restore the verified pre-integration Inventory backup if any imported state is inconsistent. The source Credential Manager database, its files/S3 objects, and original encryption key remain untouched and are the recovery source.

To remove only the integration schema after a verified backup, use `database/rollback_credentials_module.sql`. It drops credential-only tables and the `source_system` / `source_id` columns added to `audit_logs`; it retains `ip_address` and the widened audit action field. It does not touch users, employees, software, inventory, orders, assets, vendors, or reports. Running it discards imported credentials and credential history from the integration database, so retain the pre-import backup first.

No production database migration or cutover has been run from this checkout.
