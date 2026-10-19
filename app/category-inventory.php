<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/category_inventory_helpers.php';
require_once __DIR__ . '/hardware_assignment_helpers.php';
requirePermission('inventory', 'view');
requirePermission('products', 'view');
requirePermission('assignments', 'view');
requirePermission('employees', 'view');
requirePermission('hardware', 'view');

$pdo = db();
$assignedQuantitySql = activeHardwareAssignmentQuantitySql('a');
$validAssignedEmployeeSql = activeHardwareAssignmentEmployeeSql('a');
$categoryId = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$subcategoryId = filter_var($_GET['sub_category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$productId = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$search = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'name');
$sortColumns = ['name'=>'name','brand'=>'brand','model'=>'model','assigned'=>'assigned_quantity','available'=>'available_quantity','total'=>'total_quantity'];
if (!isset($sortColumns[$sort])) $sort = 'name';
$direction = strtolower((string)($_GET['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$detailPage = max(1, (int)($_GET['page'] ?? 1));
$detailPerPage = 50;

$categoryQuery = $pdo->prepare('SELECT id, name FROM categories WHERE id = ?');
$categoryQuery->execute([$categoryId]);
$category = $categoryQuery->fetch();
if (!$category) {
    http_response_code(404);
    $pageTitle = 'Category not found';
    require __DIR__ . '/header.php';
    echo '<section class="card"><h2>Category not found</h2><p class="muted">The selected category is unavailable.</p><a class="btn" href="dashboard.php">Back to Dashboard</a></section>';
    require __DIR__ . '/footer.php';
    exit;
}

$subcategoryQuery = $pdo->prepare('SELECT id, name FROM sub_categories WHERE category_id = ? ORDER BY name, id');
$subcategoryQuery->execute([$categoryId]);
$subcategories = $subcategoryQuery->fetchAll();
$validSubcategoryIds = array_map(static fn(array $row): int => (int)$row['id'], $subcategories);
if ($subcategoryId > 0 && !in_array($subcategoryId, $validSubcategoryIds, true)) $subcategoryId = 0;

// Latest ledger quantity is available stock because assignments record an "out"
// transaction. Active assignment rows supply the assigned count independently.
$productsQuery = $pdo->prepare(
    "SELECT p.id, p.name, p.brand, p.model, p.sub_category_id,
            sc.name AS subcategory_name,
            COALESCE(stock.available_quantity, 0) AS available_quantity,
            COALESCE(assigned.assigned_quantity, 0) AS assigned_quantity,
            COALESCE(stock.available_quantity, 0) + COALESCE(assigned.assigned_quantity, 0) AS total_quantity
     FROM products p
     LEFT JOIN sub_categories sc ON sc.id = p.sub_category_id
     LEFT JOIN (
         SELECT product_id, SUM(CASE WHEN transaction_type IN ('in','opening') THEN ABS(quantity) WHEN transaction_type='out' THEN -ABS(quantity) WHEN transaction_type='adjustment' THEN quantity ELSE 0 END) AS available_quantity FROM inventory_transactions GROUP BY product_id
     ) stock ON stock.product_id = p.id
     LEFT JOIN (
         SELECT a.product_id, SUM($assignedQuantitySql) AS assigned_quantity
         FROM assignments a
         WHERE a.status = 'assigned' AND a.returned_at IS NULL
           AND $validAssignedEmployeeSql
         GROUP BY a.product_id
     ) assigned ON assigned.product_id = p.id
     WHERE p.category_id = ?
     ORDER BY p.name, p.id"
);
$productsQuery->execute([$categoryId]);
$allProducts = $productsQuery->fetchAll();
if ($productId > 0 && !filterCategoryInventoryRows($allProducts, $subcategoryId, $productId)) $productId = 0;
$filteredProducts = filterCategoryInventoryRows($allProducts, $subcategoryId, $productId);
$filteredProducts = array_values(array_filter($filteredProducts, static function (array $row) use ($search): bool {
    if ($search === '') return true;
    foreach (['name','brand','model','subcategory_name'] as $field) if (stripos((string)($row[$field] ?? ''), $search) !== false) return true;
    return false;
}));
usort($filteredProducts, static function (array $left, array $right) use ($sortColumns, $sort, $direction): int {
    $a = $left[$sortColumns[$sort]] ?? ''; $b = $right[$sortColumns[$sort]] ?? '';
    $comparison = is_numeric($a) && is_numeric($b) ? ((float)$a <=> (float)$b) : strnatcasecmp((string)$a, (string)$b);
    return ($direction === 'DESC' ? -$comparison : $comparison) ?: ((int)$left['id'] <=> (int)$right['id']);
});
$filteredCount = count($filteredProducts);
$detailPages = max(1, (int)ceil($filteredCount / $detailPerPage));
$detailPage = min($detailPage, $detailPages);
$filteredProducts = array_slice($filteredProducts, ($detailPage - 1) * $detailPerPage, $detailPerPage);
$totals = categoryInventoryDetailsTotals($allProducts);

$pageTitle = 'Category Details';
$safe = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$canManageProducts = canPerform('products', 'edit');
$metricUrl = static function (string $view) use ($categoryId, $subcategoryId, $productId, $search): string {
    return 'dashboard-details.php?' . http_build_query(array_filter([
        'view' => $view, 'category_id' => $categoryId,
        'sub_category_id' => $subcategoryId ?: null, 'product_id' => $productId ?: null, 'q' => $search ?: null,
    ], static fn($value): bool => $value !== null && $value !== ''));
};
require __DIR__ . '/header.php';
?>
<section class="category-detail-section">
    <div class="category-detail-heading">
        <div><div class="eyebrow">INVENTORY CATEGORY</div><h2><?= $safe($category['name']) ?></h2></div>
        <div class="category-detail-actions"><?php if (canViewResource('hardware')): ?><a class="btn" href="hardware-assets.php">Hardware Assets</a><?php endif; ?><a class="btn" href="dashboard.php">Back to Dashboard</a></div>
    </div>
    <div class="category-inventory-values category-detail-totals" aria-label="Category inventory totals">
        <a href="<?= $safe($metricUrl('category_active')) ?>"><span>Active (assigned)</span><strong class="category-active-value"><?=number_format($totals['active'])?></strong></a>
        <a href="<?= $safe($metricUrl('category_inactive')) ?>"><span>Inactive (available)</span><strong><?=number_format($totals['inactive'])?></strong></a>
        <a href="<?= $safe($metricUrl('category_total')) ?>"><span>Total</span><strong class="category-total-value"><?=number_format($totals['total'])?></strong></a>
    </div>
    <form method="get" class="toolbar category-detail-filters">
        <input type="hidden" name="category_id" value="<?=$categoryId?>">
        <div><label for="category-detail-subcategory">Sub-category</label><select id="category-detail-subcategory" name="sub_category_id"><option value="">All sub-categories</option><?php foreach ($subcategories as $subcategory): ?><option value="<?=$subcategory['id']?>" <?=$subcategoryId === (int)$subcategory['id'] ? 'selected' : ''?>><?=$safe($subcategory['name'])?></option><?php endforeach; ?></select></div>
        <div><label for="category-detail-product">Product</label><select id="category-detail-product" name="product_id"><option value="">All products</option><?php foreach ($allProducts as $product): ?><?php if ($subcategoryId > 0 && (int)($product['sub_category_id'] ?? 0) !== $subcategoryId) continue; ?><option value="<?=$product['id']?>" <?=$productId === (int)$product['id'] ? 'selected' : ''?>><?=$safe($product['name'])?></option><?php endforeach; ?></select></div>
        <div><label for="category-detail-search">Search</label><input id="category-detail-search" name="q" value="<?=$safe($search)?>" placeholder="Product, brand or model"></div>
        <div><label for="category-detail-sort">Sort by</label><select id="category-detail-sort" name="sort"><?php foreach ($sortColumns as $sortKey => $_column): ?><option value="<?=$safe($sortKey)?>" <?=$sort === $sortKey ? 'selected' : ''?>><?=$safe(ucfirst($sortKey))?></option><?php endforeach; ?></select></div>
        <div><label for="category-detail-direction">Direction</label><select id="category-detail-direction" name="direction"><option value="asc" <?=$direction === 'ASC' ? 'selected' : ''?>>Ascending</option><option value="desc" <?=$direction === 'DESC' ? 'selected' : ''?>>Descending</option></select></div>
        <button class="btn" type="submit">Apply filters</button>
        <?php if ($subcategoryId > 0 || $productId > 0 || $search !== '' || $sort !== 'name' || $direction !== 'ASC'): ?><a class="btn" href="<?= $safe(categoryInventoryDetailsUrl($categoryId)) ?>">Clear filters</a><?php endif; ?>
    </form>
    <p class="muted">Showing <?=count($filteredProducts)?> of <?=$filteredCount?> matching products · page <?=$detailPage?> of <?=$detailPages?></p>
    <div class="table-scroll"><table class="category-detail-table">
        <thead><tr><th>Product</th><th>Brand</th><th>Model</th><th>Sub-category</th><th>Assigned quantity</th><th>Available quantity</th><th>Total quantity</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($filteredProducts as $product): ?>
            <tr><td><a class="inventory-drill-link" href="inventory-details.php?<?=http_build_query(['view'=>'product','category_id'=>$categoryId,'product_id'=>(int)$product['id']])?>"><?=$safe($product['name'])?></a></td><td><a class="inventory-drill-link" href="inventory-details.php?<?=http_build_query(['view'=>'brand','category_id'=>$categoryId,'brand'=>(string)$product['brand']])?>"><?=$safe($product['brand'] ?: '—')?></a></td><td><?=$safe($product['model'] ?: '—')?></td><td><?=$safe($product['subcategory_name'] ?: '—')?></td><td><a class="inventory-drill-link" href="inventory-details.php?<?=http_build_query(['view'=>'assigned','category_id'=>$categoryId,'product_id'=>(int)$product['id']])?>"><?=number_format((int)$product['assigned_quantity'])?></a></td><td><a class="inventory-drill-link" href="inventory-details.php?<?=http_build_query(['view'=>'available','category_id'=>$categoryId,'product_id'=>(int)$product['id']])?>"><?=number_format((int)$product['available_quantity'])?></a></td><td><a class="inventory-drill-link" href="inventory-details.php?<?=http_build_query(['view'=>'total','category_id'=>$categoryId,'product_id'=>(int)$product['id']])?>"><?=number_format((int)$product['total_quantity'])?></a></td><td><a class="btn" href="products.php?<?=http_build_query(['category_id'=>$categoryId,'sub_category_id'=>$product['sub_category_id'] ?: '', 'product_id'=>(int)$product['id']])?>"><?=$canManageProducts ? 'Manage' : 'View'?></a></td></tr>
        <?php endforeach; ?>
        <?php if (!$filteredProducts): ?><tr><td colspan="8" class="empty-state">No products match this category filter.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <nav class="pagination" aria-label="Category product pages"><?php for ($n = 1; $n <= $detailPages; $n++): $pageParams = $_GET; $pageParams['page'] = $n; ?><a class="btn" href="?<?= $safe(http_build_query($pageParams)) ?>" <?=$n === $detailPage ? 'aria-current="page"' : ''?>><?=$n?></a><?php endfor; ?></nav>
</section>
<?php require __DIR__ . '/footer.php'; ?>
