<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/inventory_helpers.php';
require_once __DIR__ . '/../app/history_helpers.php';
require_once __DIR__ . '/../app/inventory_valuation.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Inventory cost test failed: ' . $message);
};
$same = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException('Inventory cost test failed: ' . $message . '; expected ' . var_export($expected,true) . ', got ' . var_export($actual,true));
};

$layers = [
    ['id'=>1,'unit_cost'=>'100.00','remaining_quantity'=>2],
    ['id'=>2,'unit_cost'=>'125.00','remaining_quantity'=>3],
];
$assignment = planInventoryCostAllocation($layers,3);
$same([[1,2,'100.00'],[2,1,'125.00']],array_map(static fn(array $item): array=>[(int)$item[0]['id'],$item[1],$item[2]],$assignment['allocations']),'Assignment consumes actual FIFO costs across multiple purchase prices.');
$same('325.00',$assignment['value']['total_price'],'Assignment value is cost basis, not a purchase or expense amount.');
$same('108.33',$assignment['value']['unit_price'],'Mixed acquisition prices produce a rounded effective unit cost.');

$allocations = [
    ['id'=>11,'cost_layer_id'=>1,'quantity'=>2,'returned_quantity'=>0,'unit_cost'=>'100.00'],
    ['id'=>12,'cost_layer_id'=>2,'quantity'=>1,'returned_quantity'=>0,'unit_cost'=>'125.00'],
];
$partial = planInventoryCostRestoration($allocations,1);
$same('100.00',$partial['value']['total_price'],'Partial return restores the original assigned unit basis.');
$partialAgain = planInventoryCostRestoration([
    ['id'=>11,'cost_layer_id'=>1,'quantity'=>2,'returned_quantity'=>1,'unit_cost'=>'100.00'],
    ['id'=>12,'cost_layer_id'=>2,'quantity'=>1,'returned_quantity'=>0,'unit_cost'=>'125.00'],
],2);
$same('225.00',$partialAgain['value']['total_price'],'Remaining partial return restores each original acquisition layer.');
$check(planInventoryCostRestoration($allocations,4)===null,'A return cannot restore more costed units than were assigned.');

$replacementOld = planInventoryCostRestoration([['id'=>21,'cost_layer_id'=>7,'quantity'=>1,'returned_quantity'=>0,'unit_cost'=>'900.00']],1);
$replacementNew = planInventoryCostAllocation([['id'=>22,'unit_cost'=>'1100.00','remaining_quantity'=>1]],1);
$same('900.00',$replacementOld['value']['total_price'],'Replacement return uses the old asset cost, not current purchase price.');
$same('1100.00',$replacementNew['value']['total_price'],'Replacement assignment keeps the replacement asset acquisition basis.');

$removal = planInventoryCostAllocation($layers,2);
$same('200.00',$removal['value']['total_price'],'Damaged or disposed units are valued from their acquired cost layers.');
$check(planInventoryCostAllocation([['id'=>3,'unit_cost'=>'10.00','remaining_quantity'=>1]],2)===null,'Missing historical cost layers remain unavailable instead of being invented.');
$same(['available'=>400.0,'assigned'=>325.0,'physical'=>725.0,'disposed'=>200.0],inventoryValuationTotals(400,325,200),'Available, assigned, physical and disposed values reconcile separately.');
$same('Removed from employee (returned to stock)',inventoryHistoryMovementLabel(['transaction_type'=>'in','reason'=>'Hardware removed → Chirag (assignment #5 removed)']),'A hardware removal return is not labeled stock received.');
$same('Removed from employee (returned to stock)',inventoryHistoryMovementLabel(['transaction_type'=>'in','reason'=>'Removed','notes'=>'Hardware assignment #5 removed']),'A hardware removal is not labeled received when its detail is in the note.');
$same('₹1,250.00 · Partial',inventoryValuationDisplay(1250.0,2),'Unknown units make a known valuation explicitly partial.');
$same('Cost unavailable',inventoryValuationDisplay(0.0,2),'Unknown costs are never shown as a zero valuation.');
$same('Cost unavailable',inventoryValuationDisplay(null,0),'A missing valuation never falls back to zero.');
$same('₹0.00',inventoryValuationDisplay(0.0,0),'A fully supported genuine zero remains visible.');
$same(true,historicalReceiptCostSourceEligible('in','Received',27,'Confirmed'),'An actual receipt can use its confirmed linked order cost.');
$same(false,historicalReceiptCostSourceEligible('in','Received',29,'Rejected'),'A rejected purchase order cannot support receipt valuation.');
$same(false,historicalReceiptCostSourceEligible('ordered','Confirmed',null,null),'A confirmed purchase order by itself is not received stock.');
$missingBalance=inventoryHistoryStockPresentation(['transaction_type'=>'in','quantity'=>4,'previous_quantity'=>null,'new_quantity'=>null]);
$same(null,$missingBalance['stock_before'],'Missing historical opening balance is not fabricated as zero.');
$same(4,$missingBalance['stock_impact'],'Movement impact remains derivable from a recorded received quantity.');
$same(null,$missingBalance['stock_after'],'Closing balance remains unavailable if its source balance is missing.');
$same(12,inventoryHistoryAssignedImpact(['transaction_type'=>'out','quantity'=>-12,'reason'=>'Assignment → Employee','notes'=>'Assignment #12 to Employee']),'Assignment stock issues increase assigned quantity once.');
$same(-3,inventoryHistoryAssignedImpact(['transaction_type'=>'in','quantity'=>3,'reason'=>'Assignment return → Employee','notes'=>'Partial return from assignment #12']),'Partial assignment returns reduce assigned quantity by their recorded quantity.');
$same(-1,inventoryHistoryAssignedImpact(['transaction_type'=>'in','quantity'=>1,'reason'=>'Hardware removed → Employee','notes'=>'Hardware assignment #12 removed']),'A hardware removal return reduces assigned quantity, not physical total.');
$same(0,inventoryHistoryAssignedImpact(['transaction_type'=>'ordered','quantity'=>4,'reason'=>'New']),'Purchase orders never create assigned or available stock.');
$timeline=inventoryHistoryAssignmentTimeline([
    ['id'=>1,'product_id'=>1,'transaction_at'=>'2026-01-01 10:00:00','transaction_type'=>'out','quantity'=>-3,'reason'=>'Assignment → Employee','notes'=>'Assignment #4'],
    ['id'=>2,'product_id'=>1,'transaction_at'=>'2026-01-02 10:00:00','transaction_type'=>'in','quantity'=>1,'reason'=>'Assignment return → Employee','notes'=>'Return from assignment #4'],
],2);
$same(true,$timeline['reliable'],'Assigned history replays when its ending quantity matches active assignments.');
$same(3,$timeline['rows'][2]['assigned_before'],'Partial returns retain the assignment quantity recorded before return.');
$same(2,$timeline['rows'][2]['assigned_after'],'Partial return reduces assigned quantity and preserves the returned stock.');
$same(false,inventoryHistoryAssignmentTimeline([['id'=>1,'transaction_at'=>'2026-01-01','transaction_type'=>'out','quantity'=>-3,'reason'=>'Assignment → Employee','notes'=>'Assignment #4']],1)['reliable'],'Assignment balances are unavailable when the ledger does not reconcile to current assignments.');
$same(0.0,inventoryHistoryPhysicalValueImpact(['transaction_type'=>'out','quantity'=>-2,'reason'=>'Assignment → Employee','notes'=>'Assignment #4','total_price'=>'200.00']),'Assignments transfer physical cost without changing total physical valuation.');
$knownAssignmentMovement=['movement_value_cents'=>900000,'movement_cost_covered_units'=>1,'movement_cost_unknown_units'=>0];
$knownAssignmentDisplay=inventoryHistoryAssignmentValuation(['transaction_type'=>'out','quantity'=>-1,'reason'=>'Assignment → Employee','notes'=>'Assignment #9','historical_valuation'=>$knownAssignmentMovement]);
$same('₹9,000.00 transferred to assigned',inventoryHistoryAssignmentValuationDisplay($knownAssignmentDisplay),'History labels known assignment cost as a transfer to assigned inventory.');
$same(-900000,$knownAssignmentDisplay['available_change_cents'],'Known assignment lowers available inventory value by the actual acquisition cost.');
$same(900000,$knownAssignmentDisplay['assigned_change_cents'],'Known assignment raises assigned inventory value by the same actual acquisition cost.');
$same(0,$knownAssignmentDisplay['physical_change_cents'],'Known assignment preserves total physical inventory value.');
$knownReturnDisplay=inventoryHistoryAssignmentValuation(['transaction_type'=>'in','quantity'=>1,'reason'=>'Assignment return','notes'=>'Return from assignment #9','historical_valuation'=>$knownAssignmentMovement]);
$same('₹9,000.00 transferred to available',inventoryHistoryAssignmentValuationDisplay($knownReturnDisplay),'History labels a known return as a transfer to available inventory.');
$same(900000,$knownReturnDisplay['available_change_cents'],'A return restores the original known cost to available inventory.');
$unknownAssignmentDisplay=inventoryHistoryAssignmentValuation(['transaction_type'=>'out','quantity'=>-1,'reason'=>'Assignment → Employee','notes'=>'Assignment #10','historical_valuation'=>['movement_value_cents'=>null,'movement_cost_covered_units'=>0,'movement_cost_unknown_units'=>1]]);
$same('Cost unavailable · 1 unit transferred to assigned',inventoryHistoryAssignmentValuationDisplay($unknownAssignmentDisplay),'An uncosted assignment never appears as ₹0.00.');
$same(null,$unknownAssignmentDisplay['available_change_cents'],'Unknown assignment cost does not invent bucket valuation changes.');
$same(300.0,inventoryHistoryPhysicalValueImpact(['transaction_type'=>'in','quantity'=>3,'reason'=>'Received','total_price'=>'300.00','cost_covered_quantity'=>3]),'A fully costed receipt increases physical inventory value by its recorded acquisition value.');
$same(null,inventoryHistoryPhysicalValueImpact(['transaction_type'=>'in','quantity'=>3,'reason'=>'Receipt','known_cost_amount'=>'200.00','cost_covered_quantity'=>2]),'Partial cost coverage is not reported as a complete physical value change.');
$same('₹200.00 (partial: 2 of 3 units)',inventoryHistoryCostDisplay(['quantity'=>3,'known_cost_amount'=>'200.00','cost_covered_quantity'=>2]),'Known partial historical cost is explicitly labeled.');
$same('Cost unavailable',inventoryHistoryCostDisplay(['quantity'=>3,'known_cost_amount'=>null,'cost_covered_quantity'=>0]),'Missing historical cost never displays as zero.');

$valuationEvents = [
    ['id'=>1,'transaction_at'=>'2026-01-01 09:00:00','transaction_type'=>'ordered','quantity'=>2,'previous_quantity'=>0,'new_quantity'=>0,'unit_price'=>'100.00','total_price'=>'200.00','cost_covered_quantity'=>0,'reason'=>'Order'],
    ['id'=>2,'transaction_at'=>'2026-01-02 09:00:00','transaction_type'=>'in','quantity'=>2,'previous_quantity'=>0,'new_quantity'=>2,'unit_price'=>'100.00','total_price'=>'200.00','cost_covered_quantity'=>2,'reason'=>'Receipt'],
    ['id'=>3,'transaction_at'=>'2026-01-03 09:00:00','transaction_type'=>'out','quantity'=>-1,'previous_quantity'=>2,'new_quantity'=>1,'unit_price'=>'100.00','total_price'=>'100.00','cost_covered_quantity'=>1,'reason'=>'Assignment to Employee','notes'=>'Assignment #7'],
    ['id'=>4,'transaction_at'=>'2026-01-04 09:00:00','transaction_type'=>'in','quantity'=>1,'previous_quantity'=>1,'new_quantity'=>2,'unit_price'=>'100.00','total_price'=>'100.00','cost_covered_quantity'=>1,'reason'=>'Assignment return','notes'=>'Return from assignment #7'],
    ['id'=>5,'transaction_at'=>'2026-01-05 09:00:00','transaction_type'=>'out','quantity'=>-1,'previous_quantity'=>2,'new_quantity'=>1,'unit_price'=>'100.00','total_price'=>'100.00','cost_covered_quantity'=>1,'reason'=>'Disposed'],
];
$valuation = inventoryHistoryValuationTimeline($valuationEvents,true,['available_value_cents'=>10000,'assigned_value_cents'=>0,'disposed_value_cents'=>10000,'available_unknown_units'=>0,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0]);
$same(true,$valuation['reconciled'],'Receipt/assignment/return/disposal replay reconciles to current FIFO bucket values.');
$same(20000,$valuation['rows'][2]['after']['physical_value_cents'],'Assignment transfers cost without changing physical inventory value.');
$same(10000,$valuation['rows'][3]['after']['available_value_cents'],'Return restores the same original cost basis to available stock.');
$same(10000,$valuation['rows'][5]['after']['disposed_value_cents'],'Disposal carries the removed FIFO cost into cumulative disposed value.');
$same(20000,$valuation['rows'][1]['ordered_value_cents'],'Ordered amount is separately reported and does not count as received inventory.');
$unknownValuation = inventoryHistoryValuationTimeline([
    ['id'=>1,'transaction_at'=>'2026-01-01','transaction_type'=>'in','quantity'=>2,'previous_quantity'=>0,'new_quantity'=>2,'reason'=>'Receipt','cost_covered_quantity'=>0],
],true,['available_value_cents'=>0,'assigned_value_cents'=>0,'disposed_value_cents'=>0,'available_unknown_units'=>2,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0]);
$same('Cost unavailable (2 unit(s) cost unavailable)',inventoryHistoryValuationStateDisplay($unknownValuation['rows'][1],'after','available'),'Uncosted received inventory is explicitly unavailable, never ₹0.00.');
$mismatchValuation = inventoryHistoryValuationTimeline($valuationEvents,true,['available_value_cents'=>99999,'assigned_value_cents'=>0,'disposed_value_cents'=>10000,'available_unknown_units'=>0,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0]);
$check(str_contains(inventoryHistoryValuationStateDisplay($mismatchValuation['rows'][2],'after','physical'),'incomplete:'),'A known historical amount does not appear reconciled when it differs from current ledger costs.');
$same('₹200.00',inventoryHistoryValuationCompactDisplay($valuation['rows'][2],'after','available'),'Compact table valuation keeps a reconciled amount on one line.');
$same('₹200.00*',inventoryHistoryValuationCompactDisplay($mismatchValuation['rows'][2],'after','available'),'Compact table valuation marks an unreconciled amount without repeating the long caveat.');
$same('Cost unavailable',inventoryHistoryValuationCompactDisplay($unknownValuation['rows'][1],'after','available'),'Compact table valuation never turns unknown cost into zero.');
$historyMarkup=(string)file_get_contents(__DIR__.'/../app/history.php');
$detailMarkup=(string)file_get_contents(__DIR__.'/../app/transaction-details.php');
$check(str_contains($historyMarkup,'historyDetailUrl(\'stock\'')&&str_contains($historyMarkup,'history-view-details')&&str_contains($detailMarkup,'inventoryHistoryValuationTimeline($events, $assignmentTimeline[\'reliable\'], $knownCurrent)')&&!str_contains($detailMarkup,'Historical valuation calculations'),'Compact History rows retain valuation calculations while transaction details omit the valuation presentation section.');
$check(!str_contains($historyMarkup,'Physical total unchanged')&&str_contains($detailMarkup,'Assignment value transfer'),'The History table stays concise while transaction details retain the transfer breakdown.');
$historyStyles=(string)file_get_contents(__DIR__.'/../app/style.css');
$check(str_contains($historyStyles,'.history-page .history-list-scroll { max-width: 100%; overflow-x: auto;')&&str_contains($historyStyles,'.history-page .history-list-scroll .history-table { width: 100%; min-width: 1040px; table-layout: fixed;')&&str_contains($historyStyles,'.history-page .hardware-history-table { min-width: 960px !important;'),'History tables keep readable desktop columns and scroll horizontally on mobile.');

$productsSource=(string)file_get_contents(__DIR__.'/../app/products.php');
$historySource=(string)file_get_contents(__DIR__.'/../app/history.php');
$assignmentSource=(string)file_get_contents(__DIR__.'/../app/assignments.php');
$migration=(string)file_get_contents(__DIR__.'/../database/migrations/20261016_add_inventory_cost_layers_and_receipts.sql');
$valuationMigration=(string)file_get_contents(__DIR__.'/../database/migrations/20261018_add_inventory_cost_reconciliation.sql');
$valuationSource=(string)file_get_contents(__DIR__.'/../app/inventory_valuation.php');
$detailSource=(string)file_get_contents(__DIR__.'/../app/transaction-details.php');
$check(str_contains($productsSource,"'receive'")&&str_contains($productsSource,'createInventoryCostLayer')&&str_contains($productsSource,'request_key'),'Stock receipt persists a cost layer and an idempotency key.');
$check(str_contains($productsSource,'related_order_id')&&str_contains($productsSource,'remainingOrderQty'),'Confirmed orders support quantity-aware partial receipts.');
$check(str_contains($detailSource,'$historicalValuation = null')&&str_contains($detailSource,'inventoryHistoryValuationTimeline($events, $assignmentTimeline[\'reliable\'], $knownCurrent)')&&!str_contains($detailSource,'Historical unit acquisition cost')&&!str_contains($detailSource,'Transaction valuation change'),'Transaction details retain the historical valuation replay while omitting its valuation card UI.');
$check(str_contains($assignmentSource,'restoreAssignmentCost')&&str_contains($assignmentSource,'returned_quantity'),'Partial assignment returns restore cost basis and track returned units.');
$check(str_contains($migration,'CREATE TABLE IF NOT EXISTS inventory_cost_layers')&&str_contains($migration,'ADD COLUMN returned_quantity'),'Additive migration creates cost layers and supports partial returns.');
$check(str_contains($valuationMigration,'inventory_cost_reconciliations')&&str_contains($valuationMigration,'immutable_delete'),'Historical reconciliation has an additive table and database-level immutability.');
$check(str_contains($valuationSource,"'Confirmed','Received'")&&str_contains($valuationSource,'reconcileHistoricalInventoryCost'),'Reconciliation permits only verified received sources and is implemented in the admin workflow.');
$reconciliationPage=(string)file_get_contents(__DIR__.'/../app/inventory-cost-reconciliation.php');
$check(str_contains($reconciliationPage,"requirePermission('history', 'view')")&&str_contains($reconciliationPage,'canAdmin()')&&str_contains($reconciliationPage,'verifyCsrf()')&&str_contains($reconciliationPage,'Cache-Control: no-store'),'Cost reconciliation remains admin-only, CSRF protected, and no-store in its maintenance page.');
$check(!str_contains($historySource,'Administrator: historical cost reconciliation')&&!str_contains($historySource,'20261018_add_inventory_cost_reconciliation.sql')&&!str_contains($historySource,'Unknown acquisition cost:'),'The main History page omits technical reconciliation instructions and detailed cost counts.');
$check(str_contains($historySource,"po.status IN (\\'Confirmed\\',\\'Received\\')")&&str_contains($historySource,"t.status=\\'Received\\'"),'A priced purchase order is used only for an actual received movement.');

echo "PASS: receipt cost layers, multi-price assignment, partial returns, replacements, removals, unavailable costs, and valuation reconciliation\n";
