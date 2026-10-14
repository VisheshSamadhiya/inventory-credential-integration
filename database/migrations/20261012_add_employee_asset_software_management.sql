-- Idempotent employee profile, hardware event, and software history upgrade.
-- Existing employee and assignment rows remain intact.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_employee_asset_software_20261012//
CREATE PROCEDURE migrate_employee_asset_software_20261012()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='location') THEN
        ALTER TABLE employees ADD COLUMN location VARCHAR(150) NULL AFTER department;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='email') THEN
        ALTER TABLE employees ADD COLUMN email VARCHAR(190) NULL AFTER location;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments' AND COLUMN_NAME='condition_at_assignment') THEN
        ALTER TABLE assignments ADD COLUMN condition_at_assignment VARCHAR(80) NULL AFTER status;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments' AND COLUMN_NAME='employee_record_id') THEN
        ALTER TABLE assignments ADD COLUMN employee_record_id BIGINT UNSIGNED NULL AFTER employee_id;
    END IF;
    ALTER TABLE assignments MODIFY COLUMN status ENUM('assigned','returned','cancelled','replaced','removed') NOT NULL DEFAULT 'assigned';
    UPDATE assignments a JOIN employees e ON e.employee_id=a.employee_id
       SET a.employee_record_id=e.id WHERE a.employee_record_id IS NULL;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='assignments' AND CONSTRAINT_NAME='fk_assignment_employee_record') THEN
        ALTER TABLE assignments ADD CONSTRAINT fk_assignment_employee_record FOREIGN KEY (employee_record_id) REFERENCES employees(id) ON DELETE SET NULL;
    END IF;

    CREATE TABLE IF NOT EXISTS hardware_assignment_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT UNSIGNED NOT NULL,
        assignment_id BIGINT UNSIGNED NOT NULL,
        action ENUM('assigned','replaced','returned','removed') NOT NULL,
        reason TEXT NULL,
        old_asset_id BIGINT UNSIGNED NULL,
        new_asset_id BIGINT UNSIGNED NULL,
        performed_by BIGINT UNSIGNED NULL,
        performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hardware_history_employee (employee_id, performed_at),
        CONSTRAINT fk_hardware_history_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
        CONSTRAINT fk_hardware_history_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id),
        CONSTRAINT fk_hardware_history_old_asset FOREIGN KEY (old_asset_id) REFERENCES inventory_assets(id) ON DELETE SET NULL,
        CONSTRAINT fk_hardware_history_new_asset FOREIGN KEY (new_asset_id) REFERENCES inventory_assets(id) ON DELETE SET NULL,
        CONSTRAINT fk_hardware_history_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;

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
END//
DELIMITER ;
CALL migrate_employee_asset_software_20261012();
DROP PROCEDURE migrate_employee_asset_software_20261012;
