<?php
declare(strict_types=1);
require_once __DIR__ . '/audit_log_helpers.php';
requireLogin();

$pdo = db();
$schema = auditSchema($pdo);
$canInventoryAudit = canPerform('inventory_audit', 'view');
$canCredentialAudit = canPerform('credential_audit', 'view');
if (!$canInventoryAudit && !$canCredentialAudit) requirePermission('inventory_audit', 'view');

$requestedView = (string)($_GET['view'] ?? '');
$view = in_array($requestedView, ['inventory', 'credential', 'all'], true)
    ? $requestedView
    : ($canInventoryAudit ? 'inventory' : 'credential');
$viewPermissions = auditViewPermission($view, $canInventoryAudit, $canCredentialAudit);
foreach ($viewPermissions as [$resource, $capability]) requirePermission($resource, $capability);
$export = (string)($_GET['export'] ?? '');
$exportPermissions = auditExportPermissions($view, $canInventoryAudit, $canCredentialAudit);
$authorizedExportPermissions = array_values(array_filter(
    $exportPermissions,
    static fn(array $permission): bool => canPerform($permission[0], $permission[1])
));
if (in_array($export, ['csv', 'xlsx'], true) && !$authorizedExportPermissions) {
    requirePermission($view === 'credential' ? 'credential_audit' : 'inventory_audit', 'export');
}
$sourceView = $view === 'all' && in_array($export, ['csv', 'xlsx'], true)
    ? auditSourceView('all', $canInventoryAudit && canPerform('inventory_audit', 'export'), $canCredentialAudit && canPerform('credential_audit', 'export'))
    : auditSourceView($view, $canInventoryAudit, $canCredentialAudit);
$canExportAudit = false;
foreach ($exportPermissions as [$resource, $capability]) {
    if (canPerform($resource, $capability)) { $canExportAudit = true; break; }
}

$filters = auditParseFilters($_GET, $view);
$unionSql = auditSourceUnion($pdo, $schema, $sourceView);
$fromSql = ' FROM (' . $unionSql . ') ev LEFT JOIN users u ON u.id=ev.user_id';
$where = [];
$params = [];
if ($filters['search'] !== '') {
    $where[] = '(ev.action LIKE ? OR ev.module LIKE ? OR CAST(ev.target_id AS CHAR) LIKE ? OR ev.details LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)';
    $like = '%' . $filters['search'] . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filters['from'] !== '') { $where[] = 'ev.created_at >= ?'; $params[] = $filters['from'] . ' 00:00:00'; }
if ($filters['to'] !== '') { $where[] = 'ev.created_at < ?'; $params[] = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d 00:00:00'); }
if ($filters['user']) { $where[] = 'ev.user_id = ?'; $params[] = $filters['user']; }
if ($filters['action'] !== '') { $where[] = 'ev.action = ?'; $params[] = $filters['action']; }
if ($filters['target']) { $where[] = 'ev.target_id = ?'; $params[] = $filters['target']; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$orderSql = ' ORDER BY ev.created_at DESC,ev.source_table ASC,ev.source_id DESC';

$countStmt = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$window = auditPageWindow($total, $filters['page'], 50);

if (in_array($export, ['csv', 'xlsx'], true)) {
    $exportStmt = $pdo->prepare('SELECT ev.created_at,COALESCE(NULLIF(u.full_name,\'\'),u.username,\'System\') AS user_name,ev.action,ev.module,ev.target_id,ev.details,ev.ip_address' . $fromSql . $whereSql . $orderSql);
    $exportStmt->execute($params);
    $filename = 'audit-' . $view . '-' . date('Y-m-d');
    while (ob_get_level() > 0) ob_end_clean();
    header('Cache-Control: no-store, private, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $output = fopen('php://output', 'wb');
        fputcsv($output, ['Date & Time', 'User', 'Action', 'Module', 'Target', 'Details', 'IP Address']);
        while ($row = $exportStmt->fetch()) {
            fputcsv($output, [
                auditCsvSafeCell($row['created_at']), auditCsvSafeCell($row['user_name']),
                auditCsvSafeCell(str_replace('_', ' ', (string)$row['action'])), auditCsvSafeCell(auditModuleLabel($row['module'])),
                auditCsvSafeCell($row['target_id'] ? auditModuleLabel($row['module']) . ' #' . $row['target_id'] : '—'),
                auditCsvSafeCell(auditDetailsSummary($row['details'], (string)$row['action'], (string)$row['module'])), auditCsvSafeCell($row['ip_address']),
            ]);
        }
        fclose($output);
        exit;
    }
    require_once __DIR__ . '/vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Date & Time', 'User', 'Action', 'Module', 'Target', 'Details', 'IP Address'], null, 'A1');
    $exportRow = 2;
    while ($row = $exportStmt->fetch()) {
        $values = [
            $row['created_at'], $row['user_name'] ?? 'System', str_replace('_', ' ', (string)$row['action']), auditModuleLabel($row['module']),
            $row['target_id'] ? auditModuleLabel($row['module']) . ' #' . $row['target_id'] : '—', auditDetailsSummary($row['details'], (string)$row['action'], (string)$row['module']), $row['ip_address'] ?? '',
        ];
        foreach ($values as $column => $value) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1) . $exportRow;
            $sheet->setCellValueExplicit($cell, (string)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $exportRow++;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
}

$rowsStmt = $pdo->prepare('SELECT ev.created_at,COALESCE(NULLIF(u.full_name,\'\'),u.username,\'System\') AS user_name,ev.action,ev.module,ev.target_id,ev.details,ev.ip_address' . $fromSql . $whereSql . $orderSql . ' LIMIT ' . (int)$window['page_size'] . ' OFFSET ' . (int)$window['offset']);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();
$actionsStmt = $pdo->query('SELECT DISTINCT ev.action' . ' FROM (' . $unionSql . ') ev ORDER BY ev.action');
$actionOptions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query('SELECT id,full_name,username FROM users WHERE active=1 ORDER BY full_name,username')->fetchAll();
$pageTitle = 'Audit Logs';
require __DIR__ . '/header.php';

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$queryFor = static function (string $targetView, array $changes = []) use ($filters, $view): string {
    $query = $_GET;
    unset($query['export']);
    $query['view'] = $targetView;
    if ($targetView !== $view) unset($query[$targetView . '_page']);
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return 'audit_logs.php?' . http_build_query($query);
};
$activeFilterValues = [
    $filters['prefix'] . 'q' => $filters['search'], $filters['prefix'] . 'from' => $filters['from'],
    $filters['prefix'] . 'to' => $filters['to'], $filters['prefix'] . 'user' => $filters['user'],
    $filters['prefix'] . 'action' => $filters['action'], $filters['prefix'] . 'target' => $filters['target'], $filters['prefix'] . 'page' => null,
];
?>
<section class="card audit-page">
  <div class="section-heading"><div><p class="eyebrow">IMMUTABLE ACTIVITY HISTORY</p><h2>Audit Logs</h2><p class="muted">Audit records are read-only. Access is scoped by audit permissions.</p></div></div>
  <nav class="audit-tabs" aria-label="Audit views">
    <?php if($canInventoryAudit): ?><a class="<?=$view==='inventory'?'active':''?>" href="<?=$escape($queryFor('inventory'))?>" <?=$view==='inventory'?'aria-current="page"':''?>>Inventory Audit</a><?php endif; ?>
    <?php if($canCredentialAudit): ?><a class="<?=$view==='credential'?'active':''?>" href="<?=$escape($queryFor('credential'))?>" <?=$view==='credential'?'aria-current="page"':''?>>Credential Audit</a><?php endif; ?>
    <?php if($canInventoryAudit || $canCredentialAudit): ?><a class="<?=$view==='all'?'active':''?>" href="<?=$escape($queryFor('all'))?>" <?=$view==='all'?'aria-current="page"':''?>>All Activity</a><?php endif; ?>
  </nav>
  <form method="get" class="audit-filters">
    <input type="hidden" name="view" value="<?=$escape($view)?>">
    <div><label for="audit-q">Search</label><input id="audit-q" name="<?=$escape($filters['prefix'])?>q" maxlength="100" value="<?=$escape($filters['search'])?>" placeholder="Action, target, user or details"></div>
    <div><label for="audit-from">From</label><input id="audit-from" name="<?=$escape($filters['prefix'])?>from" type="date" value="<?=$escape($filters['from'])?>"></div>
    <div><label for="audit-to">To</label><input id="audit-to" name="<?=$escape($filters['prefix'])?>to" type="date" value="<?=$escape($filters['to'])?>"></div>
    <div><label for="audit-user">User</label><select id="audit-user" name="<?=$escape($filters['prefix'])?>user"><option value="">All users</option><?php foreach($users as $user): ?><option value="<?=(int)$user['id']?>" <?=((int)($filters['user']??0)===(int)$user['id'])?'selected':''?>><?=$escape($user['full_name'] . ' · ' . $user['username'])?></option><?php endforeach; ?></select></div>
    <div><label for="audit-action">Action type</label><select id="audit-action" name="<?=$escape($filters['prefix'])?>action"><option value="">All actions</option><?php foreach($actionOptions as $action): ?><option value="<?=$escape($action)?>" <?=$filters['action']===$action?'selected':''?>><?=$escape(ucwords(str_replace('_',' ',$action)))?></option><?php endforeach; ?></select></div>
    <div><label for="audit-target">Target ID</label><input id="audit-target" name="<?=$escape($filters['prefix'])?>target" type="number" min="1" value="<?=$escape($filters['target'])?>"></div>
    <div class="audit-filter-actions"><button class="btn" type="submit">Apply filters</button><a class="btn" href="<?=$escape($queryFor($view, array_fill_keys(array_keys($activeFilterValues), null)))?>">Clear</a></div>
  </form>
  <?php if(!$filters['date_valid']): ?><p class="alert" role="alert">Enter a valid date range. The invalid date filter was cleared.</p><?php endif; ?>
  <div class="audit-export-actions"><?php if($canExportAudit): ?><a class="btn" href="<?=$escape($queryFor($view, ['export'=>'csv']))?>">Export CSV</a><a class="btn btn-glow" href="<?=$escape($queryFor($view, ['export'=>'xlsx']))?>">Export XLSX</a><?php endif; ?><span class="muted">Showing <?=$total ? $window['offset'] + 1 : 0?>–<?=min($window['offset'] + count($rows), $total)?> of <?=$total?></span></div>
  <div class="table-scroll audit-table-scroll"><table class="audit-table"><thead><tr><th>Date &amp; Time</th><th>User</th><th>Action</th><th>Module</th><th>Target</th><th>Details</th><th>IP Address</th></tr></thead><tbody>
  <?php foreach($rows as $row): ?><tr><td><?=$escape($row['created_at'])?></td><td><?=$escape($row['user_name'])?></td><td><?=$escape(auditActionLabel((string)$row['action'], (string)$row['module']))?></td><td><?=$escape(auditModuleLabel($row['module']))?></td><td><?=$escape($row['target_id'] ? auditModuleLabel($row['module']) . ' #' . $row['target_id'] : '—')?></td><td class="audit-details-cell"><span><?=$escape(auditDetailsSummary($row['details'], (string)$row['action'], (string)$row['module']))?></span><button type="button" class="audit-view-details" data-audit-details="<?=$escape(auditDetailsFull($row['details'], (string)$row['action'], (string)$row['module']))?>" data-audit-date="<?=$escape($row['created_at'])?>" data-audit-user="<?=$escape($row['user_name'])?>" data-audit-action="<?=$escape(auditActionLabel((string)$row['action'], (string)$row['module']))?>" data-audit-module="<?=$escape(auditModuleLabel($row['module']))?>" data-audit-target="<?=$escape($row['target_id'] ? auditModuleLabel($row['module']) . ' #' . $row['target_id'] : '—')?>" data-audit-ip="<?=$escape($row['ip_address']??'—')?>" aria-haspopup="dialog">View Details</button></td><td><?=$escape($row['ip_address']??'—')?></td></tr><?php endforeach; ?>
  <?php if(!$rows): ?><tr><td colspan="7" class="muted">No audit entries found.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if($window['pages']>1): ?><nav class="audit-pagination" aria-label="Audit pagination"><?php if($window['page']>1): ?><a class="btn" href="<?=$escape($queryFor($view, [$filters['prefix'].'page'=>$window['page']-1]))?>">Previous</a><?php endif; ?><span>Page <?=$window['page']?> of <?=$window['pages']?></span><?php if($window['page']<$window['pages']): ?><a class="btn" href="<?=$escape($queryFor($view, [$filters['prefix'].'page'=>$window['page']+1]))?>">Next</a><?php endif; ?></nav><?php endif; ?>
</section>
<dialog class="audit-details-dialog" id="audit-details-dialog" aria-labelledby="audit-details-title"><div class="audit-details-dialog__header"><h2 id="audit-details-title">Audit Event Details</h2><button class="btn" type="button" data-close-audit-details>Close</button></div><div class="audit-details-dialog__body"><dl class="audit-detail-meta"><div><dt>Date &amp; Time</dt><dd data-audit-meta="date"></dd></div><div><dt>User</dt><dd data-audit-meta="user"></dd></div><div><dt>Action</dt><dd data-audit-meta="action"></dd></div><div><dt>Module</dt><dd data-audit-meta="module"></dd></div><div><dt>Target</dt><dd data-audit-meta="target"></dd></div><div><dt>IP Address</dt><dd data-audit-meta="ip"></dd></div></dl><pre id="audit-details-content"></pre></div></dialog>
<script>
(function(){const dialog=document.getElementById('audit-details-dialog');const content=document.getElementById('audit-details-content');if(!dialog||!content)return;const fields={date:'auditDate',user:'auditUser',action:'auditAction',module:'auditModule',target:'auditTarget',ip:'auditIp'};document.addEventListener('click',function(event){const trigger=event.target.closest('[data-audit-details]');if(trigger){content.textContent=trigger.dataset.auditDetails||'';Object.keys(fields).forEach(function(key){const target=dialog.querySelector('[data-audit-meta="'+key+'"]');if(target)target.textContent=trigger.dataset[fields[key]]||'—';});dialog.showModal();return;}if(event.target.closest('[data-close-audit-details]'))dialog.close();});dialog.addEventListener('close',function(){content.textContent='';dialog.querySelectorAll('[data-audit-meta]').forEach(function(node){node.textContent='';});});})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
