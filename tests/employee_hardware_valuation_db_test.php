<?php
declare(strict_types=1);

$applicationRoot = is_file(__DIR__ . '/../app/config.php') ? dirname(__DIR__) . '/app' : getcwd();
require_once $applicationRoot . '/config.php';
require_once $applicationRoot . '/inventory_helpers.php';
require_once $applicationRoot . '/employee_hardware_valuation.php';

if (getenv('EMPLOYEE_HARDWARE_VALUATION_DB_TESTS') !== '1') {
    fwrite(STDOUT,"SKIP: employee hardware valuation MariaDB tests require EMPLOYEE_HARDWARE_VALUATION_DB_TESTS=1 and a disposable *_asset_tag_test database.\n");
    exit(0);
}
$pdo=db();
$databaseName=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if(!str_ends_with($databaseName,'_asset_tag_test'))throw new RuntimeException('Refusing to run employee hardware valuation tests outside a disposable *_asset_tag_test database.');
$userId=(int)$pdo->query('SELECT id FROM users WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();
$categoryId=(int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
if(!$userId||!$categoryId)throw new RuntimeException('The disposable integration database needs an active user and category.');
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException('Employee hardware valuation DB test failed: '.$message);};
$stamp=date('Y-m-d H:i:s');
$pdo->beginTransaction();
try{
    $code='HWVAL-'.bin2hex(random_bytes(5));
    $pdo->prepare('INSERT INTO employees(employee_id,full_name,department,location) VALUES(?,?,?,?)')->execute([$code,'Valuation Fixture '.$code,'Test','Lab']);
    $employeeId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products(category_id,name,unit,serialized,active) VALUES(?,?,?,0,1)')->execute([$categoryId,'Hardware valuation fixture '.$code,'pcs']);
    $productId=(int)$pdo->lastInsertId();
    $receipt=$pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,unit_price,total_price,created_by,transaction_at) VALUES(?,'in',1,0,1,'Receipt',?,?,?,?)");
    $receipt->execute([$productId,'100.00','100.00',$userId,$stamp]);$receiptOne=(int)$pdo->lastInsertId();createInventoryCostLayer($pdo,$productId,$receiptOne,1,'100.00');
    $receipt->execute([$productId,'120.00','120.00',$userId,$stamp]);$receiptTwo=(int)$pdo->lastInsertId();createInventoryCostLayer($pdo,$productId,$receiptTwo,1,'120.00');

    $pdo->prepare("INSERT INTO assignments(product_id,quantity,assigned_to,employee_id,employee_record_id,assigned_by,status,assigned_at) VALUES(?,2,?,?,?,?,'assigned',?)")
        ->execute([$productId,'Valuation Fixture '.$code,$code,$employeeId,$userId,$stamp]);
    $assignmentId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,notes,created_by,transaction_at) VALUES(?,'out',-2,2,0,'Assignment',?,?,?)")->execute([$productId,'Assignment #'.$assignmentId,$userId,$stamp]);
    $assignmentTransaction=(int)$pdo->lastInsertId();
    $assignedCost=allocateInventoryCost($pdo,$productId,2,'assignment',$assignmentId,$assignmentTransaction);
    $assert(($assignedCost['total_price']??null)==='220.00','Different receipt prices are allocated at their original FIFO unit costs.');
    recordEmployeeHardwareHistoryEvent($pdo,$employeeId,$assignmentId,'assigned',null,null,null,$userId,$assignmentTransaction,2,2,'220.00');

    $returnTransaction=$pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,notes,created_by,transaction_at) VALUES(?,'in',1,0,1,'Assignment return',?, ?,?)");
    $returnTransaction->execute([$productId,'Return from assignment #'.$assignmentId,$userId,$stamp]);$returnOneId=(int)$pdo->lastInsertId();
    $returnOne=restoreAssignmentCost($pdo,$assignmentId,1);
    $pdo->prepare("UPDATE inventory_transactions SET unit_price=?,total_price=? WHERE id=?")->execute([$returnOne['unit_price'],$returnOne['total_price'],$returnOneId]);
    $pdo->prepare('UPDATE assignments SET returned_quantity=1 WHERE id=?')->execute([$assignmentId]);
    recordEmployeeHardwareHistoryEvent($pdo,$employeeId,$assignmentId,'returned',null,null,null,$userId,$returnOneId,1,1,$returnOne['total_price']);
    $allocQ=$pdo->prepare("SELECT quantity,returned_quantity,unit_cost FROM inventory_cost_allocations WHERE assignment_id=? AND source_type='assignment' ORDER BY id");$allocQ->execute([$assignmentId]);
    $breakdown=employeeHardwareAssignmentCostBreakdown(['quantity'=>2,'returned_quantity'=>1,'status'=>'assigned','cost_allocations'=>$allocQ->fetchAll()]);
    $assert($returnOne['total_price']==='100.00'&&$breakdown['active_cents']===12000,'A partial return uses the returned unit cost while the remaining unit retains its own receipt cost.');

    $returnTransaction->execute([$productId,'Return from assignment #'.$assignmentId,$userId,$stamp]);$returnTwoId=(int)$pdo->lastInsertId();
    $returnTwo=restoreAssignmentCost($pdo,$assignmentId,1);
    $pdo->prepare("UPDATE inventory_transactions SET unit_price=?,total_price=? WHERE id=?")->execute([$returnTwo['unit_price'],$returnTwo['total_price'],$returnTwoId]);
    $pdo->prepare("UPDATE assignments SET returned_quantity=2,status='returned',returned_at=NOW() WHERE id=?")->execute([$assignmentId]);
    recordEmployeeHardwareHistoryEvent($pdo,$employeeId,$assignmentId,'returned',null,null,null,$userId,$returnTwoId,1,1,$returnTwo['total_price']);
    $assert($returnTwo['total_price']==='120.00','A full return restores the remaining original unit cost.');
    $eventsQ=$pdo->prepare('SELECT assignment_id,action,event_quantity,cost_covered_quantity,acquisition_value FROM hardware_assignment_history WHERE employee_id=? AND assignment_id=? ORDER BY id');$eventsQ->execute([$employeeId,$assignmentId]);
    $assignmentEvents=$eventsQ->fetchAll();$eventSummary=employeeHardwareHistorySummary($assignmentEvents);
    $assert($eventSummary['assigned']===['cents'=>22000,'covered'=>2,'units'=>2]&&$eventSummary['returned']===['cents'=>22000,'covered'=>2,'units'=>2],'Assignment and return events are counted once each, with the same total acquisition basis.');
    $assignmentRowQ=$pdo->prepare('SELECT id assignment_id,asset_id,quantity,returned_quantity,status,returned_at FROM assignments WHERE id=?');$assignmentRowQ->execute([$assignmentId]);$assignmentRow=$assignmentRowQ->fetch();
    $assignmentAllocQ=$pdo->prepare("SELECT quantity,returned_quantity,unit_cost FROM inventory_cost_allocations WHERE assignment_id=? AND source_type='assignment' ORDER BY id");$assignmentAllocQ->execute([$assignmentId]);$assignmentRow['cost_allocations']=$assignmentAllocQ->fetchAll();
    $employeeTotals=employeeHardwareMergeHistoryMetrics([$assignmentRow],$assignmentEvents);$employeeCurrent=employeeHardwareSummary([$assignmentRow]);
    $assert($employeeCurrent['active_count']===0&&$employeeCurrent['active']['units']===0&&$employeeTotals['assigned']['cents']===22000&&$employeeTotals['returned']['cents']===22000,'A fully returned assignment has zero current value while both historical metrics retain its original recorded cost.');
    $assert($returnOneId!==$returnTwoId&&$assignmentTransaction!==$returnOneId&&$assignmentTransaction!==$returnTwoId,'Each event keeps its own linked stock transaction reference.');

    $unknownAssignment=$pdo->prepare("INSERT INTO assignments(product_id,quantity,assigned_to,employee_id,employee_record_id,assigned_by,status,assigned_at) VALUES(?,1,?,?,?,?,'assigned',?)");
    $unknownAssignment->execute([$productId,'Valuation Fixture '.$code,$code,$employeeId,$userId,$stamp]);$unknownId=(int)$pdo->lastInsertId();
    recordEmployeeHardwareHistoryEvent($pdo,$employeeId,$unknownId,'assigned',null,null,null,$userId,null,1,0,null);
    $unknownQ=$pdo->prepare('SELECT action,event_quantity,cost_covered_quantity,acquisition_value FROM hardware_assignment_history WHERE assignment_id=?');$unknownQ->execute([$unknownId]);
    $unknown=employeeHardwareHistorySummary($unknownQ->fetchAll());
    $assert($unknown['assigned']===['cents'=>0,'covered'=>0,'units'=>1]&&employeeHardwareCostDisplay(0,0,1)==='Cost unavailable','An assignment with no receipt allocation remains unavailable, never zero-valued.');

    $mixedReceipt=$pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,unit_price,total_price,created_by,transaction_at) VALUES(?,'in',1,0,1,'Mixed cost receipt',?,?,?,?)");
    $mixedReceipt->execute([$productId,'50.00','50.00',$userId,$stamp]);$mixedReceiptId=(int)$pdo->lastInsertId();createInventoryCostLayer($pdo,$productId,$mixedReceiptId,1,'50.00');
    $pdo->prepare("INSERT INTO assignments(product_id,quantity,assigned_to,employee_id,employee_record_id,assigned_by,status,assigned_at) VALUES(?,2,?,?,?,?,'assigned',?)")
        ->execute([$productId,'Valuation Fixture '.$code,$code,$employeeId,$userId,$stamp]);$mixedId=(int)$pdo->lastInsertId();
    $mixedAssignTx=$pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,notes,created_by,transaction_at) VALUES(?,'out',-2,2,0,'Assignment',?,?,?)");$mixedAssignTx->execute([$productId,'Assignment #'.$mixedId,$userId,$stamp]);$mixedAssignTxId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory_cost_allocations(cost_layer_id,assignment_id,source_transaction_id,source_type,quantity,returned_quantity,unit_cost) VALUES(?,?,?,'assignment',1,0,'50.00')")->execute([$pdo->query('SELECT id FROM inventory_cost_layers WHERE source_transaction_id='.$mixedReceiptId)->fetchColumn(),$mixedId,$mixedAssignTxId]);
    recordEmployeeHardwareHistoryEvent($pdo,$employeeId,$mixedId,'assigned',null,null,null,$userId,$mixedAssignTxId,2,1,'50.00');
    $mixedReturnTx=$pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,notes,created_by,transaction_at) VALUES(?,'in',1,0,1,'Assignment return',?,?,?)");$mixedReturnTx->execute([$productId,'Return from assignment #'.$mixedId,$userId,$stamp]);$mixedReturnTxId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE inventory_cost_allocations SET returned_quantity=1 WHERE assignment_id=?')->execute([$mixedId]);$pdo->prepare('UPDATE assignments SET returned_quantity=1 WHERE id=?')->execute([$mixedId]);
    recordEmployeeHardwareHistoryEvent($pdo,$employeeId,$mixedId,'returned',null,null,null,$userId,$mixedReturnTxId,1,0,null);
    $mixedAssignmentQ=$pdo->prepare('SELECT id assignment_id,asset_id,quantity,returned_quantity,status,returned_at FROM assignments WHERE id=?');$mixedAssignmentQ->execute([$mixedId]);$mixedAssignment=$mixedAssignmentQ->fetch();
    $mixedAllocQ=$pdo->prepare("SELECT quantity,returned_quantity,unit_cost FROM inventory_cost_allocations WHERE assignment_id=? AND source_type='assignment'");$mixedAllocQ->execute([$mixedId]);$mixedAssignment['cost_allocations']=$mixedAllocQ->fetchAll();
    $mixedEventsQ=$pdo->prepare('SELECT assignment_id,transaction_id,action,event_quantity,cost_covered_quantity,acquisition_value FROM hardware_assignment_history WHERE assignment_id=? ORDER BY id');$mixedEventsQ->execute([$mixedId]);
    $mixedTotals=employeeHardwareMergeHistoryMetrics([$mixedAssignment],$mixedEventsQ->fetchAll());
    $assert($mixedTotals['assigned']===['cents'=>5000,'covered'=>1,'units'=>2]&&employeeHardwareCostDisplay(5000,1,2)==='₹50.00 (Partial; 1 unit unknown)','A database-backed assignment allocation reports its known subtotal and missing unit without treating the unknown unit as zero.');
    $assert($mixedTotals['returned']===['cents'=>5000,'covered'=>1,'units'=>1]&&employeeHardwareCostDisplay(5000,1,1)==='₹50.00','A return retains the exact recorded cost of the returned allocated unit.');

    $assetTag='HWVAL-'.bin2hex(random_bytes(5));
    $pdo->prepare("INSERT INTO inventory_assets(product_id,asset_tag,acquisition_unit_cost,status) VALUES(?,?,900.00,'available')")->execute([$productId,$assetTag]);
    $assetId=(int)$pdo->lastInsertId();
    $firstSerialized=$pdo->prepare("INSERT INTO assignments(product_id,asset_id,quantity,assigned_to,employee_id,employee_record_id,assigned_by,status,assigned_at) VALUES(?,?,1,?,?,?,?, 'assigned',?)");
    $firstSerialized->execute([$productId,$assetId,'Valuation Fixture '.$code,$code,$employeeId,$userId,$stamp]);$firstAssetAssignment=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO active_hardware_assignments(asset_id,assignment_id) VALUES(?,?)')->execute([$assetId,$firstAssetAssignment]);
    recordEmployeeHardwareHistoryEvent($pdo,$employeeId,$firstAssetAssignment,'assigned',null,null,$assetId,$userId,null,1,1,'900.00');
    $pdo->prepare("UPDATE assignments SET status='returned',returned_at=NOW() WHERE id=?")->execute([$firstAssetAssignment]);
    $pdo->prepare('DELETE FROM active_hardware_assignments WHERE assignment_id=?')->execute([$firstAssetAssignment]);
    recordEmployeeHardwareHistoryEvent($pdo,$employeeId,$firstAssetAssignment,'returned',null,$assetId,null,$userId,null,1,1,'900.00');
    $secondCode=$code.'-NEXT';
    $pdo->prepare('INSERT INTO employees(employee_id,full_name,department,location) VALUES(?,?,?,?)')->execute([$secondCode,'Reassigned Fixture '.$secondCode,'Test','Lab']);
    $secondEmployee=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE inventory_assets SET status='assigned' WHERE id=?")->execute([$assetId]);
    $firstSerialized->execute([$productId,$assetId,'Reassigned Fixture '.$secondCode,$secondCode,$secondEmployee,$userId,$stamp]);$secondAssetAssignment=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO active_hardware_assignments(asset_id,assignment_id) VALUES(?,?)')->execute([$assetId,$secondAssetAssignment]);
    recordEmployeeHardwareHistoryEvent($pdo,$secondEmployee,$secondAssetAssignment,'reassigned',null,null,$assetId,$userId,null,1,1,'900.00');
    $activeOwner=$pdo->prepare('SELECT COUNT(*) FROM active_hardware_assignments WHERE asset_id=?');$activeOwner->execute([$assetId]);
    $assert((int)$activeOwner->fetchColumn()===1,'A reassigned serialized asset has only one active owner.');
    $oldOwnerEvents=[['assignment_id'=>$firstAssetAssignment,'action'=>'assigned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'900.00'],['assignment_id'=>$firstAssetAssignment,'action'=>'returned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'900.00']];
    $oldOwnerAssignment=['assignment_id'=>$firstAssetAssignment,'asset_id'=>$assetId,'quantity'=>1,'returned_quantity'=>0,'status'=>'returned','returned_at'=>$stamp,'asset_unit_cost'=>'900.00'];
    $oldOwnerTotals=employeeHardwareMergeHistoryMetrics([$oldOwnerAssignment],$oldOwnerEvents);
    $assert($oldOwnerTotals['assigned']['cents']===90000&&$oldOwnerTotals['returned']['cents']===90000&&employeeHardwareSummary([$oldOwnerAssignment])['active_count']===0,'The former employee retains assigned and returned value after the same asset is reassigned.');
    $assert(employeeHardwareHistorySummary([['action'=>'reassigned','event_quantity'=>1,'cost_covered_quantity'=>1,'acquisition_value'=>'900.00']])['assigned']['cents']===90000,'The new employee receives the reassigned asset value.');

    // Mirror employee 213's legacy record shape: four assignments, three
    // linked returns, one removal, no event prices or assignment allocations.
    $shapeCode='213-SHAPE-'.bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO employees(employee_id,full_name,department) VALUES(?,?,?)')->execute([$shapeCode,'Chirag fixture','Logistics']);
    $shapeEmployee=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products(category_id,name,unit,serialized,active) VALUES(?,?,?,1,1)')->execute([$categoryId,'CPU history fixture '.$shapeCode,'pcs']);$cpuProduct=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products(category_id,name,unit,serialized,active) VALUES(?,?,?,0,1)')->execute([$categoryId,'Wifi history fixture '.$shapeCode,'pcs']);$wifiProduct=(int)$pdo->lastInsertId();
    $insertShapeTx=$pdo->prepare('INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,notes,created_by,transaction_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $insertShapeAssignment=$pdo->prepare('INSERT INTO assignments(product_id,asset_id,quantity,returned_quantity,assigned_to,employee_id,employee_record_id,assigned_by,assigned_at,returned_at,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $shapeAssignments=[];
    $addShapeAssignment=static function(int $productId,int $quantity,?int $assetId,string $status,int $returnedQuantity,array $returns) use ($pdo,$insertShapeTx,$insertShapeAssignment,$shapeEmployee,$shapeCode,$userId,$stamp,&$shapeAssignments):int {
        $returnedAt=$returns?date('Y-m-d H:i:s',strtotime($stamp)+60):null;
        $insertShapeAssignment->execute([$productId,$assetId,$quantity,$returnedQuantity,'Chirag fixture',$shapeCode,$shapeEmployee,$userId,$stamp,$returnedAt,$status]);
        $assignmentId=(int)$pdo->lastInsertId();$shapeAssignments[]=$assignmentId;
        $insertShapeTx->execute([$productId,'out',-$quantity,20,20-$quantity,'Assignment','Assignment #'.$assignmentId,$userId,$stamp]);$assignTx=(int)$pdo->lastInsertId();
        recordEmployeeHardwareHistoryEvent($pdo,$shapeEmployee,$assignmentId,'assigned',null,null,$assetId,$userId,$assignTx,$quantity,0,null);
        foreach($returns as [$action,$returnQty]){
            $insertShapeTx->execute([$productId,'in',$returnQty,20-$quantity,20-$quantity+$returnQty,ucfirst($action),'Assignment #'.$assignmentId.' '.$action,$userId,$stamp]);$returnTx=(int)$pdo->lastInsertId();
            recordEmployeeHardwareHistoryEvent($pdo,$shapeEmployee,$assignmentId,$action,null,$assetId,null,$userId,$returnTx,$returnQty,0,null);
        }
        return $assignmentId;
    };
    $openingAt=date('Y-m-d H:i:s',strtotime($stamp)-3600);
    $insertShapeTx->execute([$wifiProduct,'in',10,0,10,'Opening stock',null,$userId,$openingAt]);
    $insertShapeTx->execute([$cpuProduct,'in',10,0,10,'Opening stock',null,$userId,$openingAt]);
    $addShapeAssignment($wifiProduct,6,null,'returned',0,[['returned',1]]);
    $addShapeAssignment($cpuProduct,1,null,'returned',1,[['returned',1]]);
    $pdo->prepare("INSERT INTO inventory_assets(product_id,asset_tag,status) VALUES(?,?,'available')")->execute([$cpuProduct,'213-SHAPE-ASSET-'.$shapeCode]);$shapeAsset=(int)$pdo->lastInsertId();
    $addShapeAssignment($cpuProduct,1,$shapeAsset,'removed',0,[['removed',1]]);
    $addShapeAssignment($cpuProduct,1,null,'returned',1,[['returned',1]]);
    $pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,unit_price,total_price,created_by,transaction_at) VALUES(?,'ordered',3,10,10,'New',2400.00,7200.00,?,?)")->execute([$wifiProduct,$userId,date('Y-m-d H:i:s',strtotime($stamp)+1800)]);
    $wifiOrder=(int)$pdo->lastInsertId();
    $insertShapeTx->execute([$wifiProduct,'in',3,10,13,'New','Received against order #'.$wifiOrder,$userId,date('Y-m-d H:i:s',strtotime($stamp)+2400)]);
    // A priced receipt layer exists for this product, but no assignment points
    // to it. It must not be guessed as the acquisition source for older units.
    $insertShapeTx->execute([$cpuProduct,'in',1,0,1,'Later priced receipt',null,$userId,date('Y-m-d H:i:s',strtotime($stamp)+3600)]);$laterReceipt=(int)$pdo->lastInsertId();
    createInventoryCostLayer($pdo,$cpuProduct,$laterReceipt,1,'10000.00');
    $shapeAssignmentRowsQ=$pdo->prepare('SELECT a.id assignment_id,a.asset_id,a.product_id,a.quantity,a.returned_quantity,a.returned_at,a.status,ia.acquisition_unit_cost asset_unit_cost FROM assignments a LEFT JOIN inventory_assets ia ON ia.id=a.asset_id WHERE a.employee_record_id=? ORDER BY a.id');$shapeAssignmentRowsQ->execute([$shapeEmployee]);$shapeAssignmentRows=$shapeAssignmentRowsQ->fetchAll();
    $shapeAllocQ=$pdo->prepare("SELECT quantity,returned_quantity,unit_cost FROM inventory_cost_allocations WHERE assignment_id=? AND source_type='assignment' ORDER BY id");
    foreach($shapeAssignmentRows as &$shapeAssignmentRow){$shapeAllocQ->execute([$shapeAssignmentRow['assignment_id']]);$shapeAssignmentRow['cost_allocations']=$shapeAllocQ->fetchAll();}unset($shapeAssignmentRow);
    $shapeHistoryQ=$pdo->prepare('SELECT h.assignment_id,h.transaction_id,h.action,h.event_quantity,h.cost_covered_quantity,h.acquisition_value FROM hardware_assignment_history h WHERE h.employee_id=? ORDER BY h.id');$shapeHistoryQ->execute([$shapeEmployee]);$shapeHistory=$shapeHistoryQ->fetchAll();
    $shapeMetrics=employeeHardwareMergeHistoryMetrics($shapeAssignmentRows,$shapeHistory);$shapeCurrent=employeeHardwareSummary($shapeAssignmentRows);
    $shapeActions=[];foreach($shapeHistory as $event)$shapeActions[$event['action']]=($shapeActions[$event['action']]??0)+1;
    $assert(count($shapeHistory)===8&&$shapeActions===['assigned'=>4,'returned'=>3,'removed'=>1],'The employee 213 fixture retains four assignments, three returns, and one removal as eight distinct events.');
    $assert($shapeMetrics['assigned']===['cents'=>0,'covered'=>0,'units'=>9]&&$shapeMetrics['returned']===['cents'=>0,'covered'=>0,'units'=>3],'Known later product receipt costs are not fabricated onto unlinked historical assignment or return events.');
    $assert($shapeCurrent['active_count']===0&&$shapeCurrent['active']['units']===0&&employeeHardwareCostDisplay($shapeMetrics['assigned']['cents'],$shapeMetrics['assigned']['covered'],$shapeMetrics['assigned']['units'])==='Cost unavailable'&&employeeHardwareCostDisplay($shapeMetrics['returned']['cents'],$shapeMetrics['returned']['covered'],$shapeMetrics['returned']['units'])==='Cost unavailable','Zero active assignments coexist with independently counted historical events whose costs are unsupported.');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_cost_allocations WHERE assignment_id IN ('.implode(',',array_map('intval',$shapeAssignments)).')')->fetchColumn()===0,'The legacy fixture has no assignment-to-receipt cost allocations.');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_cost_layers WHERE id=(SELECT acquisition_cost_layer_id FROM inventory_assets WHERE id='.$shapeAsset.')')->fetchColumn()===0,'The tagged legacy asset has no acquisition-layer reference.');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_cost_layers WHERE id IN (SELECT id FROM inventory_cost_layers WHERE product_id='.$cpuProduct.')')->fetchColumn()===1&&$laterReceipt>0,'A later product receipt cost layer exists but remains unlinked to the historical assignments.');

    fwrite(STDOUT,"PASS: employee hardware FIFO/partial/full returns, reassignment, missing costs, and employee 213 legacy eight-event history shape.\n");
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
}
