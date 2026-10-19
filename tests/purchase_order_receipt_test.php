<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/inventory_helpers.php';
require_once __DIR__ . '/../app/purchase_order_helpers.php';

$receiptCheck = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Purchase order receipt test failed: ' . $message);
};
$receiptThrows = static function (callable $callback, string $message) use ($receiptCheck): void {
    try { $callback(); } catch (DomainException) { return; }
    $receiptCheck(false, $message . ' must be rejected');
};

$po = ['id'=>38,'status'=>'Confirmed','product_id'=>7,'quantity'=>5,'unit_price'=>'110013.00','vendor_id'=>12,'vendor_name'=>'KKR'];
$vendor = ['id'=>12,'vendor_name'=>'KKR'];
$vendorWithContacts = ['id'=>12,'vendor_name'=>'Northwind Supply','contact_person'=>'A. Contact','phone'=>'555-0101','email'=>'vendor@example.test','address'=>'12 Market Road','gstin'=>'27ABCDE1234F1Z5'];
$vendorWithoutContacts = ['id'=>13,'vendor_name'=>'Walk-in Supplier','contact_person'=>null,'phone'=>null,'email'=>null,'address'=>null,'gstin'=>null];
$receiptCheck(purchaseOrderVendorSelection($po, [$vendorWithContacts]) === ['vendor_id'=>12,'unavailable'=>false], 'an active order vendor resolves by its stored vendor ID');
$otherPo = $po;
$otherPo['vendor_id'] = 13;
$otherPo['vendor_name'] = 'Walk-in Supplier';
$receiptCheck(purchaseOrderVendorSelection($otherPo, [$vendorWithContacts,$vendorWithoutContacts]) === ['vendor_id'=>13,'unavailable'=>false], 'switching orders resolves the new vendor ID');
$legacyPo = $po;
$legacyPo['vendor_id'] = null;
$legacyPo['vendor_name'] = 'Northwind Supply';
$receiptCheck(purchaseOrderVendorSelection($legacyPo, [$vendorWithContacts]) === ['vendor_id'=>12,'unavailable'=>false], 'legacy orders without a vendor ID match the recorded vendor name');
$receiptCheck(purchaseOrderVendorSelection($po, []) === ['vendor_id'=>null,'unavailable'=>true], 'inactive or missing linked vendors are reported as unavailable');
$receiptCheck(purchaseOrderVendorSelection(null, []) === ['vendor_id'=>null,'unavailable'=>false], 'direct receipts do not require an order vendor resolution');
ob_start();
renderVendorPicker([$vendorWithContacts], 'receipt-contact-test', 12);
$prefilledPicker = (string)ob_get_clean();
$receiptCheck(str_contains($prefilledPicker, 'value="Northwind Supply"') && str_contains($prefilledPicker, 'name="vendor_id" value="12"'), 'the selected vendor name and ID are present in the server-rendered picker');
foreach (['A. Contact','555-0101','vendor@example.test','12 Market Road','27ABCDE1234F1Z5'] as $contactValue) {
    $receiptCheck(str_contains($prefilledPicker, 'value="' . $contactValue . '"'), 'the server-rendered picker includes vendor contact data');
}
ob_start();
renderVendorPicker([$vendorWithoutContacts], 'receipt-no-contact-test', 13);
$optionalContactsPicker = (string)ob_get_clean();
$receiptCheck(str_contains($optionalContactsPicker, 'value="Walk-in Supplier"') && str_contains($optionalContactsPicker, 'name="vendor_id" value="13"'), 'a vendor without optional contact fields is still preselected');
$receiptCheck(substr_count($optionalContactsPicker, 'value="" readonly data-vendor-detail=') === 5, 'optional contact fields remain blank instead of preventing vendor selection');
$state = purchaseOrderReceiptState($po, 3);
$receiptCheck($state['remaining_quantity'] === 2, 'partial order remaining quantity is based on actual receipt rows');
$fullReceipt = validatePurchaseOrderReceipt($po, 7, 2, '110013.00', $vendor, 3);
$receiptCheck($fullReceipt['remaining_quantity'] === 2, 'a receipt for all remaining units is eligible');
$receiptPrice = calculateTransactionPrice(2, '110013.00');
$receiptCheck($receiptPrice['total_price'] === '220026.00', 'prefilled receipt quantity and order unit price produce the exact total');
$partialReceipt = validatePurchaseOrderReceipt($po, 7, 1, '110013.00', $vendor, 3);
$receiptCheck($partialReceipt['remaining_quantity'] === 2, 'a partial receipt keeps the remaining quantity available');
$receiptThrows(static fn() => purchaseOrderReceiptState($po, 5), 'a previously completed order');
$receiptThrows(static fn() => purchaseOrderReceiptState(null, 0), 'an invalid order ID');
$rejectedOrder = $po;
$rejectedOrder['status'] = 'Rejected';
$receiptThrows(static fn() => purchaseOrderReceiptState($rejectedOrder, 0), 'a rejected order');
$zeroQuantityOrder = $po;
$zeroQuantityOrder['quantity'] = 0;
$receiptThrows(static fn() => purchaseOrderReceiptState($zeroQuantityOrder, 0), 'a confirmed order with zero recorded quantity');
$receiptCheck(purchaseOrderQuantitySummary(0, 0) === 'Invalid quantity · 0 ordered · no receipt possible', 'zero ordered quantity is not described as merely not yet received');
$receiptCheck(purchaseOrderQuantitySummary(3, 3) === 'Fully received · 3 of 3', 'a fully received ledger is surfaced even if an old status was not synchronized');
$receiptCheck(purchaseOrderQuantitySummary(5, 2) === '2 received · 3 remaining', 'partial receipts show the exact remaining quantity');
$receiptThrows(static fn() => validatePurchaseOrderReceipt($po, 8, 1, '110013.00', $vendor, 3), 'a changed product ID');
$receiptThrows(static fn() => validatePurchaseOrderReceipt($po, 7, 3, '110013.00', $vendor, 3), 'a receipt exceeding the live remaining quantity');
$receiptThrows(static fn() => validatePurchaseOrderReceipt($po, 7, 1, '110012.99', $vendor, 3), 'a unit price changed from the confirmed order');
$receiptThrows(static fn() => validatePurchaseOrderReceipt($po, 7, 1, '110013.00', ['id'=>13,'vendor_name'=>'Other'], 3), 'a vendor changed from the confirmed order');
$changedOrder = $po;
$changedOrder['quantity'] = 3;
$receiptThrows(static fn() => validatePurchaseOrderReceipt($changedOrder, 7, 1, '110013.00', $vendor, 3), 'an order quantity reduced after the form was opened');

$productsSource = (string)file_get_contents(__DIR__ . '/../app/products.php');
$pickerSource = (string)file_get_contents(__DIR__ . '/../app/vendor-picker.js');
$inventorySource = (string)file_get_contents(__DIR__ . '/../app/inventory.php');
$schemaSource = (string)file_get_contents(__DIR__ . '/../database/schema.sql');
$receiptCheck(str_contains($productsSource, "requirePermission('orders', 'order_receive')") && str_contains($productsSource, "['orders','order_receive']"), 'receipt form access and submission enforce the receive permission');
$receiptCheck(str_contains($productsSource, 'data-remaining=') && str_contains($productsSource, 'quantity.max=String(remaining)'), 'the form limits editable quantity to the latest displayed remainder');
$receiptCheck(str_contains($productsSource, 'data-unit-price=') && str_contains($productsSource, 'data-order-select') && str_contains($productsSource, 'vendor-picker:select'), 'the order selection populates the existing order and vendor controls');
$receiptCheck(str_contains($productsSource, 'p.category_id,p.sub_category_id') && str_contains($productsSource, '$selectedSubcategoryId = $receiptOrder'), 'the authoritative order lookup initializes category, sub-category and product selectors');
$receiptCheck(str_contains($productsSource, "remaining_quantity'] . '\" max=\"") && str_contains($productsSource, '$applicationLocalDate'), 'the receipt form prefills the current remaining quantity and application-local date');
$receiptCheck(str_contains($productsSource, 'data-reference=') && str_contains($productsSource, 'data-notes=') && str_contains($productsSource, '$receiptOrder[\'reference_number\']'), 'reference and notes are copied only when stored on the purchase order');
$receiptCheck(str_contains($productsSource, 'data-open-receipt=') && str_contains($productsSource, 'scrollIntoView') && str_contains($productsSource, 'prefers-reduced-motion'), 'following a Receive Order link scrolls and focuses the form while honoring reduced motion');
$receiptCheck(str_contains($pickerSource, "picker.dataset.initialVendorId") && str_contains($pickerSource, "vendor-picker:select") && str_contains($pickerSource, 'hidden.value && search.value'), 'the existing vendor picker recognizes prefilled values and order changes');
$receiptCheck(str_contains($pickerSource, 'function clearSelection()') && str_contains($pickerSource, "field.value = ''"), 'switching to an order without a selectable vendor clears stale vendor contact details');
$receiptCheck(str_contains($productsSource, 'data-vendor-warning=') && str_contains($productsSource, 'orderVendorUnavailable'), 'unavailable order vendors are clearly warned and block silent submission');
$receiptCheck(str_contains($inventorySource, 'vendor_contact_snapshot') && str_contains($inventorySource, "\$vendorRecord['contact_person']") && str_contains($inventorySource, "\$vendorRecord['gstin']"), 'order creation stores vendor contact snapshots from the selected vendor');
$receiptCheck(str_contains($productsSource, 'WHERE request_key=? LIMIT 1') && str_contains($schemaSource, 'UNIQUE KEY uq_inventory_transaction_request_key'), 'duplicate receipt submissions are blocked by lookup and unique request-key constraint');
$duplicateGuard = strpos($productsSource, "SELECT id FROM inventory_transactions WHERE request_key=? LIMIT 1 FOR UPDATE");
$orderValidation = strpos($productsSource, 'validatePurchaseOrderReceipt(');
$receiptCheck($duplicateGuard !== false && $orderValidation !== false && $duplicateGuard < $orderValidation, 'an exact retry is recognized before a completed linked order can reject its duplicate receipt');
$receiptCheck(str_contains($productsSource, '$receivedAt, $requestKey]') && !str_contains($productsSource, 'form.elements.request_key.disabled='), 'stock actions retain their idempotency key through submission and persistence');
$receiptCheck(str_contains($productsSource, 'assertAssetTagStockChangeAllowed') && str_contains((string)file_get_contents(__DIR__ . '/../app/inventory.php'), 'assertAssetTagStockChangeAllowed'), 'both stock reduction routes use the same reservation-only guard');
$receiptCheck(str_contains($productsSource, "\$_GET['operation']") && str_contains($productsSource, 'unset($_SESSION[\'flash\'])'), 'failed operation context is preserved and stale reduction errors are cleared on a receipt form');
$ordersSource = (string)file_get_contents(__DIR__ . '/../app/orders.php');
$receiptCheck(str_contains($ordersSource, 'purchaseOrderQuantitySummary') && str_contains($ordersSource, "(int)\$order['quantity'] > 0"), 'orders distinguish zero quantity from a receivable confirmation and hide invalid quantity actions');

echo "PASS: full and partial receipts, completed/invalid orders, stale quantities, product/vendor/price validation, RBAC and idempotency guards\n";
