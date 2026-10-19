<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/history_helpers.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$before = ['available_value_cents'=>10000,'assigned_value_cents'=>0,'physical_value_cents'=>10000,'disposed_value_cents'=>0,'available_unknown_units'=>0,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0];
$after = ['available_value_cents'=>15000,'assigned_value_cents'=>0,'physical_value_cents'=>15000,'disposed_value_cents'=>0,'available_unknown_units'=>0,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0];
$complete = ['before'=>$before,'after'=>$after,'available_reconciled'=>true];
$check(inventoryHistoryValuationDeltaDisplay($complete,'available') === '+₹50.00', 'Complete historical valuation delta formats as a known amount.');
$partial = $complete;
$partial['after']['available_unknown_units'] = 2;
$partial['available_reconciled'] = false;
$check(str_contains(inventoryHistoryValuationDeltaDisplay($partial,'available'), 'partial / unreconciled'), 'Unknown cost units remain labeled as partial valuation.');
$check(inventoryHistoryValuationDeltaDisplay(null,'physical') === 'Valuation unavailable', 'Unavailable historical valuation is not shown as zero.');

$history = (string)file_get_contents(__DIR__ . '/../app/history.php');
$details = (string)file_get_contents(__DIR__ . '/../app/transaction-details.php');
$header = (string)file_get_contents(__DIR__ . '/../app/header.php');
$styles = (string)file_get_contents(__DIR__ . '/../app/style.css');
$check(str_contains($history, '<th>Date</th><th>Transaction ID</th><th>Product</th><th>Type</th><th>Quantity Change</th><th>Valuation Impact</th><th>View Details</th>'), 'History list uses the compact requested columns.');
$check(str_contains($history, 'history-view-details') && str_contains($history, 'transaction-details.php?'), 'History rows link to the separate detail route.');
$check(str_contains($history, 'name="q"') && str_contains($history, 'name="product"') && str_contains($history, 'name="category"') && str_contains($history, 'name="employee"') && str_contains($history, 'name="type"') && str_contains($history, 'name="time"'), 'History retains its search and filter controls.');
$check(str_contains($history, "['export'] = 'xlsx'") && str_contains($history, "['export']='csv'") && str_contains($history, 'Export Excel') && str_contains($history, 'Export CSV'), 'History retains Excel and CSV export links.');
$check(str_contains($history, 'transactionPages>1') && str_contains($history, 'hardwarePages>1') && str_contains($history, 'pageUrl(\'page\'') && str_contains($history, 'pageUrl(\'hardware_page\''), 'History retains pagination for both record lists.');
$check(str_contains($history, 'Some historical costs are unavailable.') && !str_contains($history, 'Unknown acquisition cost') && !str_contains($history, 'Administrator: historical cost reconciliation') && !str_contains($history, 'database/migrations/20261018_add_inventory_cost_reconciliation.sql'), 'History uses one concise cost notice and omits technical reconciliation instructions.');
$check(!str_contains($history, '1 unit transferred to assigned') && !str_contains($history, '1 unit transferred to available') && !str_contains($history, 'Physical total unchanged') && str_contains($history, "'Cost unavailable'"), 'Main valuation impact avoids repeated transfer explanations and does not substitute zero for unknown cost.');
$check(str_contains($history, '<h2>Inventory transactions</h2>') && str_contains($history, '<h2>Registered hardware assignment events</h2>') && !str_contains($history, 'Inventory transaction history reflects') && !str_contains($history, 'Registered hardware activity records'), 'History sections retain titles and counts without introductory paragraphs.');
$check(str_contains($history, 'history-list-scroll') && str_contains($styles, 'overflow-x: auto') && str_contains($styles, 'history-view-details'), 'History tables and detail actions remain usable in narrow layouts.');
$check(str_contains($history, "historyDetailUrl('stock'") && str_contains($history, "historyDetailUrl('hardware'"), 'Stock rows and hardware events use distinct record types.');
$check(str_contains($details, 'in_array($recordType, [\'stock\', \'hardware\'], true)') && str_contains($details, "requirePermission('history', 'view')"), 'Detail URLs validate record type and enforce history permission server-side.');
foreach (['A. Transaction information','B. Product information','C. Stock calculations','D. Related records','Assignment, return, replacement and removal'] as $section) {
    $check(str_contains($details, $section), 'Dedicated details page includes ' . $section . '.');
}
$check(!str_contains($details, 'Historical valuation calculations') && !str_contains($details, 'Historical unit acquisition cost') && !str_contains($details, 'Available cost-layer / receipt reference'), 'Stock transaction details omit the historical valuation presentation section.');
$check(str_contains($details, '$suppressBackButton = true') && str_contains($details, '$pageHeadingInTemplate = true') && str_contains($header, 'transaction-details-route') && str_contains($styles, '.topbar {'."\n".'  position: relative;'), 'Transaction details use one in-flow header and their own Back to History/title row.');
$check(!str_contains($details, "WHERE a.entity_type='product' AND a.entity_id=?") && !str_contains($details, '$auditRows'), 'Product-wide audit records are not attributed to a selected transaction.');
$check(str_contains($details, "canPerform('inventory_audit','view')") && str_contains($details, 'audit_logs.php?view=inventory'), 'Authorized users retain a link to the full inventory audit log.');
$check(str_contains($details, 'if (($purchaseOrder && canViewResource(\'orders\'))') && str_contains($details, 'D. Related records'), 'Related records render only when at least one useful record is available.');
$check(str_contains($details, 'history_navigation') && str_contains($details, 'rel="prev"') && str_contains($details, 'rel="next"'), 'Previous and next navigation uses the session-bound result set.');
$check(str_contains($details, "information_schema.COLUMNS") && str_contains($details, "COLUMN_NAME='reconciliation_id'"), 'Optional cost-layer reconciliation metadata is checked against the live schema.');
$check(str_contains($details, "'NULL AS reconciliation_id'") && str_contains($details, "'reconciliation_id'"), 'Cost-layer query selects a null fallback when the reconciliation migration is absent.');
$check(str_contains($details, 'SELECT id,source_transaction_id,\' . $reconciliationColumn') && str_contains($details, '$costLayerQuery->execute([$id])'), 'Cost-layer records remain queried independently of the removed valuation presentation.');
$check(str_contains($details, "TABLE_NAME='inventory_cost_reconciliations'") && str_contains($details, 'if ($reconciliationReady)'), 'Reconciliation records are queried independently when their table exists.');
$check(str_contains($details, 'catch (PDOException $error)') && str_contains($details, 'Unable to load this history record.'), 'Database query failures return a generic message and are handled server-side.');
$maintenance = (string)file_get_contents(__DIR__ . '/../app/inventory-cost-reconciliation.php');
$check(str_contains($history, 'inventory-cost-reconciliation.php') && str_contains($maintenance, 'canAdmin()') && str_contains($maintenance, 'verifyCsrf()') && str_contains($maintenance, 'reconcileHistoricalInventoryCost'), 'Authorized reconciliation controls remain available on the separate admin maintenance page.');
echo "PASS: transaction detail layout, section visibility, navigation, and safe audit linkage\n";
