<?php
declare(strict_types=1);

/** Return the outstanding quantity for a confirmed purchase order. */
function purchaseOrderReceiptState(?array $order, int $receivedQuantity): array
{
    if ($order === null || (int)($order['id'] ?? 0) < 1 || ($order['status'] ?? '') !== 'Confirmed') {
        throw new DomainException('Only confirmed purchase orders can be received.');
    }

    $orderedQuantity = (int)($order['quantity'] ?? 0);
    $remainingQuantity = $orderedQuantity - max(0, $receivedQuantity);
    if ($orderedQuantity < 1 || $remainingQuantity < 1) {
        throw new DomainException('This purchase order has already been fully received.');
    }

    return ['ordered_quantity' => $orderedQuantity, 'received_quantity' => max(0, $receivedQuantity), 'remaining_quantity' => $remainingQuantity];
}

/** Describe recorded order quantities without changing the stored order status. */
function purchaseOrderQuantitySummary(int $orderedQuantity, int $receivedQuantity): string
{
    $receivedQuantity = max(0, $receivedQuantity);
    if ($orderedQuantity < 1) return 'Invalid quantity · 0 ordered · no receipt possible';
    if ($receivedQuantity < 1) return 'Not yet received · ' . $orderedQuantity . ' remaining';
    if ($receivedQuantity < $orderedQuantity) return $receivedQuantity . ' received · ' . ($orderedQuantity - $receivedQuantity) . ' remaining';
    if ($receivedQuantity === $orderedQuantity) return 'Fully received · ' . $receivedQuantity . ' of ' . $orderedQuantity;
    return 'Receipt quantity exceeds order · ' . $receivedQuantity . ' received of ' . $orderedQuantity;
}

/** Resolve a purchase order's stored vendor to a selectable active vendor. */
function purchaseOrderVendorSelection(?array $order, array $activeVendors): array
{
    if ($order === null) return ['vendor_id'=>null, 'unavailable'=>false];

    $orderVendorId = (int)($order['vendor_id'] ?? 0);
    $orderVendorName = trim((string)($order['vendor_name'] ?? ''));
    foreach ($activeVendors as $vendor) {
        $matches = $orderVendorId > 0
            ? (int)($vendor['id'] ?? 0) === $orderVendorId
            : ($orderVendorName !== '' && strcasecmp(trim((string)($vendor['vendor_name'] ?? '')), $orderVendorName) === 0);
        if ($matches) return ['vendor_id'=>(int)$vendor['id'], 'unavailable'=>false];
    }

    return ['vendor_id'=>null, 'unavailable'=>$orderVendorId > 0 || $orderVendorName !== ''];
}

/** Validate receipt fields against the locked, current purchase order row. */
function validatePurchaseOrderReceipt(
    array $order,
    int $productId,
    int $quantity,
    string $unitPrice,
    array $vendor,
    int $receivedQuantity
): array {
    $state = purchaseOrderReceiptState($order, $receivedQuantity);
    if ((int)($order['product_id'] ?? 0) !== $productId) {
        throw new DomainException('The selected product does not match this purchase order.');
    }
    if ($quantity < 1 || $quantity > $state['remaining_quantity']) {
        throw new DomainException('Receipt quantity exceeds the remaining confirmed order quantity.');
    }

    $orderVendorId = (int)($order['vendor_id'] ?? 0);
    $vendorId = (int)($vendor['id'] ?? 0);
    if ($orderVendorId > 0 && $orderVendorId !== $vendorId) {
        throw new DomainException('The receipt vendor must match the confirmed purchase order.');
    }
    $orderVendorName = trim((string)($order['vendor_name'] ?? ''));
    if ($orderVendorId < 1 && $orderVendorName !== '' && strcasecmp($orderVendorName, trim((string)($vendor['vendor_name'] ?? ''))) !== 0) {
        throw new DomainException('The receipt vendor must match the confirmed purchase order.');
    }

    $orderUnitPrice = $order['unit_price'] ?? null;
    if ($orderUnitPrice !== null && (string)$orderUnitPrice !== $unitPrice) {
        throw new DomainException('The receipt unit price must match the confirmed purchase order.');
    }

    return $state;
}
