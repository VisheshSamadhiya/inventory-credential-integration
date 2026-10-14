-- Enforce one active assignment per physical asset. This is a separate mapping
-- table because MariaDB cannot use the asset foreign-key column in this table's
-- generated-column expression. The mapping and assignment changes are written
-- in the same application transaction.
CREATE TABLE IF NOT EXISTS active_hardware_assignments (
    asset_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    assignment_id BIGINT UNSIGNED NOT NULL UNIQUE,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_active_hardware_asset FOREIGN KEY (asset_id) REFERENCES inventory_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_active_hardware_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_unique_active_hardware_assets//
CREATE PROCEDURE migrate_unique_active_hardware_assets()
BEGIN
    IF EXISTS (
        SELECT asset_id FROM assignments
        WHERE asset_id IS NOT NULL AND status = 'assigned'
        GROUP BY asset_id HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Duplicate active physical asset assignments exist; resolve them before adding the uniqueness guard.';
    END IF;

    INSERT IGNORE INTO active_hardware_assignments(asset_id, assignment_id, assigned_at)
    SELECT asset_id, id, assigned_at FROM assignments
    WHERE asset_id IS NOT NULL AND status = 'assigned';
END//
DELIMITER ;
CALL migrate_unique_active_hardware_assets();
DROP PROCEDURE migrate_unique_active_hardware_assets;
