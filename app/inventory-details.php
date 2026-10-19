<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/category_inventory_helpers.php';
require_once __DIR__ . '/hardware_assignment_helpers.php';
requirePermission('inventory', 'view');
requirePermission('products', 'view');

$view = (string)($_GET['view'] ?? '');
$assignedQuantitySql = activeHardwareAssignmentQuantitySql('a');
$validAssignedEmployeeSql = activeHardwareAssignmentEmployeeSql('a');
if (!in_array($view, ['product', 'brand', 'assigned', 'available', 'total'], true)) {
    http_response_code(404);
    exit('Details page not found');
}
foreach (inventoryDrilldownRequiredPermissions($view) as $required) {
    [$resource, $operation] = explode(':', $required, 2);
    requirePermission($resource, $operation);
}

$categoryId = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$productId = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($categoryId < 1 || ($view !== 'brand' && $productId < 1)) {
    http_response_code(404);
    exit('Details page not found');
}
$pdo = db();
$categoryQuery = $pdo->prepare('SELECT id, name FROM categories WHERE id = ?');
$categoryQuery->execute([$categoryId]);
$category = $categoryQuery->fetch();
if (!$category) {
    http_response_code(404);
    exit('Category not found');
}

$stockSelect = "SELECT p.id,p.name,p.brand,p.model,p.category_id,p.sub_category_id,p.serialized,
       c.name category_name,sc.name subcategory_name,
       COALESCE(stock.available_quantity,0) available_quantity,
       COALESCE(assigned.assigned_quantity,0) assigned_quantity,
       COALESCE(stock.available_quantity,0)+COALESCE(assigned.assigned_quantity,0) total_quantity
 FROM products p JOIN categories c ON c.id=p.category_id
 LEFT JOIN sub_categories sc ON sc.id=p.sub_category_id
 LEFT JOIN (SELECT product_id,new_quantity available_quantity FROM (
       SELECT product_id,new_quantity,ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY transaction_at DESC,id DESC) rn
       FROM inventory_transactions) ranked WHERE rn=1) stock ON stock.product_id=p.id
 LEFT JOIN (SELECT a.product_id,SUM($assignedQuantitySql) assigned_quantity FROM assignments a
       WHERE a.status='assigned' AND a.returned_at IS NULL
       AND $validAssignedEmployeeSql
       GROUP BY a.product_id) assigned ON assigned.product_id=p.id";
$product = null;
$products = [];
$assignments = $assets = $transactions = [];
$bulkAvailable = 0;
$includedAssetCount = 0;
$displayedIncludedCount = 0;
$additionalCountedAssets = 0;
$outsideLedgerCount = 0;
$brand = '';

if ($view === 'brand') {
    $brand = trim((string)($_GET['brand'] ?? ''));
    $query = $pdo->prepare($stockSelect . ($brand === '' ? " WHERE (p.brand IS NULL OR p.brand='')" : ' WHERE p.brand=?') . ' ORDER BY p.name,c.name,p.id');
    if ($brand !== '') $query->execute([$brand]); else $query->execute();
    $products = $query->fetchAll();
    if (!$products) { http_response_code(404); exit('Brand not found'); }
} else {
    $query = $pdo->prepare($stockSelect . ' WHERE p.id=? AND p.category_id=?');
    $query->execute([$productId, $categoryId]);
    $product = $query->fetch();
    if (!$product) { http_response_code(404); exit('Product not found'); }
    $product['assigned_quantity'] = (int)$product['assigned_quantity'];
    $product['available_quantity'] = (int)$product['available_quantity'];
    $product['total_quantity'] = $product['assigned_quantity'] + $product['available_quantity'];

    if (in_array($view, ['assigned', 'total'], true)) {
        $assignmentQuery = $pdo->prepare("SELECT a.id,$assignedQuantitySql quantity,a.assigned_at,a.condition_at_assignment,a.asset_id,
               COALESCE(e.employee_id,a.employee_id,'—') employee_id,
               COALESCE(e.full_name,(SELECT e2.full_name FROM employees e2 WHERE e2.employee_id=a.employee_id LIMIT 1),a.assigned_to,'—') employee_name,
               COALESCE(e.department,(SELECT e2.department FROM employees e2 WHERE e2.employee_id=a.employee_id LIMIT 1),a.department,'—') department,
               COALESCE(e.location,(SELECT e2.location FROM employees e2 WHERE e2.employee_id=a.employee_id LIMIT 1),a.location,'—') location,
               ia.asset_tag,ia.serial_number
             FROM assignments a
             LEFT JOIN employees e ON e.id=a.employee_record_id
             LEFT JOIN inventory_assets ia ON ia.id=a.asset_id
             WHERE a.product_id=? AND a.status='assigned' AND a.returned_at IS NULL
               AND $validAssignedEmployeeSql
             ORDER BY a.assigned_at DESC,a.id DESC");
        $assignmentQuery->execute([$productId]);
        $assignments = $assignmentQuery->fetchAll();
    }
    if (in_array($view, ['available', 'total'], true)) {
        $eligibleAssetWhere = " FROM inventory_assets ia
             WHERE ia.product_id=? AND ia.status='available'
               AND NOT EXISTS(SELECT 1 FROM assignments a WHERE a.asset_id=ia.id AND a.status='assigned' AND a.returned_at IS NULL)
               AND NOT EXISTS(SELECT 1 FROM active_hardware_assignments aha WHERE aha.asset_id=ia.id)";
        $assetCountQuery = $pdo->prepare('SELECT COUNT(*)' . $eligibleAssetWhere);
        $assetCountQuery->execute([$productId]);
        $eligibleAssetCount = (int)$assetCountQuery->fetchColumn();
        $includedAssetCount = min($product['available_quantity'], $eligibleAssetCount);
        $assetQuery = $pdo->prepare("SELECT ia.asset_tag,ia.serial_number,ia.location
             FROM inventory_assets ia
             WHERE ia.product_id=? AND ia.status='available'
               AND NOT EXISTS(SELECT 1 FROM assignments a WHERE a.asset_id=ia.id AND a.status='assigned' AND a.returned_at IS NULL)
               AND NOT EXISTS(SELECT 1 FROM active_hardware_assignments aha WHERE aha.asset_id=ia.id)
             ORDER BY ia.asset_tag LIMIT 500");
        $assetQuery->execute([$productId]);
        $assets = $assetQuery->fetchAll();
        $displayedIncludedCount = min($includedAssetCount, count($assets));
        $additionalCountedAssets = $includedAssetCount - $displayedIncludedCount;
        $outsideLedgerCount = $eligibleAssetCount - $includedAssetCount;
        $bulkAvailable = inventoryDrilldownBulkAvailable($product['available_quantity'], $includedAssetCount);
    }
    if ($view === 'product') {
        if (canViewResource('hardware')) {
            $assetQuery = $pdo->prepare('SELECT asset_tag,serial_number,status,location,asset_condition FROM inventory_assets WHERE product_id=? ORDER BY asset_tag LIMIT 500');
            $assetQuery->execute([$productId]);
            $assets = $assetQuery->fetchAll();
        }
        if (canViewResource('history')) {
            $historyQuery = $pdo->prepare('SELECT transaction_type,quantity,previous_quantity,new_quantity,reason,transaction_at FROM inventory_transactions WHERE product_id=? ORDER BY transaction_at DESC,id DESC LIMIT 100');
            $historyQuery->execute([$productId]);
            $transactions = $historyQuery->fetchAll();
        }
    }
}

$pageTitle = match ($view) {
    'product' => 'Product Details', 'brand' => 'Brand Details', 'assigned' => 'Assigned Inventory Details',
    'available' => 'Available Inventory Details', default => 'Total Inventory Details',
};
$safe = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$canManageProducts = canPerform('products', 'edit');
$categoryUrl = categoryInventoryDetailsUrl($categoryId);
require __DIR__ . '/header.php';
?>
<section class="category-detail-section inventory-drilldown">
    <div class="category-detail-heading"><div><div class="eyebrow"><?= $safe($category['name']) ?> · INVENTORY DETAILS</div><h2><?= $safe($pageTitle) ?></h2></div><div class="drilldown-nav"><a class="btn" href="<?= $safe($categoryUrl) ?>">Back to Category</a><a class="btn" href="dashboard.php">Back to Dashboard</a></div></div>
    <?php if ($view === 'brand'): ?>
        <div class="card"><h3><?= $safe($brand !== '' ? $brand : 'No brand') ?> products</h3><p class="muted">Products with this brand across all categories.</p><div class="table-scroll"><table><thead><tr><th>Product</th><th>Category</th><th>Sub-category</th><th>Assigned</th><th>Available</th><th>Total</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($products as $row): ?><tr><td><a class="inventory-drill-link" href="<?= $safe(inventoryDrilldownUrl('product',(int)$row['category_id'],(int)$row['id'])) ?>"><?= $safe($row['name']) ?></a></td><td><?= $safe($row['category_name']) ?></td><td><?= $safe($row['subcategory_name'] ?: '—') ?></td><td><?= number_format((int)$row['assigned_quantity']) ?></td><td><?= number_format((int)$row['available_quantity']) ?></td><td><?= number_format((int)$row['total_quantity']) ?></td><td><a class="btn" href="products.php?<?= http_build_query(['category_id'=>(int)$row['category_id'],'sub_category_id'=>$row['sub_category_id'] ?: '', 'product_id'=>(int)$row['id']]) ?>"><?= $canManageProducts ? 'Manage' : 'View' ?></a></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <?php else: ?>
        <div class="card"><div class="detail-product-heading"><div><h3><?= $safe($product['name']) ?></h3><p class="muted"><?= $safe($product['brand'] ?: 'No brand') ?> · <?= $safe($product['model'] ?: 'No model') ?> · <?= $safe($product['category_name']) ?> / <?= $safe($product['subcategory_name'] ?: 'No sub-category') ?></p></div><div class="drilldown-nav"><a class="btn" href="products.php?<?= http_build_query(['category_id'=>$categoryId,'sub_category_id'=>$product['sub_category_id'] ?: '', 'product_id'=>$productId]) ?>"><?= $canManageProducts ? 'Manage Product' : 'View Product' ?></a><?php if(canViewResource('hardware')): ?><a class="btn" href="hardware-assets.php?q=<?=rawurlencode((string)$product['name'])?>">Hardware Assets</a><?php endif; ?></div></div>
        <div class="category-inventory-values category-detail-totals" aria-label="Product stock totals"><span><span>Assigned quantity</span><strong class="category-active-value"><?= number_format($product['assigned_quantity']) ?></strong></span><span><span>Available quantity</span><strong><?= number_format($product['available_quantity']) ?></strong></span><span><span>Total quantity</span><strong class="category-total-value"><?= number_format($product['total_quantity']) ?></strong></span></div>
        <?php if ($view === 'product'): ?>
        <?php if (canViewResource('hardware')): ?><h3>Registered hardware</h3><div class="table-scroll"><table><thead><tr><th>Asset tag</th><th>Serial number</th><th>Status</th><th>Location</th><th>Condition</th></tr></thead><tbody><?php foreach ($assets as $asset): ?><tr><td><?= $safe($asset['asset_tag']) ?></td><td><?= $safe($asset['serial_number'] ?: '—') ?></td><td><?= $safe(ucfirst($asset['status'])) ?></td><td><?= $safe($asset['location'] ?: '—') ?></td><td><?= $safe($asset['asset_condition'] ?: '—') ?></td></tr><?php endforeach; ?><?php if (!$assets): ?><tr><td colspan="5" class="empty-state">No hardware records are registered.</td></tr><?php endif; ?></tbody></table></div><p class="muted">Showing up to 500 registered records. Search all assets on the <a href="hardware-assets.php?q=<?=rawurlencode((string)$product['name'])?>">Hardware Assets page</a>.</p><?php else: ?><p class="muted">Hardware details are not available to your account.</p><?php endif; ?>
        <?php if (canViewResource('history')): ?><h3>Inventory history</h3><div class="table-scroll"><table><thead><tr><th>Date</th><th>Type</th><th>Quantity change</th><th>Balance</th><th>Reason</th></tr></thead><tbody><?php foreach ($transactions as $tx): ?><tr><td><?= $safe($tx['transaction_at']) ?></td><td><?= $safe($tx['transaction_type']) ?></td><td><?= number_format((int)$tx['quantity']) ?></td><td><?= number_format((int)$tx['new_quantity']) ?></td><td><?= $safe($tx['reason'] ?: '—') ?></td></tr><?php endforeach; ?><?php if (!$transactions): ?><tr><td colspan="5" class="empty-state">No inventory history is recorded.</td></tr><?php endif; ?></tbody></table></div><?php else: ?><p class="muted">Inventory history is not available to your account.</p><?php endif; ?>
        <?php elseif (in_array($view, ['assigned','total'], true)): ?><h3>Assigned inventory</h3><div class="table-scroll"><table><thead><tr><th>Employee ID</th><th>Name</th><th>Department</th><th>Location</th><th>Assigned quantity</th><th>Asset tag</th><th>Serial number</th><th>Assignment date</th><th>Condition</th></tr></thead><tbody><?php foreach ($assignments as $row): ?><tr><td><?= $safe($row['employee_id']) ?></td><td><?= $safe($row['employee_name']) ?></td><td><?= $safe($row['department']) ?></td><td><?= $safe($row['location']) ?></td><td><?= number_format((int)$row['quantity']) ?></td><td><?= $safe($row['asset_tag'] ?: '—') ?></td><td><?= $safe($row['serial_number'] ?: '—') ?></td><td><?= $safe($row['assigned_at']) ?></td><td><?= $safe($row['condition_at_assignment'] ?: '—') ?></td></tr><?php endforeach; ?><?php if (!$assignments): ?><tr><td colspan="9" class="empty-state">No employees currently hold this product.</td></tr><?php endif; ?></tbody></table></div><p class="detail-reconciliation">Assigned total: <strong><?= number_format($product['assigned_quantity']) ?></strong></p><?php endif; ?>
        <?php if (in_array($view, ['available','total'], true)): ?><h3>Available inventory</h3><div class="table-scroll"><table><thead><tr><th>Stock type</th><th>Asset tag</th><th>Serial number</th><th>Location</th><th>Counted quantity</th></tr></thead><tbody><?php foreach ($assets as $assetIndex => $asset): ?><tr><td>Unassigned registered hardware</td><td><?= $safe($asset['asset_tag']) ?></td><td><?= $safe($asset['serial_number'] ?: '—') ?></td><td><?= $safe($asset['location'] ?: '—') ?></td><td><?= $assetIndex < $displayedIncludedCount ? '1' : '0 (outside ledger balance)' ?></td></tr><?php endforeach; ?><?php if ($additionalCountedAssets > 0): ?><tr><td>Additional registered hardware</td><td colspan="3">Further tagged available units; search on Hardware Assets</td><td><?= number_format($additionalCountedAssets) ?></td></tr><?php endif; ?><?php if ($outsideLedgerCount > 0): ?><tr><td>Registered records outside ledger balance</td><td colspan="3">Not counted toward available stock</td><td>0 (<?= number_format($outsideLedgerCount) ?> records)</td></tr><?php endif; ?><?php if ($bulkAvailable > 0): ?><tr><td>Unregistered or bulk stock</td><td colspan="3">Aggregated quantity</td><td><?= number_format($bulkAvailable) ?></td></tr><?php endif; ?><?php if (!$assets && $bulkAvailable === 0 && $outsideLedgerCount === 0): ?><tr><td colspan="5" class="empty-state">No available quantity is recorded.</td></tr><?php endif; ?></tbody></table></div><p class="detail-reconciliation">Available total: <strong><?= number_format($product['available_quantity']) ?></strong></p><?php endif; ?>
        <?php if ($view === 'total'): ?><div class="detail-reconciliation">Total reconciliation: <strong><?= number_format($product['assigned_quantity']) ?> assigned + <?= number_format($product['available_quantity']) ?> available = <?= number_format($product['total_quantity']) ?> total</strong></div><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
