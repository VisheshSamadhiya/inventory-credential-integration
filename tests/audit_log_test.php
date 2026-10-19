<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/audit_log_helpers.php';

function auditCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

auditCheck(auditClassifyAction('inventory_transaction', 'product') === 'inventory', 'Stock movements must classify as inventory activity.');
auditCheck(auditClassifyAction('bulk_asset_tags_generated', 'asset_tag_job') === 'inventory', 'Bulk asset tag events must classify as inventory activity.');
auditCheck(auditClassifyAction('hardware_replaced', 'assignment') === 'inventory', 'Hardware lifecycle events must classify as inventory activity.');
auditCheck(auditClassifyAction('order_rejected', 'order') === 'inventory', 'Order events must classify as inventory activity.');
auditCheck(auditClassifyAction('credential_password_revealed', 'credential') === 'credential', 'Password reveals must classify as credential activity.');
auditCheck(auditClassifyAction('credential_department_access_revoked', 'user') === 'credential', 'Credential access changes must classify as credential activity.');
auditCheck(auditClassifyAction('user_password_reset', 'user') === 'credential', 'User password reset events must classify as credential activity.');
auditCheck(auditClassifyAction('user_permissions_updated', 'user', '{"old_permissions":{"inventory":{"view":false}},"new_permissions":{"inventory":{"view":true}}}') === 'inventory', 'Inventory permission changes must classify as inventory activity.');
$credentialPermissionDetails = '{"old_permissions":{"credentials":{"reveal":false}},"new_permissions":{"credentials":{"reveal":true}}}';
$mixedPermissionDetails = '{"old_permissions":{"inventory":{"stock_adjustment":false},"credentials":{"reveal":false}},"new_permissions":{"inventory":{"stock_adjustment":true},"credentials":{"reveal":true}}}';
auditCheck(auditClassifyActions('user_permissions_updated', 'user', $credentialPermissionDetails) === ['credential'], 'Credential permission changes must classify as credential activity.');
auditCheck(auditClassifyActions('user_permissions_updated', 'user', $mixedPermissionDetails) === ['inventory','credential'], 'Mixed permission changes must appear in both filtered categories.');
auditCheck(auditPermissionChangeCategories('{"old_permissions":{"orders":{"view":false}},"new_permissions":{"orders":{"view":false}}}') === [], 'Unchanged permissions must not create a category classification.');
$inventoryPermissionPredicate = auditSqlClassificationPredicate('a', 'inventory');
$credentialPermissionPredicate = auditSqlClassificationPredicate('a', 'credential');
$allPermissionPredicate = auditSqlClassificationPredicate('a', 'all');
auditCheck(str_contains($inventoryPermissionPredicate, '$.old_permissions.inventory') && !str_contains($inventoryPermissionPredicate, '$.old_permissions.credentials'), 'Inventory SQL classification must not scoop up credential-only permission changes.');
auditCheck(str_contains($credentialPermissionPredicate, '$.old_permissions.credentials') && !str_contains($credentialPermissionPredicate, '$.old_permissions.inventory'), 'Credential SQL classification must not scoop up inventory-only permission changes.');
auditCheck(str_contains($allPermissionPredicate, ' OR '), 'All Activity SQL classification should include mixed permission changes once through a single predicate.');
auditCheck(auditClassifyAction('login', null) === null, 'Unrelated system activity must not enter the inventory or credential views.');

$permissionDiff = auditPermissionChanges('{"old_permissions":{"inventory":{"stock_adjustment":false},"hardware":{"add":false},"categories":{"delete":false,"view":true}},"new_permissions":{"inventory":{"stock_adjustment":true},"hardware":{"add":true},"categories":{"delete":true,"view":true}}}');
$permissionSummary = auditDetailsSummary('{"old_permissions":{"inventory":{"stock_adjustment":false},"hardware":{"add":false},"categories":{"delete":false,"view":true}},"new_permissions":{"inventory":{"stock_adjustment":true},"hardware":{"add":true},"categories":{"delete":true,"view":true}}}', 'user_permissions_updated', 'user');
auditCheck(count($permissionDiff) === 3 && str_contains($permissionSummary, 'Inventory stock adjustment: Disabled → Enabled') && str_contains($permissionSummary, 'Hardware add: Disabled → Enabled') && !str_contains($permissionSummary, 'Categories view'), 'Permission summaries must include only actual changes with readable labels.');
$readableFull = auditDetailsFull($mixedPermissionDetails, 'user_permissions_updated', 'user');
auditCheck(str_contains($readableFull, 'Previous permissions (full snapshot)') && str_contains($readableFull, 'Credentials reveal passwords: Enabled'), 'Full event details should expose readable optional permission snapshots.');
$jsonSummary = auditDetailsSummary('{"target_username":"sam","role":"editor","unchanged":{"nested":true}}', 'user_updated', 'user');
auditCheck(str_contains($jsonSummary, 'Affected user: sam') && !str_contains($jsonSummary, 'unchanged'), 'JSON details should produce concise human-readable summaries.');

$inventoryFilters = auditParseFilters(['inventory_q' => 'stock', 'inventory_from' => '2026-10-01', 'inventory_page' => '4', 'credential_q' => 'other'], 'inventory');
$credentialFilters = auditParseFilters(['inventory_q' => 'stock', 'credential_q' => 'other'], 'credential');
auditCheck($inventoryFilters['search'] === 'stock' && $inventoryFilters['from'] === '2026-10-01' && $inventoryFilters['page'] === 4, 'Inventory filters should parse independently.');
auditCheck($credentialFilters['search'] === 'other' && $credentialFilters['from'] === '', 'Credential filters should use their own namespaced values.');
$badDate = auditParseFilters(['inventory_from' => '2026-02-30', 'inventory_to' => '2026-02-01'], 'inventory');
auditCheck(!$badDate['date_valid'] && $badDate['from'] === '' && $badDate['to'] === '', 'Invalid and reversed dates should be rejected safely.');
$window = auditPageWindow(101, 9, 50);
auditCheck($window === ['page' => 3, 'page_size' => 50, 'pages' => 3, 'offset' => 100, 'total' => 101], 'Pagination should clamp to the last available page.');

auditCheck(auditViewPermission('inventory') === [['inventory_audit', 'view']], 'Inventory audit requires its own view permission.');
auditCheck(auditViewPermission('credential') === [['credential_audit', 'view']], 'Credential audit requires its own explicit permission.');
auditCheck(auditViewPermission('all', true, false) === [['inventory_audit', 'view']], 'All Activity must include only categories with view permission.');
auditCheck(auditViewPermission('all', false, true) === [['credential_audit', 'view']], 'Credential-only users must be able to open All Activity without inventory records.');
auditCheck(auditViewPermission('all', true, true) === [['inventory_audit', 'view'], ['credential_audit', 'view']], 'All Activity should include both categories when both permissions exist.');
auditCheck(auditSourceView('all', true, false) === 'inventory' && auditSourceView('all', false, true) === 'credential', 'All Activity source selection must scope rows to permitted categories.');
auditCheck(auditSourceView('all', true, true) === 'all', 'All Activity should combine both categories for fully authorized users.');
auditCheck(auditExportPermissions('all', true, false) === [['inventory_audit', 'export']], 'All Activity export permissions must remain category-scoped.');
auditCheck(auditExportPermissions('all', false, true) === [['credential_audit', 'export']], 'Credential export permission must not grant inventory export.');
$catalog = permissionCatalog();
$matrix = buildEffectivePermissionMatrix($catalog, [], 'custom', ['credential_audit' => ['view' => true]], false);
auditCheck(($matrix['credential_audit']['view'] ?? false) === true && ($matrix['inventory_audit']['view'] ?? false) === false, 'Credential audit permission must not imply inventory audit access.');

$safeJson = auditSafeDetails('{"actor":"admin","password_hash":"hidden-password","smtp_password":"hidden-smtp","new_permissions":{"credentials":{"audit":true}}}');
auditCheck(!str_contains($safeJson, 'hidden-password') && !str_contains($safeJson, 'hidden-smtp') && str_contains($safeJson, '[redacted]'), 'Sensitive JSON audit values must be redacted.');
$safeCredential = auditDetailsFull('{"credential_username":"hidden-login","private_key":"hidden-private-key","access_token":"hidden-token"}');
auditCheck(!str_contains($safeCredential, 'hidden-login') && !str_contains($safeCredential, 'hidden-private-key') && !str_contains($safeCredential, 'hidden-token'), 'Credential usernames, private keys, and access tokens must be redacted from details.');
$safeExportSummary = auditDetailsSummary('{"target_username":"sam","api_token":"hidden-export-token","smtp_password":"hidden-export-password"}', 'credential_updated', 'credential');
auditCheck(!str_contains($safeExportSummary, 'hidden-export-token') && !str_contains($safeExportSummary, 'hidden-export-password'), 'Readable export summaries must not expose secrets from JSON details.');
$safeText = auditSafeDetails('password=hidden-password; CREDENTIAL_ENCRYPTION_KEY=hidden-key SMTP_PASSWORD=hidden-smtp');
auditCheck(!str_contains($safeText, 'hidden-password') && !str_contains($safeText, 'hidden-key') && !str_contains($safeText, 'hidden-smtp'), 'Sensitive plain-text audit values must be redacted.');
auditCheck(auditCsvSafeCell('=HYPERLINK("https://example.invalid")') !== '=HYPERLINK("https://example.invalid")', 'CSV exports must neutralize formula-like cells.');

$auditPage = file_get_contents(__DIR__ . '/../app/audit_logs.php');
$auditHelpers = file_get_contents(__DIR__ . '/../app/audit_log_helpers.php');
$auditHeader = file_get_contents(__DIR__ . '/../app/header.php');
$authSource = file_get_contents(__DIR__ . '/../app/auth.php');
$credentialHelpersSource = file_get_contents(__DIR__ . '/../app/credential_helpers.php');
$auditEndpoint = is_string($auditPage) ? $auditPage : '';
$headerSource = is_string($auditHeader) ? $auditHeader : '';
$authText = is_string($authSource) ? $authSource : '';
$credentialHelpersText = is_string($credentialHelpersSource) ? $credentialHelpersSource : '';
auditCheck(str_contains($auditEndpoint, 'requirePermission($resource, $capability)'), 'The audit page must enforce view permissions server-side.');
auditCheck(str_contains($auditEndpoint, 'requirePermission($resource, $capability)') && str_contains($auditEndpoint, 'auditExportPermissions($view'), 'Exports must enforce separate server-side audit export permissions.');
auditCheck(substr_count($auditEndpoint, 'auditDetailsSummary($row[\'details\']') >= 3 && str_contains($auditEndpoint, 'TYPE_STRING'), 'Table, CSV, and XLSX reports must use readable summaries and spreadsheet values emitted as text.');
auditCheck(str_contains((string)$auditHelpers, 'information_schema.COLUMNS') && str_contains((string)$auditHelpers, 'auditHasColumns($schema, \'activity_logs\''), 'Optional audit source tables must be schema-checked before use.');
auditCheck(str_contains($headerSource, 'audit_logs.php?view=inventory') && str_contains($headerSource, 'audit_logs.php?view=credential') && str_contains($headerSource, 'audit_logs.php?view=all'), 'Audit navigation options must open their matching views directly.');
auditCheck(str_contains($auditEndpoint, '>Inventory Audit</a>') && str_contains($auditEndpoint, '>Credential Audit</a>') && str_contains($auditEndpoint, '>All Activity</a>'), 'The Audit Logs page must retain its three matching tabs.');
auditCheck(str_contains($auditEndpoint, 'auditSourceView($view, $canInventoryAudit, $canCredentialAudit)'), 'Tab source selection must be constrained by the current user’s view permissions.');
auditCheck(str_contains($auditEndpoint, 'requirePermission($view === \'credential\' ? \'credential_audit\' : \'inventory_audit\', \'export\')'), 'Unauthorized exports must be rejected server-side.');
auditCheck(str_contains((string)$auditHelpers, 'NOT EXISTS (SELECT 1 FROM audit_logs'), 'Fallback audit sources must avoid duplicate entries already recorded in audit_logs.');
auditCheck(str_contains((string)$auditHelpers, "cal.action<>'revealed'") && !str_contains((string)$auditHelpers, "cal.action='revealed' AND a.action='credential_password_revealed'"), 'Separate genuine password reveal events must not be collapsed by timestamp deduplication.');
$hadRemoteIp = array_key_exists('REMOTE_ADDR', $_SERVER); $oldRemoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
$hadForwardedIp = array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER); $oldForwardedIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.45'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
auditCheck(trustedRequestIpAddress() === '192.0.2.45', 'Trusted IP resolution must use the validated connection peer, not an untrusted forwarded header.');
$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
auditCheck(trustedRequestIpAddress() === null, 'Invalid peer addresses must not be recorded as client IPs.');
if ($hadRemoteIp) $_SERVER['REMOTE_ADDR'] = $oldRemoteIp; else unset($_SERVER['REMOTE_ADDR']);
if ($hadForwardedIp) $_SERVER['HTTP_X_FORWARDED_FOR'] = $oldForwardedIp; else unset($_SERVER['HTTP_X_FORWARDED_FOR']);
auditCheck(str_contains($authText, 'function trustedRequestIpAddress()') && str_contains($authText, '$_SERVER[\'REMOTE_ADDR\']') && !str_contains($authText, 'HTTP_X_FORWARDED_FOR'), 'Audit IP capture must use a validated trusted peer address and ignore spoofable forwarded headers.');
auditCheck(str_contains($authText, 'details, ip_address) VALUES') && str_contains($credentialHelpersText, 'trustedRequestIpAddress()') && str_contains((string)file_get_contents(__DIR__ . '/../app/asset_tag_helpers.php'), 'trustedRequestIpAddress()'), 'Future request audit events must record trusted IP addresses.');
auditCheck(str_contains($auditEndpoint, 'data-audit-details=') && str_contains($auditEndpoint, 'audit-details-dialog') && str_contains($auditEndpoint, 'textContent'), 'Full event details must be shown safely in a modal, not as raw JSON in the table.');

echo "Audit log checks passed: classification, scoped filters, pagination, RBAC, export and redaction.\n";
