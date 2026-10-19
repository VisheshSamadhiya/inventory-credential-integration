<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/history_helpers.php';
require_once __DIR__ . '/inventory_valuation.php';
requirePermission('history', 'view');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$pageTitle = 'History';
$productId = (int)($_GET['product'] ?? 0);
$categoryId = (int)($_GET['category'] ?? 0);
$employeeId = (int)($_GET['employee'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 100;
$type = trim((string)($_GET['type'] ?? ''));
$orderStatus = trim((string)($_GET['order_status'] ?? ''));
if (!in_array($orderStatus, ['', 'Pending', 'Confirmed', 'Rejected', 'Received'], true)) $orderStatus = '';
$search = trim((string)($_GET['q'] ?? ''));
$timePreset = trim((string)($_GET['time'] ?? 'all'));
$allowedTimePresets = ['all', 'today', 'yesterday', 'last7', 'last30', 'this_month', 'custom'];
if (!in_array($timePreset, $allowedTimePresets, true)) $timePreset = 'all';
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$fromTime = trim((string)($_GET['from_time'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$toTime = trim((string)($_GET['to_time'] ?? ''));
$customRangeSelected = $timePreset === 'custom' || (!array_key_exists('time', $_GET) && ($fromDate !== '' || $fromTime !== '' || $toDate !== '' || $toTime !== ''));
if ($customRangeSelected) $timePreset = 'custom';
$rangeError = '';
$rangeStart = null;
$rangeEnd = null;
$rangeValid = true;

$now = new DateTimeImmutable('now');
$today = $now->setTime(0, 0, 0);
switch ($timePreset) {
    case 'today':
        $rangeStart = $today;
        $rangeEnd = $now;
        break;
    case 'yesterday':
        $rangeStart = $today->modify('-1 day');
        $rangeEnd = $today->modify('-1 second');
        break;
    case 'last7':
        $rangeStart = $today->modify('-6 days');
        $rangeEnd = $now;
        break;
    case 'last30':
        $rangeStart = $today->modify('-29 days');
        $rangeEnd = $now;
        break;
    case 'this_month':
        $rangeStart = $today->modify('first day of this month');
        $rangeEnd = $now;
        break;
    case 'custom':
        $parseDate = static function (string $value): ?DateTimeImmutable {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return null;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $errors = DateTimeImmutable::getLastErrors();
            return $date && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d') === $value ? $date : null;
        };
        $parseTime = static function (string $value): string|false|null {
            if ($value === '') return null;
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value)) return false;
            return strlen($value) === 5 ? $value . ':00' : $value;
        };
        $fromTimeValue = $parseTime($fromTime);
        $toTimeValue = $parseTime($toTime);
        if (($fromTime !== '' && $fromTimeValue === false) || ($toTime !== '' && $toTimeValue === false)) {
            $rangeValid = false;
            $rangeError = 'Please enter a valid time.';
        } elseif (($fromTime !== '' && $fromDate === '') || ($toTime !== '' && $toDate === '')) {
            $rangeValid = false;
            $rangeError = 'Select a date when entering a time.';
        } elseif (($fromDate !== '' && !($parsedFromDate = $parseDate($fromDate))) || ($toDate !== '' && !($parsedToDate = $parseDate($toDate)))) {
            $rangeValid = false;
            $rangeError = 'Please enter a valid date.';
        } else {
            if ($fromDate !== '') $rangeStart = new DateTimeImmutable($fromDate . ' ' . ($fromTimeValue ?: '00:00:00'));
            if ($toDate !== '') {
                $rangeEnd = new DateTimeImmutable($toDate . ' ' . ($toTimeValue ?: '23:59:59'));
            }
        }
        break;
}
if ($rangeValid && $rangeStart && $rangeEnd && $rangeStart > $rangeEnd) {
    $rangeValid = false;
    $rangeError = 'Invalid date range. "From" must be earlier than "To".';
}
if (!$rangeValid) setFlash('error', $rangeError);

$where = [];
$params = [];
if ($productId > 0) { $where[] = 't.product_id = ?'; $params[] = $productId; }
if ($categoryId > 0) { $where[] = 'p.category_id = ?'; $params[] = $categoryId; }
if (in_array($type, ['opening', 'ordered', 'in', 'out', 'adjustment'], true)) { $where[] = 't.transaction_type = ?'; $params[] = $type; }
if ($orderStatus !== '') { $where[] = "t.transaction_type='ordered' AND t.status = ?"; $params[] = $orderStatus; }
if ($employeeId > 0) {
    $where[] = "EXISTS (SELECT 1 FROM assignments ea WHERE ea.product_id=t.product_id AND (ea.employee_record_id=? OR (ea.employee_record_id IS NULL AND ea.employee_id=(SELECT employee_id FROM employees WHERE id=?))) AND LOWER(CONCAT(COALESCE(t.notes,''),' ',COALESCE(t.reason,''))) REGEXP CONCAT('assignment[[:space:]]*#[[:space:]]*',ea.id,'([^0-9]|$)'))";
    array_push($params, $employeeId, $employeeId);
}
if ($search !== '') {
    $where[] = '(p.name LIKE ? OR t.reason LIKE ? OR t.notes LIKE ? OR u.full_name LIKE ? OR COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}
if ($rangeStart) { $where[] = 't.transaction_at >= ?'; $params[] = $rangeStart->format('Y-m-d H:i:s'); }
if ($rangeEnd) { $where[] = 't.transaction_at <= ?'; $params[] = $rangeEnd->format('Y-m-d H:i:s'); }
$baseSql = 'SELECT t.id AS transaction_id, t.product_id, t.transaction_at, t.transaction_type, t.quantity, t.previous_quantity,
               t.new_quantity, t.reason, t.notes, t.reference_number,
               COALESCE(t.unit_price,receipt_cost.unit_cost,CASE WHEN t.transaction_type=\'in\' AND t.status=\'Received\' AND po.status IN (\'Confirmed\',\'Received\') THEN COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) END) AS unit_price,
               COALESCE(t.total_price,CASE WHEN t.unit_price IS NOT NULL THEN ABS(t.quantity)*t.unit_price END,CASE WHEN assigned_cost.units>=ABS(t.quantity) THEN assigned_cost.value END,CASE WHEN receipt_cost.original_quantity>=ABS(t.quantity) THEN ABS(t.quantity)*receipt_cost.unit_cost END,CASE WHEN t.transaction_type=\'in\' AND t.status=\'Received\' AND po.status IN (\'Confirmed\',\'Received\') AND COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) IS NOT NULL THEN ABS(t.quantity)*COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) END) AS total_price,
               CASE WHEN t.total_price IS NOT NULL THEN ABS(t.quantity) WHEN t.unit_price IS NOT NULL THEN ABS(t.quantity) WHEN assigned_cost.units IS NOT NULL THEN LEAST(ABS(t.quantity),assigned_cost.units) WHEN receipt_cost.original_quantity IS NOT NULL THEN LEAST(ABS(t.quantity),receipt_cost.original_quantity) WHEN t.transaction_type=\'in\' AND t.status=\'Received\' AND po.status IN (\'Confirmed\',\'Received\') AND COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) IS NOT NULL THEN ABS(t.quantity) ELSE 0 END AS cost_covered_quantity,
               COALESCE(t.total_price,CASE WHEN t.unit_price IS NOT NULL THEN ABS(t.quantity)*t.unit_price END,assigned_cost.value,CASE WHEN receipt_cost.original_quantity IS NOT NULL THEN LEAST(ABS(t.quantity),receipt_cost.original_quantity)*receipt_cost.unit_cost END,CASE WHEN t.transaction_type=\'in\' AND t.status=\'Received\' AND po.status IN (\'Confirmed\',\'Received\') AND COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) IS NOT NULL THEN ABS(t.quantity)*COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) END) AS known_cost_amount,
               t.related_order_id, t.order_reason, COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) AS vendor, t.vendor_id, t.vendor_contact_snapshot, t.vendor_phone_snapshot, t.vendor_email_snapshot, t.vendor_address_snapshot, t.vendor_gstin_snapshot, t.status, t.confirmed_at, t.rejected_at, t.rejection_reason,
               p.name AS product_name, p.brand, p.model, c.id AS category_id, c.name AS category_name, u.full_name AS user_name,
               po.id AS purchase_order_id, po.quantity AS order_quantity, po.status AS purchase_order_status, po.reference_number AS order_reference,
               po.confirmed_by, cu.full_name AS confirmed_by_name, po.rejected_by, ru.full_name AS rejected_by_name,
               CASE WHEN po.id IS NULL THEN NULL ELSE (SELECT COALESCE(SUM(r.quantity),0) FROM inventory_transactions r WHERE r.related_order_id=po.id AND r.transaction_type=\'in\' AND (r.transaction_at<t.transaction_at OR (r.transaction_at=t.transaction_at AND r.id<=t.id))) END AS received_as_of,
               CASE WHEN po.id IS NULL THEN NULL ELSE (SELECT COALESCE(SUM(r.quantity),0) FROM inventory_transactions r WHERE r.related_order_id=po.id AND r.transaction_type=\'in\') END AS received_current
        FROM inventory_transactions t
        JOIN products p ON p.id = t.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN vendors v ON v.id = t.vendor_id
        LEFT JOIN inventory_transactions po ON po.id=COALESCE(t.related_order_id,CASE WHEN t.transaction_type=\'ordered\' THEN t.id END)
        LEFT JOIN inventory_cost_layers receipt_cost ON receipt_cost.source_transaction_id=t.id
        LEFT JOIN (SELECT source_transaction_id,SUM(quantity) units,SUM(quantity*unit_cost) value FROM inventory_cost_allocations GROUP BY source_transaction_id) assigned_cost ON assigned_cost.source_transaction_id=t.id
        LEFT JOIN users cu ON cu.id=po.confirmed_by
        LEFT JOIN users ru ON ru.id=po.rejected_by';
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$orderSql = ' ORDER BY t.transaction_at ASC, t.id ASC';
$labels = ['opening' => 'Opening balance', 'ordered' => 'Ordered', 'in' => 'Received', 'out' => 'Removed', 'adjustment' => 'Adjustment'];
$valuation = null;
try {
    $valuation = inventoryValuationSnapshot(db());
} catch (Throwable $valuationError) {
    error_log('Inventory valuation display unavailable: ' . $valuationError->getMessage());
}

$enrichHistoryRows = static function (PDO $pdo, array $rows) use ($baseSql): array {
    if (!$rows) return [];
    $productIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['product_id'], $rows)));
    $eventsByProduct = [];
    $expectedByProduct = [];
    foreach (array_chunk($productIds, 200) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $events = $pdo->prepare($baseSql . " WHERE t.product_id IN ($marks) ORDER BY t.transaction_at,t.id");
        $events->execute($chunk);
        foreach ($events->fetchAll() as $event) {
            $event['id'] = (int)$event['transaction_id'];
            $eventsByProduct[(int)$event['product_id']][] = $event;
        }
        $current = $pdo->prepare("SELECT product_id,SUM(CASE WHEN asset_id IS NOT NULL THEN 1 ELSE GREATEST(quantity-returned_quantity,0) END) assigned_quantity FROM assignments WHERE status='assigned' AND product_id IN ($marks) GROUP BY product_id");
        $current->execute($chunk);
        foreach ($current->fetchAll() as $entry) $expectedByProduct[(int)$entry['product_id']] = (int)$entry['assigned_quantity'];
    }
    $timelines = [];
    $valuationTimelines = [];
    $currentValuationByProduct = inventoryValuationSnapshotByProduct($pdo, $productIds);
    foreach ($productIds as $id) {
        $productEvents = $eventsByProduct[$id] ?? [];
        $timelines[$id] = inventoryHistoryAssignmentTimeline($productEvents, $expectedByProduct[$id] ?? 0);
        $assignmentRows = $timelines[$id]['rows'];
        foreach ($productEvents as &$event) {
            $eventId = (int)$event['transaction_id'];
            if (isset($assignmentRows[$eventId])) {
                $event['assigned_before'] = $assignmentRows[$eventId]['assigned_before'];
                $event['assigned_after'] = $assignmentRows[$eventId]['assigned_after'];
            }
        }
        unset($event);
        $valuationTimelines[$id] = inventoryHistoryValuationTimeline(
            $productEvents,
            $timelines[$id]['reliable'],
            $currentValuationByProduct[$id] ?? null
        );
    }

    $assignmentIds = [];
    foreach ($rows as $row) {
        $assignmentId = inventoryHistoryAssignmentId($row);
        if ($assignmentId !== null) $assignmentIds[] = $assignmentId;
    }
    $assignments = [];
    foreach (array_chunk(array_values(array_unique($assignmentIds)), 200) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $query = $pdo->prepare("SELECT a.id,a.product_id,a.asset_id,a.quantity,a.returned_quantity,a.assigned_to,a.employee_id,a.assigned_at,a.returned_at,a.status,a.location,a.condition_at_assignment,e.full_name employee_name,e.department,ia.asset_tag,ia.serial_number,ia.internal_serial_number,ia.location asset_location,au.full_name assigned_by FROM assignments a LEFT JOIN employees e ON (e.id=a.employee_record_id OR (a.employee_record_id IS NULL AND e.employee_id=a.employee_id)) LEFT JOIN inventory_assets ia ON ia.id=a.asset_id LEFT JOIN users au ON au.id=a.assigned_by WHERE a.id IN ($marks)");
        $query->execute($chunk);
        foreach ($query->fetchAll() as $assignment) $assignments[(int)$assignment['id']] = $assignment;
    }

    foreach ($rows as &$row) {
        $productId = (int)$row['product_id'];
        $row['stock_calculation'] = inventoryHistoryStockPresentation($row);
        $row['movement_label'] = inventoryHistoryMovementLabel($row);
        $row['assignment_id'] = inventoryHistoryAssignmentId($row);
        $row['assigned_change'] = inventoryHistoryAssignedImpact($row);
        $timeline = $timelines[$productId] ?? ['reliable'=>false,'rows'=>[]];
        $assigned = $timeline['reliable'] ? ($timeline['rows'][(int)$row['transaction_id']] ?? null) : null;
        $row['assigned_before'] = $assigned['assigned_before'] ?? null;
        $row['assigned_after'] = $assigned['assigned_after'] ?? null;
        $row['physical_before'] = $row['stock_calculation']['stock_before'] !== null && $row['assigned_before'] !== null
            ? $row['stock_calculation']['stock_before'] + $row['assigned_before'] : null;
        $row['physical_after'] = $row['stock_calculation']['stock_after'] !== null && $row['assigned_after'] !== null
            ? $row['stock_calculation']['stock_after'] + $row['assigned_after'] : null;
        $row['physical_value_impact'] = inventoryHistoryPhysicalValueImpact($row);
        $row['historical_valuation'] = $valuationTimelines[$productId]['rows'][(int)$row['transaction_id']] ?? null;
        $row['assignment_valuation'] = inventoryHistoryAssignmentValuation($row);
        $row['assignment_record'] = $row['assignment_id'] !== null ? ($assignments[$row['assignment_id']] ?? null) : null;
        $row['employee_name'] = $row['assignment_record']['employee_name'] ?? null;
        $row['employee_number'] = $row['assignment_record']['employee_id'] ?? null;
        $row['asset_tag'] = $row['assignment_record']['asset_tag'] ?? null;
        $row['serial_number'] = $row['assignment_record']['serial_number'] ?? null;
        $row['internal_serial_number'] = $row['assignment_record']['internal_serial_number'] ?? null;
        $row['asset_location'] = $row['assignment_record']['asset_location'] ?? $row['assignment_record']['location'] ?? null;
        $row['assigned_by_name'] = $row['assignment_record']['assigned_by'] ?? null;
        $row['order_remaining_as_of'] = $row['order_quantity'] === null ? null : max(0, (int)$row['order_quantity'] - (int)($row['received_as_of'] ?? 0));
        $row['order_remaining_current'] = $row['order_quantity'] === null ? null : max(0, (int)$row['order_quantity'] - (int)($row['received_current'] ?? 0));
    }
    unset($row);
    return $rows;
};

$hardwareWhere = [];
$hardwareParams = [];
if ($productId > 0) { $hardwareWhere[] = 'COALESCE(newia.product_id,oldia.product_id,assignedp.id)=?'; $hardwareParams[] = $productId; }
if ($categoryId > 0) { $hardwareWhere[] = 'COALESCE(newp.category_id,oldp.category_id,assignedp.category_id)=?'; $hardwareParams[] = $categoryId; }
if ($employeeId > 0) { $hardwareWhere[] = 'h.employee_id=?'; $hardwareParams[] = $employeeId; }
if ($rangeStart) { $hardwareWhere[] = 'h.performed_at>=?'; $hardwareParams[] = $rangeStart->format('Y-m-d H:i:s'); }
if ($rangeEnd) { $hardwareWhere[] = 'h.performed_at<=?'; $hardwareParams[] = $rangeEnd->format('Y-m-d H:i:s'); }
if ($search !== '') {
    $hardwareWhere[] = '(e.full_name LIKE ? OR e.employee_id LIKE ? OR COALESCE(newp.name,oldp.name,assignedp.name) LIKE ? OR COALESCE(newia.asset_tag,oldia.asset_tag) LIKE ? OR COALESCE(newia.serial_number,oldia.serial_number) LIKE ? OR h.reason LIKE ? OR u.full_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($hardwareParams, $like, $like, $like, $like, $like, $like, $like);
}
$hardwareWhereSql = $hardwareWhere ? ' WHERE ' . implode(' AND ', $hardwareWhere) : '';
$hardwareBaseSql = "SELECT h.id event_id,h.performed_at,e.full_name employee_name,e.employee_id,
        h.action,COALESCE(newp.name,oldp.name,assignedp.name) product_name,
        COALESCE(newc.name,oldc.name,assignedc.name) category_name,a.product_id assigned_product_id,
        h.assignment_id,a.quantity,a.returned_quantity,a.assigned_at,COALESCE(newia.id,oldia.id,a.asset_id) asset_id,
        oldp.name old_product_name,newp.name new_product_name,oldc.name old_category_name,newc.name new_category_name,
        oldia.asset_tag old_asset_tag,newia.asset_tag new_asset_tag,oldia.serial_number old_serial_number,newia.serial_number new_serial_number,
        COALESCE(newia.asset_tag,oldia.asset_tag) asset_tag,
        COALESCE(newia.serial_number,oldia.serial_number) serial_number,
        COALESCE(newia.internal_serial_number,oldia.internal_serial_number) internal_serial_number,
        COALESCE(newia.location,oldia.location,a.location) location,
        h.reason,u.full_name performed_by
    FROM hardware_assignment_history h
    JOIN employees e ON e.id=h.employee_id
    LEFT JOIN assignments a ON a.id=h.assignment_id
    LEFT JOIN products assignedp ON assignedp.id=a.product_id
    LEFT JOIN categories assignedc ON assignedc.id=assignedp.category_id
    LEFT JOIN inventory_assets oldia ON oldia.id=h.old_asset_id
    LEFT JOIN products oldp ON oldp.id=oldia.product_id
    LEFT JOIN categories oldc ON oldc.id=oldp.category_id
    LEFT JOIN inventory_assets newia ON newia.id=h.new_asset_id
    LEFT JOIN products newp ON newp.id=newia.product_id
    LEFT JOIN categories newc ON newc.id=newp.category_id
    LEFT JOIN users u ON u.id=h.performed_by";
$categories = db()->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();
$products = db()->query('SELECT id,name FROM products ORDER BY name')->fetchAll();
$employees = db()->query('SELECT id,employee_id,full_name FROM employees ORDER BY full_name')->fetchAll();

if (in_array((string)($_GET['export'] ?? ''), ['xlsx','csv'], true)) {
    if (!$rangeValid) {
        $filters = $_GET;
        unset($filters['export']);
        header('Location: history.php' . ($filters ? '?' . http_build_query($filters) : ''));
        exit;
    }
    $exportQuery = db()->prepare($baseSql . $whereSql . $orderSql);
    $exportQuery->execute($params);
    $exportRows = $exportQuery->fetchAll();
    $exportRows = $enrichHistoryRows(db(), $exportRows);
    $appliedFilters = [];
    if ($productId > 0) {
        $productNameQuery = db()->prepare('SELECT name FROM products WHERE id=?');
        $productNameQuery->execute([$productId]);
        $productName = $productNameQuery->fetchColumn();
        $appliedFilters['Product'] = '#' . $productId . ($productName ? ' ' . $productName : '');
    }
    if ($categoryId > 0) {
        $categoryNameQuery = db()->prepare('SELECT name FROM categories WHERE id=?');
        $categoryNameQuery->execute([$categoryId]);
        $appliedFilters['Category'] = '#' . $categoryId . ' ' . (string)$categoryNameQuery->fetchColumn();
    }
    if ($employeeId > 0) {
        $employeeNameQuery = db()->prepare('SELECT full_name FROM employees WHERE id=?');
        $employeeNameQuery->execute([$employeeId]);
        $appliedFilters['Employee'] = '#' . $employeeId . ' ' . (string)$employeeNameQuery->fetchColumn();
    }
    if (in_array($type, ['opening','ordered','in','out','adjustment'], true)) $appliedFilters['Action'] = $labels[$type] ?? $type;
    if ($orderStatus !== '') $appliedFilters['Order Status'] = $orderStatus;
    if ($rangeEnd || $rangeStart) $appliedFilters['Date range'] = ($rangeStart?->format('Y-m-d H:i:s') ?? '—') . ' to ' . ($rangeEnd?->format('Y-m-d H:i:s') ?? '—');
    if ($search !== '') $appliedFilters['Search'] = $search;
    $hardwareQuery = db()->prepare($hardwareBaseSql . $hardwareWhereSql . ' ORDER BY h.performed_at DESC,h.id DESC');
    $hardwareQuery->execute($hardwareParams);
    $hardwareActivity = $hardwareQuery->fetchAll();
    require_once __DIR__ . '/history_export.php';
    while (ob_get_level() > 0) ob_end_clean();
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inventory-history-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store, max-age=0');
        echo buildInventoryHistoryCsv($exportRows, $hardwareActivity, $appliedFilters);
        exit;
    }
    require_once __DIR__ . '/vendor/autoload.php';
    $spreadsheet = buildInventoryHistoryWorkbook($exportRows, $appliedFilters, $hardwareActivity);
    $filename = 'inventory-history-' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
}

$rows = [];
$transactionCount = 0;
$transactionPages = 1;
$hardwareActivity = [];
$hardwareCount = 0;
$hardwarePages = 1;
$hardwarePage = max(1, (int)($_GET['hardware_page'] ?? 1));
$hardwarePageSize = 100;
$pageUrl = static function (string $key, int $target): string {
    $query = $_GET;
    unset($query['export']);
    $query[$key] = $target;
    return 'history.php?' . http_build_query($query);
};
$historyReturnQuery = $_GET;
unset($historyReturnQuery['export']);
$historyReturnQueryString = http_build_query($historyReturnQuery);
$historyNavigation = $_SESSION['history_navigation'] ?? [];
if (!is_array($historyNavigation)) $historyNavigation = [];
$stockNavigationToken = bin2hex(random_bytes(16));
$hardwareNavigationToken = bin2hex(random_bytes(16));
if ($rangeValid) {
    $countQuery = db()->prepare('SELECT COUNT(*) FROM inventory_transactions t JOIN products p ON p.id=t.product_id LEFT JOIN users u ON u.id=t.created_by LEFT JOIN vendors v ON v.id=t.vendor_id' . $whereSql);
    $countQuery->execute($params);
    $transactionCount = (int)$countQuery->fetchColumn();
    $transactionPages = max(1, (int)ceil($transactionCount / $pageSize));
    $page = min($page, $transactionPages);
    $offset = ($page - 1) * $pageSize;
    $sql = $baseSql . $whereSql . $orderSql . ' LIMIT ' . $pageSize . ' OFFSET ' . $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $enrichHistoryRows(db(), $stmt->fetchAll());

    $hardwareCountQuery = db()->prepare('SELECT COUNT(*)' . substr($hardwareBaseSql, strpos($hardwareBaseSql, ' FROM ')) . $hardwareWhereSql);
    $hardwareCountQuery->execute($hardwareParams);
    $hardwareCount = (int)$hardwareCountQuery->fetchColumn();
    $hardwarePages = max(1, (int)ceil($hardwareCount / $hardwarePageSize));
    $hardwarePage = min($hardwarePage, $hardwarePages);
    $hardwareOffset = ($hardwarePage - 1) * $hardwarePageSize;
    $hardwareQuery = db()->prepare($hardwareBaseSql . $hardwareWhereSql . ' ORDER BY h.performed_at DESC,h.id DESC LIMIT ' . $hardwarePageSize . ' OFFSET ' . $hardwareOffset);
    $hardwareQuery->execute($hardwareParams);
    $hardwareActivity = $hardwareQuery->fetchAll();
}
$historyNavigation[$stockNavigationToken] = ['record'=>'stock','ids'=>array_map(static fn(array $row):int=>(int)$row['transaction_id'],$rows),'back'=>$historyReturnQueryString];
$historyNavigation[$hardwareNavigationToken] = ['record'=>'hardware','ids'=>array_map(static fn(array $row):int=>(int)$row['event_id'],$hardwareActivity),'back'=>$historyReturnQueryString];
while (count($historyNavigation) > 6) array_shift($historyNavigation);
$_SESSION['history_navigation'] = $historyNavigation;
$historyDetailUrl = static fn(string $recordType,int $id,string $token):string => 'transaction-details.php?'.http_build_query(['record'=>$recordType,'id'=>$id,'nav'=>$token]);
$exportFilters = $_GET;
$exportFilters['export'] = 'xlsx';
$exportUrl = 'history.php?' . http_build_query($exportFilters);
require __DIR__ . '/header.php';
?>
<section class="card history-page">
    <?php if(canAdmin()): ?><p class="muted"><a href="inventory-cost-reconciliation.php">Inventory cost maintenance</a></p><?php endif; ?>
    <div class="grid four inventory-valuation" aria-label="Inventory cost valuation">
        <div class="metric-card"><span>Available inventory value</span><strong><?=htmlspecialchars(inventoryValuationDisplay($valuation['available']??null,$valuation['unknown_available']??0))?></strong></div>
        <div class="metric-card"><span>Assigned inventory value</span><strong><?=htmlspecialchars(inventoryValuationDisplay($valuation['assigned']??null,$valuation['unknown_assigned']??0))?></strong></div>
        <div class="metric-card"><span>Total physical inventory value</span><strong><?=htmlspecialchars(inventoryValuationDisplay($valuation['physical']??null,($valuation['unknown_available']??0)+($valuation['unknown_assigned']??0)+($valuation['unknown_other_physical']??0)))?></strong></div>
        <div class="metric-card"><span>Disposed / written-off value</span><strong><?=htmlspecialchars(inventoryValuationDisplay($valuation['disposed']??null,$valuation['unknown_disposed']??0))?></strong></div>
    </div>
    <?php if(($valuation['unknown_available']??0)+($valuation['unknown_assigned']??0)+($valuation['unknown_disposed']??0)+($valuation['unknown_other_physical']??0)>0): ?><p class="muted history-cost-notice">Some historical costs are unavailable.</p><?php endif; ?>
    <form method="get" class="history-filters">
        <input type="hidden" name="page" value="1"><input type="hidden" name="hardware_page" value="1">
        <div class="history-filter-fields">
            <div><label for="q">Search history</label><input id="q" name="q" value="<?=htmlspecialchars($search)?>" placeholder="Product, reason, or user"></div>
            <div><label for="product">Product</label><select id="product" name="product"><option value="0">All products</option><?php foreach($products as $product): ?><option value="<?=$product['id']?>" <?=$productId===(int)$product['id']?'selected':''?>><?=htmlspecialchars($product['name'])?></option><?php endforeach; ?></select></div>
            <div><label for="category">Category</label><select id="category" name="category"><option value="0">All categories</option><?php foreach($categories as $category): ?><option value="<?=$category['id']?>" <?=$categoryId===(int)$category['id']?'selected':''?>><?=htmlspecialchars($category['name'])?></option><?php endforeach; ?></select></div>
            <div><label for="employee">Employee</label><select id="employee" name="employee"><option value="0">All employees</option><?php foreach($employees as $employee): ?><option value="<?=$employee['id']?>" <?=$employeeId===(int)$employee['id']?'selected':''?>><?=htmlspecialchars($employee['full_name'].' ('.$employee['employee_id'].')')?></option><?php endforeach; ?></select></div>
            <div><label for="type">Transaction type</label><select id="type" name="type"><option value="">All transaction types</option><?php foreach($labels as $key=>$label): ?><option value="<?=$key?>" <?=$type===$key?'selected':''?>><?=htmlspecialchars($label)?></option><?php endforeach; ?></select></div>
            <div><label for="order_status">Order status</label><select id="order_status" name="order_status"><option value="">All order statuses</option><option value="Pending" <?=$orderStatus==='Pending'?'selected':''?>>Pending</option><option value="Confirmed" <?=$orderStatus==='Confirmed'?'selected':''?>>Confirmed</option><option value="Rejected" <?=$orderStatus==='Rejected'?'selected':''?>>Rejected</option><option value="Received" <?=$orderStatus==='Received'?'selected':''?>>Received</option></select></div>
            <div><label for="time">Time</label><select id="time" name="time"><option value="all" <?=$timePreset==='all'?'selected':''?>>All time</option><option value="today" <?=$timePreset==='today'?'selected':''?>>Today</option><option value="yesterday" <?=$timePreset==='yesterday'?'selected':''?>>Yesterday</option><option value="last7" <?=$timePreset==='last7'?'selected':''?>>Last 7 days</option><option value="last30" <?=$timePreset==='last30'?'selected':''?>>Last 30 days</option><option value="this_month" <?=$timePreset==='this_month'?'selected':''?>>This month</option><option value="custom" <?=$timePreset==='custom'?'selected':''?>>Custom</option></select></div>
            <div class="history-custom-time" <?=$timePreset==='custom'?'':'hidden'?>><label for="from_date">From date</label><input id="from_date" name="from_date" type="date" value="<?=htmlspecialchars($fromDate)?>"><label class="time-label" for="from_time">Time (optional)</label><input id="from_time" name="from_time" type="time" step="1" value="<?=htmlspecialchars($fromTime)?>"></div>
            <div class="history-custom-time" <?=$timePreset==='custom'?'':'hidden'?>><label for="to_date">To date</label><input id="to_date" name="to_date" type="date" value="<?=htmlspecialchars($toDate)?>"><label class="time-label" for="to_time">Time (optional)</label><input id="to_time" name="to_time" type="time" step="1" value="<?=htmlspecialchars($toTime)?>"></div>
        </div>
        <div class="history-filter-actions"><button class="btn" type="submit">Filter</button><a class="btn" href="history.php">Clear</a><a class="btn btn-glow export-button" href="<?=htmlspecialchars($exportUrl)?>">Export Excel</a><?php $csvFilters=$_GET;$csvFilters['export']='csv';unset($csvFilters['page'],$csvFilters['hardware_page']); ?><a class="btn export-button" href="<?=htmlspecialchars('history.php?'.http_build_query($csvFilters))?>">Export CSV</a></div>
    </form>
    <script>document.getElementById('time').addEventListener('change',function(){document.querySelectorAll('.history-custom-time').forEach(function(field){field.hidden=this.value!=='custom';},this);});</script>
    <div class="history-section-heading"><h2>Inventory transactions</h2><span class="muted"><?=$transactionCount?> records · page <?=$page?> of <?=$transactionPages?></span></div>
    <div class="table-scroll history-list-scroll" tabindex="0" role="region" aria-label="Inventory transactions; scroll horizontally on smaller screens"><table class="history-table inventory-transactions-table"><thead><tr><th>Date</th><th>Transaction ID</th><th>Product</th><th>Type</th><th>Quantity Change</th><th>Valuation Impact</th><th>View Details</th></tr></thead><tbody>
<?php foreach($rows as $row): $stock=$row['stock_calculation']; $quantityChange=$row['transaction_type']==='ordered'?'0 stock · '.number_format((int)$stock['quantity']).' ordered':($stock['stock_impact']===null?'Unavailable':sprintf('%+d',(int)$stock['stock_impact'])); $assignmentValuation=$row['assignment_valuation']??null; $valueImpact=$row['transaction_type']==='ordered'?'Order only':($assignmentValuation!==null?((int)($assignmentValuation['unknown_units']??0)>0||($assignmentValuation['covered_units']??0)<abs((int)$row['assigned_change'])?'Cost unavailable':$assignmentValuation['value_label']):($row['physical_value_impact']===null?'Cost unavailable':formatInr(number_format((float)$row['physical_value_impact'],2,'.','')))); ?><tr>
<td data-label="Date"><?=htmlspecialchars((string)$row['transaction_at'])?></td><td data-label="Transaction ID"><span class="history-id">#<?= (int)$row['transaction_id']?></span></td><td data-label="Product"><strong><?=htmlspecialchars((string)$row['product_name'])?></strong><?php if(trim((string)($row['brand']??'').' '.(string)($row['model']??''))!==''): ?><small><?=htmlspecialchars(trim((string)$row['brand'].' '.(string)$row['model']))?></small><?php endif; ?><small><?=htmlspecialchars((string)($row['category_name']??'—'))?></small></td><td data-label="Type"><span class="history-action-pill"><?=htmlspecialchars((string)$row['movement_label'])?></span></td><td data-label="Quantity Change"><?=htmlspecialchars($quantityChange)?></td><td data-label="Valuation Impact"><?=htmlspecialchars($valueImpact)?></td><td data-label="Details"><a class="btn history-view-details" href="<?=htmlspecialchars($historyDetailUrl('stock',(int)$row['transaction_id'],$stockNavigationToken),ENT_QUOTES)?>">View Details</a></td></tr><?php endforeach; ?>
    <?php if($rangeValid && !$rows): ?><tr><td colspan="7" class="muted">No history records found.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($transactionPages>1): ?><nav class="history-pagination" aria-label="Inventory transaction pages"><?php if($page>1): ?><a class="btn" href="<?=htmlspecialchars($pageUrl('page',$page-1))?>">Previous</a><?php endif; ?><span>Page <?=$page?> of <?=$transactionPages?></span><?php if($page<$transactionPages): ?><a class="btn" href="<?=htmlspecialchars($pageUrl('page',$page+1))?>">Next</a><?php endif; ?></nav><?php endif; ?>

    <div class="history-section-heading"><h2>Registered hardware assignment events</h2><span class="muted"><?=$hardwareCount?> records · page <?=$hardwarePage?> of <?=$hardwarePages?></span></div>
    <div class="table-scroll history-list-scroll" tabindex="0" role="region" aria-label="Hardware assignment events; scroll horizontally on smaller screens"><table class="history-table hardware-history-table"><thead><tr><th>Date</th><th>Event ID</th><th>Employee</th><th>Action</th><th>Product / Asset</th><th>View Details</th></tr></thead><tbody>
    <?php foreach($hardwareActivity as $event): $isReplacement=($event['action']??'')==='replaced'; $eventProduct=$isReplacement?trim((string)($event['old_product_name']??'—').' → '.(string)($event['new_product_name']??'—')):($event['product_name']??'—'); $eventAsset=$isReplacement?trim((string)($event['old_asset_tag']??'—').' → '.(string)($event['new_asset_tag']??'—')):($event['asset_tag']??'—'); ?><tr><td data-label="Date"><?=htmlspecialchars((string)$event['performed_at'])?></td><td data-label="Event ID"><span class="history-id">#<?= (int)$event['event_id']?></span></td><td data-label="Employee"><strong><?=htmlspecialchars((string)$event['employee_name'])?></strong><small><?=htmlspecialchars((string)$event['employee_id'])?></small></td><td data-label="Action"><span class="history-action-pill"><?=htmlspecialchars(ucfirst((string)$event['action']))?></span></td><td data-label="Product / Asset"><?=htmlspecialchars((string)$eventProduct)?><small><?=htmlspecialchars((string)$eventAsset)?></small></td><td data-label="Details"><a class="btn history-view-details" href="<?=htmlspecialchars($historyDetailUrl('hardware',(int)$event['event_id'],$hardwareNavigationToken),ENT_QUOTES)?>">View Details</a></td></tr><?php endforeach; ?>
    <?php if($rangeValid && !$hardwareActivity): ?><tr><td colspan="6" class="muted">No registered hardware assignment events match these filters.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($hardwarePages>1): ?><nav class="history-pagination" aria-label="Hardware event pages"><?php if($hardwarePage>1): ?><a class="btn" href="<?=htmlspecialchars($pageUrl('hardware_page',$hardwarePage-1))?>">Previous</a><?php endif; ?><span>Page <?=$hardwarePage?> of <?=$hardwarePages?></span><?php if($hardwarePage<$hardwarePages): ?><a class="btn" href="<?=htmlspecialchars($pageUrl('hardware_page',$hardwarePage+1))?>">Next</a><?php endif; ?></nav><?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
