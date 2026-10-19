<?php
declare(strict_types=1);

$applicationRoot = is_file(__DIR__ . '/../app/config.php') ? dirname(__DIR__) . '/app' : getcwd();
require_once $applicationRoot . '/config.php';
require_once $applicationRoot . '/inventory_helpers.php';
require_once $applicationRoot . '/inventory_valuation.php';
require_once $applicationRoot . '/history_helpers.php';

if (getenv('INVENTORY_VALUATION_DB_TESTS') !== '1') {
    fwrite(STDOUT, "SKIP: inventory valuation MariaDB tests require INVENTORY_VALUATION_DB_TESTS=1 and a disposable *_asset_tag_test database.\n");
    exit(0);
}

$pdo = db();
$databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($databaseName, '_asset_tag_test')) {
    throw new RuntimeException('Refusing to run valuation integration tests outside a disposable *_asset_tag_test database.');
}
$userId = (int)$pdo->query('SELECT id FROM users WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();
$categoryId = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
if (!$userId || !$categoryId) throw new RuntimeException('The disposable integration database needs an active user and a category.');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Inventory valuation DB test failed: ' . $message);
};
$money = static fn(array $snapshot, string $field): int => $field === 'physical'
    ? (int)($snapshot['available_value_cents'] ?? 0) + (int)($snapshot['assigned_value_cents'] ?? 0)
    : (int)($snapshot[$field . '_value_cents'] ?? -1);
$insertProduct = $pdo->prepare('INSERT INTO products(category_id,name,unit,serialized,active) VALUES(?,?,?,?,1)');
$insertTransaction = $pdo->prepare('INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,unit_price,total_price,notes,created_by,transaction_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
$insertAssignment = $pdo->prepare("INSERT INTO assignments(product_id,asset_id,quantity,assigned_to,assigned_by,status,assigned_at) VALUES(?,?,1,?,?,'assigned',?)");

$pdo->beginTransaction();
try {
    $stamp = date('Y-m-d H:i:s');

    // Ten received units at ₹9,000, then one bulk assignment and a full return.
    $insertProduct->execute([$categoryId, 'Valuation DB bulk ' . bin2hex(random_bytes(5)), 'pcs', 0]);
    $bulkProduct = (int)$pdo->lastInsertId();
    $insertTransaction->execute([$bulkProduct,'in',10,0,10,'Received','9000.00','90000.00',null,$userId,$stamp]);
    $receiptId = (int)$pdo->lastInsertId();
    createInventoryCostLayer($pdo, $bulkProduct, $receiptId, 10, '9000.00');
    $insertAssignment->execute([$bulkProduct,null,'Valuation test employee',$userId,$stamp]);
    $assignmentId = (int)$pdo->lastInsertId();
    $insertTransaction->execute([$bulkProduct,'out',-1,10,9,'Assignment → employee',null,null,'Assignment #' . $assignmentId,$userId,$stamp]);
    $assignmentTransactionId = (int)$pdo->lastInsertId();
    $assignmentCost = allocateInventoryCost($pdo, $bulkProduct, 1, 'assignment', $assignmentId, $assignmentTransactionId);
    $assert(($assignmentCost['total_price'] ?? null) === '9000.00', 'one assigned unit consumes the receipt layer at its recorded ₹9,000 cost.');
    $afterAssignment = inventoryValuationSnapshotByProduct($pdo, [$bulkProduct])[$bulkProduct];
    $assert($money($afterAssignment,'available') === 8100000 && $money($afterAssignment,'assigned') === 900000 && $money($afterAssignment,'physical') === 9000000, 'assignment transfers ₹9,000 between buckets and preserves the ₹90,000 physical total.');
    $insertTransaction->execute([$bulkProduct,'in',1,9,10,'Assignment return',null,null,'Return from assignment #' . $assignmentId,$userId,$stamp]);
    $returnId = (int)$pdo->lastInsertId();
    $returnCost = restoreAssignmentCost($pdo, $assignmentId, 1);
    $pdo->prepare("UPDATE assignments SET returned_quantity=1,status='returned' WHERE id=?")->execute([$assignmentId]);
    $afterReturn = inventoryValuationSnapshotByProduct($pdo, [$bulkProduct])[$bulkProduct];
    $assert(($returnCost['total_price'] ?? null) === '9000.00' && $money($afterReturn,'available') === 9000000 && $money($afterReturn,'assigned') === 0 && $money($afterReturn,'physical') === 9000000, 'return restores the same original cost and does not change total physical value.');

    // Serialized assets take distinct FIFO receipt costs into their asset records.
    $insertProduct->execute([$categoryId, 'Valuation DB serialized ' . bin2hex(random_bytes(5)), 'pcs', 1]);
    $assetProduct = (int)$pdo->lastInsertId();
    $insertTransaction->execute([$assetProduct,'in',1,0,1,'Received','9000.00','9000.00',null,$userId,$stamp]);
    $assetReceiptOne = (int)$pdo->lastInsertId();
    createInventoryCostLayer($pdo, $assetProduct, $assetReceiptOne, 1, '9000.00');
    $assetOneCostReference = takeInventoryAssetCostReference($pdo, $assetProduct);
    $assetOneCost = $assetOneCostReference['unit_cost'] ?? null;
    $pdo->prepare('INSERT INTO inventory_assets(product_id,asset_tag,acquisition_unit_cost,acquisition_cost_layer_id,status) VALUES(?,?,?, ?,\'available\')')
        ->execute([$assetProduct,'VAL-' . bin2hex(random_bytes(5)) . '-A',$assetOneCost,$assetOneCostReference['cost_layer_id'] ?? null]);
    $insertTransaction->execute([$assetProduct,'in',1,1,2,'Received','10000.00','10000.00',null,$userId,$stamp]);
    $assetReceiptTwo = (int)$pdo->lastInsertId();
    createInventoryCostLayer($pdo, $assetProduct, $assetReceiptTwo, 1, '10000.00');
    $assetTwoCostReference = takeInventoryAssetCostReference($pdo, $assetProduct);
    $assetTwoCost = $assetTwoCostReference['unit_cost'] ?? null;
    $assetTwoTag = 'VAL-' . bin2hex(random_bytes(5)) . '-B';
    $pdo->prepare('INSERT INTO inventory_assets(product_id,asset_tag,acquisition_unit_cost,acquisition_cost_layer_id,status) VALUES(?,?,?, ?,\'assigned\')')
        ->execute([$assetProduct,$assetTwoTag,$assetTwoCost,$assetTwoCostReference['cost_layer_id'] ?? null]);
    $insertTransaction->execute([$assetProduct,'out',-1,2,1,'Assignment → employee',null,'10000.00','Hardware assignment; asset ' . $assetTwoTag,$userId,$stamp]);
    $serialized = inventoryValuationSnapshotByProduct($pdo, [$assetProduct])[$assetProduct];
    $assetLayerLinks=$pdo->prepare('SELECT COUNT(*) FROM inventory_assets ia JOIN inventory_cost_layers l ON l.id=ia.acquisition_cost_layer_id WHERE ia.product_id=? AND l.source_transaction_id IN (?,?)');
    $assetLayerLinks->execute([$assetProduct,$assetReceiptOne,$assetReceiptTwo]);
    $assert($money($serialized,'available') === 900000 && $money($serialized,'assigned') === 1000000 && $money($serialized,'physical') === 1900000, 'serialized available and assigned assets preserve their individual ₹9,000 and ₹10,000 costs.');
    $assert((int)$assetLayerLinks->fetchColumn()===2,'Each serialized unit keeps a direct link to its distinct receipt cost layer.');

    // A historical assignment without allocation evidence stays unavailable.
    $insertProduct->execute([$categoryId, 'Valuation DB unknown ' . bin2hex(random_bytes(5)), 'pcs', 0]);
    $unknownProduct = (int)$pdo->lastInsertId();
    $insertTransaction->execute([$unknownProduct,'opening',2,0,2,'Uncosted opening fixture',null,null,null,$userId,$stamp]);
    $insertAssignment->execute([$unknownProduct,null,'Unknown cost test employee',$userId,$stamp]);
    $unknownAssignment = (int)$pdo->lastInsertId();
    $insertTransaction->execute([$unknownProduct,'out',-1,2,1,'Assignment → employee',null,null,'Assignment #' . $unknownAssignment,$userId,$stamp]);
    $unknownTransaction = (int)$pdo->lastInsertId();
    $unknown = inventoryValuationSnapshotByProduct($pdo, [$unknownProduct])[$unknownProduct];
    $unknownDisplay = inventoryHistoryAssignmentValuation([
        'transaction_type'=>'out', 'quantity'=>-1, 'reason'=>'Assignment → employee',
        'notes'=>'Assignment #' . $unknownAssignment,
        'historical_valuation'=>['movement_value_cents'=>null,'movement_cost_covered_units'=>0,'movement_cost_unknown_units'=>1],
    ]);
    $assert($money($unknown,'assigned') === 0 && (int)$unknown['assigned_unknown_units'] === 1, 'an uncosted assignment is counted as unknown, not valued at zero.');
    $assert(inventoryHistoryAssignmentValuationDisplay($unknownDisplay) === 'Cost unavailable · 1 unit transferred to assigned', 'unknown assignment display retains unavailable cost and the transfer direction.');
    $assert($assignmentTransactionId > 0 && $returnId > 0 && $unknownTransaction > 0, 'fixture created persisted assignment and return transactions.');
    fwrite(STDOUT, "PASS: MariaDB cost-layer assignment/return transfers, serialized receipt costs, and unknown-cost assignment behavior.\n");
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
