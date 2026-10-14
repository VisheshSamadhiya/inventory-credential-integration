-- Additive Credential Manager integration for Inventory Manager.
-- Does not import source rows, alter inventory records, or grant non-admin access.

CREATE TABLE IF NOT EXISTS credential_departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credential_department_access (
    user_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, department_id),
    CONSTRAINT fk_credential_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_access_department FOREIGN KEY (department_id) REFERENCES credential_departments(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_access_grantor FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NULL,
    department_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    software_installation_id BIGINT UNSIGNED NULL,
    service_name VARCHAR(180) NOT NULL,
    service_url VARCHAR(500) NULL,
    credential_username VARCHAR(190) NULL,
    credential_password_enc TEXT NOT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    source_system VARCHAR(64) NULL,
    source_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_credential_source (source_system, source_id),
    INDEX idx_credentials_employee (employee_id, active),
    INDEX idx_credentials_department (department_id, active),
    INDEX idx_credentials_software (software_installation_id),
    INDEX idx_credentials_user (user_id),
    CONSTRAINT fk_credential_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_department FOREIGN KEY (department_id) REFERENCES credential_departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_software FOREIGN KEY (software_installation_id) REFERENCES software_installations(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credential_password_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credential_id BIGINT UNSIGNED NOT NULL,
    password_enc TEXT NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    change_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_system VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_credential_version_source (source_system, source_id),
    INDEX idx_credential_versions (credential_id, created_at),
    CONSTRAINT fk_credential_version_credential FOREIGN KEY (credential_id) REFERENCES credentials(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_version_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credential_access_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credential_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_system VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_credential_log_source (source_system, source_id),
    INDEX idx_credential_access_log (credential_id, created_at),
    INDEX idx_credential_access_user (user_id, created_at),
    CONSTRAINT fk_credential_log_credential FOREIGN KEY (credential_id) REFERENCES credentials(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credential_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credential_id BIGINT UNSIGNED NOT NULL,
    storage_backend ENUM('local','s3') NOT NULL DEFAULT 'local',
    storage_key VARCHAR(700) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    content_type VARCHAR(190) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_system VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_credential_attachment_source (source_system, source_id),
    INDEX idx_credential_attachment (credential_id, created_at),
    CONSTRAINT fk_credential_attachment_credential FOREIGN KEY (credential_id) REFERENCES credentials(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_attachment_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Maps legacy Credential Manager IDs to canonical Inventory Manager IDs.
-- source_entity supports employee, department, user, and credential rows; status
-- stays pending until an exact/approved mapping is verified.
CREATE TABLE IF NOT EXISTS credential_migration_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_system VARCHAR(64) NOT NULL DEFAULT 'credential-manager',
    source_entity ENUM('employee','department','user','credential','password_version','access_log','audit_log','attachment') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    status ENUM('pending','matched','manual','unmapped') NOT NULL DEFAULT 'pending',
    source_label VARCHAR(255) NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_credential_migration_source (source_system, source_entity, source_id),
    KEY idx_credential_migration_target (source_entity, target_id)
) ENGINE=InnoDB;

-- Intentionally no INSERTs into role_permission_defaults or user_permissions:
-- credential access remains disabled for non-admins until an Admin grants it.

ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS ip_address VARCHAR(64) NULL;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS source_system VARCHAR(64) NULL;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS source_id BIGINT UNSIGNED NULL;

INSERT IGNORE INTO credential_departments(name) SELECT DISTINCT TRIM(department) FROM employees WHERE department IS NOT NULL AND TRIM(department)<>'';
ALTER TABLE audit_logs MODIFY action VARCHAR(120) NOT NULL;
