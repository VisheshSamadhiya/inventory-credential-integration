<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/asset_tag_helpers.php';
require_once __DIR__ . '/product_selection.php';
require_once __DIR__ . '/purchase_order_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('products', 'view');

$pageTitle = 'Inventory';
$pdo = db();
$canAddProduct = canPerform('products', 'add');
$canEditProduct = canPerform('products', 'edit');
$canChangeProductStatus = canPerform('products', 'status');
$canAdjustStock = canPerform('inventory', 'stock_adjustment');
$canReceiveOrder = canPerform('orders','order_receive');
$canViewVendors = canViewResource('vendors');
$activeVendors = ($canAdjustStock || canPerform('orders', 'add') || $canReceiveOrder) && $canViewVendors ? $pdo->query('SELECT id,vendor_name,contact_person,phone,email,address,gstin FROM vendors WHERE active=1 ORDER BY vendor_name')->fetchAll() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $stockOperation = (string)($_POST['operation'] ?? '');
    [$resource, $permissionOperation] = match ($action) {
        'toggle' => ['products', 'status'],
        'create' => ['products', 'add'],
        'update' => ['products', 'edit'],
        'stock' => $stockOperation === 'ordered' ? ['orders', 'add'] : ($stockOperation==='receive'&&filter_var($_POST['order_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?['orders','order_receive']:['inventory','stock_adjustment']),
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
            $isReceipt = $operation === 'receive';
            if (!in_array($operation, ['out', 'ordered', 'receive'], true) || $quantity < 1 || $reason === '') {
                throw new DomainException('Choose an operation and reason, and enter a positive quantity.');
            }

            $unitPrice = null;
            $totalPrice = null;
            $vendor = null;
            $vendorRecord = null;
            $status = null;
            $receivedAt = null;
            $referenceNumber = trim((string)($_POST['reference_number'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: null;
            $requestKey = trim((string)($_POST['request_key'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/iD', $requestKey)) throw new DomainException('Refresh the stock form and submit the change again.');
            if ($isReceipt) {
                if (!in_array($reason, ['Requested', 'New', 'Replacement'], true)) throw new DomainException('Choose a receipt reason.');
                $price = calculateTransactionPrice($quantity, trim((string)($_POST['unit_price'] ?? '')));
                $unitPrice = $price['unit_price'];
                $totalPrice = $price['total_price'];
                $dateInput = trim((string)($_POST['received_date'] ?? ''));
                $receivedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
                if (!$receivedDate || $receivedDate->format('Y-m-d') !== $dateInput) throw new DomainException('Enter a valid received date.');
                if ($dateInput > date('Y-m-d')) throw new DomainException('Received date cannot be in the future.');
                $receivedAt = $dateInput === date('Y-m-d') ? date('Y-m-d H:i:s') : $dateInput . ' 12:00:00';
                if (strlen($referenceNumber) > 180 || strlen($notes) > 2000) throw new DomainException('Reference or notes are too long.');
            }
            if ($operation === 'ordered') {
                $priceInput = trim((string)($_POST['unit_price'] ?? ''));
                $price = calculateTransactionPrice($quantity, $priceInput);
                $unitPrice = $price['unit_price'];
                $totalPrice = $price['total_price'];
                $status = $operation === 'ordered' ? 'Pending' : 'Received';
            }

            $pdo->beginTransaction();
            if ($operation === 'ordered' || $isReceipt) { $vendorRecord = activeVendorForOrder($pdo, $_POST['vendor_id'] ?? null); $vendor = $vendorRecord['vendor_name']; }
            $lock = $pdo->prepare('SELECT id FROM products WHERE id = ? AND active = 1 FOR UPDATE');
            $lock->execute([$productId]);
            if (!$lock->fetchColumn()) {
                throw new DomainException('Product is unavailable.');
            }
            $duplicate = $pdo->prepare('SELECT id FROM inventory_transactions WHERE request_key=? LIMIT 1 FOR UPDATE');
            $duplicate->execute([$requestKey]);
            if ($duplicate->fetchColumn()) {
                $pdo->commit();
                setFlash('success', $isReceipt ? 'This stock receipt was already saved.' : 'This stock change was already saved.');
                    header('Location: ' . $redirect . '&operation=' . rawurlencode($operation));
                exit;
            }
            if ($isReceipt && $orderId !== null) {
                $orderQuery = $pdo->prepare("SELECT id,product_id,quantity,status,vendor_id,unit_price,COALESCE(vendor_name_snapshot,vendor) vendor_name FROM inventory_transactions WHERE id=? AND transaction_type='ordered' FOR UPDATE");
                $orderQuery->execute([$orderId]);
                $order = $orderQuery->fetch();
                $receivedQuery = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_transactions WHERE related_order_id=? AND transaction_type='in'");
                $receivedQuery->execute([$orderId]);
                $receiptState = validatePurchaseOrderReceipt($order ?: null, $productId, $quantity, $unitPrice, $vendorRecord, (int)$receivedQuery->fetchColumn());
                $remainingOrderQty = $receiptState['remaining_quantity'];
            }
            $previous = currentStock($productId);
            $movementType = $isReceipt ? 'in' : $operation;
            $movement = calculateInventoryChange($previous, $movementType, $quantity);
            $delta = $movement['delta'];
            $current = $movement['new_stock'];
            assertAssetTagStockChangeAllowed($pdo, $productId, $delta, $current);

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_transactions
                    (product_id, transaction_type, quantity, previous_quantity, new_quantity, reason, unit_price, total_price, order_reason, vendor, vendor_id, vendor_name_snapshot, vendor_contact_snapshot, vendor_phone_snapshot, vendor_email_snapshot, vendor_address_snapshot, vendor_gstin_snapshot, status, related_order_id, notes, reference_number, created_by, transaction_at, request_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()), ?)'
            );
            $transactionType = $isReceipt ? 'in' : $operation;
            $savedStatus = $isReceipt ? 'Received' : $status;
            $stmt->execute([$productId, $transactionType, $delta, $previous, $current, $reason, $unitPrice, $totalPrice, $isReceipt ? $reason : null, $vendor, $vendorRecord['id']??null, $vendorRecord['vendor_name']??null, $vendorRecord['contact_person']??null, $vendorRecord['phone']??null, $vendorRecord['email']??null, $vendorRecord['address']??null, $vendorRecord['gstin']??null, $savedStatus, $isReceipt ? $orderId : null, $notes !== '' ? $notes : null, $isReceipt && $referenceNumber !== '' ? $referenceNumber : null, currentUser()['id'], $receivedAt, $requestKey]);
            // Allocation inserts have their own IDs; retain the movement ID.
            $transactionId = (int)$pdo->lastInsertId();
            if ($isReceipt) {
                createInventoryCostLayer($pdo, $productId, $transactionId, $quantity, $unitPrice);
                // Approval remains Confirmed; receipt completion is derived
                // independently from the linked receipt quantities.
            } elseif ($operation === 'out') {
                $cost = allocateInventoryCost($pdo, $productId, $quantity, 'stock_removal', null, $transactionId);
                if ($cost !== null) $pdo->prepare('UPDATE inventory_transactions SET unit_price=?,total_price=? WHERE id=?')->execute([$cost['unit_price'],$cost['total_price'],$transactionId]);
            }
            audit(
                'stock_update',
                'product',
                $productId,
                "Stock changed: {$previous} → {$current}; Reason: {$reason}"
            );
            recalculateInventoryMonths($productId);
            $pdo->commit();
            setFlash('success', $isReceipt ? 'Stock receipt saved successfully.' : 'Stock updated successfully.');
            header('Location: ' . $redirect . '&operation=' . rawurlencode($operation));
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
        if (($action ?? '') === 'stock' && isset($requestKey) && preg_match('/^[a-f0-9]{64}$/iD', $requestKey)) {
            $duplicate = $pdo->prepare('SELECT id FROM inventory_transactions WHERE request_key=? LIMIT 1');
            $duplicate->execute([$requestKey]);
            if ($duplicate->fetchColumn()) {
                setFlash('success', ($isReceipt ?? false) ? 'This stock receipt was already saved.' : 'This stock change was already saved.');
                header('Location: ' . $redirect . '&operation=' . rawurlencode((string)($_POST['operation'] ?? 'receive')));
                exit;
            }
        }
        error_log('Inventory product operation failed: ' . $exception->getMessage());
        setFlash('error', (int)($exception->errorInfo[1] ?? 0) === 1062
            ? 'A product with those details already exists.'
            : 'Unable to save the product. Please check the entered information.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Inventory operation failed: ' . $exception->getMessage());
        setFlash('error', 'Unable to update stock. Please try again.');
    }
    if ($action === 'stock') {
        $failedOperation = (string)($_POST['operation'] ?? '');
        $failedOrderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($failedOperation === 'receive' && $failedOrderId) {
            $redirect = 'products.php?receive_order=' . (int)$failedOrderId;
        } elseif (in_array($failedOperation, ['receive', 'ordered', 'out'], true)) {
            $redirect .= '&operation=' . rawurlencode($failedOperation);
        }
    }
    header('Location: ' . $redirect);
    exit;
}

$receiptOrder = null;
$receiptOrderError = null;
$receiptOrderRequested = array_key_exists('receive_order', $_GET);
$receiptOrderId = $receiptOrderRequested
    ? filter_var($_GET['receive_order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($receiptOrderRequested) {
    requirePermission('orders', 'order_receive');
    if (!$receiptOrderId) {
        $receiptOrderError = 'This purchase order is unavailable for receipt.';
    } else {
        $receiptQuery = $pdo->prepare("SELECT o.id,o.product_id,o.quantity,o.unit_price,o.order_reason,o.reference_number,o.notes,o.status,o.vendor_id,COALESCE(o.vendor_name_snapshot,o.vendor,v.vendor_name) vendor_name,p.category_id,p.sub_category_id,p.active product_active,COALESCE(receipts.received_quantity,0) received_quantity FROM inventory_transactions o JOIN products p ON p.id=o.product_id LEFT JOIN vendors v ON v.id=o.vendor_id LEFT JOIN (SELECT related_order_id,SUM(quantity) received_quantity FROM inventory_transactions WHERE transaction_type='in' AND related_order_id IS NOT NULL GROUP BY related_order_id) receipts ON receipts.related_order_id=o.id WHERE o.id=? AND o.transaction_type='ordered'");
        $receiptQuery->execute([(int)$receiptOrderId]);
        $candidateReceiptOrder = $receiptQuery->fetch() ?: null;
        try {
            $receiptState = purchaseOrderReceiptState($candidateReceiptOrder, (int)($candidateReceiptOrder['received_quantity'] ?? 0));
            if (!(int)$candidateReceiptOrder['product_active']) throw new DomainException('This purchase order product is inactive and cannot be received.');
            $receiptOrder = $candidateReceiptOrder + $receiptState;
        } catch (DomainException) {
            $receiptOrderError = 'This purchase order is unavailable for receipt. It may be unconfirmed or already complete.';
        }
    }
}
$requestedStockOperation = $receiptOrder ? 'receive' : (string)($_GET['operation'] ?? 'receive');
if (!in_array($requestedStockOperation, ['receive', 'ordered', 'out'], true)) $requestedStockOperation = 'receive';
if ($requestedStockOperation === 'receive'
    && ($_SESSION['flash']['message'] ?? '') === 'This stock reduction would remove units reserved by existing or queued asset tags. Retire or reconcile those assets first.') {
    unset($_SESSION['flash']);
}

$categories = $pdo->query('SELECT id, name FROM categories WHERE active = 1 ORDER BY name')->fetchAll();
$inventoryCategories = $pdo->query('SELECT id, name, active FROM categories ORDER BY name')->fetchAll();
$subcategories = $pdo->query('SELECT id, category_id, name FROM sub_categories WHERE active = 1 ORDER BY name')->fetchAll();
$inventorySubcategories = $pdo->query('SELECT id, category_id, name, active FROM sub_categories ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT p.id,p.name,p.active,p.category_id,p.sub_category_id,c.name AS category_name,sc.name AS subcategory_name FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN sub_categories sc ON sc.id=p.sub_category_id ORDER BY p.name')->fetchAll();

$managedProduct = null;
$managedCategories = $categories;
$managedSubcategories = $subcategories;
$requestedProductId = $receiptOrder
    ? (int)$receiptOrder['product_id']
    : max(0, (int)($_GET['product_id'] ?? $_GET['manage'] ?? 0));
$requestedProduct = null;
if ($requestedProductId > 0) {
    $stmt = $pdo->prepare(
        'SELECT p.*, c.name AS category_name, sc.name AS subcategory_name,
                COALESCE((SELECT SUM(CASE WHEN t.transaction_type IN (\'in\',\'opening\') THEN ABS(t.quantity) WHEN t.transaction_type=\'out\' THEN -ABS(t.quantity) WHEN t.transaction_type=\'adjustment\' THEN t.quantity ELSE 0 END) FROM inventory_transactions t WHERE t.product_id=p.id),0) AS current_quantity
         FROM products p JOIN categories c ON c.id=p.category_id
         LEFT JOIN sub_categories sc ON sc.id=p.sub_category_id WHERE p.id=?'
    );
    $stmt->execute([$requestedProductId]);
    $requestedProduct = $stmt->fetch() ?: null;
    if ($requestedProduct && !array_filter($managedCategories, static fn(array $category): bool => (int)$category['id'] === (int)$requestedProduct['category_id'])) {
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = ?');
        $stmt->execute([(int)$requestedProduct['category_id']]);
        $inactiveCategory = $stmt->fetch();
        if ($inactiveCategory) array_unshift($managedCategories, $inactiveCategory);
    }
    if ($requestedProduct && $requestedProduct['sub_category_id'] !== null && !array_filter($managedSubcategories, static fn(array $subcategory): bool => (int)$subcategory['id'] === (int)$requestedProduct['sub_category_id'])) {
        $stmt = $pdo->prepare('SELECT id, category_id, name FROM sub_categories WHERE id = ?');
        $stmt->execute([(int)$requestedProduct['sub_category_id']]);
        $inactiveSubcategory = $stmt->fetch();
        if ($inactiveSubcategory) array_unshift($managedSubcategories, $inactiveSubcategory);
    }
}
$selectedCategoryId = $receiptOrder
    ? (int)$receiptOrder['category_id']
    : (array_key_exists('category_id', $_GET)
    ? max(0, (int)$_GET['category_id'])
    : (int)($requestedProduct['category_id'] ?? 0));
$selectedSubcategoryId = $receiptOrder
    ? (int)($receiptOrder['sub_category_id'] ?? 0)
    : (array_key_exists('sub_category_id', $_GET)
    ? max(0, (int)$_GET['sub_category_id'])
    : (int)($requestedProduct['sub_category_id'] ?? 0));
$categoryIds = array_map(static fn(array $category): int => (int)$category['id'], $inventoryCategories);
if (!in_array($selectedCategoryId, $categoryIds, true)) $selectedCategoryId = 0;
$selectedSubcategory = null;
if ($selectedSubcategoryId > 0) {
    foreach ($inventorySubcategories as $subcategory) {
        if ((int)$subcategory['id'] === $selectedSubcategoryId && (int)$subcategory['category_id'] === $selectedCategoryId) {
            $selectedSubcategory = $subcategory;
            break;
        }
    }
    if ($selectedSubcategory === null) $selectedSubcategoryId = 0;
}
$matchingProducts = filterInventoryProductsForSelection($products, $selectedCategoryId, $selectedSubcategoryId);
$managedProduct = $requestedProduct && inventoryProductSelectionIsValid($products, (int)$requestedProduct['id'], $selectedCategoryId, $selectedSubcategoryId)
    ? $requestedProduct : null;
$manageId = (int)($managedProduct['id'] ?? 0);
$receiptToken = bin2hex(random_bytes(32));
$confirmedOrders = [];
if ($manageId > 0 && $canReceiveOrder) {
    $orderList = $pdo->prepare("SELECT o.id,o.quantity,o.unit_price,o.order_reason,o.reference_number,o.notes,o.status,o.vendor_id,COALESCE(o.vendor_name_snapshot,o.vendor,v.vendor_name) vendor_name,COALESCE(SUM(r.quantity),0) received_quantity FROM inventory_transactions o LEFT JOIN vendors v ON v.id=o.vendor_id LEFT JOIN inventory_transactions r ON r.related_order_id=o.id AND r.transaction_type='in' WHERE o.product_id=? AND o.transaction_type='ordered' AND o.status='Confirmed' GROUP BY o.id HAVING o.quantity>received_quantity ORDER BY o.transaction_at DESC");
    $orderList->execute([$manageId]);
    $confirmedOrders = $orderList->fetchAll();
}
$activeVendorIdForOrder = static function (array $order) use ($activeVendors): ?int {
    return purchaseOrderVendorSelection($order, $activeVendors)['vendor_id'];
};
$vendorWarningForOrder = static function (array $order) use ($activeVendors, $canViewVendors): string {
    if (!purchaseOrderVendorSelection($order, $activeVendors)['unavailable']) return '';
    return $canViewVendors
        ? 'The vendor linked to this order is inactive or unavailable. Reactivate or restore that vendor before receiving.'
        : 'Vendor selection is unavailable with your current access. Ask an administrator to resolve this order’s vendor before receiving.';
};
$selectedOrderVendorId = null;
$applicationLocalDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
if ($receiptOrder) $selectedOrderVendorId = purchaseOrderVendorSelection($receiptOrder, $activeVendors)['vendor_id'];
$receiptVendorWarning = $receiptOrder ? $vendorWarningForOrder($receiptOrder) : '';

require __DIR__ . '/header.php';
?>
<section class="inventory-screen inventory-products-screen grid two">
    <?php if ($receiptOrderError): ?><div class="alert error form-wide" role="alert"><?=htmlspecialchars($receiptOrderError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></div><?php endif; ?>
    <div class="card">
        <div class="section-heading"><h2>Manage Product</h2><?php if($canAddProduct): ?><a class="btn btn-glow" href="products.php?new=1">Add Product</a><?php endif; ?></div>
        <form method="get" class="product-picker" id="inventory-product-picker">
            <div><label for="inventory-category">Category</label><select id="inventory-category" name="category_id" required><option value="">Choose a category</option><?php foreach($inventoryCategories as $category): ?><option value="<?=$category['id']?>" <?= $selectedCategoryId===(int)$category['id']?'selected':'' ?>><?=htmlspecialchars($category['name'].(!$category['active']?' (Inactive)':''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></option><?php endforeach; ?></select></div>
            <div><label for="inventory-subcategory">Sub-category (optional)</label><select id="inventory-subcategory" name="sub_category_id" <?=$selectedCategoryId<1?'disabled':''?>><option value="">All sub-categories</option><?php foreach($inventorySubcategories as $subcategory): ?><option value="<?=$subcategory['id']?>" data-category="<?=$subcategory['category_id']?>" <?= $selectedSubcategoryId===(int)$subcategory['id']?'selected':'' ?>><?=htmlspecialchars($subcategory['name'].(!$subcategory['active']?' (Inactive)':''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></option><?php endforeach; ?></select></div>
            <div><label for="inventory-product">Product (optional)</label><select id="inventory-product" name="product_id" <?=$selectedCategoryId<1?'disabled':''?>><option value="">All matching products</option><?php foreach($products as $product): ?><option value="<?=$product['id']?>" data-category="<?=$product['category_id']?>" data-subcategory="<?=$product['sub_category_id']??''?>" <?= $manageId===(int)$product['id']?'selected':'' ?>><?=htmlspecialchars($product['name'].(!$product['active']?' (Inactive)':''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></option><?php endforeach; ?></select></div>
            <button class="btn" type="submit">View Inventory</button>
        </form>
        <?php if ($selectedCategoryId > 0 && !$managedProduct): ?>
            <?php if ($matchingProducts): ?><ul class="product-match-list" aria-label="Matching inventory products">
                <?php foreach ($matchingProducts as $product): ?><li><div><strong><?=htmlspecialchars($product['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong><span class="muted"><?=htmlspecialchars($product['category_name'].($product['subcategory_name'] ? ' · '.$product['subcategory_name'] : '').(!$product['active']?' · Inactive':''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></span></div><a class="btn" href="products.php?<?=http_build_query(['category_id'=>$selectedCategoryId,'sub_category_id'=>$selectedSubcategoryId ?: '','product_id'=>(int)$product['id']])?>"><?=$canEditProduct?'Manage':'View'?></a></li><?php endforeach; ?>
            </ul><?php else: ?><p class="muted">No products match this category selection.</p><?php endif; ?>
        <?php endif; ?>
        <?php if ($managedProduct): ?>
        <h3><?=htmlspecialchars($managedProduct['name'])?></h3>
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
        <?php else: ?><p class="muted">Select a product to view or edit its details.</p><?php endif; ?>
    </div>

    <div class="card">
        <div class="section-heading product-panel-heading"><h2>Stock management</h2>
        <?php if($canChangeProductStatus && $managedProduct): ?><form method="post" class="product-status-form" onsubmit="return confirm('Change this product status?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="product_id" value="<?=$managedProduct['id']?>"><button class="btn <?=$managedProduct['active']?'btn-danger':'btn-success'?>" type="submit"><?=$managedProduct['active']?'Deactivate product':'Reactivate product'?></button></form><?php endif; ?></div>
        <?php if ($managedProduct): ?>
        <?php if(($canAdjustStock || $canReceiveOrder) && $managedProduct['active']): ?>
        <form method="post" class="stacked-form" id="product-stock-form" data-open-receipt="<?=$receiptOrder?'1':'0'?>" data-receipt-requires-order="<?=$canAdjustStock?'0':'1'?>">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="stock"><input type="hidden" name="product_id" value="<?=$managedProduct['id']?>">
            <label>Operation</label><select name="operation" data-stock-operation required><?php if($canAdjustStock||$canReceiveOrder): ?><option value="receive" <?=$requestedStockOperation==='receive'?'selected':''?>>Receive Stock</option><?php endif; ?><?php if(!$receiptOrder && canPerform('orders', 'add')): ?><option value="ordered" <?=$requestedStockOperation==='ordered'?'selected':''?>>Order Stock</option><?php endif; ?><?php if(!$receiptOrder && $canAdjustStock): ?><option value="out" <?=$requestedStockOperation==='out'?'selected':''?>>Remove Stock</option><?php endif; ?></select>
            <label>Quantity</label><input name="quantity" type="number" min="1" step="1" required <?=$receiptOrder?'value="' . (int)$receiptOrder['remaining_quantity'] . '" max="' . (int)$receiptOrder['remaining_quantity'] . '"':''?>>
            <input type="hidden" name="request_key" value="<?=htmlspecialchars($receiptToken, ENT_QUOTES)?>">
            <div data-stock-pricing>
                <label>Unit purchase price (INR)</label><input name="unit_price" type="number" min="0" step="0.01" inputmode="decimal" value="<?=$receiptOrder && $receiptOrder['unit_price'] !== null ? htmlspecialchars((string)$receiptOrder['unit_price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''?>" <?=$receiptOrder?'readonly aria-readonly="true"':''?>>
                <?php renderVendorPicker($activeVendors, 'product-stock-vendor', $selectedOrderVendorId); ?>
                <p class="alert info form-wide" role="alert" tabindex="-1" data-order-vendor-warning<?=$receiptVendorWarning === '' ? ' hidden' : ''?>><?=htmlspecialchars($receiptVendorWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></p>
                <label>Total Price (INR)</label><input type="text" data-stock-total value="₹0.00" readonly aria-readonly="true">
                <label>Purchase order (optional)</label><select name="order_id" data-order-select><?php if($canAdjustStock): ?><option value="">Direct receipt without order</option><?php endif; ?><?php foreach($confirmedOrders as $order): ?><option value="<?=$order['id']?>" data-remaining="<?=max(0,(int)$order['quantity']-(int)$order['received_quantity'])?>" data-unit-price="<?=htmlspecialchars((string)($order['unit_price'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" data-vendor-id="<?=$activeVendorIdForOrder($order) ?? 0?>" data-vendor-warning="<?=htmlspecialchars($vendorWarningForOrder($order), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" data-reason="<?=htmlspecialchars((string)($order['order_reason'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" data-reference="<?=htmlspecialchars((string)($order['reference_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" data-notes="<?=htmlspecialchars((string)($order['notes'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" <?=((int)($receiptOrder['id'] ?? 0)===(int)$order['id'])?'selected':''?>>Order #<?=$order['id']?> · <?=((int)$order['quantity']-(int)$order['received_quantity'])?> remaining</option><?php endforeach; ?></select>
                <label>Received date</label><input name="received_date" type="date" value="<?=$receiptOrder ? $applicationLocalDate : date('Y-m-d')?>">
                <label>Invoice/reference number (optional)</label><input name="reference_number" maxlength="180" value="<?=$receiptOrder ? htmlspecialchars((string)($receiptOrder['reference_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''?>">
                <label>Notes (optional)</label><textarea name="notes" maxlength="2000"><?=$receiptOrder ? htmlspecialchars((string)($receiptOrder['notes'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''?></textarea>
            </div>
            <label>Reason</label><select name="reason" data-stock-reason required><option value="">Choose reason</option><option data-modes="receive,ordered" <?=$receiptOrder && $receiptOrder['order_reason']==='Requested'?'selected':''?>>Requested</option><option data-modes="receive,ordered" <?=$receiptOrder && $receiptOrder['order_reason']==='New'?'selected':''?>>New</option><option data-modes="receive,ordered" <?=$receiptOrder && $receiptOrder['order_reason']==='Replacement'?'selected':''?>>Replacement</option><option data-modes="out">Damaged</option><option data-modes="out">Disposed</option><option data-modes="out">Other removal</option><option data-modes="ordered">Purchase</option></select>
            <button class="btn btn-glow" type="submit">Save stock change</button>
        </form>
        <script>
        (function(){const form=document.currentScript.previousElementSibling,operation=form.querySelector('[data-stock-operation]'),pricing=form.querySelector('[data-stock-pricing]'),quantity=form.elements.quantity,price=form.elements.unit_price,vendor=form.querySelector('[data-vendor-search]'),total=form.querySelector('[data-stock-total]'),vendorWarning=form.querySelector('[data-order-vendor-warning]');
        function update(){const receiving=operation.value==='receive',ordered=operation.value==='ordered';pricing.hidden=!receiving&&!ordered;price.required=receiving||ordered;if(vendor)vendor.required=receiving||ordered;form.elements.received_date.required=receiving;form.elements.order_id.disabled=!receiving;form.elements.order_id.required=receiving&&form.dataset.receiptRequiresOrder==='1';form.elements.reference_number.disabled=!receiving;form.elements.notes.disabled=!receiving;form.elements.reason.required=true;Array.from(form.querySelector('[data-stock-reason]').options).forEach(function(option){if(!option.value)return;const modes=(option.dataset.modes||'').split(',');option.hidden=!modes.includes(operation.value);});if(form.elements.reason.selectedOptions[0]?.hidden)form.elements.reason.value='';}
        const orderSelect=form.querySelector('[data-order-select]'),vendorPicker=form.querySelector('[data-vendor-picker]');
        function calculate(){const q=Number(quantity.value)||0,p=Number(price.value)||0;total.value=window.formatCurrencyINR(q*p);}
        function applyOrder(){if(!orderSelect)return;const selected=orderSelect.selectedOptions[0],hasOrder=Boolean(selected&&selected.value);if(hasOrder){const remaining=Number(selected.dataset.remaining)||0;quantity.max=String(remaining);quantity.value=String(remaining);price.value=selected.dataset.unitPrice||'';price.readOnly=price.value!=='';if(price.readOnly)price.setAttribute('aria-readonly','true');else price.removeAttribute('aria-readonly');form.elements.reason.value=selected.dataset.reason||'';form.elements.reference_number.value=selected.dataset.reference||'';form.elements.notes.value=selected.dataset.notes||'';if(vendorWarning){vendorWarning.textContent=selected.dataset.vendorWarning||'';vendorWarning.hidden=!vendorWarning.textContent;form.dataset.orderVendorUnavailable=vendorWarning.hidden?'0':'1';}if(vendorPicker)vendorPicker.dispatchEvent(new CustomEvent('vendor-picker:select',{detail:{vendorId:Number(selected.dataset.vendorId)||0}}));}else{quantity.removeAttribute('max');quantity.value='';price.value='';price.readOnly=false;price.removeAttribute('aria-readonly');form.elements.reason.value='';form.elements.reference_number.value='';form.elements.notes.value='';if(vendorWarning){vendorWarning.textContent='';vendorWarning.hidden=true;form.dataset.orderVendorUnavailable='0';}if(vendorPicker)vendorPicker.dispatchEvent(new CustomEvent('vendor-picker:select',{detail:{vendorId:0}}));}calculate();}
        operation.addEventListener('change',update);quantity.addEventListener('input',calculate);price.addEventListener('input',calculate);orderSelect?.addEventListener('change',applyOrder);form.addEventListener('submit',function(event){if(form.dataset.orderVendorUnavailable==='1'){event.preventDefault();vendorWarning?.focus();}});update();if(orderSelect?.value)applyOrder();else calculate();})();
        </script>
        <?php elseif(!$managedProduct['active']): ?><p class="muted">This product is inactive.</p>
        <?php else: ?><p class="muted">Contact a manager to change this stock.</p>
        <?php endif; ?>
        <?php if(canViewResource('history')): ?><p><a href="history.php?product=<?=$managedProduct['id']?>">View stock history</a></p><?php endif; ?>
        <?php if(canViewResource('assignments')): ?><p><a href="assignments.php">Employee assignments (advanced)</a></p><?php endif; ?>
        <?php else: ?><p class="muted">Select a product to manage stock.</p><?php endif; ?>
    </div>
</section>
<?php if ($requestedProductId > 0 && !$managedProduct): ?><div class="alert error">That product does not match the selected category and sub-category.</div><?php endif; ?>

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
 const inventoryCategory=document.getElementById('inventory-category'),inventorySubcategory=document.getElementById('inventory-subcategory'),inventoryProduct=document.getElementById('inventory-product');
 if(inventoryCategory&&inventorySubcategory&&inventoryProduct){
  function filterInventoryOptions(){
   const categoryId=inventoryCategory.value;
   inventorySubcategory.disabled=!categoryId;inventoryProduct.disabled=!categoryId;
   for(const option of inventorySubcategory.options){if(!option.value)continue;const matches=option.dataset.category===categoryId;option.hidden=!matches;option.disabled=!matches;if(!matches&&option.selected)inventorySubcategory.value='';}
   const chosenSubcategory=inventorySubcategory.value;
   for(const option of inventoryProduct.options){if(!option.value)continue;const matches=option.dataset.category===categoryId&&(!chosenSubcategory||option.dataset.subcategory===chosenSubcategory);option.hidden=!matches;option.disabled=!matches;if(!matches&&option.selected)inventoryProduct.value='';}
  }
  inventoryCategory.addEventListener('change',function(){inventorySubcategory.value='';inventoryProduct.value='';filterInventoryOptions();});
  inventorySubcategory.addEventListener('change',function(){inventoryProduct.value='';filterInventoryOptions();});
  filterInventoryOptions();
 }
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
<script>
(function(){
 const form=document.querySelector('#product-stock-form[data-open-receipt="1"]');
 if(!form)return;
 const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
 window.requestAnimationFrame(function(){
  form.scrollIntoView({behavior:reducedMotion?'auto':'smooth',block:'start'});
  form.querySelector('[data-stock-operation]')?.focus({preventScroll:true});
 });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
