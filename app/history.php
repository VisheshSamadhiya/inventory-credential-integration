<?php
require_once __DIR__ . '/auth.php';
requirePermission('history', 'view');
$pageTitle = 'History';
$productId = (int)($_GET['product'] ?? 0);
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
if (in_array($type, ['opening', 'ordered', 'in', 'out', 'adjustment'], true)) { $where[] = 't.transaction_type = ?'; $params[] = $type; }
if ($orderStatus !== '') { $where[] = "t.transaction_type='ordered' AND t.status = ?"; $params[] = $orderStatus; }
if ($search !== '') {
    $where[] = '(p.name LIKE ? OR t.reason LIKE ? OR t.notes LIKE ? OR u.full_name LIKE ? OR COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}
if ($rangeStart) { $where[] = 't.transaction_at >= ?'; $params[] = $rangeStart->format('Y-m-d H:i:s'); }
if ($rangeEnd) { $where[] = 't.transaction_at <= ?'; $params[] = $rangeEnd->format('Y-m-d H:i:s'); }
$baseSql = 'SELECT t.transaction_at, t.transaction_type, t.quantity, t.previous_quantity,
               t.new_quantity, t.reason, t.notes, t.unit_price, t.total_price, t.order_reason, COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) AS vendor, t.vendor_id, t.vendor_contact_snapshot, t.vendor_phone_snapshot, t.vendor_email_snapshot, t.vendor_address_snapshot, t.vendor_gstin_snapshot, t.status, p.name AS product_name, c.name AS category_name, u.full_name AS user_name
        FROM inventory_transactions t
        JOIN products p ON p.id = t.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN vendors v ON v.id = t.vendor_id';
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$orderSql = ' ORDER BY t.transaction_at DESC, t.id DESC';
$labels = ['opening' => 'Opening balance', 'ordered' => 'Ordered', 'in' => 'Received', 'out' => 'Removed', 'adjustment' => 'Adjustment'];

if (($_GET['export'] ?? '') === 'xlsx') {
    if (!$rangeValid) {
        $filters = $_GET;
        unset($filters['export']);
        header('Location: history.php' . ($filters ? '?' . http_build_query($filters) : ''));
        exit;
    }
    $exportQuery = db()->prepare($baseSql . $whereSql . $orderSql);
    $exportQuery->execute($params);
    require_once __DIR__ . '/vendor/autoload.php';
    $exportRows = $exportQuery->fetchAll();
    $appliedFilters = [];
    if ($productId > 0) {
        $productNameQuery = db()->prepare('SELECT name FROM products WHERE id=?');
        $productNameQuery->execute([$productId]);
        $productName = $productNameQuery->fetchColumn();
        $appliedFilters['Product'] = '#' . $productId . ($productName ? ' ' . $productName : '');
    }
    if (in_array($type, ['opening','ordered','in','out','adjustment'], true)) $appliedFilters['Action'] = $labels[$type] ?? $type;
    if ($orderStatus !== '') $appliedFilters['Order Status'] = $orderStatus;
    if ($search !== '') $appliedFilters['Search'] = $search;
    if ($rangeStart || $rangeEnd) $appliedFilters['Date range'] = ($rangeStart?->format('Y-m-d H:i:s') ?? '—') . ' to ' . ($rangeEnd?->format('Y-m-d H:i:s') ?? '—');
    elseif ($timePreset !== 'all') $appliedFilters['Time'] = $timePreset;
    $hardwareActivity = db()->query("SELECT h.performed_at,e.full_name employee_name,e.employee_id,
            h.action,COALESCE(newp.name,oldp.name,assignedp.name) product_name,
            COALESCE(newc.name,oldc.name,assignedc.name) category_name,
            COALESCE(newia.asset_tag,oldia.asset_tag) asset_tag,
            COALESCE(newia.serial_number,oldia.serial_number) serial_number,
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
        LEFT JOIN users u ON u.id=h.performed_by
        ORDER BY h.performed_at DESC,h.id DESC")->fetchAll();
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/history_export.php';
    $spreadsheet = buildInventoryHistoryWorkbook($exportRows, $appliedFilters, $hardwareActivity);
    $filename = 'inventory-history-' . date('Y-m-d') . '.xlsx';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
}

$rows = [];
if ($rangeValid) {
    $sql = $baseSql . $whereSql . $orderSql . ' LIMIT 250';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}
$products = db()->query('SELECT id, name FROM products ORDER BY name')->fetchAll();
$exportFilters = $_GET;
$exportFilters['export'] = 'xlsx';
$exportUrl = 'history.php?' . http_build_query($exportFilters);
require __DIR__ . '/header.php';
?>
<section class="card">
    <form method="get" class="history-filters">
        <div class="history-filter-fields">
            <div><label for="q">Search history</label><input id="q" name="q" value="<?=htmlspecialchars($search)?>" placeholder="Product, reason, or user"></div>
            <div><label for="product">Product</label><select id="product" name="product"><option value="0">All products</option><?php foreach($products as $product): ?><option value="<?=$product['id']?>" <?=$productId===(int)$product['id']?'selected':''?>><?=htmlspecialchars($product['name'])?></option><?php endforeach; ?></select></div>
            <div><label for="type">Action</label><select id="type" name="type"><option value="">All actions</option><?php foreach($labels as $key=>$label): ?><option value="<?=$key?>" <?=$type===$key?'selected':''?>><?=htmlspecialchars($label)?></option><?php endforeach; ?></select></div>
            <div><label for="order_status">Order status</label><select id="order_status" name="order_status"><option value="">All order statuses</option><option value="Pending" <?=$orderStatus==='Pending'?'selected':''?>>Pending</option><option value="Confirmed" <?=$orderStatus==='Confirmed'?'selected':''?>>Confirmed</option><option value="Rejected" <?=$orderStatus==='Rejected'?'selected':''?>>Rejected</option><option value="Received" <?=$orderStatus==='Received'?'selected':''?>>Received</option></select></div>
            <div><label for="time">Time</label><select id="time" name="time"><option value="all" <?=$timePreset==='all'?'selected':''?>>All time</option><option value="today" <?=$timePreset==='today'?'selected':''?>>Today</option><option value="yesterday" <?=$timePreset==='yesterday'?'selected':''?>>Yesterday</option><option value="last7" <?=$timePreset==='last7'?'selected':''?>>Last 7 days</option><option value="last30" <?=$timePreset==='last30'?'selected':''?>>Last 30 days</option><option value="this_month" <?=$timePreset==='this_month'?'selected':''?>>This month</option><option value="custom" <?=$timePreset==='custom'?'selected':''?>>Custom</option></select></div>
            <div class="history-custom-time" <?=$timePreset==='custom'?'':'hidden'?>><label for="from_date">From date</label><input id="from_date" name="from_date" type="date" value="<?=htmlspecialchars($fromDate)?>"><label class="time-label" for="from_time">Time (optional)</label><input id="from_time" name="from_time" type="time" step="1" value="<?=htmlspecialchars($fromTime)?>"></div>
            <div class="history-custom-time" <?=$timePreset==='custom'?'':'hidden'?>><label for="to_date">To date</label><input id="to_date" name="to_date" type="date" value="<?=htmlspecialchars($toDate)?>"><label class="time-label" for="to_time">Time (optional)</label><input id="to_time" name="to_time" type="time" step="1" value="<?=htmlspecialchars($toTime)?>"></div>
        </div>
        <div class="history-filter-actions"><button class="btn" type="submit">Filter</button><a class="btn" href="history.php">Clear</a><a class="btn btn-glow export-button" href="<?=htmlspecialchars($exportUrl)?>">Export Excel</a></div>
    </form>
    <script>document.getElementById('time').addEventListener('change',function(){document.querySelectorAll('.history-custom-time').forEach(function(field){field.hidden=this.value!=='custom';},this);});</script>
    <div class="table-scroll"><table><thead><tr><th>Date</th><th>Product</th><th>Action</th><th>Quantity</th><th>Price</th><th>Total Price</th><th>Reason</th><th>Vendor</th><th>User</th><th>Status</th></tr></thead><tbody>
    <?php foreach($rows as $row): $isOrder=$row['transaction_type']==='ordered'; ?><tr><td><?=htmlspecialchars($row['transaction_at'])?></td><td><?=htmlspecialchars($row['product_name'])?></td><td><?=htmlspecialchars($labels[$row['transaction_type']]??$row['transaction_type'])?></td><td><?=$row['quantity']?></td><td><?=!$isOrder?'—':formatInr($row['unit_price'])?></td><td><?=!$isOrder?'—':formatInr($row['total_price'])?></td><td><?=htmlspecialchars($row['order_reason']??$row['reason'])?></td><td><?=!$isOrder||$row['vendor']===null?'—':htmlspecialchars($row['vendor'])?></td><td><?=htmlspecialchars($row['user_name']??'System')?></td><td><?=htmlspecialchars($row['status']??'—')?></td></tr><?php endforeach; ?>
    <?php if($rangeValid && !$rows): ?><tr><td colspan="10" class="muted">No history records found.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if(count($rows)===250): ?><p class="muted">Showing the latest 250 matching entries. Narrow your search to find older changes.</p><?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
