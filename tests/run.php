<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/inventory_helpers.php';
require_once __DIR__ . '/order_email_test.php';

$checks = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$checks): void {
    $checks++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $operation, string $message) use (&$checks): void {
    $checks++;
    try {
        $operation();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException($message . ': expected DomainException');
};

$assertSame(['delta' => 5, 'recorded_quantity' => 5, 'new_stock' => 15], calculateInventoryChange(10, 'in', 5), 'Stock received');
$assertSame(['delta' => -5, 'recorded_quantity' => -5, 'new_stock' => 5], calculateInventoryChange(10, 'out', 5), 'Stock issued');
$assertSame(['delta' => -2, 'recorded_quantity' => -2, 'new_stock' => 8], calculateInventoryChange(10, 'adjustment', -2), 'Negative adjustment');
$assertSame(['delta' => 0, 'recorded_quantity' => 5, 'new_stock' => 10], calculateInventoryChange(10, 'ordered', 5), 'Ordered stock does not become available');
$assertSame(['delta' => 5, 'recorded_quantity' => 5, 'new_stock' => 5], calculateInventoryChange(0, 'opening', 5), 'Opening balance');
$assertThrows(static fn() => calculateInventoryChange(2, 'opening', 1), 'Opening balance cannot be repeated');
$assertThrows(static fn() => calculateInventoryChange(2, 'out', 3), 'Negative stock is rejected');
$assertThrows(static fn() => calculateInventoryChange(2, 'unexpected', 1), 'Unknown movement is rejected');

$catalog = permissionCatalog();
$emptyDefaults = [];
foreach ($catalog as $module => $definition) foreach ($definition['permissions'] as $capability) $emptyDefaults[$module][$capability] = false;
$viewerDefaults = $emptyDefaults;
foreach ($catalog as $module => $definition) if (in_array('view', $definition['permissions'], true)) $viewerDefaults[$module]['view'] = true;
$editorDefaults = $viewerDefaults;
foreach ([
    'inventory' => ['stock_adjustment'],
    'products' => ['add', 'edit'],
    'categories' => ['add', 'edit', 'delete', 'status'],
    'assignments' => ['add', 'edit', 'delete', 'hardware_assignment'],
    'hardware' => ['add', 'edit', 'delete', 'hardware_assignment'],
] as $module => $operations) foreach ($operations as $operation) $editorDefaults[$module][$operation] = true;
$roleDefaults = ['editor' => $editorDefaults, 'viewer' => $viewerDefaults];
$_SESSION['user'] = ['id' => 1, 'role' => 'admin'];
$assertSame(true, canAdmin(), 'Admin role helper');
$assertSame(false, canPerform('unknown_module', 'view'), 'Unknown modules default deny');
$assertSame(false, canPerform('products', 'unknown_action'), 'Unknown actions default deny');
foreach (['editor', 'viewer'] as $role) {
    foreach ($catalog as $module => $definition) {
        foreach ($definition['permissions'] as $capability) {
            $assertSame((bool)$roleDefaults[$role][$module][$capability],
                (bool)buildEffectivePermissionMatrix($catalog, $roleDefaults[$role], null, [])[$module][$capability],
                ucfirst($role) . " default {$module}.{$capability}");
        }
    }
    $full = buildEffectivePermissionMatrix($catalog, $roleDefaults[$role], 'full', []);
    $none = buildEffectivePermissionMatrix($catalog, $roleDefaults[$role], 'none', []);
    $customOverrides = [];
    $toggleExpected = [];
    $offset = 0;
    foreach ($catalog as $module => $definition) foreach ($definition['permissions'] as $capability) {
        $allowed = ($offset++ % 2) === 0;
        $customOverrides[$module][$capability] = $allowed;
        $toggleExpected[$module][$capability] = $allowed;
        $assertSame(true, $full[$module][$capability], ucfirst($role) . " Full Access {$module}.{$capability}");
        $assertSame(false, $none[$module][$capability], ucfirst($role) . " No Access {$module}.{$capability}");
    }
    $custom = buildEffectivePermissionMatrix($catalog, $roleDefaults[$role], 'custom', $customOverrides);
    foreach ($catalog as $module => $definition) foreach ($definition['permissions'] as $capability) {
        $assertSame($toggleExpected[$module][$capability], $custom[$module][$capability], ucfirst($role) . " Custom Access {$module}.{$capability}");
    }
}
$admin = buildEffectivePermissionMatrix($catalog, [], null, [], true);
foreach ($catalog as $module => $definition) foreach ($definition['permissions'] as $capability) $assertSame(true, $admin[$module][$capability], "Admin full {$module}.{$capability}");
$_SESSION['user'] = ['id' => 1, 'role' => 'editor'];
$assertSame(false, canAdmin(), 'Editor cannot administer');
$assertSame(false, canPerform('users', 'view'), 'Editor cannot view users');
$assertSame(false, canPerform('audit_logs', 'view'), 'Editor cannot view audit logs');
$_SESSION['user']['role'] = 'viewer';
$assertSame(false, canAdmin(), 'Viewer cannot administer');
$assertSame(false, canPerform('users', 'view'), 'Viewer cannot view users');
$assertSame(false, canPerform('audit_logs', 'view'), 'Viewer cannot view audit logs');
$assertSame(false, canPerform('hardware', 'assign'), 'Legacy hardware action name defaults deny');
$assertSame(false, canPerform('orders', 'receive'), 'Legacy order action name defaults deny');
$assertSame(null, assertActiveAdminRetained([1, 2], 1, 'editor', 1), 'An Admin can be demoted when another active Admin remains');
$assertSame(null, assertActiveAdminRetained([1], 1, 'admin', 1), 'The active Admin can remain active');
$assertThrows(static fn() => assertActiveAdminRetained([1], 1, 'admin', 0), 'The last active Admin cannot be deactivated');
$assertThrows(static fn() => assertActiveAdminRetained([1], 1, 'editor', 1), 'The last active Admin cannot be demoted');

$_SESSION['csrf'] = 'valid-test-token';
$_POST['csrf'] = 'valid-test-token';
verifyCsrf();
$checks++;
$probe = static function (string $tokenState): string {
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/csrf_rejection_probe.php', $tokenState],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) throw new RuntimeException('Unable to launch the CSRF rejection probe.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($process);
    return $output;
};
$assertSame('Invalid CSRF token|http=419', $probe('invalid'), 'Invalid CSRF token is rejected');
$assertSame('Invalid CSRF token|http=419', $probe('missing'), 'Missing CSRF token is rejected');
$assertSame(null, consumeFlash(), 'Empty flash is consumed');
setFlash('success', 'Operation completed.');
$assertSame(['type' => 'success', 'message' => 'Operation completed.'], consumeFlash(), 'Success flash is consumed');
$assertSame(null, consumeFlash(), 'Flash cannot be consumed twice');

echo "PASS: {$checks} checks\n";
