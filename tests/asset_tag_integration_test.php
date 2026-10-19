<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/asset_tag_helpers.php';
require_once __DIR__ . '/../app/inventory_helpers.php';

if (getenv('ASSET_TAG_INTEGRATION_TESTS') !== '1') {
    fwrite(STDOUT, "SKIP: asset tag MariaDB integration tests require ASSET_TAG_INTEGRATION_TESTS=1 and a disposable *_asset_tag_test database.\n");
    exit(0);
}
$databaseName = envv('DB_NAME','inventory_manager');
if (!str_ends_with($databaseName,'_asset_tag_test')) {
    throw new RuntimeException('Refusing to run asset tag integration tests outside a database ending in _asset_tag_test.');
}
$pdo = db();
$userId = (int)$pdo->query('SELECT id FROM users WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();
$categoryId = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
if (!$userId || !$categoryId) throw new RuntimeException('The disposable integration database needs one active user and one category.');
if (($argv[1] ?? '') === 'internal-serial-race-worker') {
    [,,$productIdArg,$userIdArg,$serialPrefixArg,$tagPrefixArg,$tmpDirArg,$workerIndexArg]=$argv;
    file_put_contents($tmpDirArg.'/ready-'.$workerIndexArg,'ready');
    $deadline=microtime(true)+15;while(!is_file($tmpDirArg.'/go')&&microtime(true)<$deadline)usleep(10000);
    try {
        $assetId=registerSingleAsset($pdo,(int)$productIdArg,$tagPrefixArg,null,'Test Lab','Good',null,'automatic',$serialPrefixArg);
        $serialQuery=$pdo->prepare('SELECT internal_serial_number FROM inventory_assets WHERE id=?');$serialQuery->execute([$assetId]);
        fwrite(STDOUT,(string)$serialQuery->fetchColumn());
    } catch(Throwable $exception) { fwrite(STDERR,$exception->getMessage());exit(1); }
    exit(0);
}
$prefixRoot = 'T' . strtoupper(bin2hex(random_bytes(4)));
$productInsert = $pdo->prepare('INSERT INTO products(category_id,name,unit,serialized,active) VALUES(?,?,\'pcs\',1,1)');
$productInsert->execute([$categoryId,'Asset tag integration ' . $prefixRoot]);
$productId = (int)$pdo->lastInsertId();
$tx = $pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,created_by) VALUES(?,'opening',20,0,20,'Integration fixture',?)");
$tx->execute([$productId,$userId]);
$employeeInsert=$pdo->prepare('INSERT INTO employees(employee_id,full_name,department,location,active) VALUES(?,?,?,?,1)');
$employeeInsert->execute([$prefixRoot,'Asset Tag Integration Employee','QA','Test Lab']);
$employeeId=(int)$pdo->lastInsertId();
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

// Repeated submission with the same request key returns one durable job.
$availableInput=['product_id'=>$productId,'prefix'=>$prefixRoot.'-AVL','starting_number'=>'','quantity'=>'3','padding_digits'=>'3','registration_type'=>'available','asset_location'=>'Test Lab','asset_condition'=>'Good'];
$requestKey=bin2hex(random_bytes(32));
$availableJob=createAssetTagJob($pdo,$availableInput,$requestKey,$userId);
$assert(assetTagAvailableStockFloor($pdo,$productId)===3,'A pending available-tag job reserves its remaining three stock units.');
$positiveReceipt=calculateInventoryChange(20,'in',2);
assertAssetTagStockChangeAllowed($pdo,$productId,$positiveReceipt['delta'],$positiveReceipt['new_stock']);
$assert($positiveReceipt['delta']===2 && $positiveReceipt['new_stock']===22,'A positive receipt is never rejected by reduction-only asset reservations.');
$blockedRemoval=calculateInventoryChange(20,'out',18);
$removalRejected=false;
try { assertAssetTagStockChangeAllowed($pdo,$productId,$blockedRemoval['delta'],$blockedRemoval['new_stock']); }
catch (DomainException) { $removalRejected=true; }
$assert($removalRejected,'A stock removal below the pending-tag reservation floor is rejected.');
$atReservationFloor=calculateInventoryChange(20,'out',17);
assertAssetTagStockChangeAllowed($pdo,$productId,$atReservationFloor['delta'],$atReservationFloor['new_stock']);
$negativeAdjustment=calculateInventoryChange(20,'adjustment',-18);
$adjustmentRejected=false;
try { assertAssetTagStockChangeAllowed($pdo,$productId,$negativeAdjustment['delta'],$negativeAdjustment['new_stock']); }
catch (DomainException) { $adjustmentRejected=true; }
$assert($adjustmentRejected,'A negative stock adjustment below the pending-tag reservation floor is rejected.');
$retryJob=createAssetTagJob($pdo,$availableInput,$requestKey,$userId);
$assert((int)$availableJob['id']===(int)$retryJob['id'] && $retryJob['reused'],'Idempotent bulk retry must return the existing job.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM asset_tag_jobs WHERE request_key=' . $pdo->quote($requestKey))->fetchColumn()===1,'A retried request must create one job.');
$firstBatch=processNextAssetTagBatch($pdo,1,(int)$availableJob['id']);
$assert(($firstBatch['offset']??0)===1,'The worker commits bounded batches and reports progress.');
$retryAfterProgress=createAssetTagJob($pdo,$availableInput,$requestKey,$userId);
$assert((int)$retryAfterProgress['id']===(int)$availableJob['id'] && $retryAfterProgress['reused'],'Retrying after a committed batch resumes the original job.');
while (true) { $batch=processNextAssetTagBatch($pdo,1,(int)$availableJob['id']); if (!$batch || ($batch['id']??0)!==(int)$availableJob['id']) break; }
$duplicateRejected=false;
try { createAssetTagJob($pdo,array_replace($availableInput,['starting_number'=>'1','quantity'=>'1']),bin2hex(random_bytes(32)),$userId); }
catch (DomainException) { $duplicateRejected=true; }
$assert($duplicateRejected,'Previously reserved numeric ranges cannot be reused.');
$availableAssets=$pdo->prepare("SELECT asset_tag,serial_number,status FROM inventory_assets WHERE asset_tag_job_id=? ORDER BY asset_tag");
$availableAssets->execute([(int)$availableJob['id']]);$availableRows=$availableAssets->fetchAll();
$assert(count($availableRows)===3 && array_reduce($availableRows,static fn(bool $ok,array $row):bool=>$ok && $row['serial_number']===null && $row['status']==='available',true),'Available registration creates individual unassigned rows without serial numbers.');
$assert(assetTagAvailableStockFloor($pdo,$productId)===3,'Registered available assets continue reserving their matching stock units after the job completes.');
$manualTag=$prefixRoot.'-MAN-0001';
registerSingleAsset($pdo,$productId,$manualTag,null,'Test Lab','New',null);
$manualSerial=$prefixRoot.'-MFG-001';
$manualAsset=registerSingleAsset($pdo,$productId,$prefixRoot.'-SERIAL-MANUAL-1',$manualSerial,'Test Lab','Good',null,'manual');
$manualRow=$pdo->query('SELECT serial_number,internal_serial_number FROM inventory_assets WHERE id='.(int)$manualAsset)->fetch();
$assert($manualRow['serial_number']===$manualSerial && $manualRow['internal_serial_number']===null,'Manual mode stores a unique actual manufacturer serial without an internal serial.');
$autoAsset=registerSingleAsset($pdo,$productId,$prefixRoot.'-SERIAL-AUTO-1',null,'Test Lab','Good',null,'automatic',$prefixRoot.'-SN');
$autoRow=$pdo->query('SELECT serial_number,internal_serial_number FROM inventory_assets WHERE id='.(int)$autoAsset)->fetch();
$assert($autoRow['serial_number']===null && $autoRow['internal_serial_number']===$prefixRoot.'-SN-000001','Automatic mode stores generated values only in the internal serial field.');
updateAssetManufacturerSerial($pdo,$autoAsset,$prefixRoot.'-ACTUAL-001',$userId);
$autoUpdated=$pdo->query('SELECT serial_number,internal_serial_number FROM inventory_assets WHERE id='.(int)$autoAsset)->fetch();
$assert($autoUpdated['serial_number']===$prefixRoot.'-ACTUAL-001' && $autoUpdated['internal_serial_number']===$autoRow['internal_serial_number'],'Later manufacturer serial entry preserves the generated internal serial.');
$fillAsset=registerSingleAsset($pdo,$productId,$prefixRoot.'-SERIAL-FILL-1',null,'Test Lab','Good',null,'fill_later');
$fillRow=$pdo->query('SELECT serial_number,internal_serial_number FROM inventory_assets WHERE id='.(int)$fillAsset)->fetch();
$assert($fillRow['serial_number']===null && $fillRow['internal_serial_number']===null,'Fill Later mode stores both serial fields as NULL.');
updateAssetManufacturerSerial($pdo,$fillAsset,$prefixRoot.'-LATER-001',$userId);
$fillUpdated=$pdo->query('SELECT serial_number,internal_serial_number FROM inventory_assets WHERE id='.(int)$fillAsset)->fetch();
$assert($fillUpdated['serial_number']===$prefixRoot.'-LATER-001' && $fillUpdated['internal_serial_number']===null,'A later manufacturer serial update clears Pending while retaining a NULL internal serial.');
$duplicateUpdateRejected=false;
try { updateAssetManufacturerSerial($pdo,$fillAsset,$prefixRoot.'-ACTUAL-001',$userId); }
catch (PDOException) { $duplicateUpdateRejected=true; }
$assert($duplicateUpdateRejected,'Later manufacturer serial updates reject a value already assigned to another asset.');
$manualBulkInput=['product_id'=>$productId,'prefix'=>$prefixRoot.'-MUL','starting_number'=>'','quantity'=>'2','padding_digits'=>'3','registration_type'=>'available','serial_mode'=>'manual','manufacturer_serials'=>[$prefixRoot.'-MUL-MFG-1',$prefixRoot.'-MUL-MFG-2']];
$manualBulkJob=createAssetTagJob($pdo,$manualBulkInput,bin2hex(random_bytes(32)),$userId);
while (true) { $batch=processNextAssetTagBatch($pdo,10,(int)$manualBulkJob['id']); if (!$batch || ($batch['id']??0)!==(int)$manualBulkJob['id']) break; }
$manualBulkRows=$pdo->prepare('SELECT serial_number,internal_serial_number FROM inventory_assets WHERE asset_tag_job_id=? ORDER BY serial_number');$manualBulkRows->execute([(int)$manualBulkJob['id']]);$manualBulkValues=$manualBulkRows->fetchAll();
$assert(count($manualBulkValues)===2 && $manualBulkValues[0]['serial_number']===$prefixRoot.'-MUL-MFG-1' && $manualBulkValues[1]['serial_number']===$prefixRoot.'-MUL-MFG-2' && $manualBulkValues[0]['internal_serial_number']===null,'Manual bulk mode links imported serial numbers to each generated asset tag.');
$autoBulkInput=['product_id'=>$productId,'prefix'=>$prefixRoot.'-AUT','starting_number'=>'','quantity'=>'2','padding_digits'=>'3','registration_type'=>'available','serial_mode'=>'automatic','serial_prefix'=>$prefixRoot.'-BULK-SN'];
$autoBulkJob=createAssetTagJob($pdo,$autoBulkInput,bin2hex(random_bytes(32)),$userId);
while (true) { $batch=processNextAssetTagBatch($pdo,10,(int)$autoBulkJob['id']); if (!$batch || ($batch['id']??0)!==(int)$autoBulkJob['id']) break; }
$autoBulkRows=$pdo->prepare('SELECT serial_number,internal_serial_number FROM inventory_assets WHERE asset_tag_job_id=? ORDER BY asset_tag');$autoBulkRows->execute([(int)$autoBulkJob['id']]);$autoBulkValues=$autoBulkRows->fetchAll();
$assert(count($autoBulkValues)===2 && $autoBulkValues[0]['serial_number']===null && $autoBulkValues[0]['internal_serial_number']===$prefixRoot.'-BULK-SN-000001' && $autoBulkValues[1]['internal_serial_number']===$prefixRoot.'-BULK-SN-000002','Automatic bulk mode reserves stable internal serial values for worker retries.');
$duplicateManualBulkRejected=false;
try { createAssetTagJob($pdo,array_replace($manualBulkInput,['prefix'=>$prefixRoot.'-DUP','quantity'=>'1','manufacturer_serials'=>[$manualSerial]]),bin2hex(random_bytes(32)),$userId); }
catch (PDOException) { $duplicateManualBulkRejected=true; }
$assert($duplicateManualBulkRejected,'Manual bulk mode rejects manufacturer serials already registered to another asset.');
$duplicateBatchRejected=false;
try { createAssetTagJob($pdo,array_replace($manualBulkInput,['prefix'=>$prefixRoot.'-DUPB','manufacturer_serials'=>[$prefixRoot.'-DUP',''.strtolower($prefixRoot.'-DUP')]]),bin2hex(random_bytes(32)),$userId); }
catch (DomainException) { $duplicateBatchRejected=true; }
$assert($duplicateBatchRejected,'Manual bulk mode rejects duplicate manufacturer serials within a batch.');
$serialCsv=sys_get_temp_dir().'/serial-import-'.bin2hex(random_bytes(5)).'.csv';file_put_contents($serialCsv,"Asset,Manufacturer Serial Number\nLaptop,{$prefixRoot}-CSV-1\nLaptop,{$prefixRoot}-CSV-2\n");
$assert(parseManufacturerSerialImport($serialCsv,'csv')===[$prefixRoot.'-CSV-1',$prefixRoot.'-CSV-2'],'CSV bulk import reads manufacturer serials from a labeled column.');unlink($serialCsv);
$serialXlsx=sys_get_temp_dir().'/serial-import-'.bin2hex(random_bytes(5)).'.xlsx';
if(is_file(__DIR__.'/../app/vendor/autoload.php')){require_once __DIR__.'/../app/vendor/autoload.php';$serialBook=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$serialSheet=$serialBook->getActiveSheet();$serialSheet->fromArray([['Product','Manufacturer Serial Number'],['Laptop',$prefixRoot.'-XLSX-1'],['Laptop',$prefixRoot.'-XLSX-2']]);(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($serialBook))->save($serialXlsx);$assert(parseManufacturerSerialImport($serialXlsx,'xlsx')===[$prefixRoot.'-XLSX-1',$prefixRoot.'-XLSX-2'],'XLSX bulk import reads manufacturer serials from a labeled column.');unlink($serialXlsx);}
$duplicateAssetRejected=false;
try { registerSingleAsset($pdo,$productId,$manualTag,null,null,null,null); }
catch (DomainException|PDOException) { $duplicateAssetRejected=true; }
$assert($duplicateAssetRejected,'The permanent registry rejects duplicate manual asset tags.');
$oversizeRejected=false;
try { createAssetTagJob($pdo,['product_id'=>$productId,'prefix'=>$prefixRoot.'-TOO-MANY','starting_number'=>'','quantity'=>'999','padding_digits'=>'3','registration_type'=>'available'],bin2hex(random_bytes(32)),$userId); }
catch (DomainException) { $oversizeRejected=true; }
$assert($oversizeRejected,'Bulk tag quantity cannot exceed unregistered available inventory.');
$assert((int)$pdo->query('SELECT new_quantity FROM inventory_transactions WHERE product_id='.(int)$productId.' ORDER BY id DESC LIMIT 1')->fetchColumn()===20,'Tag generation must not change ledger stock.');

// Existing bulk assignment units remain assigned until each tag is explicitly matched.
$pdo->beginTransaction();
$assignment=$pdo->prepare("INSERT INTO assignments(product_id,quantity,assigned_to,employee_id,employee_record_id,department,location,assigned_by,status) VALUES(?,2,?,?,?,?,'Test Lab',?,'assigned')");
$assignment->execute([$productId,$prefixRoot,$prefixRoot,$employeeId,'QA',$userId]);
$assignmentId=(int)$pdo->lastInsertId();
$out=$pdo->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,created_by) VALUES(?,'out',-2,20,18,'Integration assignment',?)");$out->execute([$productId,$userId]);
$pdo->commit();
$reconciliationInput=['product_id'=>$productId,'prefix'=>$prefixRoot.'-REC','starting_number'=>'','quantity'=>'2','padding_digits'=>'4','registration_type'=>'reconciliation','asset_location'=>'Test Lab','asset_condition'=>'Fair'];
$reconciliationJob=createAssetTagJob($pdo,$reconciliationInput,bin2hex(random_bytes(32)),$userId);
while (true) { $batch=processNextAssetTagBatch($pdo,1,(int)$reconciliationJob['id']); if (!$batch || ($batch['id']??0)!==(int)$reconciliationJob['id']) break; }
$reserved=$pdo->prepare("SELECT id FROM inventory_assets WHERE asset_tag_job_id=? AND status='reserved' ORDER BY id");$reserved->execute([(int)$reconciliationJob['id']]);$reservedIds=$reserved->fetchAll(PDO::FETCH_COLUMN);
$assert(count($reservedIds)===2,'Assigned stock tags must wait in reserved state for explicit reconciliation.');
reconcileAssetTagAssignment($pdo,$assignmentId,(int)$reservedIds[0],$userId);
reconcileAssetTagAssignment($pdo,$assignmentId,(int)$reservedIds[1],$userId);
$assignedCount=(int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM assignments WHERE product_id=".(int)$productId." AND status='assigned' AND returned_at IS NULL")->fetchColumn();
$mappedCount=(int)$pdo->query('SELECT COUNT(*) FROM active_hardware_assignments aha JOIN inventory_assets ia ON ia.id=aha.asset_id WHERE ia.product_id='.(int)$productId)->fetchColumn();
$assert($assignedCount===2 && $mappedCount===2,'Reconciliation preserves assigned quantity and maps both tags to the actual employee.');
$assert((int)$pdo->query('SELECT new_quantity FROM inventory_transactions WHERE product_id='.(int)$productId.' ORDER BY id DESC LIMIT 1')->fetchColumn()===18,'Assignment tag reconciliation must not change stock transactions.');

// Two independent connections racing for the same prefix range must have one winner.
if (function_exists('pcntl_fork') && function_exists('stream_socket_pair')) {
    $racePrefix=$prefixRoot.'-RACE';$tmpDir=sys_get_temp_dir().'/asset-tag-race-'.bin2hex(random_bytes(6));mkdir($tmpDir,0700);
    $children=[];
    for($i=0;$i<2;$i++){
        $pid=pcntl_fork();
        if($pid===-1)throw new RuntimeException('Unable to create concurrency test process.');
        if($pid===0){
            $ready=$tmpDir.'/ready-'.$i;$result=$tmpDir.'/result-'.$i;file_put_contents($ready,'ready');
            $deadline=microtime(true)+10;while(!is_file($tmpDir.'/go')&&microtime(true)<$deadline)usleep(10000);
            try{$childPdo=new PDO('mysql:host='.envv('DB_HOST','db').';port='.envv('DB_PORT','3306').';dbname='.$databaseName.';charset=utf8mb4',envv('DB_USER','inventory_app'),envv('DB_PASSWORD',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$data=['product_id'=>$productId,'prefix'=>$racePrefix,'starting_number'=>'500','quantity'=>'1','padding_digits'=>'3','registration_type'=>'available'];createAssetTagJob($childPdo,$data,bin2hex(random_bytes(32)),$userId);file_put_contents($result,'won');}
            catch(Throwable $e){file_put_contents($result,$e instanceof DomainException?'collision':'error:'.$e->getMessage());}
            exit(0);
        }
        $children[]=$pid;
    }
    $deadline=microtime(true)+10;while((!is_file($tmpDir.'/ready-0')||!is_file($tmpDir.'/ready-1'))&&microtime(true)<$deadline)usleep(10000);touch($tmpDir.'/go');
    foreach($children as $pid)pcntl_waitpid($pid,$status);
    $raceResults=[file_get_contents($tmpDir.'/result-0'),file_get_contents($tmpDir.'/result-1')];
    $assert(count(array_filter($raceResults,static fn($value):bool=>$value==='won'))===1 && count(array_filter($raceResults,static fn($value):bool=>$value==='collision'))===1,'Concurrent range allocation must serialize overlapping prefixes and allow one winner.');
    foreach(glob($tmpDir.'/*')?:[] as $path)unlink($path);rmdir($tmpDir);
} else fwrite(STDOUT,"SKIP: pcntl is unavailable; concurrent range test was not run.\n");

if (function_exists('proc_open')) {
    $serialRacePrefix=$prefixRoot.'-SERIAL-RACE';$serialTagRoot=$prefixRoot.'-TAG-RACE';$tmpDir=sys_get_temp_dir().'/internal-serial-race-'.bin2hex(random_bytes(6));mkdir($tmpDir,0700);$processes=[];$pipes=[];
    for($i=0;$i<2;$i++){
        $process=proc_open([PHP_BINARY,__FILE__,'internal-serial-race-worker',(string)$productId,(string)$userId,$serialRacePrefix,$serialTagRoot.'-'.$i,$tmpDir,(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$childPipes);
        if(!is_resource($process))throw new RuntimeException('Unable to start internal serial concurrency worker.');
        fclose($childPipes[0]);$processes[]=$process;$pipes[]=$childPipes;
    }
    $deadline=microtime(true)+15;while((!is_file($tmpDir.'/ready-0')||!is_file($tmpDir.'/ready-1'))&&microtime(true)<$deadline)usleep(10000);touch($tmpDir.'/go');
    $serialRaceResults=[];$serialRaceExitCodes=[];
    foreach($processes as $index=>$process){$serialRaceResults[]=stream_get_contents($pipes[$index][1]);fclose($pipes[$index][1]);$stderr=stream_get_contents($pipes[$index][2]);fclose($pipes[$index][2]);$serialRaceExitCodes[]=proc_close($process);if($stderr!=='')fwrite(STDERR,$stderr."
");}
    $assert($serialRaceExitCodes===[0,0]&&count(array_unique($serialRaceResults))===2,'Concurrent automatic serial jobs reserve separate internal serial ranges.');
    $serialRaceAssets=$pdo->prepare('SELECT internal_serial_number FROM inventory_assets WHERE asset_tag IN (?,?) ORDER BY internal_serial_number');$serialRaceAssets->execute([$serialTagRoot.'-0',$serialTagRoot.'-1']);$serialRaceValues=$serialRaceAssets->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($serialRaceValues)===2&&count(array_unique($serialRaceValues))===2,'Concurrent workers persist distinct automatically generated internal serial numbers.');
    foreach(glob($tmpDir.'/*')?:[] as $path)unlink($path);rmdir($tmpDir);
} else fwrite(STDOUT,"SKIP: proc_open is unavailable; concurrent internal serial allocation was not tested.
");

fwrite(STDOUT,"PASS: asset tag duplicate protection, concurrent range reservation, idempotent jobs, reconciliation, and stock consistency.\n");
