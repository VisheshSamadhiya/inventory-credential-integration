<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/credential_totp_helpers.php';
// Validate and refresh the authenticated user's role before the edit-only gate.
// Otherwise a stale session role can produce a false 403 before requirePermission()
// gets a chance to validate the session against the current account record.
requireLogin();
$createPageRequested = (($usersPageMode ?? '') === 'create');
if (array_key_exists('edit_user', $_GET) && !canAdmin()) {
    http_response_code(403);
    exit('Forbidden');
}
requirePermission('users', 'view');
if ($createPageRequested) requirePermission('users', 'add');
$pageTitle = 'Users and permissions';
$db = db();
$catalog = permissionCatalog();
$generalCatalog = array_diff_key($catalog, ['credentials' => true, 'credential_audit' => true]);
$labels = permissionLabels();
$generalCapabilities = array_values(array_unique(array_merge(...array_values(array_map(static fn(array $entry): array => $entry['permissions'], $generalCatalog)))));
$credentialPermissionFields = [
    ['credentials', 'view', 'Allow access to Credentials module', 'Includes permission to view credential records.'],
    ['credentials', 'reveal', 'Reveal passwords', 'Allow secure password reveal for accessible records.'],
    ['credentials', 'create', 'Add credentials', 'Create new credential records.'],
    ['credentials', 'edit', 'Edit credentials', 'Update accessible credential records.'],
    ['credentials', 'delete', 'Delete credentials', 'Remove credential records from active use.'],
    ['credentials', 'audit', 'View credential access logs', 'View per-credential access events.'],
    ['credential_audit', 'view', 'View credential audit log', 'View the full credential audit log.'],
    ['credential_audit', 'export', 'Export credential audit records', 'Download authorized credential audit records.'],
    ['credentials', 'manage_access', 'Manage department credential access', 'Grant or revoke department access for users.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'create_user');
    if (in_array($action, ['edit_user', 'reset_credential_totp', 'disable_credential_totp', 'deactivate_user', 'reactivate_user'], true) && !canAdmin()) {
        http_response_code(403);
        exit('Forbidden');
    }
    requirePermission($action === 'create_user' ? 'users' : 'permission_management', $action === 'create_user' ? 'add' : 'edit');
    verifyCsrf();
    $editTargetId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $editSubmittedValues = [];
    if ($action === 'edit_user') {
        $submittedPermissions = is_array($_POST['user_permissions'] ?? null) ? $_POST['user_permissions'] : [];
        $safePermissions = [];
        foreach ($catalog as $module => $definition) {
            $moduleValues = isset($submittedPermissions[$module]) && is_array($submittedPermissions[$module]) ? $submittedPermissions[$module] : [];
            $moduleValues = array_values(array_filter($moduleValues, 'is_string'));
            $safePermissions[$module] = array_values(array_intersect($definition['permissions'], $moduleValues));
        }
        $editSubmittedValues = [
            'full_name' => (string)($_POST['full_name'] ?? ''),
            'username' => (string)($_POST['username'] ?? ''),
            'email' => (string)($_POST['email'] ?? ''),
            'role' => (string)($_POST['role'] ?? ''),
            'active' => (string)($_POST['active'] ?? ''),
            'access_mode' => (string)($_POST['access_mode'] ?? 'role'),
            'credential_totp_required' => (string)($_POST['credential_totp_required'] ?? '0'),
            'user_permissions' => $safePermissions,
        ];
    }
    $preserveEditInput = static function () use (&$action, &$editTargetId, &$editSubmittedValues): void {
        if ($action === 'edit_user' && $editTargetId > 0) {
            $_SESSION['user_edit_form_state'] = ['target_user_id' => $editTargetId, 'values' => $editSubmittedValues];
        }
    };
    try {
        if (in_array($action, ['deactivate_user', 'reactivate_user'], true)) {
            if (!canAdmin()) throw new DomainException('Only an Admin can change user account status.');
            $targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$targetUserId || ($_POST['confirm_account_status'] ?? '') !== '1') {
                throw new DomainException('Confirm the selected account status change.');
            }
            if ($action === 'deactivate_user' && (int)$targetUserId === (int)currentUser()['id']) {
                throw new DomainException('You cannot deactivate the account you are currently using.');
            }

            $db->beginTransaction();
            $activeAdmins = $db->query("SELECT id FROM users WHERE role='admin' AND active=1 FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            $lockTarget = $db->prepare('SELECT id, full_name, username, role, active FROM users WHERE id=? FOR UPDATE');
            $lockTarget->execute([$targetUserId]);
            $target = $lockTarget->fetch();
            if (!$target) throw new DomainException('The selected user account was not found.');

            if ($action === 'deactivate_user') {
                if (!(int)$target['active']) throw new DomainException('This user account is already inactive.');
                assertActiveAdminRetained($activeAdmins, (int)$targetUserId, (string)$target['role'], 0);
                $db->prepare('UPDATE users SET active=0, auth_version=auth_version+1 WHERE id=?')->execute([$targetUserId]);
                $auditAction = 'user_deactivated';
                $successMessage = 'User account deactivated. Existing sessions will be rejected on their next request.';
            } else {
                if ((int)$target['active']) throw new DomainException('This user account is already active.');
                $db->prepare('UPDATE users SET active=1, auth_version=auth_version+1 WHERE id=?')->execute([$targetUserId]);
                $auditAction = 'user_reactivated';
                $successMessage = 'User account reactivated. The user can sign in again.';
            }

            audit($auditAction, 'user', (int)$targetUserId, json_encode([
                'actor_id' => (int)currentUser()['id'],
                'target_user_id' => (int)$targetUserId,
                'target_username' => (string)$target['username'],
                'action' => $auditAction,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $db->commit();
            setFlash('success', $successMessage);
            header('Location: users.php');
            exit;
        }

        if ($action === 'save_role_defaults') {
            $role = (string)($_POST['role'] ?? '');
            if (!in_array($role, ['editor', 'viewer'], true)) throw new DomainException('Admin role defaults are fixed and cannot be changed here.');
            $submitted = is_array($_POST['role_permissions'] ?? null) ? $_POST['role_permissions'] : [];
            $db->beginTransaction();
            $lockDefaults = $db->prepare('SELECT role FROM role_permission_defaults WHERE role = ? LIMIT 1 FOR UPDATE');
            $lockDefaults->execute([$role]);
            $lockDefaults->fetchColumn();
            $oldPermissions = permissionDefaults($role);
            $newPermissions = [];
            foreach ($catalog as $module => $definition) {
                foreach ($definition['permissions'] as $capability) {
                    $newPermissions[$module][$capability] = isset($submitted[$module]) && is_array($submitted[$module]) && in_array($capability, $submitted[$module], true);
                }
            }

            $db->prepare('DELETE FROM role_permission_defaults WHERE role = ?')->execute([$role]);
            $save = $db->prepare('INSERT INTO role_permission_defaults(role,module,capability,allowed) VALUES(?,?,?,?)');
            foreach ($newPermissions as $module => $permissions) {
                foreach ($permissions as $capability => $allowed) $save->execute([$role, $module, $capability, $allowed ? 1 : 0]);
            }
            audit('role_permissions_updated', 'role_permissions', null, json_encode([
                'actor_id' => (int)currentUser()['id'], 'role' => $role,
                'old_permissions' => $oldPermissions, 'new_permissions' => $newPermissions,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $db->commit();
            setFlash('success', ucfirst($role) . ' default permissions saved. They apply to users inheriting role defaults on their next request.');
            header('Location: users.php');
            exit;
        }

        if ($action === 'save_user_permissions') {
            $targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $mode = (string)($_POST['access_mode'] ?? '');
            if (!$targetUserId || !in_array($mode, ['role', 'full', 'custom', 'none'], true)) throw new DomainException('Choose a valid user and access mode.');
            $db->beginTransaction();
            $lockUser = $db->prepare('SELECT id,full_name,username,role,active FROM users WHERE id=? FOR UPDATE');
            $lockUser->execute([$targetUserId]);
            $targetUser = $lockUser->fetch();
            if (!$targetUser) throw new DomainException('The selected user was not found.');
            if ($targetUser['role'] === 'admin') throw new DomainException('Admin access is fixed and cannot be reduced or customized.');

            $oldMode = userPermissionProfile((int)$targetUserId);
            $oldPermissions = effectivePermissionMatrix($targetUser);
            $oldMode ??= 'role';
            if ($mode === 'role') {
                $db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$targetUserId]);
                $db->prepare('DELETE FROM user_permission_profiles WHERE user_id=?')->execute([$targetUserId]);
                $newPermissions = permissionDefaults((string)$targetUser['role']);
            } elseif ($mode === 'full' || $mode === 'none') {
                $profile = $db->prepare('INSERT INTO user_permission_profiles(user_id,access_mode,changed_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE access_mode=VALUES(access_mode),changed_by=VALUES(changed_by)');
                $profile->execute([$targetUserId, $mode, currentUser()['id']]);
                $db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$targetUserId]);
                $newPermissions = [];
                foreach ($catalog as $module => $definition) {
                    foreach ($definition['permissions'] as $capability) $newPermissions[$module][$capability] = $mode === 'full';
                }
            } else {
                $submitted = is_array($_POST['user_permissions'] ?? null) ? $_POST['user_permissions'] : [];
                $newPermissions = [];
                foreach ($catalog as $module => $definition) {
                    foreach ($definition['permissions'] as $capability) {
                        $newPermissions[$module][$capability] = isset($submitted[$module]) && is_array($submitted[$module]) && in_array($capability, $submitted[$module], true);
                    }
                }
                $profile = $db->prepare('INSERT INTO user_permission_profiles(user_id,access_mode,changed_by) VALUES(?,\'custom\',?) ON DUPLICATE KEY UPDATE access_mode=\'custom\',changed_by=VALUES(changed_by)');
                $profile->execute([$targetUserId, currentUser()['id']]);
                $db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$targetUserId]);
                $save = $db->prepare('INSERT INTO user_permissions(user_id,module,capability,allowed) VALUES(?,?,?,?)');
                foreach ($newPermissions as $module => $permissions) {
                    foreach ($permissions as $capability => $allowed) $save->execute([$targetUserId, $module, $capability, $allowed ? 1 : 0]);
                }
            }

            if ($oldMode !== $mode || $oldPermissions !== $newPermissions) {
                $db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$targetUserId]);
            }

            audit('user_permissions_updated', 'user', (int)$targetUserId, json_encode([
                'actor_id' => (int)currentUser()['id'], 'target_user_id' => (int)$targetUserId,
                'target_username' => $targetUser['username'],
                'old_mode' => $oldMode ?? 'role', 'old_permissions' => $oldPermissions,
                'new_mode' => $mode, 'new_permissions' => $newPermissions,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $db->commit();
            setFlash('success', 'User permissions saved. The change takes effect on the user’s next authenticated request.');
            header('Location: users.php?permissions_user=' . (int)$targetUserId . '#user-permissions');
            exit;
        }

        if ($action === 'reset_credential_totp') {
            if (!canAdmin()) throw new DomainException('Only an Admin can reset credential 2FA enrollment.');
            $targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$targetUserId || ($_POST['confirm_reset'] ?? '') !== '1') throw new DomainException('Confirm the 2FA enrollment reset.');
            $db->beginTransaction();
            $target = $db->prepare('SELECT id,username FROM users WHERE id=? FOR UPDATE');
            $target->execute([$targetUserId]);
            $targetUser = $target->fetch();
            if (!$targetUser) throw new DomainException('The selected user account was not found.');
            $totp = credentialTotpState((int)$targetUserId, true);
            if (!(int)$totp['required']) throw new DomainException('Credential 2FA is not required for this user.');
            $hasEnrollment = (($totp['status'] ?? null) === 'active')
                || (($totp['status'] ?? null) === 'pending' && is_string($totp['pending_secret_enc'] ?? null) && $totp['pending_secret_enc'] !== '');
            if (!$hasEnrollment) throw new DomainException('There is no authenticator enrollment to reset.');
            credentialTotpResetEnrollment((int)$targetUserId, (int)currentUser()['id']);
            audit('credential_totp_enrollment_reset', 'user', (int)$targetUserId, json_encode([
                'actor_id' => (int)currentUser()['id'], 'target_user_id' => (int)$targetUserId,
                'target_username' => $targetUser['username'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $db->commit();
            setFlash('success', 'Credential 2FA enrollment reset. The user must set up a new authenticator.');
            header('Location: users.php?edit_user=' . (int)$targetUserId);
            exit;
        }

        if ($action === 'disable_credential_totp') {
            if (!canAdmin()) throw new DomainException('Only an Admin can disable credential 2FA.');
            $targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$targetUserId || ($_POST['confirm_disable'] ?? '') !== '1') throw new DomainException('Confirm disabling credential 2FA.');
            $db->beginTransaction();
            $target = $db->prepare('SELECT id,username FROM users WHERE id=? FOR UPDATE');
            $target->execute([$targetUserId]);
            $targetUser = $target->fetch();
            if (!$targetUser) throw new DomainException('The selected user account was not found.');
            $totp = credentialTotpState((int)$targetUserId, true);
            if (!(int)$totp['required']) throw new DomainException('Credential 2FA is already disabled for this user.');
            credentialTotpSetRequired((int)$targetUserId, false, (int)currentUser()['id'], $totp);
            $db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$targetUserId]);
            audit('credential_totp_requirement_changed', 'user', (int)$targetUserId, json_encode([
                'actor_id' => (int)currentUser()['id'], 'target_user_id' => (int)$targetUserId,
                'target_username' => $targetUser['username'], 'required' => false,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $db->commit();
            unset($_SESSION['credential_totp_verified']);
            setFlash('success', 'Credential TOTP requirement disabled and enrollment removed.');
            header('Location: users.php?edit_user=' . (int)$targetUserId);
            exit;
        }

        if ($action === 'edit_user') {
            $targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $name = trim((string)($_POST['full_name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $role = (string)($_POST['role'] ?? '');
            $activeInput = (string)($_POST['active'] ?? '');
            $password = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            $mode = (string)($_POST['access_mode'] ?? 'role');
            $credentialTotpRequired = canAdmin() && (string)($_POST['credential_totp_required'] ?? '0') === '1';
            if (!$targetUserId) throw new DomainException('Choose a valid user account.');
            if ($name === '' || $username === '') throw new DomainException('Full name and username are required.');
            if (strlen($name) > 150 || strlen($username) > 100) throw new DomainException('Full name or username is too long.');
            if (strlen($email) > 190 || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) throw new DomainException('Enter a valid email address or leave it blank.');
            if (!preg_match('/^[^\s]{1,100}$/u', $username)) throw new DomainException('Username cannot contain spaces.');
            if (!in_array($role, ['admin', 'editor', 'viewer'], true)) throw new DomainException('Choose a valid role.');
            if (!in_array($activeInput, ['0', '1'], true)) throw new DomainException('Choose a valid account status.');
            if ($activeInput === '0' && (int)$targetUserId === (int)currentUser()['id']) throw new DomainException('You cannot deactivate the account you are currently using.');
            if (!in_array($mode, ['role', 'full', 'custom', 'none'], true)) throw new DomainException('Choose a valid access mode.');
            if (($password === '') !== ($confirmPassword === '')) throw new DomainException('Enter and confirm the new password, or leave both password fields empty.');
            if ($password !== '') {
                $passwordLength = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
                if ($passwordLength < 12) throw new DomainException('New passwords must be at least 12 characters long.');
                if (!hash_equals($password, $confirmPassword)) throw new DomainException('New password and confirmation do not match.');
            }

            $db->beginTransaction();
            // Serialize changes to active Admin accounts so concurrent requests cannot
            // deactivate or demote the final active administrator.
            $activeAdmins = $db->query("SELECT id FROM users WHERE role='admin' AND active=1 FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            $lockUser = $db->prepare('SELECT id, full_name, username, email, role, active, auth_version FROM users WHERE id=? FOR UPDATE');
            $lockUser->execute([$targetUserId]);
            $oldUser = $lockUser->fetch();
            if (!$oldUser) throw new DomainException('The selected user account was not found.');
            $active = (int)$activeInput;
            assertActiveAdminRetained($activeAdmins, (int)$targetUserId, $role, $active);
            $oldTotpState = credentialTotpState((int)$targetUserId, true);
            $oldTotpRequired = (bool)$oldTotpState['required'];
            $newTotpRequired = canAdmin() ? $credentialTotpRequired : $oldTotpRequired;

            $oldMode = $oldUser['role'] === 'admin' ? 'fixed' : (userPermissionProfile((int)$targetUserId) ?? 'role');
            $oldPermissions = effectivePermissionMatrix($oldUser);
            if ($role === 'admin') {
                // Admin rights are fixed by role and cannot be customized.
                $db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$targetUserId]);
                $db->prepare('DELETE FROM user_permission_profiles WHERE user_id=?')->execute([$targetUserId]);
                $newMode = 'fixed';
                $newPermissions = buildEffectivePermissionMatrix($catalog, [], null, [], true);
            } elseif ($mode === 'role') {
                $db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$targetUserId]);
                $db->prepare('DELETE FROM user_permission_profiles WHERE user_id=?')->execute([$targetUserId]);
                $newMode = 'role';
                $newPermissions = permissionDefaults($role);
            } elseif ($mode === 'full' || $mode === 'none') {
                $profile = $db->prepare('INSERT INTO user_permission_profiles(user_id,access_mode,changed_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE access_mode=VALUES(access_mode),changed_by=VALUES(changed_by)');
                $profile->execute([$targetUserId, $mode, currentUser()['id']]);
                $db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$targetUserId]);
                $newMode = $mode;
                $newPermissions = buildEffectivePermissionMatrix($catalog, [], $mode, []);
            } else {
                $submitted = is_array($_POST['user_permissions'] ?? null) ? $_POST['user_permissions'] : [];
                $newPermissions = [];
                foreach ($catalog as $module => $definition) {
                    foreach ($definition['permissions'] as $capability) {
                        $newPermissions[$module][$capability] = isset($submitted[$module]) && is_array($submitted[$module]) && in_array($capability, $submitted[$module], true);
                    }
                }
                $profile = $db->prepare('INSERT INTO user_permission_profiles(user_id,access_mode,changed_by) VALUES(?,\'custom\',?) ON DUPLICATE KEY UPDATE access_mode=\'custom\',changed_by=VALUES(changed_by)');
                $profile->execute([$targetUserId, currentUser()['id']]);
                $db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$targetUserId]);
                $save = $db->prepare('INSERT INTO user_permissions(user_id,module,capability,allowed) VALUES(?,?,?,?)');
                foreach ($newPermissions as $module => $permissions) {
                    foreach ($permissions as $capability => $allowed) $save->execute([$targetUserId, $module, $capability, $allowed ? 1 : 0]);
                }
                $newMode = 'custom';
            }

            $passwordChanged = $password !== '';
            $permissionsChanged = $oldMode !== $newMode || $oldPermissions !== $newPermissions;
            $securityChanged = $passwordChanged
                || $username !== $oldUser['username']
                || $role !== $oldUser['role']
                || $active !== (int)$oldUser['active']
                || $permissionsChanged
                || $oldTotpRequired !== $newTotpRequired;
            $updateSql = 'UPDATE users SET full_name=?, username=?, email=?, role=?, active=?, auth_version=auth_version+?';
            $updateValues = [$name, $username, $email !== '' ? $email : null, $role, $active, $securityChanged ? 1 : 0];
            if ($passwordChanged) {
                $updateSql .= ', password_hash=?';
                $updateValues[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $updateSql .= ' WHERE id=?';
            $updateValues[] = $targetUserId;
            $db->prepare($updateSql)->execute($updateValues);

            if ($oldTotpRequired !== $newTotpRequired) {
                credentialTotpSetRequired((int)$targetUserId, $newTotpRequired, (int)currentUser()['id'], $oldTotpState);
                audit('credential_totp_requirement_changed', 'user', (int)$targetUserId, json_encode([
                    'actor_id' => (int)currentUser()['id'], 'target_user_id' => (int)$targetUserId,
                    'target_username' => $username, 'required' => $newTotpRequired,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $newUserDetails = ['full_name' => $name, 'username' => $username, 'role' => $role, 'active' => (bool)$active];
            $oldUserDetails = ['full_name' => $oldUser['full_name'], 'username' => $oldUser['username'], 'role' => $oldUser['role'], 'active' => (bool)$oldUser['active']];
            if ($oldUserDetails !== $newUserDetails) {
                audit('user_updated', 'user', (int)$targetUserId, json_encode([
                    'actor_id' => (int)currentUser()['id'], 'target_user_id' => (int)$targetUserId,
                    'old_user' => $oldUserDetails, 'new_user' => $newUserDetails,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            if (($oldUser['email'] ?? null) !== ($email !== '' ? $email : null)) audit('user_email_updated', 'user', (int)$targetUserId, 'Notification email updated');
            if ($passwordChanged) {
                audit('user_password_reset', 'user', (int)$targetUserId, json_encode([
                    'actor_id' => (int)currentUser()['id'], 'target_user_id' => (int)$targetUserId,
                    'target_username' => $username,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            if ($oldMode !== $newMode || $oldPermissions !== $newPermissions) {
                audit('user_permissions_updated', 'user', (int)$targetUserId, json_encode([
                    'actor_id' => (int)currentUser()['id'], 'target_user_id' => (int)$targetUserId,
                    'target_username' => $username, 'old_mode' => $oldMode,
                    'old_permissions' => $oldPermissions, 'new_mode' => $newMode,
                    'new_permissions' => $newPermissions,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $db->commit();
            unset($_SESSION['user_edit_form_state']);
            setFlash('success', 'User account updated successfully.' . ($securityChanged ? ' The user must sign in again on their next request.' : ''));
            header('Location: users.php?edit_user=' . (int)$targetUserId);
            exit;
        }

        if ($action !== 'create_user') throw new DomainException('Choose a valid user operation.');
        $name = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? '');
        $credentialTotpRequired = canAdmin() && (string)($_POST['credential_totp_required'] ?? '0') === '1';
        $credentialAccessMode = canAdmin() ? (string)($_POST['credential_access_mode'] ?? 'role') : 'role';
        if ($name === '' || $username === '') throw new DomainException('Please complete the name and username fields.');
        if (strlen($name) > 150 || strlen($username) > 100) throw new DomainException('Name or username is too long.');
        if (strlen($email) > 190 || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) throw new DomainException('Enter a valid email address or leave it blank.');
        if (strlen($password) < 12) throw new DomainException('Password must be at least 12 characters.');
        if (!in_array($role, ['admin', 'editor', 'viewer'], true)) throw new DomainException('Please select a valid role.');
        if (!in_array($credentialAccessMode, ['role', 'custom'], true)) throw new DomainException('Choose a valid credential access setting.');
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO users(full_name, username, email, password_hash, role) VALUES(?, ?, ?, ?, ?)');
        $stmt->execute([$name, $username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $role]);
        $userId = (int)$db->lastInsertId();
        if ($credentialAccessMode === 'custom' && $role !== 'admin') {
            $oldPermissions = permissionDefaults($role);
            $newPermissions = $oldPermissions;
            $submitted = is_array($_POST['user_permissions'] ?? null) ? $_POST['user_permissions'] : [];
            foreach (['credentials', 'credential_audit'] as $module) {
                foreach ($catalog[$module]['permissions'] as $capability) {
                    $values = isset($submitted[$module]) && is_array($submitted[$module]) ? $submitted[$module] : [];
                    $newPermissions[$module][$capability] = in_array($capability, $values, true);
                }
            }
            $profile = $db->prepare("INSERT INTO user_permission_profiles(user_id,access_mode,changed_by) VALUES(?,'custom',?)");
            $profile->execute([$userId, (int)currentUser()['id']]);
            $savePermission = $db->prepare('INSERT INTO user_permissions(user_id,module,capability,allowed) VALUES(?,?,?,?)');
            foreach ($newPermissions as $module => $permissions) {
                foreach ($permissions as $capability => $allowed) $savePermission->execute([$userId, $module, $capability, $allowed ? 1 : 0]);
            }
            audit('user_permissions_updated', 'user', $userId, json_encode([
                'actor_id' => (int)currentUser()['id'], 'target_user_id' => $userId,
                'target_username' => $username, 'old_mode' => 'role', 'old_permissions' => $oldPermissions,
                'new_mode' => 'custom', 'new_permissions' => $newPermissions,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        if ($credentialTotpRequired) {
            credentialTotpSetRequired($userId, true, (int)currentUser()['id']);
            audit('credential_totp_requirement_changed', 'user', $userId, json_encode([
                'actor_id' => (int)currentUser()['id'], 'target_user_id' => $userId,
                'target_username' => $username, 'required' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        audit('create', 'user', $userId, $username . ' (' . $role . ')');
        $db->commit();
        setFlash('success', $credentialAccessMode === 'custom'
            ? 'User created successfully with a custom credential access profile.'
            : 'User created successfully. The user receives their role’s current default permissions.');
        $createdUserId = $userId;
        $_SESSION['newly_created_user_id'] = $userId;
    } catch (DomainException $exception) {
        if ($db->inTransaction()) $db->rollBack();
        $preserveEditInput();
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($db->inTransaction()) $db->rollBack();
        $preserveEditInput();
        error_log('User or permission save failed: ' . $exception->getMessage());
        setFlash('error', (int)($exception->errorInfo[1] ?? 0) === 1062 ? 'That username or permission record is already in use.' : 'Unable to save the change. Please try again.');
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        $preserveEditInput();
        error_log('User or permission save failed: ' . $exception->getMessage());
        setFlash('error', 'Unable to save the change. Please try again.');
    }
    if ($action === 'edit_user' && $editTargetId > 0) {
        header('Location: users.php?edit_user=' . $editTargetId);
    } elseif ($action === 'create_user' && isset($createdUserId) && $createdUserId > 0) {
        header('Location: users.php?edit_user=' . $createdUserId);
    } elseif ($action === 'create_user') {
        header('Location: create-user.php');
    } else {
        header('Location: users.php');
    }
    exit;
}

$users = $db->query('SELECT id, full_name, username, email, role, active, created_at FROM users ORDER BY id')->fetchAll();
$credentialRoleDefaults = [
    'admin' => buildEffectivePermissionMatrix($catalog, [], null, [], true),
    'editor' => permissionDefaults('editor'),
    'viewer' => permissionDefaults('viewer'),
];
$credentialTotpStates = [];
try {
    foreach ($db->query('SELECT user_id,required,status,updated_at FROM user_credential_totp')->fetchAll() as $totpRow) {
        $credentialTotpStates[(int)$totpRow['user_id']] = $totpRow;
    }
} catch (Throwable $exception) {
    error_log('User credential TOTP statuses unavailable: ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine());
    http_response_code(503);
    exit('User security settings are temporarily unavailable.');
}
$editRequested = array_key_exists('edit_user', $_GET);
$rawEditUserId = $_GET['edit_user'] ?? null;
$selectedUserId = $editRequested && (is_string($rawEditUserId) || is_int($rawEditUserId))
    ? (filter_var($rawEditUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0)
    : 0;
$selectedUser = null;
foreach ($users as $user) if ((int)$user['id'] === $selectedUserId) { $selectedUser = $user; break; }
$savedEditState = $_SESSION['user_edit_form_state'] ?? null;
if (!$selectedUser || !is_array($savedEditState) || (int)($savedEditState['target_user_id'] ?? 0) !== (int)$selectedUser['id']) {
    unset($_SESSION['user_edit_form_state']);
    $savedEditState = null;
}
if ($editRequested && !$selectedUser) setFlash('error', 'The selected user account was not found.');
$editFormValues = [];
if ($selectedUser) {
    $editFormValues = [
        'full_name' => (string)$selectedUser['full_name'],
        'username' => (string)$selectedUser['username'],
        'email' => (string)($selectedUser['email'] ?? ''),
        'role' => (string)$selectedUser['role'],
        'active' => (string)(int)$selectedUser['active'],
        'access_mode' => $selectedUser['role'] === 'admin' ? 'fixed' : (userPermissionProfile((int)$selectedUser['id']) ?? 'role'),
        'user_permissions' => [],
    ];
    if ($savedEditState) $editFormValues = array_replace($editFormValues, $savedEditState['values'] ?? []);
    $selectedTotpState = credentialTotpState((int)$selectedUser['id']);
    $selectedHasTotpEnrollment = (($selectedTotpState['status'] ?? null) === 'active')
        || (($selectedTotpState['status'] ?? null) === 'pending' && is_string($selectedTotpState['pending_secret_enc'] ?? null) && $selectedTotpState['pending_secret_enc'] !== '');
    $selectedPendingEnrollmentReady = ($selectedTotpState['status'] ?? null) === 'pending'
        && !credentialTotpPendingExpired($selectedTotpState)
        && is_string($selectedTotpState['pending_secret_enc'] ?? null)
        && $selectedTotpState['pending_secret_enc'] !== '';
    if (!$savedEditState || !array_key_exists('credential_totp_required', $savedEditState['values'] ?? [])) {
        $editFormValues['credential_totp_required'] = (string)(int)$selectedTotpState['required'];
    }
    $pageTitle = 'Edit User — ' . (string)$selectedUser['full_name'];
    $showNewUserNotice = (int)($_SESSION['newly_created_user_id'] ?? 0) === (int)$selectedUser['id'];
    if ($showNewUserNotice) unset($_SESSION['newly_created_user_id']);
    $pageHeadingInTemplate = true;
    $suppressBackButton = true;
} elseif ($createPageRequested) {
    $pageTitle = 'Create User';
    $pageHeadingInTemplate = true;
    $suppressBackButton = true;
} else {
    $pageTitle = 'Users & Permissions';
}
$userMode = $selectedUser ? ($editFormValues['role'] === 'admin' ? 'fixed' : ((string)$editFormValues['access_mode'] === 'fixed' ? 'role' : (string)$editFormValues['access_mode'])) : 'role';
$userPermissionMatrix = $selectedUser ? effectivePermissionMatrix($selectedUser) : [];
if ($selectedUser && $editFormValues['role'] === 'admin') {
    $userPermissionMatrix = $credentialRoleDefaults['admin'];
} elseif ($selectedUser) {
    if (!in_array($userMode, ['role', 'full', 'custom', 'none'], true)) $userMode = 'role';
    if ($userMode === 'role') $userPermissionMatrix = permissionDefaults((string)$editFormValues['role']);
    elseif ($userMode === 'full' || $userMode === 'none') $userPermissionMatrix = buildEffectivePermissionMatrix($catalog, [], $userMode, []);
}
if ($selectedUser && !$savedEditState && $userMode === 'custom') {
    foreach ($catalog as $module => $definition) {
        foreach ($definition['permissions'] as $capability) {
            if (!empty($userPermissionMatrix[$module][$capability])) $editFormValues['user_permissions'][$module][] = $capability;
        }
    }
}
if ($selectedUser && $userMode === 'custom' && is_array($editFormValues['user_permissions'] ?? null)) {
    foreach ($catalog as $module => $definition) {
        $selectedCapabilities = is_array($editFormValues['user_permissions'][$module] ?? null) ? $editFormValues['user_permissions'][$module] : [];
        foreach ($definition['permissions'] as $capability) $userPermissionMatrix[$module][$capability] = in_array($capability, $selectedCapabilities, true);
    }
}

function renderPermissionMatrix(string $inputName, array $matrix, array $catalog, array $capabilities, array $labels, bool $disabled = false): void {
    ?>
    <div class="permission-matrix-scroll" data-permission-scroll>
    <div class="permission-horizontal-scroll permission-horizontal-scroll-top" data-permission-horizontal tabindex="0" role="region" aria-label="Scroll permission columns horizontally"><div class="permission-scroll-spacer" data-permission-spacer aria-hidden="true"></div></div>
    <div class="table-scroll permission-table-scroll" data-permission-viewport tabindex="0" role="region" aria-label="Permission matrix; scroll to view all modules and permissions"><table class="permissions-table"><thead><tr><th scope="col">Module</th><?php foreach ($capabilities as $capability): ?><th scope="col"><?=htmlspecialchars($labels[$capability] ?? $capability)?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($catalog as $module => $definition): ?><tr><th scope="row"><?=htmlspecialchars($definition['label'])?></th><?php foreach ($capabilities as $capability): ?><td><?php if (in_array($capability, $definition['permissions'], true)): $id = $inputName . '-' . $module . '-' . $capability; ?><input type="checkbox" id="<?=htmlspecialchars($id)?>" name="<?=htmlspecialchars($inputName)?>[<?=htmlspecialchars($module)?>][]" value="<?=htmlspecialchars($capability)?>" <?=!empty($matrix[$module][$capability])?'checked':''?> <?=$disabled?'disabled':''?>><label class="visually-hidden" for="<?=htmlspecialchars($id)?>"><?=htmlspecialchars($definition['label'] . ' — ' . ($labels[$capability] ?? $capability))?></label><?php else: ?><span class="muted">—</span><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="permission-horizontal-scroll permission-horizontal-scroll-bottom" data-permission-horizontal tabindex="0" role="region" aria-label="Scroll permission columns horizontally"><div class="permission-scroll-spacer" data-permission-spacer aria-hidden="true"></div></div>
    </div>
    <?php
}

function renderCredentialPermissionControls(array $fields, array $matrix, array $roleDefaults, bool $disabled, string $inputName = 'user_permissions'): void {
    ?>
    <div class="credential-access-controls">
        <?php foreach ($fields as [$module, $capability, $label, $description]): $inputId = 'credential-permission-' . $module . '-' . $capability; ?>
        <label class="credential-access-option" for="<?=htmlspecialchars($inputId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
            <input id="<?=htmlspecialchars($inputId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" type="checkbox" name="<?=htmlspecialchars($inputName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>[<?=htmlspecialchars($module, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>][]" value="<?=htmlspecialchars($capability, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" <?=!empty($matrix[$module][$capability])?'checked':''?> <?=$disabled?'disabled':''?> data-credential-permission data-default-admin="<?=!empty($roleDefaults['admin'][$module][$capability])?'1':'0'?>" data-default-editor="<?=!empty($roleDefaults['editor'][$module][$capability])?'1':'0'?>" data-default-viewer="<?=!empty($roleDefaults['viewer'][$module][$capability])?'1':'0'?>">
            <span><strong><?=htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong><small><?=htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></small></span>
        </label>
        <?php endforeach; ?>
    </div>
    <?php
}

require __DIR__ . '/header.php';
?>
<?php if ($selectedUser): ?>
<?php if (!empty($showNewUserNotice)): ?>
<div class="alert success users-created-followup" role="status">
    <span>User created. Continue with the account’s security settings.</span>
    <a class="btn btn-small" href="#credential-security">Edit User / Configure Authenticator</a>
    <?php if ((int)($selectedTotpState['required'] ?? 0) === 1 && ($selectedTotpState['status'] ?? '') !== 'active'): ?><a class="btn btn-small btn-primary" href="credential-totp-setup.php?user_id=<?=(int)$selectedUser['id']?>"><?=!empty($selectedPendingEnrollmentReady)?'Verify Enrollment':'Configure Authenticator'?></a><?php endif; ?>
</div>
<?php endif; ?>
<a class="users-back-link" href="users.php"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m14.5 5.5-6.5 6.5 6.5 6.5M8.5 12h12"></path></svg><span>Back to Users</span></a>
<div class="page-head users-edit-page-head"><div><h1>Edit User — <?=htmlspecialchars((string)$selectedUser['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></h1><p>Update account details, password and access permissions.</p></div></div>
<section class="card user-edit-card" id="edit-user">
    <form method="post" class="user-edit-form">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="target_user_id" value="<?=(int)$selectedUser['id']?>">
        <fieldset class="user-account-details">
            <legend>Account details</legend>
            <div class="user-edit-fields">
                <div><label for="edit-full-name">Full name</label><input id="edit-full-name" name="full_name" maxlength="150" autocomplete="name" value="<?=htmlspecialchars((string)$editFormValues['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" required></div>
                <div><label for="edit-username">Username</label><input id="edit-username" name="username" maxlength="100" autocomplete="username" value="<?=htmlspecialchars((string)$editFormValues['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" required></div>
                <div><label for="edit-email">Notification email</label><input id="edit-email" name="email" type="email" maxlength="190" autocomplete="email" value="<?=htmlspecialchars((string)$editFormValues['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><small class="muted">Used for order approval and rejection notifications.</small></div>
                <div><label for="edit-role">Role</label><select id="edit-role" name="role" required><?php foreach (['admin'=>'Admin','editor'=>'Editor / Manager','viewer'=>'Viewer'] as $roleValue=>$roleLabel): ?><option value="<?=htmlspecialchars($roleValue)?>" <?=$editFormValues['role']===$roleValue?'selected':''?>><?=htmlspecialchars($roleLabel)?></option><?php endforeach; ?></select></div>
                <div><label for="edit-active">Account status</label><select id="edit-active" name="active" required><option value="1"<?=$editFormValues['active']==='1'?' selected':''?>>Active</option><option value="0"<?=$editFormValues['active']==='0'?' selected':''?>>Inactive</option></select></div>
            </div>
        </fieldset>
        <fieldset class="credential-totp-panel credential-access-panel" id="credential-security">
            <legend>Credential Access &amp; Security</legend>
            <?php if ($editFormValues['role'] !== 'admin'): ?>
                <div class="permission-mode credential-access-mode"><label for="access-mode">Credential access mode</label><select id="access-mode" name="access_mode"><option value="role" <?=$userMode==='role'?'selected':''?>>Use role defaults</option><option value="full" <?=$userMode==='full'?'selected':''?>>Full Access</option><option value="custom" <?=$userMode==='custom'?'selected':''?>>Custom Access</option><option value="none" <?=$userMode==='none'?'selected':''?>>No Access</option></select></div>
                <p class="muted credential-access-mode-note" id="credential-access-mode-help"><?php if ($userMode === 'custom'): ?>Customize the credential permissions below. General permissions use this same access mode.<?php else: ?>Role defaults are shown read-only. Select Custom Access above to change individual permissions. General permissions use this same access mode.<?php endif; ?></p>
            <?php else: ?>
                <p class="muted credential-access-mode-note">Admin credential permissions are fixed and enabled.</p>
            <?php endif; ?>
            <?php renderCredentialPermissionControls($credentialPermissionFields, $userPermissionMatrix, $credentialRoleDefaults, $userMode !== 'custom' || $editFormValues['role'] === 'admin'); ?>
            <div class="credential-totp-summary"><span>Authenticator enrollment</span><strong><?=htmlspecialchars(credentialTotpStatusLabel($selectedTotpState), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong></div>
            <?php if (canAdmin()): ?>
                <label class="credential-totp-toggle" for="edit-credential-totp-required"><input id="edit-credential-totp-required" type="checkbox" name="credential_totp_required" value="1" <?=$editFormValues['credential_totp_required']==='1'?'checked':''?>> Require TOTP 2FA for Credentials</label>
                <p class="muted">Only an Admin can configure this authenticator. The user must verify with their own device before credential operations are allowed.</p>
                <?php if ((int)$selectedTotpState['required']): ?>
                <div class="credential-totp-admin-actions">
                    <?php if (($selectedTotpState['status'] ?? null) !== 'active'): ?><a class="btn btn-primary btn-small" href="credential-totp-setup.php?user_id=<?=(int)$selectedUser['id']?>"><?=$selectedPendingEnrollmentReady?'Verify Enrollment':'Set Up Authenticator'?></a><?php endif; ?>
                    <?php if ($selectedHasTotpEnrollment): ?><button class="btn btn-danger btn-small" type="submit" name="action" value="reset_credential_totp" onclick="return window.confirm('Reset this user’s credential 2FA enrollment? Their current authenticator will stop working.');">Reset 2FA Enrollment</button><input type="hidden" name="confirm_reset" value="1"><?php endif; ?>
                    <button class="btn btn-small" type="submit" name="action" value="disable_credential_totp" formnovalidate onclick="return window.confirm('Disable credential 2FA and remove this user’s authenticator enrollment?');">Disable TOTP requirement</button><input type="hidden" name="confirm_disable" value="1">
                </div>
                <?php else: ?><p class="muted">Enable the requirement and save changes before setting up the selected user’s authenticator.</p><?php endif; ?>
            <?php else: ?><p class="muted">Credential 2FA settings are managed by an Admin.</p><?php endif; ?>
        </fieldset>
        <fieldset class="user-password-reset">
            <legend>Reset password <span class="muted">(optional)</span></legend>
            <p class="muted">Leave both fields empty to keep the current password. Passwords must be at least 12 characters.</p>
            <div class="user-edit-fields">
                <div><label for="edit-new-password">New password</label><input id="edit-new-password" name="new_password" type="password" minlength="12" autocomplete="new-password"></div>
                <div><label for="edit-confirm-password">Confirm new password</label><input id="edit-confirm-password" name="confirm_password" type="password" minlength="12" autocomplete="new-password"></div>
            </div>
        </fieldset>
        <fieldset class="user-permissions-settings">
            <legend>Access permissions</legend>
            <?php if ($editFormValues['role'] === 'admin'): ?>
                <p class="muted">Admin permissions are fixed and cannot be reduced. Change the role above to configure non-admin permissions.</p>
            <?php else: ?>
                <p class="muted">The access mode above controls this matrix and the Credential Access &amp; Security panel. Individual permission changes apply on the user's next authenticated request.</p>
                <div id="user-permission-matrix" data-mode="<?=htmlspecialchars($userMode)?>"><?php renderPermissionMatrix('user_permissions', $userPermissionMatrix, $generalCatalog, $generalCapabilities, $labels, $userMode!=='custom'); ?></div>
            <?php endif; ?>
        </fieldset>
        <div class="user-edit-actions"><button class="btn btn-primary" type="submit">Save Changes</button><a class="btn" href="users.php">Cancel</a></div>
    </form>
</section>

<?php elseif ($createPageRequested): ?>
<a class="users-back-link" href="users.php"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m14.5 5.5-6.5 6.5 6.5 6.5M8.5 12h12"></path></svg><span>Back to Users</span></a>
<div class="page-head users-create-page-head"><div><h1>Create User</h1><p>Create a login account and choose its initial access.</p></div></div>
<section class="card user-create-page-card">
        <form method="post" action="users.php" class="user-create-form">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
            <input type="hidden" name="action" value="create_user">
            <label for="user-full-name">Full name</label><input id="user-full-name" name="full_name" maxlength="150" autocomplete="name" required>
            <label for="user-username">Username</label><input id="user-username" name="username" maxlength="100" autocomplete="username" required>
            <label for="user-email">Notification email (optional)</label><input id="user-email" name="email" type="email" maxlength="190" autocomplete="email">
            <label for="user-password">Password</label><input id="user-password" name="password" type="password" minlength="12" autocomplete="new-password" required>
            <label for="user-role">Role</label><select id="user-role" name="role" required><option value="admin">Admin</option><option value="editor">Editor / Manager</option><option value="viewer">Viewer</option></select>
            <fieldset class="create-account-status"><legend>Account Status</legend><p class="muted">New user accounts are active when created.</p></fieldset>
            <fieldset class="credential-totp-panel credential-access-panel"><legend>Credential Access &amp; Security</legend>
                <label class="credential-access-mode-label" for="create-access-mode">Credential permissions</label><select id="create-access-mode" name="credential_access_mode"><option value="role">Use selected role defaults</option><option value="custom">Customize credential permissions</option></select>
                <p class="muted">The checkboxes show effective access for the selected role. Custom access starts from the role defaults for other modules.</p>
                <?php renderCredentialPermissionControls($credentialPermissionFields, $credentialRoleDefaults['admin'], $credentialRoleDefaults, true); ?>
                <?php if (canAdmin()): ?><label class="credential-totp-toggle" for="create-credential-totp-required"><input id="create-credential-totp-required" type="checkbox" name="credential_totp_required" value="1"> Require TOTP 2FA for Credentials</label>
                <?php else: ?><p class="muted">Credential 2FA enforcement is managed by an Admin.</p><?php endif; ?>
            </fieldset>
            <div class="user-edit-actions"><button class="btn btn-primary" type="submit">Create User</button><a class="btn" href="users.php">Cancel</a></div>
        </form>
</section>
<?php else: ?>
<div class="page-head users-list-page-head"><div><h1>Users &amp; Permissions</h1><p>Manage login accounts and access settings.</p></div><a class="btn btn-primary" href="create-user.php">+ Create User</a></div>
    <section class="card users-list-card">
        <div class="table-scroll users-accounts-scroll" role="region" aria-label="Login accounts; scroll horizontally to view all columns" tabindex="0"><table class="users-accounts-table"><thead><tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Credential 2FA</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($users as $user): $totpState = $credentialTotpStates[(int)$user['id']] ?? ['status'=>null]; ?><tr><td title="<?=htmlspecialchars($user['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><?=htmlspecialchars($user['full_name'])?></td><td title="<?=htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><?=htmlspecialchars($user['username'])?></td><td title="<?=htmlspecialchars($user['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><?=htmlspecialchars($user['email'] ?? '—')?></td><td><?=htmlspecialchars(['admin'=>'Admin','editor'=>'Editor / Manager','viewer'=>'Viewer'][$user['role']]??$user['role'])?></td><td><span class="state-pill <?=$user['active']?'installed':'uninstalled'?>"><?=$user['active']?'Active':'Inactive'?></span></td><td><?=htmlspecialchars(credentialTotpStatusLabel($totpState), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></td><td class="users-account-actions"><div class="users-account-action-group">
            <a class="btn btn-small" href="users.php?edit_user=<?=(int)$user['id']?>" aria-label="Edit <?=htmlspecialchars($user['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">Edit</a>
            <?php if (canAdmin() && (int)$user['active'] === 1 && (int)$user['id'] !== (int)currentUser()['id']): $confirmText = 'Delete (deactivate) ' . $user['full_name'] . ' (' . $user['username'] . ')? The account will be disabled; historical records will be retained.'; ?>
            <form method="post" class="users-account-status-form" onsubmit="return window.confirm(<?=htmlspecialchars((string)json_encode($confirmText, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>)">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="action" value="deactivate_user"><input type="hidden" name="target_user_id" value="<?=(int)$user['id']?>"><input type="hidden" name="confirm_account_status" value="1">
                <button class="btn btn-small btn-outline-danger" type="submit" aria-label="Delete <?=htmlspecialchars($user['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">Delete</button>
            </form>
            <?php elseif (canAdmin() && (int)$user['active'] === 0): ?>
            <form method="post" class="users-account-status-form" onsubmit="return window.confirm(<?=htmlspecialchars((string)json_encode('Reactivate ' . $user['full_name'] . ' (' . $user['username'] . ')?', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>)">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="action" value="reactivate_user"><input type="hidden" name="target_user_id" value="<?=(int)$user['id']?>"><input type="hidden" name="confirm_account_status" value="1">
                <button class="btn btn-small" type="submit" aria-label="Reactivate <?=htmlspecialchars($user['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">Reactivate</button>
            </form>
            <?php endif; ?>
        </div></td></tr><?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="7" class="empty-state">No login accounts found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

<?php endif; ?>
<script>(function(){
const mode=document.getElementById('access-mode')||document.getElementById('create-access-mode');
const matrix=document.getElementById('user-permission-matrix');
const role=document.getElementById('edit-role')||document.getElementById('user-role');
const modeHelp=document.getElementById('credential-access-mode-help');
const credentialInputs=Array.from(document.querySelectorAll('[data-credential-permission]'));
const createPage=Boolean(document.getElementById('create-access-mode'));
let previousAdminState=null,lastAdmin=role&&role.value==='admin';
function syncModeHelp(){
 if(!modeHelp||!mode)return;
 if(role&&role.value==='admin')modeHelp.textContent='Admin credential permissions are fixed and enabled.';
 else if(mode.value==='custom')modeHelp.textContent='Customize the credential permissions below. General permissions use this same access mode.';
 else if(mode.value==='none')modeHelp.textContent='No module permissions are granted. Select Custom Access to choose individual credential permissions.';
 else if(mode.value==='full')modeHelp.textContent='All non-admin permissions are enabled. Select Custom Access to choose individual credential permissions.';
 else modeHelp.textContent='Role defaults are shown read-only. Select Custom Access above to change individual permissions. General permissions use this same access mode.';
}
function syncCredentialPermissions(){
 const selectedRole=role?role.value:'viewer',accessMode=mode?mode.value:'role',isAdmin=selectedRole==='admin';
 if(isAdmin&&!lastAdmin)previousAdminState=credentialInputs.map(function(input){return input.checked;});
 else if(!isAdmin&&lastAdmin&&accessMode==='custom'&&previousAdminState){credentialInputs.forEach(function(input,index){input.checked=previousAdminState[index];});previousAdminState=null;}
 credentialInputs.forEach(function(input){
  if(isAdmin||accessMode==='full')input.checked=true;
  else if(accessMode==='none')input.checked=false;
  else if(accessMode==='role')input.checked=input.dataset['default'+selectedRole.charAt(0).toUpperCase()+selectedRole.slice(1)]==='1';
  input.disabled=isAdmin||accessMode!=='custom';
 });
 lastAdmin=isAdmin;syncModeHelp();
}
function syncMatrix(){
 if(!matrix||!mode)return;
 const editable=mode.value==='custom'&&(!role||role.value!=='admin');
 matrix.querySelectorAll('input[type="checkbox"]').forEach(function(input){input.disabled=!editable;});
}
if(mode)mode.addEventListener('change',function(){syncMatrix();syncCredentialPermissions();});
if(role)role.addEventListener('change',function(){
 if(createPage&&mode&&mode.value==='custom'&&role.value!=='admin')credentialInputs.forEach(function(input){input.checked=input.dataset['default'+role.value.charAt(0).toUpperCase()+role.value.slice(1)]==='1';});
 syncMatrix();syncCredentialPermissions();
});
syncMatrix();syncCredentialPermissions();
document.querySelectorAll('[data-permission-scroll]').forEach(function(shell){
 const viewport=shell.querySelector('[data-permission-viewport]'),table=viewport&&viewport.querySelector('table'),rails=Array.from(shell.querySelectorAll('[data-permission-horizontal]')),spacers=Array.from(shell.querySelectorAll('[data-permission-spacer]'));
 if(!viewport||!table||!rails.length)return;
 let syncing=false;
 function sizeRails(){const width=Math.max(table.scrollWidth,viewport.clientWidth);spacers.forEach(function(spacer){spacer.style.width=width+'px';});rails.forEach(function(rail){rail.scrollLeft=viewport.scrollLeft;});}
 function fromSource(source){if(syncing)return;syncing=true;const left=source.scrollLeft;[viewport].concat(rails).forEach(function(target){if(target!==source)target.scrollLeft=left;});syncing=false;}
 rails.concat([viewport]).forEach(function(source){source.addEventListener('scroll',function(){fromSource(source);},{passive:true});});
 if('ResizeObserver'in window)new ResizeObserver(sizeRails).observe(viewport);
 window.addEventListener('resize',sizeRails,{passive:true});
 if(document.fonts&&document.fonts.ready)document.fonts.ready.then(sizeRails);
 sizeRails();
});
})();</script>
<?php require __DIR__ . '/footer.php'; ?>
