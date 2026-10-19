-- Run against the database selected by the connection. Docker's MariaDB
-- entrypoint creates and selects MARIADB_DATABASE before importing this file.

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(190) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
    active TINYINT(1) NOT NULL DEFAULT 1,
    auth_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Credential-only TOTP enforcement. Login behavior remains account-wide-password based.
CREATE TABLE IF NOT EXISTS login_attempt_limits (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    INDEX idx_login_attempt_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_credential_totp (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    required TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','active','reset_required') NULL DEFAULT NULL,
    secret_enc TEXT NULL,
    pending_secret_enc TEXT NULL,
    last_accepted_step BIGINT NOT NULL DEFAULT -1,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    generation INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_credential_totp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_credential_totp_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_name VARCHAR(180) NOT NULL,
    contact_person VARCHAR(150) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    address TEXT NULL,
    gstin VARCHAR(15) NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vendor_name (vendor_name),
    UNIQUE KEY uq_vendor_gstin (gstin),
    CONSTRAINT fk_vendor_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_category_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sub_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subcategory (category_id, name),
    CONSTRAINT fk_sub_category_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    sub_category_id BIGINT UNSIGNED NULL,
    name VARCHAR(180) NOT NULL,
    brand VARCHAR(120) NULL,
    model VARCHAR(120) NULL,
    unit VARCHAR(40) NOT NULL DEFAULT 'pcs',
    minimum_stock INT UNSIGNED NOT NULL DEFAULT 0,
    serialized TINYINT(1) NOT NULL DEFAULT 0,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT fk_product_sub_category FOREIGN KEY (sub_category_id) REFERENCES sub_categories(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_months (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    month TINYINT UNSIGNED NOT NULL,
    opening_quantity INT NOT NULL DEFAULT 0,
    total_in INT NOT NULL DEFAULT 0,
    total_out INT NOT NULL DEFAULT 0,
    total_adjustment INT NOT NULL DEFAULT 0,
    closing_quantity INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_month (product_id, year, month),
    CONSTRAINT fk_inventory_month_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    inventory_month_id BIGINT UNSIGNED NULL,
    transaction_type ENUM('opening','ordered','in','out','adjustment') NOT NULL,
    quantity INT NOT NULL,
    previous_quantity INT NOT NULL,
    new_quantity INT NOT NULL,
    reason VARCHAR(100) NOT NULL,
    unit_price DECIMAL(13,2) NULL,
    total_price DECIMAL(15,2) NULL,
    order_reason ENUM('Requested','New','Replacement') NULL,
    vendor VARCHAR(180) NULL,
    vendor_id BIGINT UNSIGNED NULL,
    vendor_name_snapshot VARCHAR(180) NULL,
    vendor_contact_snapshot VARCHAR(150) NULL,
    vendor_phone_snapshot VARCHAR(40) NULL,
    vendor_email_snapshot VARCHAR(190) NULL,
    vendor_address_snapshot TEXT NULL,
    vendor_gstin_snapshot VARCHAR(15) NULL,
    expected_date DATE NULL,
    status ENUM('Pending','Confirmed','Rejected','Received') NULL DEFAULT 'Pending',
    confirmed_at DATETIME NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    rejected_at DATETIME NULL,
    rejected_by BIGINT UNSIGNED NULL,
    rejection_reason VARCHAR(500) NULL,
    request_key CHAR(64) NULL,
    related_order_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    reference_number VARCHAR(180) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    transaction_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transaction_product_date (product_id, transaction_at),
    UNIQUE KEY uq_inventory_transaction_request_key (request_key),
    CONSTRAINT fk_transaction_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_transaction_month FOREIGN KEY (inventory_month_id) REFERENCES inventory_months(id) ON DELETE SET NULL,
    CONSTRAINT fk_transaction_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_transaction_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    CONSTRAINT fk_transaction_confirmed_user FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_transaction_rejected_user FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_transaction_order FOREIGN KEY (related_order_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_cost_layers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    source_transaction_id BIGINT UNSIGNED NULL UNIQUE,
    reconciliation_id BIGINT UNSIGNED NULL,
    unit_cost DECIMAL(13,2) NOT NULL,
    original_quantity INT UNSIGNED NOT NULL,
    remaining_quantity INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_cost_layers_available (product_id, remaining_quantity, id),
    CONSTRAINT fk_inventory_cost_layer_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_inventory_cost_layer_transaction FOREIGN KEY (source_transaction_id) REFERENCES inventory_transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_reminder_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    recipient_email VARCHAR(254) NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    outcome ENUM('sent','failed') NOT NULL,
    error_message VARCHAR(1000) NULL,
    INDEX idx_order_reminder_attempt (order_id, attempted_at),
    INDEX idx_order_reminder_recipient (order_id, recipient_email, outcome, attempted_at),
    CONSTRAINT fk_order_reminder_transaction FOREIGN KEY (order_id) REFERENCES inventory_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_email_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(191) NOT NULL UNIQUE,
    event_type VARCHAR(40) NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    html_body LONGTEXT NOT NULL,
    text_body LONGTEXT NOT NULL,
    status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_email_outbox_ready (status, available_at, id),
    INDEX idx_order_email_outbox_locked (status, locked_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_tag_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_key CHAR(64) NOT NULL UNIQUE,
    request_hash CHAR(64) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    prefix VARCHAR(60) NOT NULL,
    starting_number BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    padding_digits TINYINT UNSIGNED NOT NULL,
    asset_location VARCHAR(150) NULL,
    asset_condition VARCHAR(80) NULL,
    registration_type ENUM('available','reconciliation') NOT NULL,
    serial_mode ENUM('manual','automatic','fill_later') NOT NULL DEFAULT 'fill_later',
    serial_prefix VARCHAR(60) NULL,
    internal_serial_first_number BIGINT UNSIGNED NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    next_offset INT UNSIGNED NOT NULL DEFAULT 0,
    batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 250,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_asset_tag_jobs_work (status,id),
    INDEX idx_asset_tag_jobs_product (product_id,status),
    CONSTRAINT fk_asset_tag_job_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_asset_tag_job_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_tag_ranges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED NOT NULL,
    prefix VARCHAR(60) NOT NULL,
    first_number BIGINT UNSIGNED NOT NULL,
    last_number BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_tag_range_job (job_id),
    INDEX idx_asset_tag_range_prefix (prefix,first_number,last_number),
    CONSTRAINT fk_asset_tag_range_job FOREIGN KEY (job_id) REFERENCES asset_tag_jobs(id) ON DELETE RESTRICT,
    CONSTRAINT chk_asset_tag_range_order CHECK (last_number >= first_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_tag_prefix_sequences (
    prefix VARCHAR(60) NOT NULL PRIMARY KEY,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_tag_registry (
    asset_tag VARCHAR(100) NOT NULL PRIMARY KEY,
    prefix VARCHAR(60) NULL,
    numeric_value BIGINT UNSIGNED NULL,
    request_key CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_tag_registry_numeric (prefix,numeric_value)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    asset_tag VARCHAR(100) NOT NULL UNIQUE,
    serial_number VARCHAR(150) NULL UNIQUE,
    internal_serial_number VARCHAR(150) NULL UNIQUE,
    acquisition_unit_cost DECIMAL(13,2) NULL,
    acquisition_cost_layer_id BIGINT UNSIGNED NULL,
    status ENUM('available','assigned','reserved','maintenance','damaged','lost','returned','disposed') NOT NULL DEFAULT 'available',
    location VARCHAR(150) NULL,
    asset_condition VARCHAR(80) NULL,
    asset_tag_job_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inventory_asset_product_status (product_id,status),
    INDEX idx_inventory_asset_tag_job (asset_tag_job_id),
    CONSTRAINT fk_asset_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_inventory_asset_tag_job FOREIGN KEY (asset_tag_job_id) REFERENCES asset_tag_jobs(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_asset_cost_layer FOREIGN KEY (acquisition_cost_layer_id) REFERENCES inventory_cost_layers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS internal_serial_prefix_sequences (
    prefix VARCHAR(60) NOT NULL PRIMARY KEY,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS manufacturer_serial_registry (
    serial_number VARCHAR(150) NOT NULL PRIMARY KEY,
    job_id BIGINT UNSIGNED NULL,
    job_offset INT UNSIGNED NULL,
    asset_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manufacturer_serial_job_offset (job_id,job_offset),
    UNIQUE KEY uq_manufacturer_serial_asset (asset_id),
    INDEX idx_manufacturer_serial_job (job_id,job_offset),
    CONSTRAINT fk_manufacturer_serial_job FOREIGN KEY (job_id) REFERENCES asset_tag_jobs(id) ON DELETE RESTRICT,
    CONSTRAINT fk_manufacturer_serial_asset FOREIGN KEY (asset_id) REFERENCES inventory_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO asset_tag_registry (asset_tag, prefix, numeric_value, request_key)
    SELECT asset_tag, NULL, NULL, NULL FROM inventory_assets;

CREATE TABLE IF NOT EXISTS employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(100) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    department VARCHAR(150) NULL,
    location VARCHAR(150) NULL,
    email VARCHAR(190) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    returned_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    assigned_to VARCHAR(150) NOT NULL,
    employee_id VARCHAR(100) NULL,
    employee_record_id BIGINT UNSIGNED NULL,
    department VARCHAR(150) NULL,
    location VARCHAR(150) NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    returned_at DATETIME NULL,
    status ENUM('assigned','returned','cancelled','replaced','removed') NOT NULL DEFAULT 'assigned',
    condition_at_assignment VARCHAR(80) NULL,
    notes TEXT NULL,
    INDEX idx_assignment_product_tag_reconcile (product_id,status,returned_at,asset_id),
    INDEX idx_assignment_asset_status_returned (asset_id,status,returned_at),
    CONSTRAINT fk_assignment_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_assignment_asset FOREIGN KEY (asset_id) REFERENCES inventory_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_assignment_user FOREIGN KEY (assigned_by) REFERENCES users(id),
    CONSTRAINT fk_assignment_employee_record FOREIGN KEY (employee_record_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS active_hardware_assignments (
    asset_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    assignment_id BIGINT UNSIGNED NOT NULL UNIQUE,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_active_hardware_asset FOREIGN KEY (asset_id) REFERENCES inventory_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_active_hardware_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hardware_assignment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    action ENUM('assigned','replaced','returned','removed','reassigned','tagged') NOT NULL,
    reason TEXT NULL,
    old_asset_id BIGINT UNSIGNED NULL,
    new_asset_id BIGINT UNSIGNED NULL,
    performed_by BIGINT UNSIGNED NULL,
    transaction_id BIGINT UNSIGNED NULL,
    event_quantity INT UNSIGNED NULL,
    cost_covered_quantity INT UNSIGNED NULL,
    acquisition_value DECIMAL(13,2) NULL,
    performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hardware_history_employee (employee_id, performed_at),
    INDEX idx_hardware_history_transaction (transaction_id),
    CONSTRAINT fk_hardware_history_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_hardware_history_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id),
    CONSTRAINT fk_hardware_history_old_asset FOREIGN KEY (old_asset_id) REFERENCES inventory_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_hardware_history_new_asset FOREIGN KEY (new_asset_id) REFERENCES inventory_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_hardware_history_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_hardware_history_transaction FOREIGN KEY (transaction_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_cost_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cost_layer_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NULL,
    source_transaction_id BIGINT UNSIGNED NULL,
    source_type ENUM('assignment','stock_removal') NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    returned_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    unit_cost DECIMAL(13,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_cost_allocation_assignment (assignment_id, id),
    INDEX idx_inventory_cost_allocation_transaction (source_transaction_id),
    CONSTRAINT fk_inventory_cost_allocation_layer FOREIGN KEY (cost_layer_id) REFERENCES inventory_cost_layers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_cost_allocation_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_cost_allocation_transaction FOREIGN KEY (source_transaction_id) REFERENCES inventory_transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_cost_reconciliations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('receipt','asset','assignment','disposal') NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    source_transaction_id BIGINT UNSIGNED NULL,
    asset_id BIGINT UNSIGNED NULL,
    assignment_id BIGINT UNSIGNED NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(13,2) NOT NULL,
    source_reference VARCHAR(180) NOT NULL,
    notes TEXT NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cost_reconciliation_product (product_id, created_at),
    INDEX idx_cost_reconciliation_source (source_transaction_id),
    CONSTRAINT fk_cost_reconciliation_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_cost_reconciliation_transaction FOREIGN KEY (source_transaction_id) REFERENCES inventory_transactions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cost_reconciliation_asset FOREIGN KEY (asset_id) REFERENCES inventory_assets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cost_reconciliation_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cost_reconciliation_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE inventory_cost_layers
    ADD CONSTRAINT fk_inventory_cost_layer_reconciliation FOREIGN KEY (reconciliation_id) REFERENCES inventory_cost_reconciliations(id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS software_installations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    software_name VARCHAR(180) NOT NULL,
    version VARCHAR(100) NULL,
    license_type VARCHAR(100) NULL,
    installation_date DATE NULL,
    status ENUM('installed','uninstalled') NOT NULL DEFAULT 'installed',
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_software_employee_status (employee_id, status),
    CONSTRAINT fk_software_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_software_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS software_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    software_installation_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    action ENUM('added','edited','uninstalled') NOT NULL,
    details TEXT NULL,
    performed_by BIGINT UNSIGNED NULL,
    performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_software_history_employee (employee_id, performed_at),
    CONSTRAINT fk_software_history_installation FOREIGN KEY (software_installation_id) REFERENCES software_installations(id),
    CONSTRAINT fk_software_history_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_software_history_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,
    details TEXT NULL,
    ip_address VARCHAR(64) NULL,
    source_system VARCHAR(64) NULL,
    source_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Admin-managed RBAC configuration. Existing databases use the additive migration.
CREATE TABLE IF NOT EXISTS role_permission_defaults (
    role ENUM('editor','viewer') NOT NULL,
    module VARCHAR(64) NOT NULL,
    capability VARCHAR(64) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role, module, capability),
    CONSTRAINT chk_role_permission_allowed CHECK (allowed IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_permission_profiles (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    access_mode ENUM('full','custom','none') NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_permission_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permission_profile_actor FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id BIGINT UNSIGNED NOT NULL,
    module VARCHAR(64) NOT NULL,
    capability VARCHAR(64) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, module, capability),
    CONSTRAINT chk_user_permission_allowed CHECK (allowed IN (0,1)),
    CONSTRAINT fk_user_permission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO role_permission_defaults (role, module, capability, allowed) VALUES
('viewer','dashboard','view',1),('viewer','inventory','view',1),('viewer','products','view',1),
('viewer','categories','view',1),('viewer','assignments','view',1),('viewer','employees','view',1),
('viewer','hardware','view',1),('viewer','software','view',1),('viewer','orders','view',1),
('viewer','vendors','view',1),('viewer','history','view',1),('viewer','search','view',1),
('editor','dashboard','view',1),('editor','inventory','view',1),('editor','inventory','stock_adjustment',1),
('editor','products','view',1),('editor','products','add',1),('editor','products','edit',1),
('editor','products','status',0),
('editor','categories','view',1),('editor','categories','add',1),('editor','categories','edit',1),
('editor','categories','delete',1),('editor','categories','status',1),
('editor','assignments','view',1),('editor','assignments','add',1),('editor','assignments','edit',1),
('editor','assignments','delete',1),
('editor','employees','view',1),('editor','hardware','view',1),('editor','hardware','add',1),
('editor','hardware','edit',1),('editor','hardware','delete',1),('editor','hardware','hardware_assignment',1),
('editor','software','view',1),('editor','orders','view',1),('editor','vendors','view',1),
('editor','history','view',1),('editor','search','view',1);

-- Credential module schema (same DDL as migration 20261014_add_credentials_module.sql).
CREATE TABLE IF NOT EXISTS credential_departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS credential_department_access (
    user_id BIGINT UNSIGNED NOT NULL, department_id BIGINT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, department_id),
    CONSTRAINT fk_credential_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_access_department FOREIGN KEY (department_id) REFERENCES credential_departments(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_access_grantor FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NULL, department_id BIGINT UNSIGNED NULL, user_id BIGINT UNSIGNED NULL,
    software_installation_id BIGINT UNSIGNED NULL, service_name VARCHAR(180) NOT NULL,
    service_url VARCHAR(500) NULL, credential_username VARCHAR(190) NULL,
    credential_password_enc TEXT NOT NULL, notes TEXT NULL, created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL, active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    source_system VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_credential_source (source_system, source_id),
    INDEX idx_credentials_employee (employee_id, active), INDEX idx_credentials_department (department_id, active),
    INDEX idx_credentials_software (software_installation_id), INDEX idx_credentials_user (user_id),
    CONSTRAINT fk_credential_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_department FOREIGN KEY (department_id) REFERENCES credential_departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_software FOREIGN KEY (software_installation_id) REFERENCES software_installations(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_credential_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS credential_password_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, credential_id BIGINT UNSIGNED NOT NULL,
    password_enc TEXT NOT NULL, changed_by BIGINT UNSIGNED NULL, change_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, source_system VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_credential_version_source (source_system, source_id), INDEX idx_credential_versions (credential_id, created_at),
    CONSTRAINT fk_credential_version_credential FOREIGN KEY (credential_id) REFERENCES credentials(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_version_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS credential_access_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, credential_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL, action VARCHAR(80) NOT NULL, ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_system VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL, UNIQUE KEY uq_credential_log_source (source_system, source_id),
    INDEX idx_credential_access_log (credential_id, created_at), INDEX idx_credential_access_user (user_id, created_at),
    CONSTRAINT fk_credential_log_credential FOREIGN KEY (credential_id) REFERENCES credentials(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS credential_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, credential_id BIGINT UNSIGNED NOT NULL,
    storage_backend ENUM('local','s3') NOT NULL DEFAULT 'local', storage_key VARCHAR(700) NOT NULL,
    original_filename VARCHAR(255) NOT NULL, content_type VARCHAR(190) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL, uploaded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, source_system VARCHAR(64) NULL, source_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_credential_attachment_source (source_system, source_id), INDEX idx_credential_attachment (credential_id, created_at),
    CONSTRAINT fk_credential_attachment_credential FOREIGN KEY (credential_id) REFERENCES credentials(id) ON DELETE CASCADE,
    CONSTRAINT fk_credential_attachment_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS credential_migration_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_system VARCHAR(64) NOT NULL DEFAULT 'credential-manager',
    source_entity ENUM('employee','department','user','credential','password_version','access_log','audit_log','attachment') NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
    target_id BIGINT UNSIGNED NULL, status ENUM('pending','matched','manual','unmapped') NOT NULL DEFAULT 'pending',
    source_label VARCHAR(255) NULL, notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_credential_migration_source (source_system, source_entity, source_id),
    KEY idx_credential_migration_target (source_entity, target_id)
) ENGINE=InnoDB;
-- Permission defaults are deliberately absent; Admins grant each credential capability explicitly.
INSERT IGNORE INTO credential_departments(name) SELECT DISTINCT TRIM(department) FROM employees WHERE department IS NOT NULL AND TRIM(department)<>'';

DELIMITER //
CREATE TRIGGER trg_inventory_cost_reconciliation_immutable_update BEFORE UPDATE ON inventory_cost_reconciliations
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical cost reconciliations are immutable'; END//
CREATE TRIGGER trg_inventory_cost_reconciliation_immutable_delete BEFORE DELETE ON inventory_cost_reconciliations
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical cost reconciliations are immutable'; END//
DELIMITER ;
