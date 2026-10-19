<?php
declare(strict_types=1);
require_once __DIR__ . '/inventory_helpers.php';

function historicalReceiptCostSourceEligible(string $movementType, ?string $movementStatus, ?int $orderId, ?string $orderStatus): bool
{
    return $movementType === 'in'
        && $movementStatus === 'Received'
        && ($orderId === null || in_array($orderStatus, ['Confirmed','Received'], true));
}

function inventoryValuationSnapshot(PDO $pdo): array
{
    $hasAssetCost = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='acquisition_unit_cost'")->fetchColumn();
    $availableKnown = (float)$pdo->query('SELECT COALESCE(SUM(remaining_quantity*unit_cost),0) FROM inventory_cost_layers')->fetchColumn();
    $assignedKnown = (float)$pdo->query("SELECT COALESCE(SUM((a.quantity-a.returned_quantity)*a.unit_cost),0) FROM inventory_cost_allocations a JOIN assignments x ON x.id=a.assignment_id WHERE a.source_type='assignment' AND a.quantity>a.returned_quantity AND x.status='assigned' AND x.asset_id IS NULL")->fetchColumn();
    $disposedKnown = (float)$pdo->query("SELECT COALESCE(SUM((a.quantity-a.returned_quantity)*a.unit_cost),0) FROM inventory_cost_allocations a JOIN inventory_transactions t ON t.id=a.source_transaction_id WHERE a.source_type='stock_removal' AND (LOWER(COALESCE(t.reason,'')) LIKE '%dispos%' OR LOWER(COALESCE(t.reason,'')) LIKE '%damag%' OR LOWER(COALESCE(t.reason,'')) LIKE '%write%off%')")->fetchColumn();
    $result = ['available'=>$availableKnown,'assigned'=>$assignedKnown,'disposed'=>$disposedKnown,'physical'=>0.0,'unknown_available'=>0,'unknown_assigned'=>0,'unknown_disposed'=>0,'unknown_other_physical'=>0,'available_known_units'=>0,'assigned_known_units'=>0,'valuation_available'=>true];

    $assets = $hasAssetCost
        ? $pdo->query("SELECT status,COUNT(*) units,COALESCE(SUM(acquisition_unit_cost),0) value,SUM(acquisition_unit_cost IS NULL) unknown_units FROM inventory_assets GROUP BY status")->fetchAll(PDO::FETCH_UNIQUE)
        : [];
    if (!$hasAssetCost) {
        $unknownAssetCounts=$pdo->query("SELECT status,COUNT(*) units FROM inventory_assets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $result['unknown_available'] += (int)($unknownAssetCounts['available']??0)+(int)($unknownAssetCounts['reserved']??0);
        $result['unknown_other_physical'] += (int)($unknownAssetCounts['damaged']??0)+(int)($unknownAssetCounts['maintenance']??0);
        $result['unknown_assigned'] += (int)($unknownAssetCounts['assigned']??0);
        $result['unknown_disposed'] += (int)($unknownAssetCounts['disposed']??0);
    }
    foreach (['available','reserved'] as $status) {
        $row = $assets[$status] ?? ['units'=>0,'value'=>0,'unknown_units'=>0];
        $result['available'] += (float)$row['value'];
        $result['unknown_available'] += (int)$row['unknown_units'];
    }
    foreach (['assigned'] as $status) {
        $row = $assets[$status] ?? ['units'=>0,'value'=>0,'unknown_units'=>0];
        $result['assigned'] += (float)$row['value'];
        $result['unknown_assigned'] += (int)$row['unknown_units'];
    }
    foreach (['damaged','maintenance'] as $status) {
        $row = $assets[$status] ?? ['units'=>0,'value'=>0,'unknown_units'=>0];
        $result['physical'] += (float)$row['value'];
        $result['unknown_other_physical'] += (int)$row['unknown_units'];
    }
    $bulk = $pdo->query("SELECT p.id, GREATEST(0,COALESCE((SELECT SUM(CASE WHEN t.transaction_type IN ('in','opening') THEN ABS(t.quantity) WHEN t.transaction_type='out' THEN -ABS(t.quantity) WHEN t.transaction_type='adjustment' THEN t.quantity ELSE 0 END) FROM inventory_transactions t WHERE t.product_id=p.id),0)-COALESCE(ai.units,0)-COALESCE(cl.units,0)) unknown_units,COALESCE(cl.units,0) layer_units FROM products p LEFT JOIN (SELECT product_id,COUNT(*) units FROM inventory_assets WHERE status IN ('available','reserved') GROUP BY product_id) ai ON ai.product_id=p.id LEFT JOIN (SELECT product_id,SUM(remaining_quantity) units FROM inventory_cost_layers GROUP BY product_id) cl ON cl.product_id=p.id")->fetchAll();
    foreach ($bulk as $row) $result['unknown_available'] += (int)$row['unknown_units'];
    $result['unknown_assigned'] += (int)$pdo->query("SELECT COALESCE(SUM(GREATEST(0,a.quantity-a.returned_quantity-COALESCE(c.units,0))),0) FROM assignments a LEFT JOIN (SELECT assignment_id,SUM(quantity-returned_quantity) units FROM inventory_cost_allocations WHERE source_type='assignment' GROUP BY assignment_id) c ON c.assignment_id=a.id WHERE a.status='assigned' AND a.asset_id IS NULL")->fetchColumn();
    $result['unknown_disposed'] = (int)$pdo->query("SELECT COALESCE(SUM(GREATEST(0,ABS(t.quantity)-COALESCE(c.units,0))),0) FROM inventory_transactions t LEFT JOIN (SELECT source_transaction_id,SUM(quantity) units FROM inventory_cost_allocations WHERE source_type='stock_removal' GROUP BY source_transaction_id) c ON c.source_transaction_id=t.id WHERE t.transaction_type='out' AND (LOWER(COALESCE(t.reason,'')) LIKE '%dispos%' OR LOWER(COALESCE(t.reason,'')) LIKE '%damag%' OR LOWER(COALESCE(t.reason,'')) LIKE '%write%off%')")->fetchColumn();
    if ($hasAssetCost) $result['unknown_disposed'] += (int)($assets['disposed']['unknown_units'] ?? 0);
    $result['physical'] += $result['available'] + $result['assigned'];
    $result['available_known_units'] = (int)$pdo->query('SELECT COALESCE(SUM(remaining_quantity),0) FROM inventory_cost_layers')->fetchColumn();
    $result['assigned_known_units'] += (int)$pdo->query("SELECT COALESCE(SUM(a.quantity-a.returned_quantity),0) FROM inventory_cost_allocations a JOIN assignments x ON x.id=a.assignment_id WHERE a.source_type='assignment' AND a.quantity>a.returned_quantity AND x.status='assigned' AND x.asset_id IS NULL")->fetchColumn();
    if ($hasAssetCost) {
        $result['available_known_units'] += (int)$pdo->query("SELECT COUNT(*) FROM inventory_assets WHERE status IN ('available','reserved') AND acquisition_unit_cost IS NOT NULL")->fetchColumn();
        $result['assigned_known_units'] += (int)$pdo->query("SELECT COUNT(*) FROM inventory_assets WHERE status='assigned' AND acquisition_unit_cost IS NOT NULL")->fetchColumn();
    }
    $result['available'] = round($result['available'], 2);
    $result['assigned'] = round($result['assigned'], 2);
    $result['disposed'] = round($result['disposed'], 2);
    $result['physical'] = round($result['physical'], 2);
    return $result;
}

/** Current known cost-ledger totals by product, matching inventoryValuationSnapshot buckets. */
function inventoryValuationSnapshotByProduct(PDO $pdo, array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
    $result = [];
    foreach ($ids as $id) $result[$id] = ['available_value_cents'=>0,'assigned_value_cents'=>0,'disposed_value_cents'=>0,'available_unknown_units'=>0,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0];
    foreach (array_chunk($ids, 200) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $queries = [
            'available' => "SELECT product_id,SUM(remaining_quantity*unit_cost) value FROM inventory_cost_layers WHERE product_id IN ($marks) GROUP BY product_id",
            'assigned' => "SELECT x.product_id,SUM((a.quantity-a.returned_quantity)*a.unit_cost) value FROM inventory_cost_allocations a JOIN assignments x ON x.id=a.assignment_id WHERE a.source_type='assignment' AND a.quantity>a.returned_quantity AND x.status='assigned' AND x.asset_id IS NULL AND x.product_id IN ($marks) GROUP BY x.product_id",
            'disposed' => "SELECT t.product_id,SUM((a.quantity-a.returned_quantity)*a.unit_cost) value FROM inventory_cost_allocations a JOIN inventory_transactions t ON t.id=a.source_transaction_id WHERE a.source_type='stock_removal' AND (LOWER(COALESCE(t.reason,'')) LIKE '%dispos%' OR LOWER(COALESCE(t.reason,'')) LIKE '%damag%' OR LOWER(COALESCE(t.reason,'')) LIKE '%write%off%') AND t.product_id IN ($marks) GROUP BY t.product_id",
        ];
        foreach ($queries as $bucket => $sql) {
            $query = $pdo->prepare($sql);
            $query->execute($chunk);
            foreach ($query->fetchAll() as $row) {
                $id = (int)$row['product_id'];
                if (isset($result[$id])) $result[$id][$bucket . '_value_cents'] += (int)round((float)$row['value'] * 100);
            }
        }
        $hasAssetCost = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='acquisition_unit_cost'")->fetchColumn();
        if ($hasAssetCost) {
            $assets = $pdo->prepare("SELECT product_id,status,SUM(COALESCE(acquisition_unit_cost,0)) value FROM inventory_assets WHERE product_id IN ($marks) AND status IN ('available','reserved','assigned','disposed') GROUP BY product_id,status");
            $assets->execute($chunk);
            foreach ($assets->fetchAll() as $row) {
                $bucket = match ((string)$row['status']) {
                    'available','reserved' => 'available',
                    'assigned' => 'assigned',
                    'disposed' => 'disposed',
                    default => null,
                };
                $id = (int)$row['product_id'];
                if ($bucket !== null && isset($result[$id])) $result[$id][$bucket . '_value_cents'] += (int)round((float)$row['value'] * 100);
            }
            $unknownAssets = $pdo->prepare("SELECT product_id,SUM(status IN ('available','reserved') AND acquisition_unit_cost IS NULL) available_units,SUM(status='assigned' AND acquisition_unit_cost IS NULL) assigned_units,SUM(status='disposed' AND acquisition_unit_cost IS NULL) disposed_units FROM inventory_assets WHERE product_id IN ($marks) GROUP BY product_id");
            $unknownAssets->execute($chunk);
            foreach ($unknownAssets->fetchAll() as $row) {
                $id=(int)$row['product_id'];
                if(isset($result[$id])) {
                    $result[$id]['available_unknown_units']+=(int)$row['available_units'];
                    $result[$id]['assigned_unknown_units']+=(int)$row['assigned_units'];
                    $result[$id]['disposed_unknown_units']+=(int)$row['disposed_units'];
                }
            }
        }
        $unknownBulkAvailable = $pdo->prepare("SELECT p.id product_id,GREATEST(0,COALESCE((SELECT SUM(CASE WHEN t.transaction_type IN ('in','opening') THEN ABS(t.quantity) WHEN t.transaction_type='out' THEN -ABS(t.quantity) WHEN t.transaction_type='adjustment' THEN t.quantity ELSE 0 END) FROM inventory_transactions t WHERE t.product_id=p.id),0)-COALESCE(ai.units,0)-COALESCE(cl.units,0)) units FROM products p LEFT JOIN (SELECT product_id,COUNT(*) units FROM inventory_assets WHERE status IN ('available','reserved') GROUP BY product_id) ai ON ai.product_id=p.id LEFT JOIN (SELECT product_id,SUM(remaining_quantity) units FROM inventory_cost_layers GROUP BY product_id) cl ON cl.product_id=p.id WHERE p.id IN ($marks)");
        $unknownBulkAvailable->execute($chunk);
        foreach ($unknownBulkAvailable->fetchAll() as $row) $result[(int)$row['product_id']]['available_unknown_units']+=(int)$row['units'];
        $unknownBulkAssigned = $pdo->prepare("SELECT a.product_id,SUM(GREATEST(0,a.quantity-a.returned_quantity-COALESCE(c.units,0))) units FROM assignments a LEFT JOIN (SELECT assignment_id,SUM(quantity-returned_quantity) units FROM inventory_cost_allocations WHERE source_type='assignment' GROUP BY assignment_id) c ON c.assignment_id=a.id WHERE a.status='assigned' AND a.asset_id IS NULL AND a.product_id IN ($marks) GROUP BY a.product_id");
        $unknownBulkAssigned->execute($chunk);
        foreach ($unknownBulkAssigned->fetchAll() as $row) $result[(int)$row['product_id']]['assigned_unknown_units']+=(int)$row['units'];
        $unknownDisposed = $pdo->prepare("SELECT t.product_id,SUM(GREATEST(0,ABS(t.quantity)-COALESCE(c.units,0))) units FROM inventory_transactions t LEFT JOIN (SELECT source_transaction_id,SUM(quantity) units FROM inventory_cost_allocations WHERE source_type='stock_removal' GROUP BY source_transaction_id) c ON c.source_transaction_id=t.id WHERE t.transaction_type='out' AND (LOWER(COALESCE(t.reason,'')) LIKE '%dispos%' OR LOWER(COALESCE(t.reason,'')) LIKE '%damag%' OR LOWER(COALESCE(t.reason,'')) LIKE '%write%off%') AND t.product_id IN ($marks) GROUP BY t.product_id");
        $unknownDisposed->execute($chunk);
        foreach ($unknownDisposed->fetchAll() as $row) $result[(int)$row['product_id']]['disposed_unknown_units']+=(int)$row['units'];
    }
    return $result;
}

function reconcileHistoricalInventoryCost(PDO $pdo, array $input, int $userId): int
{
    $kind = (string)($input['kind'] ?? '');
    $id = filter_var($input['source_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
    $reference = trim((string)($input['source_reference'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    if (!in_array($kind,['receipt','asset','assignment','disposal'],true) || !$id || !$quantity || $reference==='' || $notes==='') throw new DomainException('Choose a valid historical source and provide its reference, quantity, and reconciliation notes.');
    $price = calculateTransactionPrice($quantity, trim((string)($input['unit_cost'] ?? '')));
    $pdo->beginTransaction();
    try {
        $productId=0;$transactionId=null;$assetId=null;$assignmentId=null;$assignmentFifoPlan=null;$disposalFifoPlan=null;
        if ($kind==='receipt') {
            $q=$pdo->prepare("SELECT t.*,o.status order_status,o.unit_price order_unit_price FROM inventory_transactions t LEFT JOIN inventory_transactions o ON o.id=t.related_order_id WHERE t.id=? FOR UPDATE");$q->execute([$id]);$t=$q->fetch();
            if (!$t || !in_array($t['transaction_type'],['in','opening'],true) || (int)$t['quantity'] < $quantity || (stripos((string)$t['reason'],'assignment')!==false || stripos((string)$t['reason'],'hardware returned')!==false)) throw new DomainException('Select a stock receipt that is not an assignment return.');
            if ($t['transaction_type']==='in' && !historicalReceiptCostSourceEligible('in',(string)$t['status'],$t['related_order_id']===null?null:(int)$t['related_order_id'],$t['order_status']===null?null:(string)$t['order_status'])) throw new DomainException('Only a recorded receipt linked to a confirmed or received purchase order can support receipt valuation.');
            $exists=$pdo->prepare('SELECT id FROM inventory_cost_layers WHERE source_transaction_id=?');$exists->execute([$id]);if($exists->fetchColumn())throw new DomainException('This receipt already has a cost layer.');
            $productId=(int)$t['product_id'];$transactionId=$id;
            $stock=currentStock($productId);$assetCount=$pdo->prepare("SELECT COUNT(*) FROM inventory_assets WHERE product_id=? AND status IN ('available','reserved')");$assetCount->execute([$productId]);$layerCount=$pdo->prepare('SELECT COALESCE(SUM(remaining_quantity),0) FROM inventory_cost_layers WHERE product_id=?');$layerCount->execute([$productId]);
            $availableUncosted=max(0,$stock-(int)$assetCount->fetchColumn()-(int)$layerCount->fetchColumn());
            if($quantity>$availableUncosted)throw new DomainException('Receipt valuation quantity cannot exceed the product’s current uncosted available bulk units. Reconcile assigned or unavailable stock separately.');
        } elseif ($kind==='asset') {
            $q=$pdo->prepare("SELECT id,product_id,status,acquisition_unit_cost FROM inventory_assets WHERE id=? FOR UPDATE");$q->execute([$id]);$a=$q->fetch();
            if(!$a || $a['acquisition_unit_cost']!==null || $quantity!==1) throw new DomainException('Select one registered asset that has no recorded acquisition cost.');
            $productId=(int)$a['product_id'];$assetId=$id;
        } elseif ($kind==='assignment') {
            $q=$pdo->prepare("SELECT id,product_id,quantity,returned_quantity,status,asset_id,assigned_at FROM assignments WHERE id=? FOR UPDATE");$q->execute([$id]);$a=$q->fetch();
            if(!$a || $a['status']!=='assigned' || $a['asset_id']!==null) throw new DomainException('Select an active bulk assignment.');
            $alloc=$pdo->prepare("SELECT COALESCE(SUM(quantity-returned_quantity),0) FROM inventory_cost_allocations WHERE assignment_id=? AND source_type='assignment'");$alloc->execute([$id]);
            $already=(int)$alloc->fetchColumn();
            if($quantity>(int)$a['quantity']-(int)$a['returned_quantity']-$already)throw new DomainException('Quantity exceeds the uncosted active assignment units.');
            $productId=(int)$a['product_id'];$assignmentId=$id;
            $layersQuery=$pdo->prepare('SELECT l.id,l.unit_cost,l.remaining_quantity FROM inventory_cost_layers l LEFT JOIN inventory_transactions receipt ON receipt.id=l.source_transaction_id WHERE l.product_id=? AND l.remaining_quantity>0 AND ((receipt.transaction_at IS NOT NULL AND receipt.transaction_at<=?) OR (l.source_transaction_id IS NULL AND l.created_at<=?)) ORDER BY l.id FOR UPDATE');$layersQuery->execute([$productId,$a['assigned_at'],$a['assigned_at']]);$layers=$layersQuery->fetchAll();
            $knownUnits=array_sum(array_map(static fn(array $layer):int=>(int)$layer['remaining_quantity'],$layers));
            if($knownUnits>0){
                if($quantity>$knownUnits)throw new DomainException('Reconcile the available FIFO cost layers first, then reconcile any remaining uncosted assignment units separately.');
                $assignmentFifoPlan=planInventoryCostAllocation($layers,$quantity);
                if($assignmentFifoPlan===null||$assignmentFifoPlan['value']['unit_price']!==$price['unit_price'])throw new DomainException('Entered unit cost must match the assignment’s FIFO acquisition cost.');
            }
        } else {
            $q=$pdo->prepare("SELECT id,product_id,quantity,reason,transaction_at FROM inventory_transactions WHERE id=? AND transaction_type='out' FOR UPDATE");$q->execute([$id]);$t=$q->fetch();
            if(!$t || !(preg_match('/dispos|damag|write.?off/i',(string)$t['reason'])))throw new DomainException('Select a disposal, damage, or write-off stock movement.');
            $alloc=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_cost_allocations WHERE source_transaction_id=? AND source_type='stock_removal'");$alloc->execute([$id]);$already=(int)$alloc->fetchColumn();
            if($quantity>abs((int)$t['quantity'])-$already)throw new DomainException('Quantity exceeds the uncosted disposal movement.');
            $productId=(int)$t['product_id'];$transactionId=$id;
            $layersQuery=$pdo->prepare('SELECT l.id,l.unit_cost,l.remaining_quantity FROM inventory_cost_layers l LEFT JOIN inventory_transactions receipt ON receipt.id=l.source_transaction_id WHERE l.product_id=? AND l.remaining_quantity>0 AND ((receipt.transaction_at IS NOT NULL AND receipt.transaction_at<=?) OR (l.source_transaction_id IS NULL AND l.created_at<=?)) ORDER BY l.id FOR UPDATE');$layersQuery->execute([$productId,$t['transaction_at'],$t['transaction_at']]);$layers=$layersQuery->fetchAll();$knownUnits=array_sum(array_map(static fn(array $layer):int=>(int)$layer['remaining_quantity'],$layers));
            if($knownUnits>0){if($quantity>$knownUnits)throw new DomainException('Reconcile the available FIFO cost layers first, then reconcile any remaining uncosted disposal units separately.');$disposalFifoPlan=planInventoryCostAllocation($layers,$quantity);if($disposalFifoPlan===null||$disposalFifoPlan['value']['unit_price']!==$price['unit_price'])throw new DomainException('Entered unit cost must match the disposal FIFO acquisition cost.');}
        }
        $ins=$pdo->prepare('INSERT INTO inventory_cost_reconciliations(kind,product_id,source_transaction_id,asset_id,assignment_id,quantity,unit_cost,source_reference,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([$kind,$productId,$transactionId,$assetId,$assignmentId,$quantity,$price['unit_price'],$reference,$notes,$userId]);$reconId=(int)$pdo->lastInsertId();
        if($kind==='receipt'){
            $layer=$pdo->prepare('INSERT INTO inventory_cost_layers(product_id,source_transaction_id,reconciliation_id,unit_cost,original_quantity,remaining_quantity) VALUES(?,?,?,?,?,?)');$layer->execute([$productId,$transactionId,$reconId,$price['unit_price'],$quantity,$quantity]);
        }elseif($kind==='asset'){
            $layer=$pdo->prepare('INSERT INTO inventory_cost_layers(product_id,source_transaction_id,reconciliation_id,unit_cost,original_quantity,remaining_quantity) VALUES(?,NULL,?,?,1,0)');$layer->execute([$productId,$reconId,$price['unit_price']]);$layerId=(int)$pdo->lastInsertId();
            $upd=$pdo->prepare('UPDATE inventory_assets SET acquisition_unit_cost=?,acquisition_cost_layer_id=? WHERE id=? AND acquisition_unit_cost IS NULL');$upd->execute([$price['unit_price'],$layerId,$assetId]);if($upd->rowCount()!==1)throw new RuntimeException('Asset cost changed concurrently.');
        }elseif($kind==='assignment'&&$assignmentFifoPlan!==null){
            $reduce=$pdo->prepare('UPDATE inventory_cost_layers SET remaining_quantity=remaining_quantity-? WHERE id=? AND remaining_quantity>=?');
            $allocation=$pdo->prepare('INSERT INTO inventory_cost_allocations(cost_layer_id,assignment_id,source_transaction_id,source_type,quantity,unit_cost) VALUES(?,?,NULL,\'assignment\',?,?)');
            foreach($assignmentFifoPlan['allocations'] as [$layer,$take,$cost]){$reduce->execute([$take,$layer['id'],$take]);if($reduce->rowCount()!==1)throw new RuntimeException('Inventory FIFO layer changed concurrently.');$allocation->execute([$layer['id'],$assignmentId,$take,$cost]);}
        }elseif($kind==='disposal'&&$disposalFifoPlan!==null){
            $reduce=$pdo->prepare('UPDATE inventory_cost_layers SET remaining_quantity=remaining_quantity-? WHERE id=? AND remaining_quantity>=?');
            $allocation=$pdo->prepare('INSERT INTO inventory_cost_allocations(cost_layer_id,assignment_id,source_transaction_id,source_type,quantity,unit_cost) VALUES(?,NULL,?,\'stock_removal\',?,?)');
            foreach($disposalFifoPlan['allocations'] as [$layer,$take,$cost]){$reduce->execute([$take,$layer['id'],$take]);if($reduce->rowCount()!==1)throw new RuntimeException('Inventory FIFO layer changed concurrently.');$allocation->execute([$layer['id'],$transactionId,$take,$cost]);}
        }else{
            $layer=$pdo->prepare('INSERT INTO inventory_cost_layers(product_id,source_transaction_id,reconciliation_id,unit_cost,original_quantity,remaining_quantity) VALUES(?,NULL,?,?,?,0)');$layer->execute([$productId,$reconId,$price['unit_price'],$quantity]);$layerId=(int)$pdo->lastInsertId();
            $allocation=$pdo->prepare('INSERT INTO inventory_cost_allocations(cost_layer_id,assignment_id,source_transaction_id,source_type,quantity,unit_cost) VALUES(?,?,?,?,?,?)');
            $allocation->execute([$layerId,$assignmentId,$transactionId,$kind==='assignment'?'assignment':'stock_removal',$quantity,$price['unit_price']]);
        }
        audit('inventory_cost_reconciled','inventory_cost_reconciliation',$reconId,json_encode(['kind'=>$kind,'product_id'=>$productId,'source_transaction_id'=>$transactionId,'asset_id'=>$assetId,'assignment_id'=>$assignmentId,'quantity'=>$quantity,'unit_cost'=>$price['unit_price'],'source_reference'=>$reference],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $pdo->commit();return $reconId;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
