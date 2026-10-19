<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function auditViewPermission(string $view, bool $canInventory = false, bool $canCredential = false): array
{
    return match ($view) {
        'inventory' => [['inventory_audit', 'view']],
        'credential' => [['credential_audit', 'view']],
        'all' => array_values(array_filter([
            $canInventory ? ['inventory_audit', 'view'] : null,
            $canCredential ? ['credential_audit', 'view'] : null,
        ])),
        default => throw new InvalidArgumentException('Unknown audit view.'),
    };
}

function auditExportPermissions(string $view, bool $canInventory = false, bool $canCredential = false): array
{
    return array_map(static fn(array $permission): array => [$permission[0], 'export'], auditViewPermission($view, $canInventory, $canCredential));
}

function auditSourceView(string $view, bool $canInventory, bool $canCredential): string
{
    if ($view !== 'all') return $view;
    if ($canInventory && $canCredential) return 'all';
    if ($canInventory) return 'inventory';
    if ($canCredential) return 'credential';
    throw new InvalidArgumentException('No audit categories are authorized.');
}

function auditParseFilters(array $input, string $view): array
{
    if (!in_array($view, ['inventory', 'credential', 'all'], true)) throw new InvalidArgumentException('Unknown audit view.');
    $prefix = $view . '_';
    $search = trim(substr((string)($input[$prefix . 'q'] ?? ''), 0, 100));
    $from = trim((string)($input[$prefix . 'from'] ?? ''));
    $to = trim((string)($input[$prefix . 'to'] ?? ''));
    $validDate = static function (string $date): bool {
        if ($date === '') return true;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) return false;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    };
    $dateValid = $validDate($from) && $validDate($to) && ($from === '' || $to === '' || $from <= $to);
    if (!$dateValid) { $from = ''; $to = ''; }
    $userId = filter_var($input[$prefix . 'user'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $targetId = filter_var($input[$prefix . 'target'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $action = preg_match('/^[a-z0-9_]{1,120}$/iD', (string)($input[$prefix . 'action'] ?? '')) ? (string)$input[$prefix . 'action'] : '';
    $page = filter_var($input[$prefix . 'page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    return ['prefix' => $prefix, 'search' => $search, 'from' => $from, 'to' => $to, 'user' => $userId, 'target' => $targetId, 'action' => $action, 'page' => (int)$page, 'date_valid' => $dateValid];
}

function auditPageWindow(int $total, int $page, int $pageSize = 50): array
{
    $pageSize = max(1, min(200, $pageSize));
    $pages = max(1, (int)ceil(max(0, $total) / $pageSize));
    $page = max(1, min($pages, $page));
    return ['page' => $page, 'page_size' => $pageSize, 'pages' => $pages, 'offset' => ($page - 1) * $pageSize, 'total' => max(0, $total)];
}

function auditClassifyAction(string $action, ?string $entityType = null, ?string $details = null): ?string
{
    $action = strtolower($action);
    $entityType = strtolower((string)$entityType);
    if (in_array($action, ['user_permissions_updated', 'role_permissions_updated'], true)) return auditPermissionChangeCategories($details)[0] ?? null;
    if (str_starts_with($action, 'credential_') || $action === 'user_password_reset' || in_array($entityType, ['credential', 'credential_department'], true)) return 'credential';
    if (in_array($action, ['inventory_transaction', 'inventory_order_received', 'stock_added', 'stock_removed', 'stock_adjusted', 'order_created'], true)
        || str_starts_with($action, 'hardware_') || str_starts_with($action, 'asset_tag')
        || str_starts_with($action, 'bulk_asset_tags_') || str_starts_with($action, 'order_')
        || str_starts_with($action, 'software_')
        || (in_array($action, ['create', 'update', 'status_change'], true) && in_array($entityType, ['product', 'category', 'sub_category', 'employee', 'vendor'], true))) return 'inventory';
    return null;
}

function auditClassifyActions(string $action, ?string $entityType = null, ?string $details = null): array
{
    if (in_array(strtolower($action), ['user_permissions_updated', 'role_permissions_updated'], true)) return auditPermissionChangeCategories($details);
    $category = auditClassifyAction($action, $entityType, $details);
    return $category === null ? [] : [$category];
}

function auditPermissionChangeCategories(?string $details): array
{
    $event = json_decode((string)$details, true);
    if (!is_array($event) || !is_array($event['old_permissions'] ?? null) || !is_array($event['new_permissions'] ?? null)) return [];
    $old = $event['old_permissions'];
    $new = $event['new_permissions'];
    $changed = [];
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $module) {
        $oldValue = $old[$module] ?? null;
        $newValue = $new[$module] ?? null;
        if ($oldValue !== $newValue) $changed[] = (string)$module;
    }
    $categories = [];
    $inventoryModules = ['inventory','products','categories','assignments','employees','hardware','software','orders','vendors','inventory_audit'];
    if (array_intersect($changed, $inventoryModules)) $categories[] = 'inventory';
    if (array_intersect($changed, ['credentials','credential_audit'])) $categories[] = 'credential';
    return $categories;
}

function auditPermissionSqlPredicate(string $alias, string $category): string
{
    $modules = $category === 'inventory'
        ? ['inventory','products','categories','assignments','employees','hardware','software','orders','vendors','inventory_audit']
        : ['credentials','credential_audit'];
    $comparisons = [];
    foreach ($modules as $module) {
        $path = '$.old_permissions.' . $module;
        $newPath = '$.new_permissions.' . $module;
        $comparisons[] = "NOT (JSON_EXTRACT({$alias}.details,'{$path}') <=> JSON_EXTRACT({$alias}.details,'{$newPath}'))";
    }
    return "({$alias}.action IN ('user_permissions_updated','role_permissions_updated') AND JSON_VALID({$alias}.details)=1 AND (" . implode(' OR ', $comparisons) . '))';
}

function auditSqlClassificationPredicate(string $alias, string $view): string
{
    $inventory = "({$alias}.action IN ('inventory_transaction','inventory_order_received','stock_added','stock_removed','stock_adjusted','order_created') OR LEFT({$alias}.action,9)='hardware_' OR LEFT({$alias}.action,9)='asset_tag' OR LEFT({$alias}.action,16)='bulk_asset_tags_' OR LEFT({$alias}.action,6)='order_' OR LEFT({$alias}.action,9)='software_' OR ({$alias}.action IN ('create','update','status_change') AND {$alias}.entity_type IN ('product','category','sub_category','employee','vendor')) OR " . auditPermissionSqlPredicate($alias, 'inventory') . ')';
    $credential = "(LEFT({$alias}.action,11)='credential_' OR {$alias}.action='user_password_reset' OR {$alias}.entity_type IN ('credential','credential_department') OR " . auditPermissionSqlPredicate($alias, 'credential') . ')';
    return match ($view) {
        'inventory' => $inventory,
        'credential' => $credential,
        'all' => "({$inventory} OR {$credential})",
        default => '0=1',
    };
}

function auditActivityClassificationPredicate(string $alias, string $view): string
{
    $inventory = "({$alias}.action IN ('inventory_transaction','inventory_order_received','stock_added','stock_removed','stock_adjusted','order_created') OR LEFT({$alias}.action,9)='hardware_' OR LEFT({$alias}.action,9)='asset_tag' OR LEFT({$alias}.action,16)='bulk_asset_tags_' OR LEFT({$alias}.action,6)='order_' OR LEFT({$alias}.action,9)='software_' OR ({$alias}.action IN ('create','update','status_change') AND {$alias}.module IN ('product','category','sub_category','employee','vendor')) OR " . auditPermissionSqlPredicate($alias, 'inventory') . ')';
    $credential = "(LEFT({$alias}.action,11)='credential_' OR {$alias}.action='user_password_reset' OR {$alias}.module IN ('credential','credential_department') OR " . auditPermissionSqlPredicate($alias, 'credential') . ')';
    return match ($view) {
        'inventory' => $inventory,
        'credential' => $credential,
        'all' => "({$inventory} OR {$credential})",
        default => '0=1',
    };
}

function auditSchema(PDO $pdo): array
{
    $rows = $pdo->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll();
    $schema = [];
    foreach ($rows as $row) $schema[(string)$row['TABLE_NAME']][] = (string)$row['COLUMN_NAME'];
    return $schema;
}

function auditHasColumns(array $schema, string $table, array $columns): bool
{
    return isset($schema[$table]) && array_diff($columns, $schema[$table]) === [];
}

function auditSourceUnion(PDO $pdo, array $schema, string $view): string
{
    if (!auditHasColumns($schema, 'audit_logs', ['id','user_id','action','entity_type','entity_id','details','ip_address','created_at'])) {
        throw new RuntimeException('The required audit_logs table is unavailable or incompatible.');
    }
    $parts = ["SELECT a.created_at,a.user_id,CASE WHEN a.action='inventory_transaction' AND a.details LIKE 'in %' THEN 'stock_added' WHEN a.action='inventory_transaction' AND a.details LIKE 'opening %' THEN 'stock_added' WHEN a.action='inventory_transaction' AND a.details LIKE 'out %' THEN 'stock_removed' WHEN a.action='inventory_transaction' AND a.details LIKE 'adjustment %' THEN 'stock_adjusted' WHEN a.action='inventory_transaction' AND a.details LIKE 'ordered %' THEN 'order_created' ELSE a.action END AS action,a.entity_type AS module,a.entity_id AS target_id,a.details,a.ip_address,a.id AS source_id,'audit_logs' AS source_table FROM audit_logs a WHERE " . auditSqlClassificationPredicate('a', $view)];

    if (in_array($view, ['credential', 'all'], true) && auditHasColumns($schema, 'credential_access_logs', ['id','credential_id','user_id','action','ip_address','created_at'])) {
        $duplicate = "((cal.action='created' AND a.action='credential_created') OR (cal.action='edited' AND a.action IN ('credential_updated','credential_password_changed')) OR (cal.action='deleted' AND a.action='credential_deleted') OR (cal.action='linked' AND a.action='credential_linked') OR (cal.action='unlinked' AND a.action='credential_unlinked') OR (cal.action<>'revealed' AND a.action=CONCAT('credential_',cal.action)))";
        $parts[] = "SELECT cal.created_at,cal.user_id,CONCAT('credential_access_',cal.action) AS action,'credential' AS module,cal.credential_id AS target_id,CONCAT('Credential access event: ',cal.action) AS details,cal.ip_address,cal.id AS source_id,'credential_access_logs' AS source_table FROM credential_access_logs cal WHERE NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.entity_type='credential' AND a.entity_id=cal.credential_id AND a.user_id <=> cal.user_id AND ABS(TIMESTAMPDIFF(SECOND,a.created_at,cal.created_at))<=10 AND {$duplicate})";
    }

    if (in_array($view, ['credential', 'all'], true) && auditHasColumns($schema, 'credential_password_versions', ['id','credential_id','changed_by','change_reason','created_at'])) {
        $parts[] = "SELECT v.created_at,v.changed_by AS user_id,'credential_password_changed' AS action,'credential' AS module,v.credential_id AS target_id,COALESCE(v.change_reason,'Credential password changed') AS details,NULL AS ip_address,v.id AS source_id,'credential_password_versions' AS source_table FROM credential_password_versions v WHERE NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.action='credential_password_changed' AND a.entity_type='credential' AND a.entity_id=v.credential_id AND a.user_id <=> v.changed_by AND ABS(TIMESTAMPDIFF(SECOND,a.created_at,v.created_at))<=10)";
    }

    if (in_array($view, ['inventory', 'all'], true) && auditHasColumns($schema, 'hardware_assignment_history', ['id','employee_id','assignment_id','action','reason','performed_by','performed_at'])) {
        $parts[] = "SELECT h.performed_at AS created_at,h.performed_by AS user_id,CONCAT('hardware_',h.action) AS action,'assignment' AS module,h.assignment_id AS target_id,h.reason AS details,NULL AS ip_address,h.id AS source_id,'hardware_assignment_history' AS source_table FROM hardware_assignment_history h WHERE NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.entity_type='assignment' AND a.entity_id=h.assignment_id AND a.user_id <=> h.performed_by AND a.action=CONCAT('hardware_',h.action) AND ABS(TIMESTAMPDIFF(SECOND,a.created_at,h.performed_at))<=10)";
    }

    if (in_array($view, ['inventory', 'all'], true) && auditHasColumns($schema, 'inventory_transactions', ['id','product_id','transaction_type','quantity','previous_quantity','new_quantity','reason','created_by','transaction_at','status','confirmed_at','confirmed_by','rejected_at','rejected_by','rejection_reason'])) {
        $parts[] = "SELECT t.transaction_at AS created_at,t.created_by AS user_id,CASE t.transaction_type WHEN 'in' THEN 'stock_added' WHEN 'opening' THEN 'stock_added' WHEN 'out' THEN 'stock_removed' WHEN 'adjustment' THEN 'stock_adjusted' WHEN 'ordered' THEN 'order_created' ELSE 'inventory_transaction' END AS action,'product' AS module,t.product_id AS target_id,CONCAT('Quantity ',t.quantity,'; stock ',t.previous_quantity,' → ',t.new_quantity,'; ',COALESCE(t.reason,'')) AS details,NULL AS ip_address,t.id AS source_id,'inventory_transactions' AS source_table FROM inventory_transactions t WHERE NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.action='inventory_transaction' AND a.entity_type='product' AND a.entity_id=t.product_id AND a.user_id <=> t.created_by AND a.details LIKE CONCAT(t.transaction_type,' ',t.quantity,';%') AND ABS(TIMESTAMPDIFF(SECOND,a.created_at,t.transaction_at))<=10)";
        $parts[] = "SELECT t.confirmed_at AS created_at,t.confirmed_by AS user_id,'order_confirmed' AS action,'order' AS module,t.id AS target_id,'Order confirmed' AS details,NULL AS ip_address,t.id AS source_id,'inventory_transactions' AS source_table FROM inventory_transactions t WHERE t.confirmed_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.action='order_confirmed' AND a.entity_type='order' AND a.entity_id=t.id AND a.user_id <=> t.confirmed_by AND ABS(TIMESTAMPDIFF(SECOND,a.created_at,t.confirmed_at))<=10)";
        $parts[] = "SELECT t.rejected_at AS created_at,t.rejected_by AS user_id,'order_rejected' AS action,'order' AS module,t.id AS target_id,CONCAT('Order rejected; ',COALESCE(t.rejection_reason,'')) AS details,NULL AS ip_address,t.id AS source_id,'inventory_transactions' AS source_table FROM inventory_transactions t WHERE t.rejected_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.action='order_rejected' AND a.entity_type='order' AND a.entity_id=t.id AND a.user_id <=> t.rejected_by AND ABS(TIMESTAMPDIFF(SECOND,a.created_at,t.rejected_at))<=10)";
    }

    if (in_array($view, ['inventory', 'all'], true) && auditHasColumns($schema, 'asset_tag_jobs', ['id','quantity','prefix','created_by','created_at','completed_at'])) {
        $parts[] = "SELECT j.completed_at AS created_at,j.created_by AS user_id,'bulk_asset_tags_generated' AS action,'asset_tag_job' AS module,j.id AS target_id,CONCAT('Generated ',j.quantity,' asset tags with prefix ',j.prefix) AS details,NULL AS ip_address,j.id AS source_id,'asset_tag_jobs' AS source_table FROM asset_tag_jobs j WHERE j.completed_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.action='bulk_asset_tags_generated' AND ((a.source_system IN ('asset_tag_job','asset_tag_worker') AND a.source_id=j.id) OR (a.entity_type='asset_tag_job' AND a.entity_id=j.id)))";
    }

    if (in_array($view, ['inventory', 'all'], true) && auditHasColumns($schema, 'software_history', ['id','software_installation_id','employee_id','action','details','performed_by','performed_at'])) {
        $parts[] = "SELECT h.performed_at AS created_at,h.performed_by AS user_id,CONCAT('software_',h.action) AS action,'software' AS module,h.software_installation_id AS target_id,h.details,NULL AS ip_address,h.id AS source_id,'software_history' AS source_table FROM software_history h WHERE NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.action=CONCAT('software_',h.action) AND a.entity_type='software' AND a.entity_id=h.software_installation_id AND a.user_id <=> h.performed_by AND ABS(TIMESTAMPDIFF(SECOND,a.created_at,h.performed_at))<=10)";
    }

    if (auditHasColumns($schema, 'activity_logs', ['id','created_at','user_id','action','module','target_id','details','ip_address'])) {
        $activityPredicate = auditActivityClassificationPredicate('l', $view);
        $parts[] = "SELECT l.created_at,l.user_id,l.action,l.module,l.target_id,l.details,l.ip_address,l.id AS source_id,'activity_logs' AS source_table FROM activity_logs l WHERE {$activityPredicate} AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.action=l.action AND a.entity_type=l.module AND a.entity_id=l.target_id AND a.user_id <=> l.user_id AND ABS(TIMESTAMPDIFF(SECOND,a.created_at,l.created_at))<=10)";
    }

    return implode(' UNION ALL ', $parts);
}

function auditSafeDetails(?string $details, int $maxLength = 4000): string
{
    if ($details === null || $details === '') return '';
    $decoded = json_decode($details, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $redact = static function (mixed $value) use (&$redact): mixed {
            if (!is_array($value)) return $value;
            $safe = [];
            foreach ($value as $key => $child) {
                if (is_string($key) && preg_match('/password|passwd|secret|token|api.?key|encryption.?key|private.?key|smtp|authorization|bearer|credential_(?:password|username|user|secret)/i', $key)) {
                    $safe[$key] = '[redacted]';
                } else {
                    $safe[$key] = $redact($child);
                }
            }
            return $safe;
        };
        $details = json_encode($redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[details redacted]';
    }
    $details = preg_replace('/((?:password|passwd|secret|token|api[_ -]?key|encryption[_ -]?key|private[_ -]?key|smtp[_ -]?(?:user|password)|authorization|bearer|credential[_ -]?(?:username|user|password))\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1[redacted]', $details) ?? '[details redacted]';
    return mb_substr($details, 0, max(1, min(20000, $maxLength)), 'UTF-8');
}

function auditPermissionChanges(?string $details): array
{
    $event = json_decode((string)$details, true);
    if (!is_array($event) || !is_array($event['old_permissions'] ?? null) || !is_array($event['new_permissions'] ?? null)) return [];
    $old = auditFlattenPermissions($event['old_permissions']);
    $new = auditFlattenPermissions($event['new_permissions']);
    $changes = [];
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $path) {
        $before = (bool)($old[$path] ?? false);
        $after = (bool)($new[$path] ?? false);
        if ($before === $after) continue;
        [$module, $capability] = array_pad(explode('.', $path, 2), 2, '');
        $changes[] = [
            'path' => $path,
            'label' => auditPermissionModuleLabel($module) . ($capability !== '' ? ' ' . strtolower(auditPermissionCapabilityLabel($capability)) : ''),
            'old' => $before ? 'Enabled' : 'Disabled',
            'new' => $after ? 'Enabled' : 'Disabled',
        ];
    }
    return $changes;
}

function auditFlattenPermissions(array $permissions, string $prefix = ''): array
{
    $flat = [];
    foreach ($permissions as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_array($value)) $flat += auditFlattenPermissions($value, $path);
        elseif (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') $flat[$path] = in_array($value, [true, 1, '1'], true);
    }
    return $flat;
}

function auditPermissionModuleLabel(string $module): string
{
    $catalog = permissionCatalog();
    $label = $catalog[$module]['label'] ?? ucwords(str_replace('_', ' ', $module));
    return match ($module) {
        'inventory' => 'Inventory',
        'hardware' => 'Hardware',
        'software' => 'Software',
        'inventory_audit' => 'Inventory Audit',
        'credential_audit' => 'Credential Audit',
        'credentials' => 'Credentials',
        default => preg_replace('/^(Inventory and stock)$/', 'Inventory', $label) ?? $label,
    };
}

function auditPermissionCapabilityLabel(string $capability): string
{
    $labels = permissionLabels();
    return $labels[$capability] ?? ucwords(str_replace('_', ' ', $capability));
}

function auditDetailsSummary(?string $details, string $action = '', ?string $module = null): string
{
    if (in_array(strtolower($action), ['user_permissions_updated', 'role_permissions_updated'], true)) {
        $changes = auditPermissionChanges($details);
        if ($changes) {
            $labels = array_map(static fn(array $change): string => $change['label'] . ': ' . $change['old'] . ' → ' . $change['new'], $changes);
            $shown = array_slice($labels, 0, 2);
            if (count($labels) > 2) $shown[] = '+' . (count($labels) - 2) . ' more changes';
            return implode('; ', $shown);
        }
        return 'Permissions updated; no individual permission changes detected.';
    }
    if ($details === null || trim($details) === '') return auditActionLabel($action, $module);
    $decoded = json_decode($details, true);
    if (is_array($decoded)) {
        $safe = json_decode(auditSafeDetails($details), true);
        $summary = [];
        foreach (['target_username','username','role','old_mode','new_mode','reason','message'] as $key) {
            if (isset($safe[$key]) && is_scalar($safe[$key])) $summary[] = auditFieldLabel($key) . ': ' . (string)$safe[$key];
        }
        if (!$summary) $summary[] = auditActionLabel($action, $module);
        return mb_substr(implode('; ', $summary), 0, 220, 'UTF-8');
    }
    $safe = trim(auditSafeDetails($details));
    return mb_substr($safe !== '' ? $safe : auditActionLabel($action, $module), 0, 220, 'UTF-8');
}

function auditDetailsFull(?string $details, string $action = '', ?string $module = null): string
{
    if ($details === null || trim($details) === '') return auditActionLabel($action, $module);
    $decoded = json_decode($details, true);
    if (!is_array($decoded)) return auditSafeDetails($details, 20000);
    $decoded = json_decode(auditSafeDetails($details, 20000), true);
    if (!is_array($decoded)) return '[Details redacted]';
    $lines = [];
    if (in_array(strtolower($action), ['user_permissions_updated', 'role_permissions_updated'], true)) {
        $changes = auditPermissionChanges($details);
        $lines[] = 'Permission changes';
        if ($changes) {
            foreach ($changes as $change) $lines[] = '  ' . $change['label'] . ': ' . $change['old'] . ' → ' . $change['new'];
        } else $lines[] = '  No individual permission changes detected.';
        $lines[] = '';
    }
    foreach ($decoded as $key => $value) {
        $label = auditFieldLabel((string)$key);
        if (in_array($key, ['old_permissions','new_permissions'], true) && is_array($value)) {
            $lines[] = $label . ':';
            foreach (auditFlattenPermissions($value) as $path => $enabled) {
                [$resource, $capability] = array_pad(explode('.', $path, 2), 2, '');
                $lines[] = '  ' . auditPermissionModuleLabel($resource) . ($capability !== '' ? ' ' . strtolower(auditPermissionCapabilityLabel($capability)) : '') . ': ' . ($enabled ? 'Enabled' : 'Disabled');
            }
        } elseif (is_array($value)) {
            $lines[] = $label . ':';
            foreach ($value as $childKey => $childValue) {
                $lines[] = '  ' . auditFieldLabel((string)$childKey) . ': ' . auditScalarText($childValue);
            }
        } else $lines[] = $label . ': ' . auditScalarText($value);
    }
    return mb_substr(implode("\n", $lines), 0, 20000, 'UTF-8');
}

function auditFieldLabel(string $key): string
{
    $labels = [
        'actor_id' => 'Changed by user ID', 'target_user_id' => 'Affected user ID', 'target_username' => 'Affected user',
        'old_mode' => 'Previous access mode', 'new_mode' => 'New access mode', 'old_permissions' => 'Previous permissions (full snapshot)',
        'new_permissions' => 'New permissions (full snapshot)', 'old_user' => 'Previous user information', 'new_user' => 'Updated user information',
        'full_name' => 'Full name', 'username' => 'Username', 'role' => 'Role', 'active' => 'Active', 'reason' => 'Reason',
        'message' => 'Message', 'email' => 'Email', 'id' => 'ID',
    ];
    return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
}

function auditScalarText(mixed $value): string
{
    if (is_bool($value)) return $value ? 'Enabled' : 'Disabled';
    if (is_scalar($value) || $value === null) return (string)($value ?? '—');
    return '[structured value]';
}

function auditActionLabel(string $action, ?string $module = null): string
{
    $label = ucwords(str_replace('_', ' ', trim($action)));
    return $label !== '' ? $label : (auditModuleLabel($module) . ' activity');
}

function auditModuleLabel(?string $module): string
{
    $module = strtolower(trim((string)$module));
    return match ($module) {
        'asset', 'asset_tag_job', 'assignment', 'hardware' => 'Hardware',
        'product', 'inventory' => 'Inventory',
        'category', 'sub_category' => 'Categories',
        'employee' => 'Employees',
        'order' => 'Orders',
        'software' => 'Software',
        'credential', 'credential_department' => 'Credentials',
        'user', 'role_permissions' => 'Access control',
        default => $module !== '' ? ucwords(str_replace('_', ' ', $module)) : '—',
    };
}

function auditCsvSafeCell(mixed $value): string
{
    $value = (string)($value ?? '');
    return preg_match('/^[\s]*[=+@\-]/', $value) ? "'" . $value : $value;
}
