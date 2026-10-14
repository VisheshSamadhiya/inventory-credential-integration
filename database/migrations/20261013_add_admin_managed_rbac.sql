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
('viewer','dashboard','view',1),
('viewer','inventory','view',1),
('viewer','products','view',1),
('viewer','categories','view',1),
('viewer','assignments','view',1),
('viewer','employees','view',1),
('viewer','hardware','view',1),
('viewer','software','view',1),
('viewer','orders','view',1),
('viewer','vendors','view',1),
('viewer','history','view',1),
('viewer','search','view',1),
('editor','dashboard','view',1),
('editor','inventory','view',1),
('editor','inventory','stock_adjustment',1),
('editor','products','view',1),
('editor','products','add',1),
('editor','products','edit',1),
('editor','products','status',0),
('editor','categories','view',1),
('editor','categories','add',1),
('editor','categories','edit',1),
('editor','categories','delete',1),
('editor','categories','status',1),
('editor','assignments','view',1),
('editor','assignments','add',1),
('editor','assignments','edit',1),
('editor','assignments','delete',1),
('editor','employees','view',1),
('editor','hardware','view',1),
('editor','hardware','add',1),
('editor','hardware','edit',1),
('editor','hardware','delete',1),
('editor','hardware','hardware_assignment',1),
('editor','software','view',1),
('editor','orders','view',1),
('editor','vendors','view',1),
('editor','history','view',1),
('editor','search','view',1);
