<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requirePermission('search', 'view');

$pageTitle = 'Search';
$query = trim((string)($_GET['q'] ?? ''));
$query = function_exists('mb_substr') ? mb_substr($query, 0, 100) : substr($query, 0, 100);
$groups = [];

if ($query !== '') {
    $pdo = db();
    $like = '%' . $query . '%';
    $run = static function (string $sql, array $params) use ($pdo): array {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    };
    $canSeeEmployeeDetails = canViewResource('employees');
    $searches = [
        'Inventory' => ['products', fn(): array => $run(
            'SELECT p.id, p.name, p.brand, p.model, c.name AS category_name
             FROM products p JOIN categories c ON c.id=p.category_id
             WHERE p.name LIKE ? OR p.brand LIKE ? OR p.model LIKE ? OR c.name LIKE ?
             ORDER BY p.name LIMIT 10',
            [$like, $like, $like, $like]
        )],
        'Employees' => ['employees', fn(): array => $run(
            'SELECT id, employee_id, full_name, department, location
             FROM employees WHERE full_name LIKE ? OR employee_id LIKE ? OR department LIKE ? OR location LIKE ?
             ORDER BY full_name LIMIT 10',
            [$like, $like, $like, $like]
        )],
        'Hardware' => ['hardware', fn(): array => $run(
            'SELECT a.id, a.product_id, a.asset_tag, a.serial_number, a.status, a.location, p.name AS product_name, '
             . ($canSeeEmployeeDetails ? 'e.id AS assigned_employee_id, e.full_name AS assigned_employee' : 'NULL AS assigned_employee_id, NULL AS assigned_employee') . '
             FROM inventory_assets a JOIN products p ON p.id=a.product_id'
             . ($canSeeEmployeeDetails ? ' LEFT JOIN active_hardware_assignments active ON active.asset_id=a.id LEFT JOIN assignments assignment ON assignment.id=active.assignment_id LEFT JOIN employees e ON e.id=assignment.employee_record_id' : '') . '
             WHERE a.asset_tag LIKE ? OR a.serial_number LIKE ? OR p.name LIKE ? OR a.location LIKE ?
             ORDER BY a.asset_tag LIMIT 10',
            [$like, $like, $like, $like]
        )],
        'Vendors' => ['vendors', fn(): array => $run(
            'SELECT id, vendor_name, contact_person, phone, email
             FROM vendors WHERE vendor_name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR email LIKE ? OR gstin LIKE ?
             ORDER BY vendor_name LIMIT 10',
            [$like, $like, $like, $like, $like]
        )],
        'Orders' => ['orders', fn(): array => $run(
            "SELECT t.id, p.name AS product_name, t.quantity, t.status, t.vendor_name_snapshot,
                    t.vendor, t.expected_date
             FROM inventory_transactions t JOIN products p ON p.id=t.product_id
             WHERE t.transaction_type='ordered' AND (p.name LIKE ? OR t.vendor LIKE ? OR t.vendor_name_snapshot LIKE ? OR t.status LIKE ? OR CAST(t.id AS CHAR) LIKE ?)
             ORDER BY t.transaction_at DESC LIMIT 10",
            [$like, $like, $like, $like, $like]
        )],
        'Categories' => ['categories', fn(): array => $run(
            'SELECT id, name, description FROM categories WHERE name LIKE ? OR description LIKE ? ORDER BY name LIMIT 10',
            [$like, $like]
        )],
    ];
    foreach ($searches as $name => [$resource, $searchGroup]) {
        if (canViewResource($resource)) $groups[$name] = $searchGroup();
    }
}

require __DIR__ . '/header.php';
?>
<section class="card global-search-page">
    <form method="get" action="search.php" class="global-search-form">
        <label for="search-query">Search all inventory</label>
        <div><input id="search-query" name="q" type="search" value="<?=htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" maxlength="100" autofocus><button class="btn btn-primary" type="submit">Search</button></div>
    </form>
    <?php if ($query === ''): ?>
        <p class="muted">Search inventory, employees, hardware tags, vendors, orders and categories.</p>
    <?php else: $total = array_sum(array_map('count', $groups)); ?>
        <p class="muted search-summary"><?=$total?> result<?=($total === 1 ? '' : 's')?> for <strong><?=htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong><?=($total === 50 ? ' (showing up to 10 per section)' : '')?></p>
        <div class="search-groups">
        <?php foreach ($groups as $group => $rows): if (!$rows) continue; ?>
            <section class="search-group"><h2><?=htmlspecialchars($group, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></h2>
                <ul>
                <?php foreach ($rows as $row):
                    if ($group === 'Inventory') { $title=$row['name']; $detail=trim(($row['brand']??'') . ' ' . ($row['model']??'')) . ' · ' . $row['category_name']; $href='products.php?status=all&q=' . rawurlencode($query); }
                    elseif ($group === 'Employees') { $title=$row['full_name']; $detail=$row['employee_id'] . ' · ' . ($row['department'] ?: 'No department') . ' · ' . ($row['location'] ?: 'No location'); $href='employees.php?q=' . rawurlencode($query); }
                    elseif ($group === 'Hardware') { $title=$row['asset_tag'] . ' · ' . $row['product_name']; $detail='Status: ' . $row['status'] . ($row['serial_number'] ? ' · Serial: ' . $row['serial_number'] : '') . ($row['assigned_employee'] ? ' · Assigned to ' . $row['assigned_employee'] : ''); $href=$row['assigned_employee_id'] && canViewResource('employees') ? 'employee.php?id=' . (int)$row['assigned_employee_id'] : (canViewResource('products') ? 'products.php?manage=' . (int)$row['product_id'] : 'search.php?q=' . rawurlencode($query)); }
                    elseif ($group === 'Vendors') { $title=$row['vendor_name']; $detail=implode(' · ', array_filter([$row['contact_person'], $row['phone'], $row['email']])); $href='vendors.php?status=all&q=' . rawurlencode($query); }
                    elseif ($group === 'Orders') { $title='Order #' . $row['id'] . ' · ' . $row['product_name']; $detail=($row['quantity'] < 0 ? abs((int)$row['quantity']) : $row['quantity']) . ' units · ' . ($row['status'] ?: 'No status') . ' · ' . ($row['vendor_name_snapshot'] ?: $row['vendor'] ?: 'No vendor'); $href='orders.php'; }
                    else { $title=$row['name']; $detail=(string)($row['description'] ?? ''); $href='categories.php'; }
                ?>
                    <li><a href="<?=htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><strong><?=htmlspecialchars((string)$title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong><span><?=htmlspecialchars($detail !== '' ? $detail : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></span></a></li>
                <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
        </div>
        <?php if ($total === 0): ?><p class="empty-state">No matching records were found.</p><?php endif; ?>
    <?php endif; ?>
</section>
