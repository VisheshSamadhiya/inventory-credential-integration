<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
if (array_key_exists('edit_user', $_GET) && !canAdmin()) {
    http_response_code(403);
    exit('Forbidden');
}
requirePermission('users', 'view');
$pageTitle = 'Users and permissions';
$db = db();
$catalog = permissionCatalog();
$labels = permissionLabels();
$capabilities = array_values(array_unique(array_merge(...array_values(array_map(static fn(array $entry): array => $entry['permissions'], $catalog)))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'create_user');
    if ($action === 'edit_user' && !canAdmin()) {
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
            'role' => (string)($_POST['role'] ?? ''),
            'active' => (string)($_POST['active'] ?? ''),
            'access_mode' => (string)($_POST['access_mode'] ?? 'role'),
            'user_permissions' => $safePermissions,
        ];
    }
    $preserveEditInput = static function () use (&$action, &$editTargetId, &$editSubmittedValues): void {
        if ($action === 'edit_user' && $editTargetId > 0) {
            $_SESSION['user_edit_form_state'] = ['target_user_id' => $editTargetId, 'values' => $editSubmittedValues];
        }
    };
    try {
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

        if ($action === 'edit_user') {
            $targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $name = trim((string)($_POST['full_name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $role = (string)($_POST['role'] ?? '');
            $activeInput = (string)($_POST['active'] ?? '');
            $password = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            $mode = (string)($_POST['access_mode'] ?? 'role');
            if (!$targetUserId) throw new DomainException('Choose a valid user account.');
            if ($name === '' || $username === '') throw new DomainException('Full name and username are required.');
            if (strlen($name) > 150 || strlen($username) > 100) throw new DomainException('Full name or username is too long.');
            if (!preg_match('/^[^\s]{1,100}$/u', $username)) throw new DomainException('Username cannot contain spaces.');
            if (!in_array($role, ['admin', 'editor', 'viewer'], true)) throw new DomainException('Choose a valid role.');
            if (!in_array($activeInput, ['0', '1'], true)) throw new DomainException('Choose a valid account status.');
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
            $lockUser = $db->prepare('SELECT id, full_name, username, role, active, auth_version FROM users WHERE id=? FOR UPDATE');
            $lockUser->execute([$targetUserId]);
            $oldUser = $lockUser->fetch();
            if (!$oldUser) throw new DomainException('The selected user account was not found.');
            $active = (int)$activeInput;
            assertActiveAdminRetained($activeAdmins, (int)$targetUserId, $role, $active);

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
            $securityChanged = $passwordChanged
                || $username !== $oldUser['username']
                || $role !== $oldUser['role']
                || $active !== (int)$oldUser['active'];
            $updateSql = 'UPDATE users SET full_name=?, username=?, role=?, active=?, auth_version=auth_version+?';
            $updateValues = [$name, $username, $role, $active, $securityChanged ? 1 : 0];
            if ($passwordChanged) {
                $updateSql .= ', password_hash=?';
                $updateValues[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $updateSql .= ' WHERE id=?';
            $updateValues[] = $targetUserId;
            $db->prepare($updateSql)->execute($updateValues);

            $newUserDetails = ['full_name' => $name, 'username' => $username, 'role' => $role, 'active' => (bool)$active];
            $oldUserDetails = ['full_name' => $oldUser['full_name'], 'username' => $oldUser['username'], 'role' => $oldUser['role'], 'active' => (bool)$oldUser['active']];
            if ($oldUserDetails !== $newUserDetails) {
                audit('user_updated', 'user', (int)$targetUserId, json_encode([
                    'actor_id' => (int)currentUser()['id'], 'target_user_id' => (int)$targetUserId,
                    'old_user' => $oldUserDetails, 'new_user' => $newUserDetails,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
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
            header('Location: users.php');
            exit;
        }

        if ($action !== 'create_user') throw new DomainException('Choose a valid user operation.');
        $name = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? '');
        if ($name === '' || $username === '') throw new DomainException('Please complete the name and username fields.');
        if (strlen($name) > 150 || strlen($username) > 100) throw new DomainException('Name or username is too long.');
        if (strlen($password) < 8) throw new DomainException('Password must be at least 8 characters.');
        if (!in_array($role, ['admin', 'editor', 'viewer'], true)) throw new DomainException('Please select a valid role.');
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO users(full_name, username, password_hash, role) VALUES(?, ?, ?, ?)');
        $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
        $userId = (int)$db->lastInsertId();
        audit('create', 'user', $userId, $username . ' (' . $role . ')');
        $db->commit();
        setFlash('success', 'User created successfully. The user receives their role’s current default permissions.');
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
    header('Location: users.php' . ($action === 'edit_user' && $editTargetId > 0 ? '?edit_user=' . $editTargetId : ''));
    exit;
}

$users = $db->query('SELECT id, full_name, username, role, active, created_at FROM users ORDER BY id')->fetchAll();
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
        'role' => (string)$selectedUser['role'],
        'active' => (string)(int)$selectedUser['active'],
        'access_mode' => $selectedUser['role'] === 'admin' ? 'fixed' : (userPermissionProfile((int)$selectedUser['id']) ?? 'role'),
        'user_permissions' => [],
    ];
    if ($savedEditState) $editFormValues = array_replace($editFormValues, $savedEditState['values'] ?? []);
    $pageTitle = 'Edit User — ' . (string)$selectedUser['full_name'];
    $pageHeadingInTemplate = true;
    $suppressBackButton = true;
} else {
    $pageTitle = 'Users and permissions';
    $pageHeadingInTemplate = false;
    $suppressBackButton = false;
}
$userMode = $selectedUser ? ($editFormValues['role'] === 'admin' ? 'fixed' : ((string)$editFormValues['access_mode'] === 'fixed' ? 'role' : (string)$editFormValues['access_mode'])) : 'role';
$userPermissionMatrix = $selectedUser ? effectivePermissionMatrix($selectedUser) : [];
if ($selectedUser && $editFormValues['role'] !== 'admin') {
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

require __DIR__ . '/header.php';
?>
<?php if ($selectedUser): ?>
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
                <div><label for="edit-role">Role</label><select id="edit-role" name="role" required><?php foreach (['admin'=>'Admin','editor'=>'Editor / Manager','viewer'=>'Viewer'] as $roleValue=>$roleLabel): ?><option value="<?=htmlspecialchars($roleValue)?>" <?=$editFormValues['role']===$roleValue?'selected':''?>><?=htmlspecialchars($roleLabel)?></option><?php endforeach; ?></select></div>
                <div><label for="edit-active">Account status</label><select id="edit-active" name="active" required><option value="1"<?=$editFormValues['active']==='1'?' selected':''?>>Active</option><option value="0"<?=$editFormValues['active']==='0'?' selected':''?>>Inactive</option></select></div>
            </div>
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
                <p class="muted">The selected access mode does not change the user's base role. Individual permission changes apply on the next authenticated request.</p>
                <div class="permission-mode"><label for="access-mode">Access mode</label><select id="access-mode" name="access_mode"><option value="role" <?=$userMode==='role'?'selected':''?>>Use role defaults</option><option value="full" <?=$userMode==='full'?'selected':''?>>Full Access</option><option value="custom" <?=$userMode==='custom'?'selected':''?>>Custom Access</option><option value="none" <?=$userMode==='none'?'selected':''?>>No Access</option></select></div>
                <div id="user-permission-matrix" data-mode="<?=htmlspecialchars($userMode)?>"><?php renderPermissionMatrix('user_permissions', $userPermissionMatrix, $catalog, $capabilities, $labels, $userMode!=='custom'); ?></div>
            <?php endif; ?>
        </fieldset>
        <div class="user-edit-actions"><button class="btn btn-primary" type="submit">Save Changes</button><a class="btn" href="users.php">Cancel</a></div>
    </form>
</section>
<script>(function(){const mode=document.getElementById('access-mode'),matrix=document.getElementById('user-permission-matrix');if(mode&&matrix){function sync(){const editable=mode.value==='custom';matrix.querySelectorAll('input[type="checkbox"]').forEach(function(input){input.disabled=!editable;});}mode.addEventListener('change',sync);sync();}document.querySelectorAll('[data-permission-scroll]').forEach(function(shell){const viewport=shell.querySelector('[data-permission-viewport]'),table=viewport&&viewport.querySelector('table'),rails=Array.from(shell.querySelectorAll('[data-permission-horizontal]')),spacers=Array.from(shell.querySelectorAll('[data-permission-spacer]'));if(!viewport||!table||!rails.length)return;let syncing=false;function sizeRails(){const width=Math.max(table.scrollWidth,viewport.clientWidth);spacers.forEach(function(spacer){spacer.style.width=width+'px';});rails.forEach(function(rail){rail.scrollLeft=viewport.scrollLeft;});}function fromSource(source){if(syncing)return;syncing=true;const left=source.scrollLeft;[viewport].concat(rails).forEach(function(target){if(target!==source)target.scrollLeft=left;});syncing=false;}rails.concat([viewport]).forEach(function(source){source.addEventListener('scroll',function(){fromSource(source);},{passive:true});});if('ResizeObserver'in window){new ResizeObserver(sizeRails).observe(viewport);}window.addEventListener('resize',sizeRails,{passive:true});if(document.fonts&&document.fonts.ready)document.fonts.ready.then(sizeRails);sizeRails();});})();</script>
<?php else: ?>
<div class="grid two users-layout">
    <section class="card">
        <h2>Create user</h2>
        <form method="post" class="user-create-form">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
            <input type="hidden" name="action" value="create_user">
            <label for="user-full-name">Full name</label><input id="user-full-name" name="full_name" maxlength="150" autocomplete="name" required>
            <label for="user-username">Username</label><input id="user-username" name="username" maxlength="100" autocomplete="username" required>
            <label for="user-password">Password</label><input id="user-password" name="password" type="password" minlength="8" autocomplete="new-password" required>
            <label for="user-role">Role</label><select id="user-role" name="role" required><option value="admin">Admin</option><option value="editor">Editor / Manager</option><option value="viewer">Viewer</option></select>
            <button class="btn btn-primary" type="submit">Create user</button>
        </form>
    </section>
    <section class="card users-list-card">
        <h2>Login accounts</h2>
        <div class="table-scroll"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th>Access</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($users as $user): $mode = $user['role'] === 'admin' ? 'Full (fixed)' : (userPermissionProfile((int)$user['id']) ?? 'Role defaults'); ?><tr><td><?=htmlspecialchars($user['full_name'])?></td><td><?=htmlspecialchars($user['username'])?></td><td><?=htmlspecialchars(['admin'=>'Admin','editor'=>'Editor / Manager','viewer'=>'Viewer'][$user['role']]??$user['role'])?></td><td><span class="state-pill <?=$user['active']?'installed':'uninstalled'?>"><?=$user['active']?'Active':'Inactive'?></span></td><td><?=htmlspecialchars($user['created_at'])?></td><td><?=$user['role']==='admin'?'Admin only':htmlspecialchars(ucfirst($mode))?></td><td><a class="btn btn-small" href="users.php?edit_user=<?=(int)$user['id']?>" aria-label="Edit <?=htmlspecialchars($user['full_name'], ENT_QUOTES)?>">Edit</a></td></tr><?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="7" class="empty-state">No login accounts found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
