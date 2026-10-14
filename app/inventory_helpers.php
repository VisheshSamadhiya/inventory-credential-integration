<?php
require_once __DIR__ . '/auth.php';

function currentStock(int $productId): int
{
    $stmt = db()->prepare(
        'SELECT new_quantity
         FROM inventory_transactions
         WHERE product_id = ?
         ORDER BY transaction_at DESC, id DESC
         LIMIT 1'
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

function renderVendorPicker(array $vendors, string $prefix): void
{
    $inputId = $prefix . '-search';
    $listId = $prefix . '-suggestions';
    ?>
    <div class="vendor-picker" data-vendor-picker>
        <label for="<?=htmlspecialchars($inputId, ENT_QUOTES)?>">Vendor</label>
        <div class="vendor-search-control">
        <input id="<?=htmlspecialchars($inputId, ENT_QUOTES)?>" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?=htmlspecialchars($listId, ENT_QUOTES)?>" autocomplete="off" placeholder="Search active vendors" data-vendor-search required>
        <input type="hidden" name="vendor_id" value="" data-vendor-id>
        <div id="<?=htmlspecialchars($listId, ENT_QUOTES)?>" class="vendor-suggestions" role="listbox" hidden>
            <?php foreach ($vendors as $vendor): $payload = json_encode($vendor, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>
                <button class="vendor-option" type="button" role="option" aria-selected="false" data-vendor="<?=htmlspecialchars((string)$payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><?=htmlspecialchars($vendor['vendor_name'])?><?php if ($vendor['contact_person']): ?><small><?=htmlspecialchars($vendor['contact_person'])?></small><?php endif; ?></button>
            <?php endforeach; ?>
            <?php if (!$vendors): ?><div class="vendor-suggestion-empty">No active vendors are available.</div><?php endif; ?>
        </div>
        </div>
        <div class="vendor-autofill" aria-live="polite">
            <label>Contact Person<input type="text" readonly data-vendor-detail="contact_person" aria-readonly="true"></label>
            <label>Phone<input type="text" readonly data-vendor-detail="phone" aria-readonly="true"></label>
            <label>Email<input type="text" readonly data-vendor-detail="email" aria-readonly="true"></label>
            <label>Address<input type="text" readonly data-vendor-detail="address" aria-readonly="true"></label>
            <label>GSTIN<input type="text" readonly data-vendor-detail="gstin" aria-readonly="true"></label>
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
