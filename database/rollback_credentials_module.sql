-- Destructive to integrated credential data. Back up the target first.
DELETE FROM audit_logs WHERE source_system='credential-manager' OR (entity_type IN ('credential','credential_department') AND action LIKE 'credential_%');
DROP TABLE IF EXISTS credential_attachments;
DROP TABLE IF EXISTS credential_access_logs;
DROP TABLE IF EXISTS credential_password_versions;
DROP TABLE IF EXISTS credentials;
DROP TABLE IF EXISTS credential_department_access;
DROP TABLE IF EXISTS credential_departments;
DROP TABLE IF EXISTS credential_migration_mappings;
ALTER TABLE audit_logs DROP COLUMN IF EXISTS source_id;
ALTER TABLE audit_logs DROP COLUMN IF EXISTS source_system;
