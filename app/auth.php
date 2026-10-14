<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void {
    static $validatedUserId = null;
    $user = currentUser();
    if (!$user || !isset($user['id']) || (int)$user['id'] < 1) {
        header('Location: login.php');
        exit;
    }

    $userId = (int)$user['id'];
    if ($validatedUserId === $userId) return;

    $stmt = db()->prepare('SELECT id, full_name, username, role, active, auth_version FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $current = $stmt->fetch();
    if (!$current || !(bool)$current['active']) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }

    // Account security changes increment auth_version. The next request using an
    // older session is signed out, including sessions stored by PHP's file handler.
    if ((int)($user['auth_version'] ?? 0) !== (int)$current['auth_version']) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }

    $_SESSION['user'] = [
        'id' => (int)$current['id'],
        'full_name' => $current['full_name'],
        'username' => $current['username'],
        'role' => $current['role'],
        'auth_version' => (int)$current['auth_version'],
    ];
    $validatedUserId = $userId;
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403);
        exit('403 Forbidden');
    }
}

function permissionCatalog(): array {
    return [
        'dashboard' => ['label' => 'Dashboard', 'permissions' => ['view']],
        'inventory' => ['label' => 'Inventory and stock', 'permissions' => ['view', 'stock_adjustment']],
        'products' => ['label' => 'Products', 'permissions' => ['view', 'add', 'edit', 'delete', 'status']],
        'categories' => ['label' => 'Categories', 'permissions' => ['view', 'add', 'edit', 'delete', 'status']],
        'assignments' => ['label' => 'Assignments', 'permissions' => ['view', 'add', 'edit', 'delete']],
        'employees' => ['label' => 'Employees', 'permissions' => ['view', 'add', 'edit', 'delete', 'status']],
        'hardware' => ['label' => 'Hardware assets', 'permissions' => ['view', 'add', 'edit', 'delete', 'hardware_assignment']],
        'software' => ['label' => 'Software assignments', 'permissions' => ['view', 'add', 'edit', 'delete', 'status']],
        'credentials' => ['label' => 'Credentials', 'permissions' => ['view', 'create', 'edit', 'reveal', 'delete', 'audit', 'manage_access']],
        'orders' => ['label' => 'Orders', 'permissions' => ['view', 'add', 'edit', 'delete', 'order_approval', 'order_rejection', 'order_receive']],
        'vendors' => ['label' => 'Vendors', 'permissions' => ['view', 'add', 'edit', 'delete', 'status']],
        'history' => ['label' => 'History', 'permissions' => ['view']],
        'search' => ['label' => 'Global search', 'permissions' => ['view']],
    ];
}

function systemPermissionCatalog(): array {
    return [
        'users' => ['label' => 'Users', 'permissions' => ['view', 'add', 'edit', 'delete', 'status']],
        'audit_logs' => ['label' => 'Audit Logs', 'permissions' => ['view']],
        'permission_management' => ['label' => 'Permission management', 'permissions' => ['view', 'edit']],
    ];
}

function permissionLabels(): array {
    return [
        'view' => 'View', 'add' => 'Add', 'edit' => 'Edit', 'delete' => 'Delete',
        'status' => 'Status changes', 'stock_adjustment' => 'Stock adjustment',
        'hardware_assignment' => 'Hardware assignment', 'order_approval' => 'Order approval',
        'order_rejection' => 'Order rejection', 'order_receive' => 'Receive order',
        'create' => 'Add credentials', 'reveal' => 'Reveal passwords', 'audit' => 'Credential access logs',
        'manage_access' => 'Manage department access',
    ];
}

function permissionDefaults(string $role): array {
    if (!in_array($role, ['editor', 'viewer'], true)) return [];
    $catalog = permissionCatalog();
    $matrix = [];
    foreach ($catalog as $module => $definition) {
        foreach ($definition['permissions'] as $capability) $matrix[$module][$capability] = false;
    }
    $stmt = db()->prepare('SELECT module, capability, allowed FROM role_permission_defaults WHERE role = ?');
    $stmt->execute([$role]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($catalog[$row['module']]['permissions']) && in_array($row['capability'], $catalog[$row['module']]['permissions'], true)) {
            $matrix[$row['module']][$row['capability']] = (bool)$row['allowed'];
        }
    }
    return $matrix;
}

function userPermissionProfile(int $userId): ?string {
    $stmt = db()->prepare('SELECT access_mode FROM user_permission_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $mode = $stmt->fetchColumn();
    return $mode === false ? null : (string)$mode;
}

function userPermissionOverrides(int $userId): array {
    $stmt = db()->prepare('SELECT module, capability, allowed FROM user_permissions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $overrides = [];
    foreach ($stmt->fetchAll() as $row) $overrides[$row['module']][$row['capability']] = (bool)$row['allowed'];
    return $overrides;
}

function buildEffectivePermissionMatrix(array $catalog, array $roleDefaults, ?string $profile, array $overrides, bool $isAdmin = false): array {
    $matrix = [];
    foreach ($catalog as $module => $definition) {
        foreach ($definition['permissions'] as $capability) $matrix[$module][$capability] = false;
    }
    if ($isAdmin) {
        foreach ($matrix as $module => $permissions) foreach ($permissions as $capability => $_) $matrix[$module][$capability] = true;
        foreach (systemPermissionCatalog() as $module => $definition) {
            foreach ($definition['permissions'] as $capability) $matrix[$module][$capability] = true;
        }
        return $matrix;
    }
    if ($profile === 'none') return $matrix;
    if ($profile === 'full') {
        foreach ($matrix as $module => $permissions) foreach ($permissions as $capability => $_) $matrix[$module][$capability] = true;
        return $matrix;
    }
    foreach ($matrix as $module => $permissions) {
        foreach ($permissions as $capability => $_) $matrix[$module][$capability] = (bool)($roleDefaults[$module][$capability] ?? false);
    }
    if ($profile === 'custom') {
        foreach ($overrides as $module => $permissions) {
            foreach ($permissions as $capability => $allowed) {
                if (isset($matrix[$module][$capability])) $matrix[$module][$capability] = $allowed;
            }
        }
    }
    return $matrix;
}

function effectivePermissionMatrix(?array $user = null): array {
    static $cache = [];
    $user ??= currentUser();
    if (!$user || !isset($user['id'], $user['role'])) return [];
    $userId = (int)$user['id'];
    $role = (string)$user['role'];
    $key = $userId . ':' . $role;
    if (isset($cache[$key])) return $cache[$key];
    if ($role === 'admin') return $cache[$key] = buildEffectivePermissionMatrix(permissionCatalog(), [], null, [], true);
    if (!in_array($role, ['editor', 'viewer'], true)) return $cache[$key] = [];
    return $cache[$key] = buildEffectivePermissionMatrix(
        permissionCatalog(),
        permissionDefaults($role),
        userPermissionProfile($userId),
        userPermissionOverrides($userId)
    );
}

function canPerform(string $resource, string $operation): bool {
    $known = permissionCatalog() + systemPermissionCatalog();
    if ($resource === 'permission_management' && in_array($operation, ['view', 'edit'], true)) {
        return canAdmin();
    }
    if (!isset($known[$resource]) || !in_array($operation, $known[$resource]['permissions'], true)) return false;
    if (isset(systemPermissionCatalog()[$resource])) return canAdmin();
    return (bool)(effectivePermissionMatrix()[$resource][$operation] ?? false);
}

function canViewResource(string $resource): bool {
    return canPerform($resource, 'view');
}

function requirePermission(string $resource, string $operation): void {
    requireLogin();
    if (!canPerform($resource, $operation)) {
        http_response_code(403);
        exit('403 Forbidden');
    }
}

function canAdmin(): bool {
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function assertActiveAdminRetained(array $activeAdminIds, int $targetUserId, string $newRole, int $newActive): void {
    $activeAdminIds = array_map('intval', $activeAdminIds);
    $targetIsActiveAdmin = in_array($targetUserId, $activeAdminIds, true);
    if ($targetIsActiveAdmin && !($newRole === 'admin' && $newActive === 1) && count(array_unique($activeAdminIds)) <= 1) {
        throw new DomainException('The last active Admin cannot be deactivated or demoted.');
    }
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}

function audit(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void {
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([currentUser()['id'] ?? null, $action, $entityType, $entityId, $details]);
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => in_array($type, ['success', 'info'], true) ? $type : 'error',
        'message' => $message,
    ];
}

function consumeFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}
