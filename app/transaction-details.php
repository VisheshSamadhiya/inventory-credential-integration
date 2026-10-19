<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/history_helpers.php';
require_once __DIR__ . '/inventory_valuation.php';
requirePermission('history', 'view');
header('Cache-Control: no-store, private, max-age=0, must-revalidate');
header('Pragma: no-cache');

$recordType = (string)($_GET['record'] ?? (isset($_GET['id']) ? 'stock' : ''));
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!in_array($recordType, ['stock', 'hardware'], true) || !$id) { http_response_code(404); exit('History record not found'); }
$safe = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$notRecorded = static fn(mixed $value): string => $value === null || $value === '' ? 'Unavailable' : (string)$value;
$money = static fn(mixed $value): string => $value === null ? 'Cost unavailable' : '₹' . number_format((float)$value, 2, '.', ',');
$navigationToken = (string)($_GET['nav'] ?? '');
$navigation = null;
if (preg_match('/^[a-f0-9]{32}$/D', $navigationToken)) {
    $candidate = $_SESSION['history_navigation'][$navigationToken] ?? null;
    if (is_array($candidate) && ($candidate['record'] ?? '') === $recordType && is_array($candidate['ids'] ?? null)) $navigation = $candidate;
}
$backUrl = 'history.php';
if ($navigation && is_string($navigation['back'] ?? null)) {
    parse_str($navigation['back'], $backFilters);
    $allowedBackKeys = ['q','product','category','employee','type','order_status','time','from_date','from_time','to_date','to_time','page','hardware_page'];
    $safeBack = [];
    foreach ($allowedBackKeys as $key) {
        if (isset($backFilters[$key]) && is_scalar($backFilters[$key])) $safeBack[$key] = substr((string)$backFilters[$key], 0, 200);
    }
    if ($safeBack) $backUrl .= '?' . http_build_query($safeBack);
}
$navigationIds = $navigation ? array_values(array_filter(array_map('intval', $navigation['ids']), static fn(int $value): bool => $value > 0)) : [];
$navigationIndex = $navigation ? array_search((int)$id, $navigationIds, true) : false;
$previousId = $navigationIndex !== false && $navigationIndex > 0 ? $navigationIds[$navigationIndex - 1] : null;
$nextId = $navigationIndex !== false && $navigationIndex < count($navigationIds) - 1 ? $navigationIds[$navigationIndex + 1] : null;
$navHref = static fn(int $target): string => 'transaction-details.php?' . http_build_query(['record'=>$recordType,'id'=>$target,'nav'=>$navigationToken]);

$pageTitle = '';
$stock = null;
$hardware = null;
$assignment = null;
$assets = [];
$relatedStock = [];
$relatedReceipts = [];
$assignmentEvents = [];
$costLayer = null;
$costAllocations = [];
$reconciliationRows = [];
$historicalValuation = null;
$assignmentImpact = null;
$reconciliationReady = false;
$costLayerReconciliationColumn = false;

try {
    $pdo = db();
    $costLayerReconciliationColumn = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_cost_layers' AND COLUMN_NAME='reconciliation_id'")->fetchColumn();
    // Cost-layer links and reconciliation records were introduced by separate
    // schema changes. Either capability may exist without the other.
    $reconciliationReady = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_cost_reconciliations'")->fetchColumn();

if ($recordType === 'stock') {
    $detailQuery = $pdo->prepare('SELECT t.*,p.name product_name,p.brand,p.model,p.category_id,c.name category_name,p.sub_category_id,sc.name subcategory_name,u.full_name user_name,COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) vendor_name FROM inventory_transactions t JOIN products p ON p.id=t.product_id JOIN categories c ON c.id=p.category_id LEFT JOIN sub_categories sc ON sc.id=p.sub_category_id LEFT JOIN users u ON u.id=t.created_by LEFT JOIN vendors v ON v.id=t.vendor_id WHERE t.id=?');
    $detailQuery->execute([$id]);
    $stock = $detailQuery->fetch();
    if (!$stock) { http_response_code(404); exit('History record not found'); }
    $pageTitle = 'Stock transaction #' . (int)$stock['id'];
    $orderId = (int)($stock['related_order_id'] ?: ($stock['transaction_type'] === 'ordered' ? $stock['id'] : 0));
    if ($orderId > 0) {
        $orderQuery = $pdo->prepare("SELECT t.id,t.quantity,t.status,t.unit_price,t.total_price,t.vendor_id,COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) vendor_name,t.vendor_contact_snapshot,t.vendor_phone_snapshot,t.vendor_email_snapshot,t.expected_date,t.reference_number,t.reason,t.order_reason,t.confirmed_at,t.rejected_at,t.rejection_reason FROM inventory_transactions t LEFT JOIN vendors v ON v.id=t.vendor_id WHERE t.id=? AND t.transaction_type='ordered'");
        $orderQuery->execute([$orderId]);
        $purchaseOrder = $orderQuery->fetch() ?: null;
        $receiptQuery = $pdo->prepare("SELECT id,quantity,transaction_at,unit_price,total_price,reference_number,status,created_by FROM inventory_transactions WHERE related_order_id=? AND transaction_type='in' ORDER BY transaction_at,id");
        $receiptQuery->execute([$orderId]);
        $relatedReceipts = $receiptQuery->fetchAll();
    } else $purchaseOrder = null;

    $assignmentId = inventoryHistoryAssignmentId($stock);
    if ($assignmentId !== null) {
        $assignmentQuery = $pdo->prepare('SELECT a.*,e.full_name employee_name,e.employee_id employee_code,e.department employee_department,e.location employee_location,au.full_name assigned_by,ia.asset_tag,ia.serial_number,ia.internal_serial_number,ia.location asset_location,ia.asset_condition FROM assignments a LEFT JOIN employees e ON e.id=a.employee_record_id OR (a.employee_record_id IS NULL AND e.employee_id=a.employee_id) LEFT JOIN users au ON au.id=a.assigned_by LEFT JOIN inventory_assets ia ON ia.id=a.asset_id WHERE a.id=?');
        $assignmentQuery->execute([$assignmentId]);
        $assignment = $assignmentQuery->fetch() ?: null;
        $assignmentEventsQuery = $pdo->prepare('SELECT h.id,h.action,h.performed_at,h.reason,u.full_name performed_by FROM hardware_assignment_history h LEFT JOIN users u ON u.id=h.performed_by WHERE h.assignment_id=? ORDER BY h.performed_at,h.id');
        $assignmentEventsQuery->execute([$assignmentId]);
        $assignmentEvents = $assignmentEventsQuery->fetchAll();
    }

    $timelineSql = "SELECT t.id,t.id transaction_id,t.product_id,t.transaction_at,t.transaction_type,t.quantity,t.previous_quantity,t.new_quantity,t.reason,t.notes,t.reference_number,t.related_order_id,t.order_reason,t.status,
        COALESCE(t.unit_price,receipt_cost.unit_cost,CASE WHEN t.transaction_type='in' AND t.status='Received' AND po.status IN ('Confirmed','Received') THEN COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) END) unit_price,
        COALESCE(t.total_price,CASE WHEN t.unit_price IS NOT NULL THEN ABS(t.quantity)*t.unit_price END,CASE WHEN assigned_cost.units>=ABS(t.quantity) THEN assigned_cost.value END,CASE WHEN receipt_cost.original_quantity>=ABS(t.quantity) THEN ABS(t.quantity)*receipt_cost.unit_cost END,CASE WHEN t.transaction_type='in' AND t.status='Received' AND po.status IN ('Confirmed','Received') AND COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) IS NOT NULL THEN ABS(t.quantity)*COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) END) total_price,
        CASE WHEN t.total_price IS NOT NULL OR t.unit_price IS NOT NULL THEN ABS(t.quantity) WHEN assigned_cost.units IS NOT NULL THEN LEAST(ABS(t.quantity),assigned_cost.units) WHEN receipt_cost.original_quantity IS NOT NULL THEN LEAST(ABS(t.quantity),receipt_cost.original_quantity) WHEN t.transaction_type='in' AND t.status='Received' AND po.status IN ('Confirmed','Received') AND COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) IS NOT NULL THEN ABS(t.quantity) ELSE 0 END cost_covered_quantity,
        COALESCE(t.total_price,CASE WHEN t.unit_price IS NOT NULL THEN ABS(t.quantity)*t.unit_price END,assigned_cost.value,CASE WHEN receipt_cost.original_quantity IS NOT NULL THEN LEAST(ABS(t.quantity),receipt_cost.original_quantity)*receipt_cost.unit_cost END,CASE WHEN t.transaction_type='in' AND t.status='Received' AND po.status IN ('Confirmed','Received') AND COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) IS NOT NULL THEN ABS(t.quantity)*COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) END) known_cost_amount
        FROM inventory_transactions t LEFT JOIN inventory_transactions po ON po.id=COALESCE(t.related_order_id,CASE WHEN t.transaction_type='ordered' THEN t.id END)
        LEFT JOIN inventory_cost_layers receipt_cost ON receipt_cost.source_transaction_id=t.id
        LEFT JOIN (SELECT source_transaction_id,SUM(quantity) units,SUM(quantity*unit_cost) value FROM inventory_cost_allocations GROUP BY source_transaction_id) assigned_cost ON assigned_cost.source_transaction_id=t.id
        WHERE t.product_id=? ORDER BY t.transaction_at,t.id";
    $eventsQuery = $pdo->prepare($timelineSql);
    $eventsQuery->execute([(int)$stock['product_id']]);
    $events = $eventsQuery->fetchAll();
    foreach ($events as &$event) {
        $event['id'] = (int)$event['transaction_id'];
        if ((int)$event['transaction_id'] === (int)$id) {
            foreach (['unit_price','total_price','cost_covered_quantity','known_cost_amount'] as $costField) $stock[$costField] = $event[$costField] ?? null;
        }
    }
    unset($event);
    $expectedQuery = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN asset_id IS NOT NULL THEN 1 ELSE GREATEST(quantity-returned_quantity,0) END),0) FROM assignments WHERE status='assigned' AND product_id=?");
    $expectedQuery->execute([(int)$stock['product_id']]);
    $assignmentTimeline = inventoryHistoryAssignmentTimeline($events, (int)$expectedQuery->fetchColumn());
    foreach ($events as &$event) {
        $eventId = (int)$event['transaction_id'];
        $event['assigned_before'] = $assignmentTimeline['rows'][$eventId]['assigned_before'] ?? null;
        $event['assigned_after'] = $assignmentTimeline['rows'][$eventId]['assigned_after'] ?? null;
    }
    unset($event);
    $knownCurrent = inventoryValuationSnapshotByProduct($pdo, [(int)$stock['product_id']])[(int)$stock['product_id']] ?? null;
    $valuationTimeline = inventoryHistoryValuationTimeline($events, $assignmentTimeline['reliable'], $knownCurrent);
    $historicalValuation = $valuationTimeline['rows'][(int)$id] ?? null;
    $stock['historical_valuation'] = $historicalValuation;
    $stock['stock_calculation'] = inventoryHistoryStockPresentation($stock);
    $stock['movement_label'] = inventoryHistoryMovementLabel($stock);
    $stock['assignment_id'] = $assignmentId;
    $stock['assigned_change'] = inventoryHistoryAssignedImpact($stock);
    $timelineAssignment = $assignmentTimeline['rows'][(int)$id] ?? null;
    $stock['assigned_before'] = $timelineAssignment['assigned_before'] ?? null;
    $stock['assigned_after'] = $timelineAssignment['assigned_after'] ?? null;
    $stock['physical_before'] = $stock['stock_calculation']['stock_before'] !== null && $stock['assigned_before'] !== null ? $stock['stock_calculation']['stock_before'] + $stock['assigned_before'] : null;
    $stock['physical_after'] = $stock['stock_calculation']['stock_after'] !== null && $stock['assigned_after'] !== null ? $stock['stock_calculation']['stock_after'] + $stock['assigned_after'] : null;
    $stock['physical_value_impact'] = inventoryHistoryPhysicalValueImpact($stock);
    $stock['assignment_valuation'] = inventoryHistoryAssignmentValuation($stock);
    $reconciliationColumn = $costLayerReconciliationColumn ? 'reconciliation_id' : 'NULL AS reconciliation_id';
    $costLayerQuery = $pdo->prepare('SELECT id,source_transaction_id,' . $reconciliationColumn . ',unit_cost,original_quantity,remaining_quantity,created_at FROM inventory_cost_layers WHERE source_transaction_id=? ORDER BY id');
    $costLayerQuery->execute([$id]);
    $costLayer = $costLayerQuery->fetch() ?: null;
    if ($assignmentId !== null) {
        $allocationQuery = $pdo->prepare('SELECT a.id,a.cost_layer_id,a.source_transaction_id,a.source_type,a.quantity,a.returned_quantity,a.unit_cost,l.source_transaction_id receipt_id FROM inventory_cost_allocations a JOIN inventory_cost_layers l ON l.id=a.cost_layer_id WHERE a.assignment_id=? ORDER BY a.id');
        $allocationQuery->execute([$assignmentId]);
    } else {
        $allocationQuery = $pdo->prepare('SELECT a.id,a.cost_layer_id,a.source_transaction_id,a.source_type,a.quantity,a.returned_quantity,a.unit_cost,l.source_transaction_id receipt_id FROM inventory_cost_allocations a JOIN inventory_cost_layers l ON l.id=a.cost_layer_id WHERE a.source_transaction_id=? ORDER BY a.id');
        $allocationQuery->execute([$id]);
    }
    $costAllocations = $allocationQuery->fetchAll();
    if ($reconciliationReady) {
        $reconciliationSql = 'SELECT r.id,r.kind,r.quantity,r.unit_cost,r.source_reference,r.notes,r.created_at,u.full_name recorded_by FROM inventory_cost_reconciliations r JOIN users u ON u.id=r.created_by WHERE r.source_transaction_id=?';
        $reconciliationParams = [(int)$id];
        if ($assignmentId !== null) { $reconciliationSql .= ' OR r.assignment_id=?'; $reconciliationParams[] = $assignmentId; }
        $reconciliationSql .= ' ORDER BY r.created_at,r.id';
        $reconciliationQuery = $pdo->prepare($reconciliationSql);
        $reconciliationQuery->execute($reconciliationParams);
        $reconciliationRows = $reconciliationQuery->fetchAll();
    }
} else {
    $hardwareQuery = $pdo->prepare("SELECT h.id event_id,h.performed_at,h.employee_id,h.assignment_id,h.action,h.reason,h.old_asset_id,h.new_asset_id,e.full_name employee_name,e.employee_id employee_code,e.department employee_department,e.location employee_location,u.full_name performed_by FROM hardware_assignment_history h JOIN employees e ON e.id=h.employee_id LEFT JOIN users u ON u.id=h.performed_by WHERE h.id=?");
    $hardwareQuery->execute([$id]);
    $hardware = $hardwareQuery->fetch();
    if (!$hardware) { http_response_code(404); exit('History record not found'); }
    $pageTitle = 'Hardware event #' . (int)$hardware['event_id'];
    $assignmentQuery = $pdo->prepare('SELECT a.*,e.full_name employee_name,e.employee_id employee_code,e.department employee_department,e.location employee_location,au.full_name assigned_by FROM assignments a LEFT JOIN employees e ON e.id=a.employee_record_id OR (a.employee_record_id IS NULL AND e.employee_id=a.employee_id) LEFT JOIN users au ON au.id=a.assigned_by WHERE a.id=?');
    $assignmentQuery->execute([(int)$hardware['assignment_id']]);
    $assignment = $assignmentQuery->fetch() ?: null;
    $assignmentEventsQuery = $pdo->prepare('SELECT h.id,h.action,h.performed_at,h.reason,u.full_name performed_by FROM hardware_assignment_history h LEFT JOIN users u ON u.id=h.performed_by WHERE h.assignment_id=? ORDER BY h.performed_at,h.id');
    $assignmentEventsQuery->execute([(int)$hardware['assignment_id']]);
    $assignmentEvents = $assignmentEventsQuery->fetchAll();
    $assetIds = array_values(array_unique(array_filter([(int)($hardware['old_asset_id'] ?? 0),(int)($hardware['new_asset_id'] ?? 0)], static fn(int $assetId): bool => $assetId > 0)));
    if ($assetIds) {
        $marks = implode(',', array_fill(0, count($assetIds), '?'));
        $assetQuery = $pdo->prepare("SELECT ia.id,ia.product_id,ia.asset_tag,ia.serial_number,ia.internal_serial_number,ia.status,ia.location,ia.asset_condition,ia.acquisition_unit_cost,p.name product_name,p.brand,p.model,c.name category_name,sc.name subcategory_name FROM inventory_assets ia JOIN products p ON p.id=ia.product_id JOIN categories c ON c.id=p.category_id LEFT JOIN sub_categories sc ON sc.id=p.sub_category_id WHERE ia.id IN ($marks)");
        $assetQuery->execute($assetIds);
        $assets = $assetQuery->fetchAll();
    }
    $reconciliationConditions = [];
    $reconciliationParams = [];
    if ($assetIds) { $reconciliationConditions[] = 'r.asset_id IN (' . implode(',', array_fill(0, count($assetIds), '?')) . ')'; array_push($reconciliationParams, ...$assetIds); }
    if ((int)$hardware['assignment_id'] > 0) { $reconciliationConditions[] = 'r.assignment_id=?'; $reconciliationParams[] = (int)$hardware['assignment_id']; }
    if ($reconciliationReady && $reconciliationConditions) {
        $reconciliationQuery = $pdo->prepare('SELECT r.id,r.kind,r.quantity,r.unit_cost,r.source_reference,r.notes,r.created_at,u.full_name recorded_by FROM inventory_cost_reconciliations r JOIN users u ON u.id=r.created_by WHERE ' . implode(' OR ', $reconciliationConditions) . ' ORDER BY r.created_at,r.id');
        $reconciliationQuery->execute($reconciliationParams);
        $reconciliationRows = $reconciliationQuery->fetchAll();
    }
    $assetProductId = (int)($assets[0]['product_id'] ?? $assignment['product_id'] ?? 0);
    if ($assetProductId > 0) {
        $relatedQuery = $pdo->prepare("SELECT id,transaction_type,transaction_at,quantity,previous_quantity,new_quantity,reason,unit_price,total_price FROM inventory_transactions WHERE product_id=? AND LOWER(CONCAT(COALESCE(notes,''),' ',COALESCE(reason,''))) REGEXP CONCAT('assignment[[:space:]]*#[[:space:]]*',?,'([^0-9]|$)') ORDER BY transaction_at,id");
        $relatedQuery->execute([$assetProductId,(int)$hardware['assignment_id']]);
        $relatedStock = $relatedQuery->fetchAll();
    }
}
} catch (PDOException $error) {
    $sqlState = preg_match('/^[0-9A-Z]{5}$/', (string)$error->getCode()) ? (string)$error->getCode() : 'unknown';
    $driverCode = isset($error->errorInfo[1]) && is_scalar($error->errorInfo[1]) ? (string)$error->errorInfo[1] : 'unknown';
    error_log(sprintf('Transaction details database query failed: record=%s id=%d sqlstate=%s driver_code=%s', $recordType, (int)$id, $sqlState, $driverCode));
    http_response_code(500);
    exit('Unable to load this history record. Please try again later.');
}

$suppressBackButton = true;
$pageHeadingInTemplate = true;
require __DIR__ . '/header.php';
?>
<section class="card history-page transaction-details-page">
    <div class="history-detail-heading"><div><div class="eyebrow">HISTORICAL RECORD</div><h2><?= $safe($pageTitle) ?></h2></div><div class="history-detail-nav"><a class="btn" href="<?= $safe($backUrl) ?>">Back to History</a><?php if ($previousId !== null): ?><a class="btn" rel="prev" href="<?= $safe($navHref($previousId)) ?>">Previous</a><?php endif; ?><?php if ($nextId !== null): ?><a class="btn" rel="next" href="<?= $safe($navHref($nextId)) ?>">Next</a><?php endif; ?><?php if (canPerform('inventory_audit','view')): ?><a class="btn" href="audit_logs.php?view=inventory">Inventory audit log</a><?php endif; ?></div></div>
    <?php if ($stock): $calculation=$stock['stock_calculation']; $physicalChange=$stock['physical_before']!==null&&$stock['physical_after']!==null?(int)$stock['physical_after']-(int)$stock['physical_before']:null; $assignmentValuation=$stock['assignment_valuation']; $formatCents=static fn(?int $cents):string=>$cents===null?'Unavailable':($cents===0?'₹0.00':($cents>0?'+':'-').'₹'.number_format(abs($cents)/100,2,'.',',')); ?>
    <section class="history-detail-section"><h3>A. Transaction information</h3><dl class="history-detail-grid">
        <div><dt>Transaction ID</dt><dd>#<?= (int)$stock['id'] ?></dd></div><div><dt>Transaction type</dt><dd><?= $safe($stock['movement_label']) ?></dd></div><div><dt>Date and time</dt><dd><?= $safe($stock['transaction_at']) ?></dd></div><div><dt>Performed by</dt><dd><?= $safe($notRecorded($stock['user_name'] ?? null)) ?></dd></div>
        <div><dt>Reason</dt><dd><?= $safe($notRecorded($stock['order_reason'] ?: $stock['reason'])) ?></dd></div><div><dt>Notes</dt><dd><?= $safe($notRecorded($stock['notes'])) ?></dd></div><div><dt>Status</dt><dd><?= $safe($notRecorded($stock['status'])) ?></dd></div><div><dt>Invoice / reference</dt><dd><?= $safe($notRecorded($stock['reference_number'])) ?></dd></div>
    </dl></section>
    <section class="history-detail-section"><h3>B. Product information</h3><dl class="history-detail-grid">
        <div><dt>Product</dt><dd><?= $safe($stock['product_name']) ?></dd></div><div><dt>Category</dt><dd><?= $safe($stock['category_name']) ?></dd></div><div><dt>Subcategory</dt><dd><?= $safe($notRecorded($stock['subcategory_name'])) ?></dd></div><div><dt>Brand / model</dt><dd><?= $safe(trim((string)($stock['brand'] ?? '') . ' ' . (string)($stock['model'] ?? '')) ?: 'Unavailable') ?></dd></div>
    </dl></section>
    <section class="history-detail-section"><h3>C. Stock calculations</h3><dl class="history-detail-grid">
        <div><dt>Opening available quantity</dt><dd><?= $safe(inventoryHistoryBalanceDisplay($calculation['stock_before'])) ?></dd></div><div><dt>Quantity added or deducted</dt><dd><?= $stock['transaction_type']==='ordered'?'0 stock impact · '.number_format((int)$calculation['quantity']).' ordered':$safe($calculation['stock_impact']===null?'Historical balance unavailable':sprintf('%+d',(int)$calculation['stock_impact'])) ?></dd></div><div><dt>Closing available quantity</dt><dd><?= $safe(inventoryHistoryBalanceDisplay($calculation['stock_after'])) ?></dd></div><div><dt>Assigned units before / change / after</dt><dd><?php if ($stock['assigned_before'] === null): ?>Historical balance unavailable<?php else: ?><?= number_format((int)$stock['assigned_before']) ?> / <?= sprintf('%+d',(int)$stock['assigned_change']) ?> / <?= number_format((int)$stock['assigned_after']) ?><?php endif; ?></dd></div><div><dt>Total physical units before / change / after</dt><dd><?php if ($stock['physical_before'] === null): ?>Historical balance unavailable<?php else: ?><?= number_format((int)$stock['physical_before']) ?> / <?= sprintf('%+d',(int)$physicalChange) ?> / <?= number_format((int)$stock['physical_after']) ?><?php endif; ?></dd></div>
        <?php if ($assignmentValuation !== null): ?><div><dt>Assignment value transfer</dt><dd><?= $safe(inventoryHistoryAssignmentValuationDisplay($assignmentValuation)) ?><br><small>Available change <?= $safe($formatCents($assignmentValuation['available_change_cents'])) ?> · assigned change <?= $safe($formatCents($assignmentValuation['assigned_change_cents'])) ?> · physical change <?= $safe($formatCents($assignmentValuation['physical_change_cents'])) ?></small></dd></div><?php endif; ?>
    </dl><?php if ($stock['transaction_type']==='ordered'): ?><p class="history-detail-note">This purchase order is an order-only event. It does not change available or physical stock; only linked receipt transactions do.</p><?php endif; ?></section>
    <?php if (($purchaseOrder && canViewResource('orders')) || ($relatedReceipts && canViewResource('orders')) || $assignment || $assignmentEvents || $costAllocations || $reconciliationRows): ?>
    <section class="history-detail-section"><h3>D. Related records</h3>
        <?php if ($purchaseOrder): ?><h4>Purchase order <?= canViewResource('orders')?'#'.(int)$purchaseOrder['id']:'(restricted)' ?></h4><?php if (canViewResource('orders')): ?><dl class="history-detail-grid"><div><dt>Order quantity</dt><dd><?= number_format(abs((int)$purchaseOrder['quantity'])) ?></dd></div><div><dt>Order status</dt><dd><?= $safe($purchaseOrder['status'] ?? 'Unavailable') ?></dd></div><div><dt>Ordered unit / total value</dt><dd><?= $safe($money($purchaseOrder['unit_price'])) ?> / <?= $safe($money($purchaseOrder['total_price'])) ?> · ordered value only</dd></div><div><dt>Vendor</dt><dd><?= $safe(canViewResource('vendors')?$notRecorded($purchaseOrder['vendor_name']):'Restricted') ?></dd></div><div><dt>Vendor contact</dt><dd><?= $safe(canViewResource('vendors')?trim(implode(' · ',array_filter([$purchaseOrder['vendor_contact_snapshot'],$purchaseOrder['vendor_phone_snapshot'],$purchaseOrder['vendor_email_snapshot']] ))):'Restricted') ?></dd></div><div><dt>Expected date / order reference</dt><dd><?= $safe($notRecorded($purchaseOrder['expected_date'])) ?> / <?= $safe($notRecorded($purchaseOrder['reference_number'])) ?></dd></div></dl><?php endif; ?>
        <?php if ($relatedReceipts && canViewResource('orders')): ?><h4>Linked receipts</h4><div class="table-scroll"><table class="history-detail-table"><thead><tr><th>Receipt</th><th>Date</th><th>Quantity</th><th>Unit cost</th><th>Total</th><th>Reference</th></tr></thead><tbody><?php foreach($relatedReceipts as $receipt): ?><tr><td><a href="transaction-details.php?record=stock&amp;id=<?=(int)$receipt['id']?>">#<?=(int)$receipt['id']?></a></td><td><?= $safe($receipt['transaction_at']) ?></td><td><?= number_format(abs((int)$receipt['quantity'])) ?></td><td><?= $safe($money($receipt['unit_price'])) ?></td><td><?= $safe($money($receipt['total_price'])) ?></td><td><?= $safe($notRecorded($receipt['reference_number'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?><?php endif; ?>
        <?php if ($assignment): ?><h4>Employee and assignment</h4><dl class="history-detail-grid"><div><dt>Employee</dt><dd><?= $safe($notRecorded($assignment['employee_name'] ?? $assignment['assigned_to'])) ?></dd></div><div><dt>Employee ID</dt><dd><?= $safe($notRecorded($assignment['employee_code'] ?? $assignment['employee_id'])) ?></dd></div><div><dt>Assignment ID / status</dt><dd>#<?= (int)$assignment['id'] ?> · <?= $safe($assignment['status']) ?></dd></div><div><dt>Assignment date / returned date</dt><dd><?= $safe($assignment['assigned_at']) ?> / <?= $safe($notRecorded($assignment['returned_at'])) ?></dd></div><div><dt>Quantity / returned</dt><dd><?= number_format((int)$assignment['quantity']) ?> / <?= number_format((int)$assignment['returned_quantity']) ?></dd></div><div><dt>Assigned by / location</dt><dd><?= $safe($notRecorded($assignment['assigned_by'])) ?> / <?= $safe($notRecorded($assignment['location'])) ?></dd></div><div><dt>Asset tag / serial numbers</dt><dd><?= $safe($notRecorded($assignment['asset_tag'])) ?> / <?= $safe($notRecorded($assignment['serial_number'])) ?><?= !empty($assignment['internal_serial_number'])?' · Internal '. $safe($assignment['internal_serial_number']):'' ?></dd></div></dl><?php endif; ?>
        <?php if ($assignmentEvents): ?><h4>Assignment, return, replacement and removal events</h4><div class="history-event-list"><?php foreach($assignmentEvents as $assignmentEvent): ?><article><strong><?= $safe(ucfirst((string)$assignmentEvent['action'])) ?></strong><span><?= $safe($assignmentEvent['performed_at']) ?></span><p><?= $safe($notRecorded($assignmentEvent['reason'])) ?></p><small><?= $safe($notRecorded($assignmentEvent['performed_by'])) ?></small></article><?php endforeach; ?></div><?php endif; ?>
        <?php if ($costAllocations): ?><h4>Cost-layer allocations</h4><div class="table-scroll"><table class="history-detail-table"><thead><tr><th>Allocation</th><th>Cost layer</th><th>Receipt source</th><th>Type</th><th>Quantity / returned</th><th>Unit cost</th></tr></thead><tbody><?php foreach($costAllocations as $allocation): ?><tr><td>#<?=(int)$allocation['id']?></td><td>#<?=(int)$allocation['cost_layer_id']?></td><td><?= $allocation['receipt_id']?'#'.(int)$allocation['receipt_id']:'Unavailable' ?></td><td><?= $safe($allocation['source_type']) ?></td><td><?= number_format((int)$allocation['quantity']) ?> / <?= number_format((int)$allocation['returned_quantity']) ?></td><td><?= $safe($money($allocation['unit_cost'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <?php if ($reconciliationRows): ?><h4>Historical cost source references</h4><div class="table-scroll"><table class="history-detail-table"><thead><tr><th>Reconciliation</th><th>Source type</th><th>Quantity</th><th>Unit cost</th><th>Source reference</th><th>Notes</th><th>Recorded by</th><th>Date</th></tr></thead><tbody><?php foreach($reconciliationRows as $reconciliation): ?><tr><td>#<?=(int)$reconciliation['id']?></td><td><?= $safe($reconciliation['kind']) ?></td><td><?= number_format((int)$reconciliation['quantity']) ?></td><td><?= $safe($money($reconciliation['unit_cost'])) ?></td><td><?= $safe($reconciliation['source_reference']) ?></td><td><?= $safe($reconciliation['notes']) ?></td><td><?= $safe($reconciliation['recorded_by']) ?></td><td><?= $safe($reconciliation['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php else: ?>
    <section class="history-detail-section"><h3>A. Hardware assignment event</h3><dl class="history-detail-grid"><div><dt>Event ID</dt><dd>#<?= (int)$hardware['event_id'] ?></dd></div><div><dt>Action</dt><dd><?= $safe(ucfirst((string)$hardware['action'])) ?></dd></div><div><dt>Date and time</dt><dd><?= $safe($hardware['performed_at']) ?></dd></div><div><dt>Performed by</dt><dd><?= $safe($notRecorded($hardware['performed_by'])) ?></dd></div><div><dt>Reason</dt><dd><?= $safe($notRecorded($hardware['reason'])) ?></dd></div></dl></section>
    <section class="history-detail-section"><h3>B. Employee and assignment</h3><?php if ($assignment): ?><dl class="history-detail-grid"><div><dt>Employee</dt><dd><?= $safe($hardware['employee_name']) ?></dd></div><div><dt>Employee ID</dt><dd><?= $safe($hardware['employee_code']) ?></dd></div><div><dt>Department / location</dt><dd><?= $safe($notRecorded($hardware['employee_department'])) ?> / <?= $safe($notRecorded($hardware['employee_location'])) ?></dd></div><div><dt>Assignment ID / status</dt><dd>#<?= (int)$assignment['id'] ?> · <?= $safe($assignment['status']) ?></dd></div><div><dt>Quantity / returned</dt><dd><?= number_format((int)$assignment['quantity']) ?> / <?= number_format((int)$assignment['returned_quantity']) ?></dd></div><div><dt>Assigned / returned dates</dt><dd><?= $safe($assignment['assigned_at']) ?> / <?= $safe($notRecorded($assignment['returned_at'])) ?></dd></div><div><dt>Assigned by / reason</dt><dd><?= $safe($notRecorded($assignment['assigned_by'])) ?> / <?= $safe($notRecorded($assignment['notes'])) ?></dd></div></dl><?php else: ?><p class="history-detail-note">Assignment details are no longer available for this event.</p><?php endif; ?></section>
    <section class="history-detail-section"><h3>C. Registered asset details</h3><?php if ($assets): ?><div class="history-asset-grid"><?php foreach($assets as $asset): ?><article><h4><?= $safe($asset['asset_tag']) ?> · <?= $safe($asset['product_name']) ?></h4><dl><div><dt>Category / subcategory</dt><dd><?= $safe($asset['category_name']) ?> / <?= $safe($notRecorded($asset['subcategory_name'])) ?></dd></div><div><dt>Brand / model</dt><dd><?= $safe(trim((string)($asset['brand']??'').' '.(string)($asset['model']??'')) ?: 'Unavailable') ?></dd></div><div><dt>Serial numbers</dt><dd><?= $safe($notRecorded($asset['serial_number'])) ?><?= $asset['internal_serial_number']?' · Internal '.$safe($asset['internal_serial_number']):'' ?></dd></div><div><dt>Status / condition / location</dt><dd><?= $safe($asset['status']) ?> / <?= $safe($notRecorded($asset['asset_condition'])) ?> / <?= $safe($notRecorded($asset['location'])) ?></dd></div><div><dt>Acquisition cost</dt><dd><?= $safe($money($asset['acquisition_unit_cost'])) ?></dd></div></dl></article><?php endforeach; ?></div><?php else: ?><p class="history-detail-note">No registered asset is linked to this event.</p><?php endif; ?></section>
    <?php if ($assignmentEvents): ?><section class="history-detail-section"><h3>Assignment, return, replacement and removal timeline</h3><div class="history-event-list"><?php foreach($assignmentEvents as $assignmentEvent): ?><article><strong><?= $safe(ucfirst((string)$assignmentEvent['action'])) ?></strong><span><?= $safe($assignmentEvent['performed_at']) ?></span><p><?= $safe($notRecorded($assignmentEvent['reason'])) ?></p><small><?= $safe($notRecorded($assignmentEvent['performed_by'])) ?></small></article><?php endforeach; ?></div></section><?php endif; ?>
    <?php if ($reconciliationRows): ?><section class="history-detail-section"><h3>Historical cost source references</h3><div class="table-scroll"><table class="history-detail-table"><thead><tr><th>Reconciliation</th><th>Source type</th><th>Quantity</th><th>Unit cost</th><th>Source reference</th><th>Notes</th><th>Recorded by</th><th>Date</th></tr></thead><tbody><?php foreach($reconciliationRows as $reconciliation): ?><tr><td>#<?=(int)$reconciliation['id']?></td><td><?= $safe($reconciliation['kind']) ?></td><td><?= number_format((int)$reconciliation['quantity']) ?></td><td><?= $safe($money($reconciliation['unit_cost'])) ?></td><td><?= $safe($reconciliation['source_reference']) ?></td><td><?= $safe($reconciliation['notes']) ?></td><td><?= $safe($reconciliation['recorded_by']) ?></td><td><?= $safe($reconciliation['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
    <section class="history-detail-section"><h3>D. Stock and valuation impact</h3><p class="history-detail-note">This hardware event is an assignment-history record and is not counted as an additional stock movement. Any associated stock transaction is listed separately below.</p><?php if ($relatedStock): ?><div class="table-scroll"><table class="history-detail-table"><thead><tr><th>Transaction</th><th>Date</th><th>Action</th><th>Quantity impact</th><th>Available before / after</th><th>Recorded cost</th></tr></thead><tbody><?php foreach($relatedStock as $movement): $movementPresentation=inventoryHistoryStockPresentation($movement); ?><tr><td><a href="transaction-details.php?record=stock&amp;id=<?=(int)$movement['id']?>">#<?=(int)$movement['id']?></a></td><td><?= $safe($movement['transaction_at']) ?></td><td><?= $safe(inventoryHistoryMovementLabel($movement)) ?></td><td><?= $safe($movementPresentation['stock_impact']===null?'Unavailable':sprintf('%+d',(int)$movementPresentation['stock_impact'])) ?></td><td><?= $safe(inventoryHistoryBalanceDisplay($movementPresentation['stock_before'])) ?> / <?= $safe(inventoryHistoryBalanceDisplay($movementPresentation['stock_after'])) ?></td><td><?= $safe(inventoryHistoryCostDisplay($movement)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="history-detail-note">No linked stock transaction could be reconstructed from the recorded assignment reference.</p><?php endif; ?></section>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
