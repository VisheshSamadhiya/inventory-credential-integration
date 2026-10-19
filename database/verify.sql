-- Run after fresh installation or an upgrade, with the target database selected.
SELECT DATABASE() AS selected_database, VERSION() AS server_version;
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_NAME;

-- Core module tables. Expected count is 1 for each listed table.
SELECT required.table_name, COUNT(actual.TABLE_NAME) AS present
FROM (
    SELECT 'users' table_name UNION ALL SELECT 'categories' UNION ALL SELECT 'sub_categories'
    UNION ALL SELECT 'products' UNION ALL SELECT 'inventory_months' UNION ALL SELECT 'inventory_transactions'
    UNION ALL SELECT 'vendors' UNION ALL SELECT 'employees' UNION ALL SELECT 'assignments'
    UNION ALL SELECT 'inventory_assets' UNION ALL SELECT 'active_hardware_assignments'
    UNION ALL SELECT 'hardware_assignment_history' UNION ALL SELECT 'inventory_cost_layers'
    UNION ALL SELECT 'inventory_cost_allocations' UNION ALL SELECT 'inventory_cost_reconciliations'
    UNION ALL SELECT 'software_installations' UNION ALL SELECT 'software_history'
    UNION ALL SELECT 'audit_logs' UNION ALL SELECT 'role_permission_defaults'
    UNION ALL SELECT 'user_permission_profiles' UNION ALL SELECT 'user_permissions'
    UNION ALL SELECT 'credential_departments' UNION ALL SELECT 'credential_department_access'
    UNION ALL SELECT 'credentials' UNION ALL SELECT 'credential_password_versions'
    UNION ALL SELECT 'credential_access_logs' UNION ALL SELECT 'credential_attachments'
    UNION ALL SELECT 'credential_migration_mappings' UNION ALL SELECT 'user_credential_totp'
    UNION ALL SELECT 'login_attempt_limits' UNION ALL SELECT 'order_reminder_attempts'
    UNION ALL SELECT 'order_email_outbox' UNION ALL SELECT 'asset_tag_jobs'
    UNION ALL SELECT 'asset_tag_ranges' UNION ALL SELECT 'asset_tag_prefix_sequences'
    UNION ALL SELECT 'asset_tag_registry' UNION ALL SELECT 'internal_serial_prefix_sequences'
    UNION ALL SELECT 'manufacturer_serial_registry'
) AS required
LEFT JOIN information_schema.TABLES actual
    ON actual.TABLE_SCHEMA = DATABASE() AND actual.TABLE_NAME = required.table_name
GROUP BY required.table_name
HAVING present <> 1;

-- An empty result means no inventory/user fixtures were inserted by schema setup.
SELECT 'users' AS table_name, COUNT(*) AS rows_present FROM users
UNION ALL SELECT 'products', COUNT(*) FROM products
UNION ALL SELECT 'inventory_transactions', COUNT(*) FROM inventory_transactions
UNION ALL SELECT 'inventory_assets', COUNT(*) FROM inventory_assets
UNION ALL SELECT 'assignments', COUNT(*) FROM assignments
UNION ALL SELECT 'credentials', COUNT(*) FROM credentials;

-- Key compatibility check: existing ciphertext remains byte-for-byte preserved.
-- Take these counts before and after upgrade for comparison; do not print secrets.
SELECT COUNT(*) AS credential_rows, COUNT(credential_password_enc) AS encrypted_values
FROM credentials;
SELECT COUNT(*) AS totp_enrollments,
       SUM(secret_enc IS NOT NULL) AS active_secret_ciphertexts,
       SUM(pending_secret_enc IS NOT NULL) AS pending_secret_ciphertexts
FROM user_credential_totp;

-- Valuation integrity: unavailable historical costs remain NULL and are not
-- synthesized from current product prices. These are review queries, not fixes.
SELECT COUNT(*) AS asset_rows_without_recorded_cost
FROM inventory_assets WHERE acquisition_unit_cost IS NULL AND acquisition_cost_layer_id IS NULL;
SELECT COUNT(*) AS valuation_events_without_recorded_value
FROM hardware_assignment_history WHERE acquisition_value IS NULL;
