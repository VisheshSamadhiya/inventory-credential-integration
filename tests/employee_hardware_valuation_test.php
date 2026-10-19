<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/employee_hardware_valuation.php';

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException('Employee hardware valuation test failed: ' . $message . '; expected ' . var_export($expected,true) . ', got ' . var_export($actual,true));
    }
};

$assigned = [
    [
        'assignment_id'=>1,'asset_id'=>null,'quantity'=>3,'returned_quantity'=>1,'status'=>'assigned',
        'cost_allocations'=>[
            ['quantity'=>2,'returned_quantity'=>1,'unit_cost'=>'100.00'],
            ['quantity'=>1,'returned_quantity'=>0,'unit_cost'=>'125.00'],
        ],
    ],
    [
        'assignment_id'=>2,'asset_id'=>22,'quantity'=>1,'returned_quantity'=>0,'status'=>'assigned','asset_unit_cost'=>'900.00',
    ],
    [
        'assignment_id'=>3,'asset_id'=>null,'quantity'=>1,'returned_quantity'=>0,'status'=>'assigned','cost_allocations'=>[],
    ],
    [
        'assignment_id'=>4,'asset_id'=>null,'quantity'=>1,'returned_quantity'=>1,'status'=>'returned','cost_allocations'=>[
            ['quantity'=>1,'returned_quantity'=>1,'unit_cost'=>'250.00'],
        ],
    ],
];
$summary = employeeHardwareSummary($assigned);
$assertSame(['cents'=>112500,'covered'=>3,'units'=>4],$summary['active'],'Active values include allocated units and registered assets, while preserving unknown coverage.');
$assertSame(4,$summary['active_count'],'Only currently assigned units count as active.');
$assertSame(['cents'=>35000,'covered'=>2,'units'=>2],$summary['returned'],'Returned assignment value comes from the same original cost allocations.');
$partial = employeeHardwareAssignmentCostBreakdown($assigned[0]);
$assertSame(['assigned_cents'=>32500,'assigned_covered'=>3,'assigned_units'=>3,'active_cents'=>22500,'active_covered'=>2,'active_units'=>2,'returned_cents'=>10000,'returned_covered'=>1,'returned_units'=>1],$partial,'Partial returns use the original allocation layers and retain active cost.');
$assertSame('₹1,125.00 (Partial; 1 unit unknown)',employeeHardwareCostDisplay($summary['active']['cents'],$summary['active']['covered'],$summary['active']['units']),'A mixed known/unknown total is explicitly partial with the missing unit count.');
$assertSame('Cost unavailable',employeeHardwareCostDisplay(0,0,2),'Unknown cost is never represented as zero.');

$events = [
    ['action'=>'assigned','event_quantity'=>2,'cost_covered_quantity'=>2,'acquisition_value'=>'225.00'],
    ['action'=>'returned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'100.00'],
    ['action'=>'reassigned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'900.00'],
    ['action'=>'returned','event_quantity'=>1,'cost_covered_quantity'=>0,'acquisition_value'=>null],
    ['action'=>'removed','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'500.00'],
    // The linked stock transaction is a reference only; this summary consumes each event snapshot once.
];
$history = employeeHardwareHistorySummary($events);
$assertSame(['cents'=>112500,'covered'=>3,'units'=>3],$history['assigned'],'Assignment and reassignment snapshots are counted once per event.');
$assertSame(['cents'=>10000,'covered'=>1,'units'=>2],$history['returned'],'Known return value remains partial when another returned event has unknown cost.');
$assertSame('₹100.00 (Partial; 1 unit unknown)',employeeHardwareCostDisplay($history['returned']['cents'],$history['returned']['covered'],$history['returned']['units']),'Returned total explicitly shows incomplete cost coverage.');

$employeeSource=(string)file_get_contents(__DIR__.'/../app/employee.php');
$assertSame(true,str_contains($employeeSource,'CASE WHEN h.acquisition_value IS NOT NULL THEN h.acquisition_value'),'History prefers stored event costs and supports exact legacy cost references.');
$assertSame(false,str_contains($employeeSource,'p.purchase_price'),'Employee history never substitutes the current product purchase price.');

$returnedAssignments=[
    ['assignment_id'=>10,'asset_id'=>77,'quantity'=>1,'returned_quantity'=>1,'status'=>'returned','asset_unit_cost'=>'800.00'],
    ['assignment_id'=>11,'asset_id'=>null,'quantity'=>3,'returned_quantity'=>1,'status'=>'assigned','cost_allocations'=>[
        ['quantity'=>2,'returned_quantity'=>1,'unit_cost'=>'100.00'],['quantity'=>1,'returned_quantity'=>0,'unit_cost'=>'200.00'],
    ]],
    ['assignment_id'=>12,'asset_id'=>null,'quantity'=>1,'returned_quantity'=>1,'status'=>'returned','cost_allocations'=>[]],
];
$returnEvents=[
    ['assignment_id'=>10,'action'=>'assigned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'800.00'],
    ['assignment_id'=>10,'action'=>'returned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'800.00'],
    ['assignment_id'=>11,'action'=>'assigned','event_quantity'=>3,'cost_covered_quantity'=>3,'acquisition_value'=>'400.00'],
    ['assignment_id'=>11,'action'=>'returned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'100.00'],
    ['assignment_id'=>11,'action'=>'returned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'100.00'],
    ['assignment_id'=>12,'action'=>'assigned','event_quantity'=>1,'cost_covered_quantity'=>0,'acquisition_value'=>null],
    ['assignment_id'=>12,'action'=>'returned','event_quantity'=>1,'cost_covered_quantity'=>0,'acquisition_value'=>null],
];
$merged=employeeHardwareMergeHistoryMetrics($returnedAssignments,$returnEvents);
$assertSame(['cents'=>120000,'covered'=>4,'units'=>5],$merged['assigned'],'Known serialized and bulk costs remain historical after associated units are returned; unknown units remain visible.');
$assertSame(['cents'=>100000,'covered'=>3,'units'=>4],$merged['returned'],'Return value uses completed return events and original assignment costs without duplicate stock movements.');
$assertSame('₹1,000.00 (Partial; 1 unit unknown)',employeeHardwareCostDisplay($merged['returned']['cents'],$merged['returned']['covered'],$merged['returned']['units']),'Partial return totals identify the number of unknown units.');
$assertSame(['cents'=>0,'covered'=>0,'units'=>0],employeeHardwareSummary([])['active'],'No active units have a genuine zero current value.');
$assertSame(2,employeeHardwareSummary($returnedAssignments)['active_count'],'Only the two units remaining on the partial bulk assignment count as active.');
$zeroActive=employeeHardwareSummary([['assignment_id'=>30,'asset_id'=>30,'quantity'=>1,'returned_quantity'=>1,'status'=>'returned','asset_unit_cost'=>'500.00']]);
$assertSame(0,$zeroActive['active_count'],'A fully returned asset has zero active count.');
$assertSame(50000,$zeroActive['assigned']['cents'],'A fully returned asset retains its historical assigned value.');
$assertSame('₹0.00',employeeHardwareCostDisplay(0,0,0),'A category with no qualifying events displays a true zero.');

$betterEvent=employeeHardwareMergeHistoryMetrics([
    ['assignment_id'=>20,'asset_id'=>null,'quantity'=>2,'returned_quantity'=>0,'status'=>'returned','cost_allocations'=>[['quantity'=>1,'returned_quantity'=>0,'unit_cost'=>'100.00']]],
], [['assignment_id'=>20,'action'=>'assigned','event_quantity'=>2,'cost_covered_quantity'=>2,'acquisition_value'=>'250.00']]);
$assertSame(['cents'=>25000,'covered'=>2,'units'=>2],$betterEvent['assigned'],'A complete immutable event snapshot can complete an older incomplete assignment allocation.');
$duplicateTransaction=employeeHardwareHistorySummary([
    ['assignment_id'=>40,'transaction_id'=>700,'action'=>'assigned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'300.00'],
    ['assignment_id'=>40,'transaction_id'=>700,'action'=>'assigned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'300.00'],
]);
$assertSame(['cents'=>30000,'covered'=>1,'units'=>1],$duplicateTransaction['assigned'],'Duplicate history rows linked to the same stock transaction count once.');

fwrite(STDOUT,"PASS: employee hardware active, assigned, returned, partial, unknown, and reassigned valuation cases.\n");
