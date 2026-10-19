<?php
require_once __DIR__ . '/auth.php';

function currentStock(int $productId): int
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('in','opening') THEN ABS(quantity) WHEN transaction_type='out' THEN -ABS(quantity) WHEN transaction_type='adjustment' THEN quantity ELSE 0 END),0)
         FROM inventory_transactions
         WHERE product_id = ?"
    );
    $stmt->execute([$productId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function calculateInventoryChange(int $previousStock, string $type, int $quantity): array
{
    $allowedTypes = ['opening', 'ordered', 'in', 'out', 'adjustment'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new DomainException('Choose a valid stock operation.');
    }
    if ($type !== 'adjustment' && $quantity < 1) {
        throw new DomainException('Enter a positive quantity.');
    }
    if ($type === 'opening' && $previousStock !== 0) {
        throw new DomainException('Opening balance can only be the first balance.');
    }

    $delta = match ($type) {
        'out' => -abs($quantity),
        'in', 'opening' => abs($quantity),
        'ordered' => 0,
        'adjustment' => $quantity,
    };
    $newStock = $previousStock + $delta;
    if ($newStock < 0) {
        throw new DomainException('There is not enough stock for that removal.');
    }

    return [
        'delta' => $delta,
        'recorded_quantity' => $type === 'ordered' ? abs($quantity) : $delta,
        'new_stock' => $newStock,
    ];
}

function categoryInventoryStockTotals(int $assignedUnits, int $availableUnits): array
{
    if ($assignedUnits < 0 || $availableUnits < 0) {
        throw new InvalidArgumentException('Category stock quantities cannot be negative.');
    }

    return [
        'active' => $assignedUnits,
        'inactive' => $availableUnits,
        'total' => $assignedUnits + $availableUnits,
    ];
}

function calculateTransactionPrice(int $quantity, string $priceInput): array
{
    if ($quantity < 1 || $quantity > 2147483647) {
        throw new DomainException('Enter a positive quantity within the supported limit.');
    }
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\\.[0-9]{1,2})?$/D', $priceInput)) {
        throw new DomainException('Enter a valid non-negative price with up to two decimal places.');
    }

    [$whole, $fraction] = array_pad(explode('.', $priceInput, 2), 2, '');
    $unitCents = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
    if ($unitCents > 0 && $quantity > intdiv(999999999999999, $unitCents)) {
        throw new DomainException('Total price exceeds the supported limit.');
    }

    $totalCents = $unitCents * $quantity;
    return [
        'unit_price' => $whole . '.' . str_pad($fraction, 2, '0'),
        'total_price' => intdiv($totalCents, 100) . '.' . str_pad((string)($totalCents % 100), 2, '0', STR_PAD_LEFT),
    ];
}

function createInventoryCostLayer(PDO $pdo, int $productId, int $transactionId, int $quantity, string $unitCost): void
{
    if ($quantity < 1 || $transactionId < 1) throw new InvalidArgumentException('A receipt cost layer requires a saved transaction and positive quantity.');
    $stmt = $pdo->prepare('INSERT INTO inventory_cost_layers(product_id,source_transaction_id,unit_cost,original_quantity,remaining_quantity) VALUES(?,?,?,?,?)');
    $stmt->execute([$productId,$transactionId,$unitCost,$quantity,$quantity]);
}

/** Consume FIFO receipt layers only when all currently unregistered stock has a known basis. */
function allocateInventoryCost(PDO $pdo, int $productId, int $quantity, string $sourceType, ?int $assignmentId, int $transactionId): ?array
{
    if ($quantity < 1 || !in_array($sourceType, ['assignment','stock_removal'], true)) return null;
    $assets = $pdo->prepare("SELECT COUNT(*) FROM inventory_assets WHERE product_id=? AND status IN ('available','reserved')");
    $assets->execute([$productId]);
    $assetCount = (int)$assets->fetchColumn();
    // Called after the movement is inserted: use its pre-movement balance so
    // reducing stock cannot make an incomplete cost pool appear complete.
    $movement = $pdo->prepare('SELECT previous_quantity FROM inventory_transactions WHERE id=? AND product_id=?');
    $movement->execute([$transactionId,$productId]);
    $before = $movement->fetchColumn();
    if ($before === false) throw new InvalidArgumentException('Cost allocation requires the matching stock movement.');
    $unregisteredAvailable = max(0, (int)$before - $assetCount);
    $layersQuery = $pdo->prepare('SELECT id,unit_cost,remaining_quantity FROM inventory_cost_layers WHERE product_id=? AND remaining_quantity>0 ORDER BY id FOR UPDATE');
    $layersQuery->execute([$productId]);
    $layers = $layersQuery->fetchAll();
    $knownAvailable = array_sum(array_map(static fn(array $layer): int => (int)$layer['remaining_quantity'], $layers));
    if ($knownAvailable < $unregisteredAvailable || $knownAvailable < $quantity) return null;

    $plan = planInventoryCostAllocation($layers,$quantity);
    if ($plan === null) return null;
    $reduce = $pdo->prepare('UPDATE inventory_cost_layers SET remaining_quantity=remaining_quantity-? WHERE id=? AND remaining_quantity>=?');
    $allocation = $pdo->prepare('INSERT INTO inventory_cost_allocations(cost_layer_id,assignment_id,source_transaction_id,source_type,quantity,unit_cost) VALUES(?,?,?,?,?,?)');
    foreach ($plan['allocations'] as [$layer,$take,$cost]) {
        $reduce->execute([$take,$layer['id'],$take]);
        if ($reduce->rowCount() !== 1) throw new RuntimeException('Inventory cost layer changed concurrently.');
        $allocation->execute([$layer['id'],$assignmentId,$transactionId,$sourceType,$take,$cost]);
    }
    return $plan['value'];
}

function planInventoryCostAllocation(array $layers, int $quantity): ?array
{
    if ($quantity < 1) return null;
    $remaining=$quantity;$totalCents=0;$allocations=[];
    foreach($layers as $layer){
        if($remaining<1)break;
        $take=min($remaining,(int)($layer['remaining_quantity']??0));
        if($take<1)continue;
        $cost=(string)$layer['unit_cost'];
        $cents=(int)round((float)$cost*100);
        $totalCents+=$take*$cents;
        $allocations[]=[$layer,$take,$cost];
        $remaining-=$take;
    }
    return $remaining>0?null:['allocations'=>$allocations,'value'=>inventoryCostResult($totalCents,$quantity)];
}

/** Transfer one known FIFO unit to a registered asset and retain its receipt-layer reference. */
function takeInventoryAssetCostReference(PDO $pdo, int $productId): ?array
{
    $assets = $pdo->prepare("SELECT COUNT(*) FROM inventory_assets WHERE product_id=? AND status IN ('available','reserved')");
    $assets->execute([$productId]);
    $unregisteredAvailable = max(0, currentStock($productId) - (int)$assets->fetchColumn());
    $layersQuery = $pdo->prepare('SELECT id,unit_cost,remaining_quantity FROM inventory_cost_layers WHERE product_id=? AND remaining_quantity>0 ORDER BY id FOR UPDATE');
    $layersQuery->execute([$productId]);
    $layers = $layersQuery->fetchAll();
    $knownAvailable = array_sum(array_map(static fn(array $layer): int => (int)$layer['remaining_quantity'], $layers));
    if ($knownAvailable < $unregisteredAvailable || $knownAvailable < 1) return null;
    $layer = $layers[0];
    $reduce = $pdo->prepare('UPDATE inventory_cost_layers SET remaining_quantity=remaining_quantity-1 WHERE id=? AND remaining_quantity>0');
    $reduce->execute([$layer['id']]);
    if ($reduce->rowCount() !== 1) throw new RuntimeException('Inventory cost layer changed concurrently.');
    return ['cost_layer_id'=>(int)$layer['id'],'unit_cost'=>(string)$layer['unit_cost']];
}

/** Backwards-compatible cost-only wrapper for callers that do not store layer links. */
function takeInventoryAssetCost(PDO $pdo, int $productId): ?string
{
    return takeInventoryAssetCostReference($pdo,$productId)['unit_cost'] ?? null;
}

function restoreAssignmentCost(PDO $pdo, int $assignmentId, int $quantity): ?array
{
    if ($assignmentId < 1 || $quantity < 1) return null;
    $query = $pdo->prepare('SELECT a.id,a.cost_layer_id,a.quantity,a.returned_quantity,a.unit_cost,l.remaining_quantity FROM inventory_cost_allocations a JOIN inventory_cost_layers l ON l.id=a.cost_layer_id WHERE a.assignment_id=? AND a.source_type=\'assignment\' AND a.quantity>a.returned_quantity ORDER BY a.id FOR UPDATE');
    $query->execute([$assignmentId]);
    $allocations = $query->fetchAll();
    $plan=planInventoryCostRestoration($allocations,$quantity);
    if($plan===null)return null;
    $restore = $pdo->prepare('UPDATE inventory_cost_layers SET remaining_quantity=remaining_quantity+? WHERE id=?');
    $mark = $pdo->prepare('UPDATE inventory_cost_allocations SET returned_quantity=returned_quantity+? WHERE id=?');
    foreach ($plan['allocations'] as [$allocation,$take]) {
        $restore->execute([$take,$allocation['cost_layer_id']]);
        $mark->execute([$take,$allocation['id']]);
    }
    return $plan['value'];
}

function planInventoryCostRestoration(array $allocations,int $quantity): ?array
{
    if($quantity<1)return null;$remaining=$quantity;$totalCents=0;$plan=[];
    foreach($allocations as $allocation){
        if($remaining<1)break;
        $unreturned=(int)$allocation['quantity']-(int)$allocation['returned_quantity'];
        $take=min($remaining,$unreturned);if($take<1)continue;
        $totalCents+=$take*(int)round((float)$allocation['unit_cost']*100);
        $plan[]=[$allocation,$take];$remaining-=$take;
    }
    return $remaining>0?null:['allocations'=>$plan,'value'=>inventoryCostResult($totalCents,$quantity)];
}

function inventoryCostResult(int $totalCents, int $quantity): array
{
    $averageCents = $quantity > 0 ? (int)round($totalCents / $quantity, 0, PHP_ROUND_HALF_UP) : 0;
    $money = static fn(int $cents): string => intdiv($cents,100) . '.' . str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    return ['unit_price'=>$money($averageCents),'total_price'=>$money($totalCents),'total_cents'=>$totalCents];
}

function inventoryValuationTotals(float $available, float $assigned, float $disposed): array
{
    return ['available'=>round($available,2),'assigned'=>round($assigned,2),'physical'=>round($available+$assigned,2),'disposed'=>round($disposed,2)];
}

function activeVendorForOrder(PDO $pdo, mixed $input): array
{
    $vendorId = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$vendorId) throw new DomainException('Select an active vendor from the suggestions.');
    $lock = $pdo->inTransaction() ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT id,vendor_name,contact_person,phone,email,address,gstin FROM vendors WHERE id=? AND active=1' . $lock);
    $stmt->execute([$vendorId]);
    $vendor = $stmt->fetch();
    if (!$vendor) throw new DomainException('That vendor is unavailable. Select an active vendor.');
    return $vendor;
}

function renderVendorPicker(array $vendors, string $prefix, ?int $selectedVendorId = null): void
{
    $inputId = $prefix . '-search';
    $listId = $prefix . '-suggestions';
    $selectedVendor = null;
    foreach ($vendors as $vendor) {
        if ($selectedVendorId !== null && (int)($vendor['id'] ?? 0) === $selectedVendorId) {
            $selectedVendor = $vendor;
            break;
        }
    }
    ?>
    <div class="vendor-picker" data-vendor-picker<?=$selectedVendorId && $selectedVendorId > 0 ? ' data-initial-vendor-id="' . (int)$selectedVendorId . '"' : ''?>>
        <label for="<?=htmlspecialchars($inputId, ENT_QUOTES)?>">Vendor</label>
        <div class="vendor-search-control">
        <input id="<?=htmlspecialchars($inputId, ENT_QUOTES)?>" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?=htmlspecialchars($listId, ENT_QUOTES)?>" autocomplete="off" placeholder="Search active vendors" data-vendor-search value="<?=htmlspecialchars((string)($selectedVendor['vendor_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" required>
        <input type="hidden" name="vendor_id" value="<?=htmlspecialchars((string)($selectedVendor['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" data-vendor-id>
        <div id="<?=htmlspecialchars($listId, ENT_QUOTES)?>" class="vendor-suggestions" role="listbox" hidden>
            <?php foreach ($vendors as $vendor): $payload = json_encode($vendor, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>
                <button class="vendor-option" type="button" role="option" aria-selected="false" data-vendor="<?=htmlspecialchars((string)$payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><?=htmlspecialchars($vendor['vendor_name'])?><?php if ($vendor['contact_person']): ?><small><?=htmlspecialchars($vendor['contact_person'])?></small><?php endif; ?></button>
            <?php endforeach; ?>
            <?php if (!$vendors): ?><div class="vendor-suggestion-empty">No active vendors are available.</div><?php endif; ?>
        </div>
        </div>
        <div class="vendor-autofill" aria-live="polite">
            <label>Contact Person<input type="text" value="<?=htmlspecialchars((string)($selectedVendor['contact_person'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" readonly data-vendor-detail="contact_person" aria-readonly="true"></label>
            <label>Phone<input type="text" value="<?=htmlspecialchars((string)($selectedVendor['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" readonly data-vendor-detail="phone" aria-readonly="true"></label>
            <label>Email<input type="text" value="<?=htmlspecialchars((string)($selectedVendor['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" readonly data-vendor-detail="email" aria-readonly="true"></label>
            <label>Address<input type="text" value="<?=htmlspecialchars((string)($selectedVendor['address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" readonly data-vendor-detail="address" aria-readonly="true"></label>
            <label>GSTIN<input type="text" value="<?=htmlspecialchars((string)($selectedVendor['gstin'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" readonly data-vendor-detail="gstin" aria-readonly="true"></label>
        </div>
    </div>
    <?php
}

function recalculateInventoryMonths(int $productId): void
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT transaction_type, quantity, transaction_at
             FROM inventory_transactions
             WHERE product_id = ?
             ORDER BY transaction_at, id'
        );
        $stmt->execute([$productId]);
        $months = [];

        foreach ($stmt->fetchAll() as $transaction) {
            $key = date('Y-m', strtotime($transaction['transaction_at']));
            $months[$key] ??= ['in' => 0, 'out' => 0, 'adjustment' => 0];
            $quantity = (int)$transaction['quantity'];
            if (in_array($transaction['transaction_type'], ['in', 'opening'], true)) {
                $months[$key]['in'] += abs($quantity);
            } elseif ($transaction['transaction_type'] === 'out') {
                $months[$key]['out'] += abs($quantity);
            } elseif ($transaction['transaction_type'] === 'adjustment') {
                $months[$key]['adjustment'] += $quantity;
            }
        }

        $balance = 0;
        $upsert = $pdo->prepare(
            'INSERT INTO inventory_months
                (product_id, year, month, opening_quantity, total_in, total_out, total_adjustment, closing_quantity)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                opening_quantity = VALUES(opening_quantity),
                total_in = VALUES(total_in),
                total_out = VALUES(total_out),
                total_adjustment = VALUES(total_adjustment),
                closing_quantity = VALUES(closing_quantity)'
        );
        foreach ($months as $key => $totals) {
            [$year, $month] = array_map('intval', explode('-', $key));
            $opening = $balance;
            $balance += $totals['in'] - $totals['out'] + $totals['adjustment'];
            $upsert->execute([
                $productId,
                $year,
                $month,
                $opening,
                $totals['in'],
                $totals['out'],
                $totals['adjustment'],
                $balance,
            ]);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
