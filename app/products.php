<?php
require_once __DIR__ . '/inventory_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('products', 'view');

$pageTitle = 'Inventory';
$pdo = db();
$canAddProduct = canPerform('products', 'add');
$canEditProduct = canPerform('products', 'edit');
$canChangeProductStatus = canPerform('products', 'status');
$canAdjustStock = canPerform('inventory', 'stock_adjustment');
$activeVendors = canPerform('orders', 'add') && canViewResource('vendors') ? $pdo->query('SELECT id,vendor_name,contact_person,phone,email,address,gstin FROM vendors WHERE active=1 ORDER BY vendor_name')->fetchAll() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $stockOperation = (string)($_POST['operation'] ?? '');
    [$resource, $permissionOperation] = match ($action) {
        'toggle' => ['products', 'status'],
        'create' => ['products', 'add'],
        'update' => ['products', 'edit'],
        'stock' => $stockOperation === 'ordered' ? ['orders', 'add'] : ['inventory', 'stock_adjustment'],
        default => ['products', 'edit'],
    };
    requirePermission($resource, $permissionOperation);
    verifyCsrf();

    $productId = (int)($_POST['product_id'] ?? 0);
    $redirect = $productId > 0 ? 'products.php?manage=' . $productId : 'products.php';
    try {
        if ($action === 'stock') {
            $operation = (string)($_POST['operation'] ?? '');
            $quantityInput = trim((string)($_POST['quantity'] ?? ''));
            if (!preg_match('/^[0-9]{1,10}$/D', $quantityInput) || (int)$quantityInput > 2147483647) {
                throw new DomainException('Enter a positive whole-number quantity.');
            }
            $quantity = (int)$quantityInput;
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (!in_array($operation, ['in', 'out', 'ordered'], true) || $quantity < 1 || !in_array($reason, ['Requested', 'New', 'Replacement', 'Purchase'], true)) {
                throw new DomainException('Choose an operation and reason, and enter a positive quantity.');
            }

            $unitPrice = null;
            $totalPrice = null;
            $vendor = null;
            $vendorRecord = null;
            $status = null;
            if ($operation === 'ordered') {
                $priceInput = trim((string)($_POST['unit_price'] ?? ''));
                $price = calculateTransactionPrice($quantity, $priceInput);
                $unitPrice = $price['unit_price'];
                $totalPrice = $price['total_price'];
                $status = $operation === 'ordered' ? 'Pending' : 'Received';
            }

            $pdo->beginTransaction();
            if ($operation === 'ordered') { $vendorRecord = activeVendorForOrder($pdo, $_POST['vendor_id'] ?? null); $vendor = $vendorRecord['vendor_name']; }
            $lock = $pdo->prepare('SELECT id FROM products WHERE id = ? AND active = 1 FOR UPDATE');
            $lock->execute([$productId]);
            if (!$lock->fetchColumn()) {
                throw new DomainException('Product is unavailable.');
            }
            $previous = currentStock($productId);
            $movement = calculateInventoryChange($previous, $operation, $quantity);
            $delta = $movement['delta'];
            $current = $movement['new_stock'];

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_transactions
                    (product_id, transaction_type, quantity, previous_quantity, new_quantity, reason, unit_price, total_price, vendor, vendor_id, vendor_name_snapshot, vendor_contact_snapshot, vendor_phone_snapshot, vendor_email_snapshot, vendor_address_snapshot, vendor_gstin_snapshot, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)'
            );
            $stmt->execute([$productId, $operation, $delta, $previous, $current, $reason, $unitPrice, $totalPrice, $vendor, $vendorRecord['id']??null, $vendorRecord['vendor_name']??null, $vendorRecord['contact_person']??null, $vendorRecord['phone']??null, $vendorRecord['email']??null, $vendorRecord['address']??null, $vendorRecord['gstin']??null, $status, currentUser()['id']]);
            audit(
                'stock_update',
                'product',
                $productId,
                "Stock changed: {$previous} → {$current}; Reason: {$reason}"
            );
            recalculateInventoryMonths($productId);
            $pdo->commit();
            setFlash('success', 'Stock updated successfully.');
            header('Location: ' . $redirect);
            exit;
        }

        if (!in_array($action, ['create', 'update', 'toggle'], true)) {
            throw new DomainException('Choose a valid product action.');
        }
        $pdo->beginTransaction();

        if ($action === 'toggle') {
            $stmt = $pdo->prepare('UPDATE products SET active = IF(active = 1, 0, 1) WHERE id = ?');
            $stmt->execute([$productId]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Product could not be updated.');
            }
            audit('status_change', 'product', $productId, 'Product status changed');
            $pdo->commit();
            setFlash('success', 'Changes saved successfully.');
            header('Location: products.php');
            exit;
        }

        $categoryId = (int)($_POST['category_id'] ?? 0);
        $subcategoryRaw = trim((string)($_POST['sub_category_id'] ?? ''));
        $subcategoryId = $subcategoryRaw === '' ? null : (int)$subcategoryRaw;
        $name = trim((string)($_POST['name'] ?? ''));
        $unit = trim((string)($_POST['unit'] ?? ''));
        if ($unit !== '' && !preg_match('/^[0-9]+$/D', $unit)) {
            throw new DomainException('Unit must contain numbers only.');
        }
        $brand = trim((string)($_POST['brand'] ?? ''));
        $model = trim((string)($_POST['model'] ?? ''));
        $minimumStock = filter_var($_POST['minimum_stock'] ?? 0, FILTER_VALIDATE_INT);
        if ($categoryId < 1) {
            throw new DomainException('Please select a category.');
        }
        if ($name === '') {
            throw new DomainException('Please enter a product name.');
        }
        if ($minimumStock === false || $minimumStock < 0) {
            throw new DomainException('Minimum stock must be zero or greater.');
        }
        $existingProduct = null;
        if ($action === 'update') {
            $stmt = $pdo->prepare('SELECT category_id, sub_category_id, unit FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $existingProduct = $stmt->fetch();
            if (!$existingProduct) throw new DomainException('Product was not found.');
            if ($unit === '' && !preg_match('/^[0-9]+$/D', (string)$existingProduct['unit'])) {
                $unit = (string)$existingProduct['unit'];
            }
        }
        $stmt = $pdo->prepare('SELECT id, active FROM categories WHERE id = ?');
        $stmt->execute([$categoryId]);
        $categoryRow = $stmt->fetch();
        if (!$categoryRow || (!$categoryRow['active'] && ($action === 'create' || (int)$existingProduct['category_id'] !== $categoryId))) {
            throw new DomainException('Please select an active category.');
        }
        if ($subcategoryId !== null) {
            $stmt = $pdo->prepare('SELECT id, active FROM sub_categories WHERE id = ? AND category_id = ?');
            $stmt->execute([$subcategoryId, $categoryId]);
            $subcategoryRow = $stmt->fetch();
            if (!$subcategoryRow || (!$subcategoryRow['active'] && ($action === 'create' || (int)$existingProduct['sub_category_id'] !== $subcategoryId))) {
                throw new DomainException('The selected sub-category does not belong to this category.');
            }
        }

        $values = [
            $categoryId,
            $subcategoryId,
            $name,
        ];
        array_push(
            $values,
            $brand !== '' ? $brand : null,
            $model !== '' ? $model : null,
            $unit,
            $minimumStock,
            isset($_POST['serialized']) ? 1 : 0,
            trim((string)($_POST['description'] ?? '')) ?: null
        );

        if ($action === 'create') {
            $columns = 'category_id, sub_category_id, name, brand, model, unit, minimum_stock, serialized, description';
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $stmt = $pdo->prepare("INSERT INTO products ({$columns}) VALUES ({$placeholders})");
            $stmt->execute($values);
            $productId = (int)$pdo->lastInsertId();
            audit('create', 'product', $productId, $name);
            $pdo->commit();
            setFlash('success', 'Product created successfully.');
            header('Location: products.php?manage=' . $productId);
            exit;
        }

        $sets = 'category_id = ?, sub_category_id = ?, name = ?, brand = ?, model = ?, unit = ?, minimum_stock = ?, serialized = ?, description = ?';
        $values[] = $productId;
        $stmt = $pdo->prepare("UPDATE products SET {$sets} WHERE id = ?");
        $stmt->execute($values);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT id FROM products WHERE id = ?');
            $exists->execute([$productId]);
            if (!$exists->fetchColumn()) {
                throw new DomainException('Product was not found.');
            }
        }
        audit('update', 'product', $productId, $name);
        $pdo->commit();
        setFlash('success', 'Changes saved successfully.');
        header('Location: ' . $redirect);
        exit;
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Inventory product operation failed: ' . $exception->getMessage());
        setFlash('error', (int)($exception->errorInfo[1] ?? 0) === 1062
            ? 'A product with those details already exists.'
            : 'Unable to save the product. Please check the entered information.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Inventory operation failed: ' . $exception->getMessage());
        setFlash('error', 'Unable to update stock. Please try again.');
    }
    header('Location: ' . $redirect);
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$categoryFilter = (int)($_GET['category'] ?? 0);
$subcategoryFilter = (int)($_GET['subcategory'] ?? 0);
$stockFilter = (string)($_GET['stock'] ?? '');
$statusFilter = (string)($_GET['status'] ?? 'active');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 200;
$categories = $pdo->query('SELECT id, name FROM categories WHERE active = 1 ORDER BY name')->fetchAll();
$subcategories = $pdo->query('SELECT id, category_id, name FROM sub_categories WHERE active = 1 ORDER BY name')->fetchAll();

$productSql = "SELECT p.*, c.name AS category_name, sc.name AS subcategory_name,
        COALESCE((SELECT t.new_quantity FROM inventory_transactions t WHERE t.product_id=p.id ORDER BY t.transaction_at DESC,t.id DESC LIMIT 1),0) AS current_quantity
    FROM products p
    JOIN categories c ON c.id=p.category_id
    LEFT JOIN sub_categories sc ON sc.id=p.sub_category_id
    WHERE 1=1";
$params = [];
if ($statusFilter === 'active') $productSql .= ' AND p.active = 1';
if ($statusFilter === 'inactive') $productSql .= ' AND p.active = 0';
if ($categoryFilter > 0) { $productSql .= ' AND p.category_id = ?'; $params[] = $categoryFilter; }
if ($subcategoryFilter > 0) { $productSql .= ' AND p.sub_category_id = ?'; $params[] = $subcategoryFilter; }
if ($search !== '') {
    $productSql .= ' AND (p.name LIKE ? OR p.brand LIKE ? OR p.model LIKE ? OR c.name LIKE ? OR sc.name LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}
if ($stockFilter === 'out') $productSql .= ' AND COALESCE((SELECT t.new_quantity FROM inventory_transactions t WHERE t.product_id=p.id ORDER BY t.transaction_at DESC,t.id DESC LIMIT 1),0) <= 0';
if ($stockFilter === 'low') $productSql .= ' AND COALESCE((SELECT t.new_quantity FROM inventory_transactions t WHERE t.product_id=p.id ORDER BY t.transaction_at DESC,t.id DESC LIMIT 1),0) > 0 AND COALESCE((SELECT t.new_quantity FROM inventory_transactions t WHERE t.product_id=p.id ORDER BY t.transaction_at DESC,t.id DESC LIMIT 1),0) <= p.minimum_stock';
if ($stockFilter === 'in') $productSql .= ' AND COALESCE((SELECT t.new_quantity FROM inventory_transactions t WHERE t.product_id=p.id ORDER BY t.transaction_at DESC,t.id DESC LIMIT 1),0) > p.minimum_stock';
$countSql = 'SELECT COUNT(*) FROM (' . $productSql . ') matching_products';
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$matchingCount = (int)$countStmt->fetchColumn();
$productSql .= ' ORDER BY p.name LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
$stmt = $pdo->prepare($productSql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$managedProduct = null;
$managedCategories = $categories;
$managedSubcategories = $subcategories;
$manageId = (int)($_GET['manage'] ?? 0);
if ($manageId > 0) {
    $stmt = $pdo->prepare(
        'SELECT p.*, c.name AS category_name, sc.name AS subcategory_name,
                COALESCE((SELECT t.new_quantity FROM inventory_transactions t WHERE t.product_id=p.id ORDER BY t.transaction_at DESC,t.id DESC LIMIT 1),0) AS current_quantity
         FROM products p JOIN categories c ON c.id=p.category_id
         LEFT JOIN sub_categories sc ON sc.id=p.sub_category_id WHERE p.id=?'
    );
    $stmt->execute([$manageId]);
    $managedProduct = $stmt->fetch() ?: null;
    if ($managedProduct && !array_filter($managedCategories, static fn(array $category): bool => (int)$category['id'] === (int)$managedProduct['category_id'])) {
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = ?');
        $stmt->execute([(int)$managedProduct['category_id']]);
        $inactiveCategory = $stmt->fetch();
        if ($inactiveCategory) array_unshift($managedCategories, $inactiveCategory);
    }
    if ($managedProduct && $managedProduct['sub_category_id'] !== null && !array_filter($managedSubcategories, static fn(array $subcategory): bool => (int)$subcategory['id'] === (int)$managedProduct['sub_category_id'])) {
        $stmt = $pdo->prepare('SELECT id, category_id, name FROM sub_categories WHERE id = ?');
        $stmt->execute([(int)$managedProduct['sub_category_id']]);
        $inactiveSubcategory = $stmt->fetch();
        if ($inactiveSubcategory) array_unshift($managedSubcategories, $inactiveSubcategory);
    }
}

require __DIR__ . '/header.php';
?>
<section class="card">
    <div class="section-heading"><span class="muted"><?=number_format($matchingCount)?> matching product<?=($matchingCount===1?'':'s')?></span><button type="button" class="icon-button" id="inventory-search-toggle" aria-expanded="<?=($_GET?'true':'false')?>" aria-controls="inventory-filters" aria-label="Search and filter inventory" title="Search and filter inventory"><span aria-hidden="true">⌕</span></button></div>
    <form method="get" class="filter-panel" id="inventory-filters" <?=($_GET?'':'hidden')?> >
        <div class="toolbar compact-toolbar">
        <div><label for="inventory-q">Search inventory</label><input id="inventory-q" name="q" type="search" value="<?=htmlspecialchars($search)?>" placeholder="Product, brand, model, category, or sub-category"></div>
        <div><label for="category">Category</label><select id="category" name="category"><option value="0">All categories</option><?php foreach($categories as $category): ?><option value="<?=$category['id']?>" <?= $categoryFilter===(int)$category['id']?'selected':'' ?>><?=htmlspecialchars($category['name'])?></option><?php endforeach; ?></select></div>
        <div><label for="subcategory">Sub-category</label><select id="subcategory" name="subcategory"><option value="0">All sub-categories</option><?php foreach($subcategories as $subcategory): ?><option value="<?=$subcategory['id']?>" data-category="<?=$subcategory['category_id']?>" <?=$subcategoryFilter===(int)$subcategory['id']?'selected':''?>><?=htmlspecialchars($subcategory['name'])?></option><?php endforeach; ?></select></div>
        <div><label for="stock">Stock</label><select id="stock" name="stock"><option value="">All stock</option><option value="in" <?=$stockFilter==='in'?'selected':''?>>In stock</option><option value="low" <?=$stockFilter==='low'?'selected':''?>>Low stock</option><option value="out" <?=$stockFilter==='out'?'selected':''?>>Out of stock</option></select></div>
        <div><label for="status">Product status</label><select id="status" name="status"><option value="active" <?=$statusFilter==='active'?'selected':''?>>Active</option><option value="inactive" <?=$statusFilter==='inactive'?'selected':''?>>Inactive</option><option value="all" <?=$statusFilter==='all'?'selected':''?>>All</option></select></div>
        <input type="hidden" name="page" value="1">
        <button class="btn" type="submit">Apply filters</button><a class="btn" href="products.php">Clear</a>
        </div>
    </form>
    <div class="inventory-actions">
        <?php if($canAddProduct): ?><a class="btn btn-glow" href="products.php?new=1">Add Product</a><?php endif; ?>
    </div>
    <div class="table-scroll"><table class="product-table">
        <thead><tr><th>Product</th><th>Category</th><th>Stock</th><th>Minimum</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($products as $product): $quantity=(int)$product['current_quantity']; $low=$quantity<=(int)$product['minimum_stock']; ?>
            <tr>
                <td><?=htmlspecialchars($product['name'])?><div class="muted"><?=htmlspecialchars(trim(($product['brand']??'').' '.($product['model']??'')))?></div></td>
                <td><?=htmlspecialchars($product['category_name'])?><?= $product['subcategory_name'] ? '<div class="muted">'.htmlspecialchars($product['subcategory_name']).'</div>' : '' ?></td>
                <td><strong><?=$quantity?></strong></td>
                <td><?=$product['minimum_stock']?></td>
                <td><span class="<?= $low?'status-low':'status-ok' ?>"><?= $product['active'] ? ($quantity<=0?'Out of stock':($low?'Low stock':'In stock')) : 'Inactive' ?></span></td>
                <td><a class="btn" href="products.php?manage=<?=$product['id']?>"><?=$canEditProduct?'Manage':'View'?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if(!$products): ?><tr><td colspan="6" class="muted">No products match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if($matchingCount>$perPage): ?><nav class="pagination" aria-label="Inventory pages">
        <?php if($page>1): ?><a class="btn" href="?<?=http_build_query(array_merge($_GET,['page'=>$page-1]))?>">Previous</a><?php endif; ?>
        <span class="muted">Page <?=$page?> of <?=ceil($matchingCount/$perPage)?></span>
        <?php if($page*$perPage<$matchingCount): ?><a class="btn" href="?<?=http_build_query(array_merge($_GET,['page'=>$page+1]))?>">Next</a><?php endif; ?>
    </nav><?php endif; ?>
</section>

<?php if ($managedProduct): ?>
<section class="grid two section-spaced">
    <div class="card">
        <h2><?=$canEditProduct?'Manage product':'Product details'?>: <?=htmlspecialchars($managedProduct['name'])?></h2>
        <p>Current stock: <strong><?=$managedProduct['current_quantity']?></strong></p>
        <?php if($canEditProduct): ?>
        <form method="post" class="product-form" id="edit-product-form">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="product_id" value="<?=$managedProduct['id']?>">
            <div class="form-field"><label>Product name</label><input name="name" required value="<?=htmlspecialchars($managedProduct['name'])?>"></div>
            <div class="form-field"><label>Brand</label><input name="brand" value="<?=htmlspecialchars($managedProduct['brand']??'')?>"></div>
            <div class="form-field"><label>Category</label><select name="category_id" id="edit_category" required><?php foreach($managedCategories as $category): ?><option value="<?=$category['id']?>" <?= (int)$managedProduct['category_id']===(int)$category['id']?'selected':'' ?>><?=htmlspecialchars($category['name'])?></option><?php endforeach; ?></select></div>
            <div class="form-field"><label>Model</label><input name="model" value="<?=htmlspecialchars($managedProduct['model']??'')?>"></div>
            <div class="form-field"><label>Sub-category</label><select name="sub_category_id" id="edit_subcategory"><option value="">None</option><?php foreach($managedSubcategories as $sub): ?><option value="<?=$sub['id']?>" data-category="<?=$sub['category_id']?>" <?= (int)$managedProduct['sub_category_id']===(int)$sub['id']?'selected':'' ?>><?=htmlspecialchars($sub['name'])?></option><?php endforeach; ?></select></div>
            <div class="form-field"><label>Unit (numbers only)</label><input name="unit" inputmode="numeric" pattern="[0-9]*" data-numeric-only value="<?=preg_match('/^[0-9]+$/D',(string)$managedProduct['unit'])?htmlspecialchars($managedProduct['unit']):''?>"></div>
            <div class="form-field"><label>Minimum quantity</label><input name="minimum_stock" type="number" min="0" required value="<?=$managedProduct['minimum_stock']?>"></div>
            <div class="form-field"><label>Description</label><textarea name="description"><?=htmlspecialchars($managedProduct['description']??'')?></textarea></div>
            <label class="check form-wide"><input name="serialized" type="checkbox" <?= $managedProduct['serialized']?'checked':'' ?>> Serialized asset</label>
        </form>
        <div class="inline-actions product-management-actions"><button class="btn btn-glow" type="submit" form="edit-product-form">Save changes</button></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Stock</h2>
        <?php if($canChangeProductStatus): ?><form method="post" class="product-status-form" onsubmit="return confirm('Change this product status?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="product_id" value="<?=$managedProduct['id']?>"><button class="btn <?=$managedProduct['active']?'btn-danger':'btn-success'?>" type="submit"><?=$managedProduct['active']?'Deactivate product':'Reactivate product'?></button></form><?php endif; ?>
        <?php if($canAdjustStock && $managedProduct['active']): ?>
        <form method="post" class="stacked-form">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="stock"><input type="hidden" name="product_id" value="<?=$managedProduct['id']?>">
            <label>Operation</label><select name="operation" data-stock-operation required><option value="in">Add stock</option><?php if(canPerform('orders', 'add')): ?><option value="ordered">Order</option><?php endif; ?><option value="out">Remove stock</option></select>
            <label>Quantity</label><input name="quantity" type="number" min="1" step="1" required>
            <div data-stock-pricing hidden>
                <label>Price per unit (₹)</label><input name="unit_price" type="number" min="0" step="0.01" inputmode="decimal">
                <?php renderVendorPicker($activeVendors, 'product-stock-vendor'); ?>
                <label>Total Price (₹)</label><input type="text" data-stock-total value="₹0.00" readonly aria-readonly="true">
            </div>
            <label>Reason</label><select name="reason" required><option value="">Choose reason</option><option>Requested</option><option>New</option><option>Replacement</option><option>Purchase</option></select>
            <button class="btn btn-glow" type="submit">Save stock change</button>
        </form>
        <script>
        (function(){const form=document.currentScript.previousElementSibling,operation=form.querySelector('[data-stock-operation]'),pricing=form.querySelector('[data-stock-pricing]'),quantity=form.elements.quantity,price=form.elements.unit_price,vendor=form.querySelector('[data-vendor-search]'),total=form.querySelector('[data-stock-total]');
        function update(){const priced=operation.value==='ordered';pricing.hidden=!priced;price.required=priced;vendor.required=priced;}
        function calculate(){const q=Number(quantity.value)||0,p=Number(price.value)||0;total.value=window.formatCurrencyINR(q*p);}
        operation.addEventListener('change',update);quantity.addEventListener('input',calculate);price.addEventListener('input',calculate);update();calculate();})();
        </script>
        <?php elseif(!$managedProduct['active']): ?><p class="muted">This product is inactive.</p>
        <?php else: ?><p class="muted">Contact a manager to change this stock.</p>
        <?php endif; ?>
        <?php if(canViewResource('history')): ?><p><a href="history.php?product=<?=$managedProduct['id']?>">View stock history</a></p><?php endif; ?>
        <?php if(canViewResource('assignments')): ?><p><a href="assignments.php">Employee assignments (advanced)</a></p><?php endif; ?>
    </div>
</section>
<?php elseif ($manageId > 0): ?><div class="alert error">Product not found.</div><?php endif; ?>

<?php if ($canAddProduct && isset($_GET['new'])): ?>
<section class="card section-spaced">
    <h2>Add product</h2>
    <form method="post" class="product-form">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="create">
        <div class="form-field"><label>Product name</label><input name="name" required></div>
        <div class="form-field"><label>Brand</label><input name="brand"></div>
        <div class="form-field"><label>Category</label><select name="category_id" id="new_category" required><option value="">Select category</option><?php foreach($categories as $category): ?><option value="<?=$category['id']?>"><?=htmlspecialchars($category['name'])?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label>Model</label><input name="model"></div>
        <div class="form-field"><label>Sub-category</label><select name="sub_category_id" id="new_subcategory"><option value="">None</option><?php foreach($subcategories as $sub): ?><option value="<?=$sub['id']?>" data-category="<?=$sub['category_id']?>"><?=htmlspecialchars($sub['name'])?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label>Unit (numbers only)</label><input name="unit" inputmode="numeric" pattern="[0-9]*" data-numeric-only><small class="muted">Optional</small></div>
        <div class="form-field"><label>Minimum quantity</label><input name="minimum_stock" type="number" min="0" value="0" required></div>
        <div class="form-field"><label>Description</label><textarea name="description"></textarea></div>
        <label class="check form-wide"><input name="serialized" type="checkbox"> Serialized asset</label>
        <div class="form-actions"><button class="btn btn-glow" type="submit">Create product</button></div>
    </form>
</section>
<?php endif; ?>

<script>
(function(){
 const toggle=document.getElementById('inventory-search-toggle'),panel=document.getElementById('inventory-filters');if(toggle&&panel)toggle.addEventListener('click',function(){const opening=panel.hidden;panel.hidden=!opening;toggle.setAttribute('aria-expanded',String(opening));if(opening)panel.querySelector('input[type="search"]').focus();});
 const category=document.getElementById('category'),subcategory=document.getElementById('subcategory');if(category&&subcategory){function filterSubcategories(){for(const option of subcategory.options){if(!option.value)continue;const valid=category.value==='0'||option.dataset.category===category.value;option.hidden=!valid;option.disabled=!valid;if(!valid&&option.selected)subcategory.value='0';}}category.addEventListener('change',filterSubcategories);filterSubcategories();}
 document.querySelectorAll('[data-numeric-only]').forEach(function(field){
  const validate=function(){field.setCustomValidity(field.value!==''&&!/^[0-9]+$/.test(field.value)?'Unit must contain numbers only.':'');};
  field.addEventListener('input',validate);field.form.addEventListener('submit',validate);
 });
 const editCategory=document.getElementById('edit_category'),editSubcategory=document.getElementById('edit_subcategory');
 if(editCategory&&editSubcategory){function filter(){for(const option of editSubcategory.options){if(!option.value)continue;const valid=option.dataset.category===editCategory.value;option.hidden=!valid;option.disabled=!valid;if(!valid&&option.selected)editSubcategory.value='';}}editCategory.addEventListener('change',filter);filter();}
 const newCategory=document.getElementById('new_category'),newSubcategory=document.getElementById('new_subcategory');
 if(newCategory&&newSubcategory){function filterNew(){for(const option of newSubcategory.options){if(!option.value)continue;const valid=option.dataset.category===newCategory.value;option.hidden=!valid;option.disabled=!valid;if(!valid&&option.selected)newSubcategory.value='';}}newCategory.addEventListener('change',filterNew);filterNew();}
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
