<?php
declare(strict_types=1);

require_once __DIR__ . '/asset_tag_helpers.php';
requirePermission('hardware', 'view');
$pdo = db();
$actor = (int)(currentUser()['id'] ?? 0);
$notice = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $permission = match ($action) {
        'create_job' => ['hardware','add'],
        'register_asset' => ['hardware','add'],
        'update_manufacturer_serial' => ['hardware','edit'],
        'retry_job' => ['hardware','edit'],
        'reconcile_asset' => ['hardware','hardware_assignment'],
        default => ['hardware','edit'],
    };
    requirePermission($permission[0], $permission[1]);
    if ($action === 'reconcile_asset') requirePermission('employees','view');
    verifyCsrf();
    try {
        if ($action === 'create_job') {
            $jobInput = $_POST;
            if (($jobInput['serial_mode'] ?? 'fill_later') === 'manual') {
                $upload = $_FILES['manufacturer_serial_file'] ?? null;
                if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new DomainException('The manufacturer serial import could not be uploaded.');
                    $extension = strtolower(pathinfo((string)($upload['name'] ?? ''),PATHINFO_EXTENSION));
                    $jobInput['manufacturer_serials'] = parseManufacturerSerialImport((string)$upload['tmp_name'],$extension);
                } else {
                    $jobInput['manufacturer_serials'] = parseManufacturerSerialText((string)($jobInput['manufacturer_serials'] ?? ''));
                }
                $jobInput['quantity'] = (string)count($jobInput['manufacturer_serials']);
            }
            $job = createAssetTagJob($pdo, $jobInput, (string)($_POST['request_key'] ?? ''), $actor);
            $notice = $job['reused'] ? 'This request was already accepted. Showing its original job.' : 'Bulk registration queued. Asset tags will appear as the worker processes the job.';
            header('Location: hardware-assets.php?job=' . (int)$job['id'] . '&message=' . rawurlencode($notice));
            exit;
        }
        if ($action === 'register_asset') {
            $productId=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$productId)throw new DomainException('Select a serialized product.');
            $mode=(string)($_POST['serial_mode']??'fill_later');
            $serial=trim((string)($_POST['serial_number']??''));
            $pdo->beginTransaction();
            $assetId=registerSingleAsset($pdo,(int)$productId,(string)($_POST['asset_tag']??''),$serial?:null,trim((string)($_POST['asset_location']??''))?:null,trim((string)($_POST['asset_condition']??''))?:null,trim((string)($_POST['asset_notes']??''))?:null,$mode,(string)($_POST['serial_prefix']??'SN'));
            audit('hardware_asset_registered','asset',$assetId,'Serial mode '.$mode);
            $pdo->commit();
            $notice='Hardware asset registered.';
            header('Location: hardware-assets.php?message=' . rawurlencode($notice));
            exit;
        }
        if ($action === 'update_manufacturer_serial') {
            $assetId=filter_var($_POST['asset_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$assetId)throw new DomainException('Select a registered asset.');
            updateAssetManufacturerSerial($pdo,(int)$assetId,(string)($_POST['serial_number']??''),$actor);
            $notice='Manufacturer serial number updated.';
            header('Location: asset-details.php?id='.(int)$assetId.'&message='.rawurlencode($notice));
            exit;
        }
        if ($action === 'retry_job') {
            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$jobId || !retryAssetTagJob($pdo,(int)$jobId)) throw new DomainException('Only a failed asset tag job can be retried.');
            audit('asset_tag_job_retried','asset_tag_job',(int)$jobId,'Safe retry requested; committed batches are retained.');
            header('Location: hardware-assets.php?job=' . (int)$jobId . '&message=' . rawurlencode('Job queued for a safe retry.'));
            exit;
        }
        if ($action === 'reconcile_asset') {
            $assignmentId = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $assetId = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$assignmentId || !$assetId) throw new DomainException('Select an assignment and a registered asset tag.');
            $reconciled = reconcileAssetTagAssignment($pdo,(int)$assignmentId,(int)$assetId,$actor);
            header('Location: hardware-assets.php?message=' . rawurlencode('Tag ' . $reconciled['asset_tag'] . ' explicitly associated with ' . $reconciled['employee'] . '.'));
            exit;
        }
        throw new DomainException('Unknown hardware asset action.');
    } catch (DomainException $exception) {
        if($pdo->inTransaction())$pdo->rollBack();
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Hardware asset operation failed: ' . $exception->getMessage());
        if($pdo->inTransaction())$pdo->rollBack();
        $error = (string)($exception->errorInfo[1] ?? '') === '1062'
            ? 'That asset tag or serial number is already registered or reserved. No stock or asset records were changed.'
            : 'Unable to save the hardware request. Please retry.';
    }
}

if (isset($_GET['preview'])) {
    requirePermission('hardware','add');
    header('Content-Type: application/json; charset=utf-8');
    try {
        $prefix = normalizeAssetTagPrefix((string)($_GET['prefix'] ?? ''));
        $quantity = filter_var($_GET['quantity'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>ASSET_TAG_MAX_BULK_QUANTITY]]);
        $padding = filter_var($_GET['padding_digits'] ?? 3,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>18]]);
        $startRaw = trim((string)($_GET['starting_number'] ?? ''));
        $first = $startRaw === '' ? suggestedAssetTagStart($pdo,$prefix) : filter_var($startRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$quantity || !$padding || !$first || (int)$first > PHP_INT_MAX-(int)$quantity) throw new DomainException('Enter a valid starting number, quantity, and padding width.');
        $last = (int)$first+(int)$quantity-1;
        $sample = [];
        for ($i=0; $i<min(5,(int)$quantity); $i++) $sample[] = buildAssetTag($prefix,(int)$first+$i,(int)$padding);
        echo json_encode(['first'=>buildAssetTag($prefix,(int)$first,(int)$padding),'last'=>buildAssetTag($prefix,$last,(int)$padding),'quantity'=>(int)$quantity,'sample'=>$sample,'starting_number'=>(int)$first],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
    } catch (Throwable $exception) { http_response_code(400); echo json_encode(['error'=>$exception->getMessage()],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); }
    exit;
}

if (isset($_GET['serial_preview'])) {
    requirePermission('hardware','add');
    header('Content-Type: application/json; charset=utf-8');
    try {
        $prefix=normalizeAssetTagPrefix((string)($_GET['serial_prefix']??'SN'));
        $first=suggestedInternalSerialNumber($pdo,$prefix);
        $sample=[];
        preg_match('/^(.*-)([0-9]+)$/',$first,$match);$number=(int)($match[2]??1);
        for($i=0;$i<5;$i++)$sample[]=buildInternalSerialNumber($prefix,$number+$i);
        echo json_encode(['first'=>$first,'sample'=>$sample],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
    } catch(Throwable $exception) { http_response_code(400); echo json_encode(['error'=>$exception->getMessage()],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); }
    exit;
}

if (isset($_GET['reserved_assets'])) {
    requirePermission('hardware','hardware_assignment');
    requirePermission('employees','view');
    header('Content-Type: application/json; charset=utf-8');
    $productId=filter_var($_GET['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $term=trim((string)($_GET['q']??''));$term=function_exists('mb_substr')?mb_substr($term,0,100):substr($term,0,100);
    if(!$productId||strlen($term)<2){echo '[]';exit;}
    $reservedQuery=$pdo->prepare("SELECT ia.id,ia.asset_tag FROM inventory_assets ia JOIN asset_tag_jobs j ON j.id=ia.asset_tag_job_id
        WHERE ia.product_id=? AND ia.status='reserved' AND j.registration_type='reconciliation' AND ia.asset_tag LIKE ? ORDER BY ia.asset_tag LIMIT 20");
    $reservedQuery->execute([(int)$productId,'%'.$term.'%']);
    echo json_encode($reservedQuery->fetchAll(),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);exit;
}

if (isset($_GET['available_assets'])) {
    requirePermission('hardware','hardware_assignment');
    requirePermission('employees','view');
    header('Content-Type: application/json; charset=utf-8');
    $term=trim((string)($_GET['q']??''));$term=function_exists('mb_substr')?mb_substr($term,0,100):substr($term,0,100);
    if(strlen($term)<2){echo '[]';exit;}
    $like='%'.$term.'%';
    $availableQuery=$pdo->prepare("SELECT ia.id,ia.asset_tag,ia.serial_number,ia.internal_serial_number,ia.location,p.name product_name,c.name category_name
        FROM inventory_assets ia JOIN products p ON p.id=ia.product_id JOIN categories c ON c.id=p.category_id
        WHERE ia.status='available' AND p.active=1
          AND NOT EXISTS(SELECT 1 FROM assignments a WHERE a.asset_id=ia.id AND a.status='assigned' AND a.returned_at IS NULL)
          AND NOT EXISTS(SELECT 1 FROM active_hardware_assignments aha WHERE aha.asset_id=ia.id)
          AND (ia.asset_tag LIKE ? OR ia.serial_number LIKE ? OR ia.internal_serial_number LIKE ? OR ia.location LIKE ? OR p.name LIKE ? OR c.name LIKE ?)
        ORDER BY p.name,ia.asset_tag LIMIT 20");
    $availableQuery->execute(array_fill(0,6,$like));
    echo json_encode($availableQuery->fetchAll(),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);exit;
}

$canAddHardware = canPerform('hardware','add');
$canEditHardware = canPerform('hardware','edit');
$canAssignHardware = canPerform('hardware','hardware_assignment');
$canViewEmployees = canViewResource('employees');
$jobId = filter_var($_GET['job'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
$pageMessage = trim((string)($_GET['message'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$search = function_exists('mb_substr') ? mb_substr($search,0,100) : substr($search,0,100);
$categoryFilter=filter_var($_GET['category_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$productFilter=filter_var($_GET['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$assetPage=max(1,(int)($_GET['page']??1));$assetPageSize=50;
$pageTitle = 'Hardware Assets';
$safe = static fn(mixed $value): string => htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');

$jobs = $pdo->query("SELECT j.*,p.name product_name,u.full_name created_by_name FROM asset_tag_jobs j JOIN products p ON p.id=j.product_id JOIN users u ON u.id=j.created_by ORDER BY j.id DESC LIMIT 25")->fetchAll();
$products = $canAddHardware ? $pdo->query('SELECT id,name FROM products WHERE active=1 AND serialized=1 ORDER BY name,id')->fetchAll() : [];
$filterCategories=$pdo->query('SELECT DISTINCT c.id,c.name FROM categories c JOIN products p ON p.category_id=c.id JOIN inventory_assets ia ON ia.product_id=p.id ORDER BY c.name,c.id')->fetchAll();
$filterProducts=$pdo->query('SELECT DISTINCT p.id,p.name,p.category_id FROM products p JOIN inventory_assets ia ON ia.product_id=p.id ORDER BY p.name,p.id')->fetchAll();

$labelJob = filter_var($_GET['print_labels'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
if ($labelJob) {
    $jobStmt=$pdo->prepare('SELECT id,prefix,starting_number,quantity,padding_digits FROM asset_tag_jobs WHERE id=?');$jobStmt->execute([$labelJob]);$labelJobRow=$jobStmt->fetch();
    if (!$labelJobRow) { http_response_code(404); exit('Asset tag job not found.'); }
    $labelPage=max(1,(int)($_GET['label_page']??1));$pageSize=1000;$offset=($labelPage-1)*$pageSize;
    $labelQuery=$pdo->prepare('SELECT asset_tag,serial_number,internal_serial_number,location FROM inventory_assets WHERE asset_tag_job_id=? ORDER BY asset_tag LIMIT ? OFFSET ?');
    $labelQuery->bindValue(1,$labelJob,PDO::PARAM_INT);$labelQuery->bindValue(2,$pageSize,PDO::PARAM_INT);$labelQuery->bindValue(3,$offset,PDO::PARAM_INT);$labelQuery->execute();$labels=$labelQuery->fetchAll();
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Asset tag labels</title><style>
    @page{size:A4;margin:10mm}.label-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:5mm}.label{height:30mm;border:1px dashed #7b8794;border-radius:2mm;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;padding:2mm;overflow:hidden}.label strong{font:700 15pt Arial,sans-serif;overflow-wrap:anywhere}.label span{font:9pt Arial,sans-serif;margin-top:2mm}.actions{margin:12px 0;display:flex;gap:10px}@media print{.actions{display:none}.label{border:1px solid #cbd5e1}}
    </style></head><body><div class="actions"><button onclick="window.print()">Print labels</button><a href="hardware-assets.php?print_labels=<?=$labelJob?>&amp;label_page=<?=$labelPage+1?>">Next label sheet</a><a href="hardware-assets.php">Back to hardware</a></div><div class="label-grid"><?php foreach($labels as $label): ?><div class="label"><strong><?=$safe($label['asset_tag'])?></strong><span>Manufacturer serial: <?=$safe($label['serial_number']?:'Pending')?></span><span>Internal serial: <?=$safe($label['internal_serial_number']?:'—')?></span><span><?=$safe($label['location']?:'')?></span></div><?php endforeach; ?><?php if(!$labels): ?><p>No tags are available on this sheet.</p><?php endif; ?></div><script>window.addEventListener('load',()=>window.print());</script></body></html><?php
    exit;
}

$whereParts=[];$params=[];
if($categoryFilter){$whereParts[]='p.category_id=?';$params[]=$categoryFilter;}
if($productFilter){$whereParts[]='ia.product_id=?';$params[]=$productFilter;}
if ($search !== '') {
    $like='%'.$search.'%';
    $searchPart='(ia.asset_tag LIKE ? OR ia.serial_number LIKE ? OR ia.internal_serial_number LIKE ? OR p.name LIKE ? OR ia.location LIKE ?';
    array_push($params,$like,$like,$like,$like,$like);
    if ($canViewEmployees) { $searchPart.=' OR e.full_name LIKE ? OR e.employee_id LIKE ?';array_push($params,$like,$like); }
    $whereParts[]=$searchPart.')';
}
$where=$whereParts?' WHERE '.implode(' AND ',$whereParts):'';
$assetFrom=" FROM inventory_assets ia JOIN products p ON p.id=ia.product_id
    LEFT JOIN active_hardware_assignments aha ON aha.asset_id=ia.id
    LEFT JOIN assignments a ON a.id=aha.assignment_id
    LEFT JOIN employees e ON e.id=a.employee_record_id OR (a.employee_record_id IS NULL AND e.employee_id=a.employee_id)".$where;
$assetCountQuery=$pdo->prepare('SELECT COUNT(DISTINCT ia.id)'.$assetFrom);$assetCountQuery->execute($params);$assetCount=(int)$assetCountQuery->fetchColumn();$assetPages=max(1,(int)ceil($assetCount/$assetPageSize));$assetPage=min($assetPage,$assetPages);
$assetSqlBase="SELECT ia.id,ia.product_id,ia.asset_tag,ia.serial_number,ia.internal_serial_number,ia.status,ia.location,ia.asset_condition,ia.created_at,p.name product_name,p.category_id,
    e.id employee_pk,e.employee_id,e.full_name employee_name,e.department,e.location employee_location".$assetFrom.' ORDER BY ia.created_at DESC,ia.id DESC';
$assetSql=$assetSqlBase.' LIMIT '.$assetPageSize.' OFFSET '.(($assetPage-1)*$assetPageSize);
$assetQuery=$pdo->prepare($assetSql);$assetQuery->execute($params);$assetRows=$assetQuery->fetchAll();

if (($_GET['export']??'')==='csv' || ($_GET['export']??'')==='xlsx') {
    $exportType=(string)$_GET['export'];
    if ($exportType==='csv') {
        $exportSql=$assetSqlBase;
        $export=$pdo->prepare($exportSql,[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>false]);$export->execute($params);
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="hardware-assets-' . date('Y-m-d') . '.csv"');
        $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
        $headers=['Asset tag','Manufacturer serial number','Internal serial number','Product','Status','Location','Condition'];if($canViewEmployees)array_push($headers,'Employee ID','Employee name','Department');
        fputcsv($out,$headers);
        $csvValue=static function(mixed $value):string{$text=(string)($value??'');return preg_match('/^[=+@-]/',$text)?"'".$text:$text;};
        while($row=$export->fetch()){$values=[$row['asset_tag'],$row['serial_number'],$row['internal_serial_number'],$row['product_name'],$row['status'],$row['location'],$row['asset_condition']];if($canViewEmployees)array_push($values,$row['employee_id'],$row['employee_name'],$row['department']);fputcsv($out,array_map($csvValue,$values));}
        fclose($out);exit;
    }
    $autoload=__DIR__.'/vendor/autoload.php';if(!is_file($autoload)){http_response_code(503);exit('XLSX export is unavailable; install the project Composer dependencies.');}
    require_once $autoload;
    $export=$pdo->prepare($assetSqlBase.' LIMIT 50000');$export->execute($params);
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Hardware assets');
    $headers=['Asset tag','Manufacturer serial number','Internal serial number','Product','Status','Location','Condition'];if($canViewEmployees)array_push($headers,'Employee ID','Employee name','Department');
    foreach($headers as $column=>$header)$sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column+1).'1',$header,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $rowNumber=2;while($row=$export->fetch()){$values=[$row['asset_tag'],$row['serial_number'],$row['internal_serial_number'],$row['product_name'],$row['status'],$row['location'],$row['asset_condition']];if($canViewEmployees)array_push($values,$row['employee_id'],$row['employee_name'],$row['department']);foreach($values as $column=>$value)$sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column+1).$rowNumber,(string)($value??''),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$rowNumber++;}
    $sheet->freezePane('A2');$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);foreach(range('A',$sheet->getHighestColumn()) as $column)$sheet->getColumnDimension($column)->setAutoSize(true);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="hardware-assets-' . date('Y-m-d') . '.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');$book->disconnectWorksheets();exit;
}

$activeJobs=array_filter($jobs,static fn(array $job):bool=>in_array($job['status'],['pending','processing'],true));
$assignments=[];$assignmentCount=0;
$reconcileSearch=trim((string)($_GET['employee_q']??''));$reconcileSearch=function_exists('mb_substr')?mb_substr($reconcileSearch,0,100):substr($reconcileSearch,0,100);
if ($canViewEmployees && $canAssignHardware) {
    $reconcileWhere="a.asset_id IS NULL AND a.status='assigned' AND a.returned_at IS NULL AND p.serialized=1
        AND EXISTS(SELECT 1 FROM employees e2 WHERE e2.id=a.employee_record_id OR e2.employee_id=a.employee_id)";
    $reconcileParams=[];
    if($reconcileSearch!==''){$reconcileWhere.=" AND (e.full_name LIKE ? OR e.employee_id LIKE ? OR p.name LIKE ?)";$needle='%'.$reconcileSearch.'%';array_push($reconcileParams,$needle,$needle,$needle);}
    $countQuery=$pdo->prepare('SELECT COUNT(*) FROM assignments a JOIN products p ON p.id=a.product_id LEFT JOIN employees e ON e.id=a.employee_record_id OR (a.employee_record_id IS NULL AND e.employee_id=a.employee_id) WHERE '.$reconcileWhere);
    $countQuery->execute($reconcileParams);$assignmentCount=(int)$countQuery->fetchColumn();
    $assignmentQuery=$pdo->prepare("SELECT a.id,a.product_id,(a.quantity-a.returned_quantity) quantity,a.assigned_to,a.employee_id,a.department,a.location,a.assigned_at,p.name product_name,
        COALESCE(e.employee_id,a.employee_id) current_employee_id,COALESCE(e.full_name,a.assigned_to) current_name,COALESCE(e.department,a.department) current_department,COALESCE(e.location,a.location) current_location
        FROM assignments a JOIN products p ON p.id=a.product_id LEFT JOIN employees e ON e.id=a.employee_record_id OR (a.employee_record_id IS NULL AND e.employee_id=a.employee_id)
        WHERE {$reconcileWhere} ORDER BY p.name,a.assigned_at,a.id LIMIT 250");
    $assignmentQuery->execute($reconcileParams);$assignments=$assignmentQuery->fetchAll();
}

require __DIR__ . '/header.php';
?>
<section class="card asset-manager">
    <div class="section-heading"><div><p class="eyebrow">INVENTORY · SERIALIZED ASSETS</p><h2>Hardware Assets</h2></div><div class="asset-export-links"><a class="btn" href="hardware-assets.php?<?=htmlspecialchars(http_build_query(array_filter(['export'=>'csv','q'=>$search?:null,'category_id'=>$categoryFilter?:null,'product_id'=>$productFilter?:null])))?>">Export CSV</a><a class="btn" href="hardware-assets.php?<?=htmlspecialchars(http_build_query(array_filter(['export'=>'xlsx','q'=>$search?:null,'category_id'=>$categoryFilter?:null,'product_id'=>$productFilter?:null])))?>">Export XLSX</a></div></div>
    <?php if($pageMessage): ?><div class="alert success" role="status"><?=$safe($pageMessage)?></div><?php endif; ?>
    <?php if($error): ?><div class="alert error" role="alert"><?=$safe($error)?></div><?php endif; ?>
    <form method="get" class="toolbar asset-search"><div><label for="asset-category">Category</label><select id="asset-category" name="category_id"><option value="">All categories</option><?php foreach($filterCategories as $option):?><option value="<?=$option['id']?>" <?=$categoryFilter===(int)$option['id']?'selected':''?>><?=$safe($option['name'])?></option><?php endforeach;?></select></div><div><label for="asset-product">Product</label><select id="asset-product" name="product_id"><option value="">All products</option><?php foreach($filterProducts as $option):?><option value="<?=$option['id']?>" <?=$productFilter===(int)$option['id']?'selected':''?>><?=$safe($option['name'])?></option><?php endforeach;?></select></div><div><label for="asset-search">Search tag, serial, product, employee or location</label><input id="asset-search" name="q" value="<?=$safe($search)?>" maxlength="100"></div><button class="btn" type="submit">Apply filters</button><?php if($search!==''||$categoryFilter||$productFilter): ?><a class="btn" href="hardware-assets.php">Clear</a><?php endif; ?></form>
    <p class="muted">Showing <?=count($assetRows)?> of <?=$assetCount?> assets · page <?=$assetPage?> of <?=$assetPages?></p>
    <?php if($canAddHardware): ?><details class="asset-bulk-panel asset-single-panel" <?=$error?'open':''?>><summary class="btn btn-glow">Register Hardware</summary><p class="muted">Register one physical asset without changing stock quantities. Asset tags and manufacturer serials remain unique.</p><form method="post" class="profile-form asset-bulk-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><input type="hidden" name="action" value="register_asset"><div><label for="single-product">Serialized product</label><select id="single-product" name="product_id" required><option value="">Select product</option><?php foreach($products as $product): ?><option value="<?=$product['id']?>"><?=$safe($product['name'])?></option><?php endforeach; ?></select></div><div><label for="single-asset-tag">Asset tag</label><input id="single-asset-tag" name="asset_tag" maxlength="100" required></div><div><label for="single-serial-mode">Serial number mode</label><select id="single-serial-mode" name="serial_mode" data-single-serial-mode><option value="manual">Manual</option><option value="automatic">Automatic</option><option value="fill_later" selected>Fill Later</option></select></div><div data-single-manual><label for="single-serial-number">Manufacturer serial number</label><input id="single-serial-number" name="serial_number" maxlength="150"><small>Use the value printed by the manufacturer.</small></div><div data-single-automatic hidden><label for="single-serial-prefix">Internal serial prefix</label><input id="single-serial-prefix" name="serial_prefix" maxlength="60" value="SN"><small>Generated as an Internal Serial Number, not a manufacturer serial.</small><span class="muted" data-single-serial-preview></span></div><div><label for="single-location">Location</label><input id="single-location" name="asset_location" maxlength="150"></div><div><label for="single-condition">Condition</label><select id="single-condition" name="asset_condition"><option value="">Not recorded</option><option>New</option><option>Good</option><option>Fair</option><option>Poor</option></select></div><div class="form-wide"><label for="single-notes">Notes</label><textarea id="single-notes" name="asset_notes"></textarea></div><div class="form-wide"><button class="btn btn-glow" type="submit" <?=$products?'':'disabled'?>>Save asset</button></div></form></details><?php endif; ?>
    <?php if($canAddHardware): ?><details id="bulk-register" class="asset-bulk-panel" <?=$error?'open':''?>><summary class="btn btn-glow">Bulk Register Hardware</summary><p class="muted">Creates one asset record per unit. Asset tags are generated; manufacturer serial numbers remain blank. No stock transactions are created.</p>
        <form method="post" enctype="multipart/form-data" class="profile-form asset-bulk-form" id="asset-bulk-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><input type="hidden" name="action" value="create_job"><input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(32))?>">
            <div><label for="bulk-product">Serialized product</label><select id="bulk-product" name="product_id" required><option value="">Select product</option><?php foreach($products as $product): ?><option value="<?=$product['id']?>"><?=$safe($product['name'])?></option><?php endforeach; ?></select></div>
            <div><label for="bulk-prefix">Asset tag prefix</label><input id="bulk-prefix" name="prefix" maxlength="60" pattern="[A-Za-z0-9][A-Za-z0-9_-]*[A-Za-z0-9]|[A-Za-z0-9]" placeholder="DTPL-CPU" required></div>
            <div><label for="bulk-start">Starting number (optional)</label><input id="bulk-start" name="starting_number" type="number" min="1" step="1" placeholder="Next available"></div>
            <div><label for="bulk-quantity">Quantity</label><input id="bulk-quantity" name="quantity" type="number" min="1" max="<?=ASSET_TAG_MAX_BULK_QUANTITY?>" value="1" required></div>
            <div><label for="bulk-padding">Minimum zero-padding digits</label><select id="bulk-padding" name="padding_digits"><?php foreach([3,4,6] as $digits): ?><option value="<?=$digits?>" <?=$digits===3?'selected':''?>><?=$digits?></option><?php endforeach; ?><option value="1">1</option><option value="2">2</option><option value="5">5</option><option value="7">7</option><option value="8">8</option><option value="9">9</option><option value="10">10</option><option value="11">11</option><option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15">15</option><option value="16">16</option><option value="17">17</option><option value="18">18</option></select></div>
            <div><label for="bulk-type">Units to tag</label><select id="bulk-type" name="registration_type"><option value="available">Unregistered available units</option><option value="reconciliation">Existing assigned units (hold for explicit employee reconciliation)</option></select></div>
            <div><label for="bulk-location">Location (optional)</label><input id="bulk-location" name="asset_location" maxlength="150"></div>
            <div><label for="bulk-condition">Condition (optional)</label><select id="bulk-condition" name="asset_condition"><option value="">Not recorded</option><option>New</option><option>Good</option><option>Fair</option><option>Poor</option></select></div>
            <div><label for="bulk-serial-mode">Serial number mode</label><select id="bulk-serial-mode" name="serial_mode"><option value="manual">Manual manufacturer serials</option><option value="automatic">Automatic internal serials</option><option value="fill_later" selected>Fill Later</option></select></div>
            <div data-bulk-serial-prefix hidden><label for="bulk-serial-prefix">Internal serial prefix</label><input id="bulk-serial-prefix" name="serial_prefix" maxlength="60" value="SN"><small>Generated values are Internal Serial Numbers, not manufacturer serial numbers.</small></div>
            <div class="form-wide" data-bulk-manual-serials hidden><label for="bulk-manufacturer-serials">Manufacturer serial numbers</label><textarea id="bulk-manufacturer-serials" name="manufacturer_serials" rows="5" placeholder="One actual manufacturer serial number per line"></textarea><label for="bulk-manufacturer-file">Or import CSV/XLSX (serials in the first column)</label><input id="bulk-manufacturer-file" name="manufacturer_serial_file" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"><small>Duplicate values in this batch or existing records are rejected. A header row is optional.</small></div>
            <div class="form-wide"><div class="asset-tag-preview" aria-live="polite"><strong>Asset tag preview</strong><span id="preview-range">Enter a prefix to preview the first and last tags.</span><span id="preview-sample"></span><strong>Serial number preview</strong><span id="serial-preview">Choose a serial number mode.</span></div></div>
            <div class="form-wide"><button class="btn btn-glow" type="submit" <?=$products?'':'disabled'?>>Confirm and queue registration</button><span class="muted">Jobs run in batches of <?=assetTagBatchSize()?> and can be safely retried.</span></div>
        </form></details><?php endif; ?>
    <h3>Bulk registration jobs</h3><div class="table-scroll"><table class="asset-job-table"><thead><tr><th>Job</th><th>Product</th><th>Prefix and range</th><th>Progress</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($jobs as $job): $lastNumber=(int)$job['starting_number']+(int)$job['quantity']-1;$firstTag=buildAssetTag($job['prefix'],(int)$job['starting_number'],(int)$job['padding_digits']);$lastTag=buildAssetTag($job['prefix'],$lastNumber,(int)$job['padding_digits']);$percent=(int)floor(100*(int)$job['next_offset']/max(1,(int)$job['quantity'])); ?><tr><td>#<?=$job['id']?></td><td><?=$safe($job['product_name'])?></td><td><?=$safe($firstTag)?> – <?=$safe($lastTag)?><small><?=$safe($job['registration_type']==='available'?'Available stock':'Pending reconciliation')?></small></td><td><progress value="<?=$job['next_offset']?>" max="<?=$job['quantity']?>"></progress> <?=$job['next_offset']?> / <?=$job['quantity']?> (<?=$percent?>%)</td><td><span class="state-pill <?= $safe($job['status']) ?>"><?=$safe(ucfirst($job['status']))?></span><?php if($job['last_error']): ?><small title="<?=$safe($job['last_error'])?>">Worker will retry (<?=$job['attempts']?>)</small><?php endif; ?></td><td><?=$safe($job['created_at'])?></td><td class="asset-job-actions"><a class="btn" href="hardware-assets.php?job=<?=$job['id']?>">Details</a><?php if((int)$job['next_offset']>0): ?><a class="btn" href="hardware-assets.php?print_labels=<?=$job['id']?>">Print labels</a><?php endif; ?><?php if($job['status']==='failed'&&$canEditHardware): ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><input type="hidden" name="action" value="retry_job"><input type="hidden" name="job_id" value="<?=$job['id']?>"><button class="btn" type="submit">Retry</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$jobs): ?><tr><td colspan="7" class="empty-state">No bulk tag jobs yet.</td></tr><?php endif; ?>
    </tbody></table></div>

    <?php if($canViewEmployees&&$canAssignHardware): ?><section class="asset-reconciliation"><h3>Assigned units awaiting reconciliation</h3><p class="muted">Select a registered tag for the employee shown. Each action associates one tag with one existing assigned unit and does not change stock. Tag search returns at most 20 matches.</p><form method="get" class="toolbar asset-search"><label for="reconcile-employee-search">Find assignment by employee or product</label><input id="reconcile-employee-search" name="employee_q" value="<?=$safe($reconcileSearch)?>" maxlength="100"><button class="btn" type="submit">Find assignments</button></form><?php if(!$assignments): ?><p class="empty-state">No matching untagged active assignments need reconciliation.</p><?php else: ?><?php if($assignmentCount>count($assignments)): ?><p class="muted">Showing <?=$safe(count($assignments))?> of <?=$safe($assignmentCount)?> matching assignments.</p><?php endif; ?><div class="table-scroll"><table><thead><tr><th>Employee</th><th>Product</th><th>Remaining quantity</th><th>Assignment date</th><th>Associate one tag</th></tr></thead><tbody><?php foreach($assignments as $assignment): ?><tr><td><?=$safe($assignment['current_name'])?><small><?=$safe($assignment['current_employee_id'])?> · <?=$safe($assignment['current_department']?:'No department')?> · <?=$safe($assignment['current_location']?:'No location')?></small></td><td><?=$safe($assignment['product_name'])?></td><td><?=$assignment['quantity']?></td><td><?=$safe($assignment['assigned_at'])?></td><td><form method="post" class="asset-reconcile-form" data-reconcile-product="<?=$assignment['product_id']?>"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><input type="hidden" name="action" value="reconcile_asset"><input type="hidden" name="assignment_id" value="<?=$assignment['id']?>"><input type="hidden" name="asset_id" data-reconcile-asset-id required><input type="search" data-reconcile-asset-search autocomplete="off" placeholder="Search reserved asset tag" required><div class="asset-tag-suggestions" role="listbox" hidden></div><button class="btn" type="submit">Associate tag</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>

    <h3>Registered assets<?= $search!==''?' matching “'.$safe($search).'”':'' ?></h3><div class="table-scroll"><table class="hardware-assets-table"><thead><tr><th>Asset tag</th><th>Manufacturer serial number</th><th>Internal serial number</th><th>Product</th><th>Status</th><th>Location</th><th>Condition</th><?php if($canViewEmployees): ?><th>Employee</th><?php endif; ?><th>Asset details</th></tr></thead><tbody><?php foreach($assetRows as $asset): ?><tr><td><?=$safe($asset['asset_tag'])?></td><td><?=$safe($asset['serial_number']?:'Pending')?></td><td><?=$safe($asset['internal_serial_number']?:'—')?></td><td><a href="inventory-details.php?<?=http_build_query(['view'=>'product','category_id'=>(int)$asset['category_id'],'product_id'=>(int)$asset['product_id']])?>"><?=$safe($asset['product_name'])?></a></td><td><?=$safe(ucfirst($asset['status']))?></td><td><?=$safe($asset['location']?:'—')?></td><td><?=$safe($asset['asset_condition']?:'—')?></td><?php if($canViewEmployees): ?><td><?=$asset['employee_pk']?'<a href="employee.php?id='.(int)$asset['employee_pk'].'">'.$safe($asset['employee_name']).'</a>':'—'?></td><?php endif; ?><td><a class="btn" href="asset-details.php?id=<?=(int)$asset['id']?>">Details</a></td></tr><?php endforeach; ?><?php if(!$assetRows): ?><tr><td colspan="<?= $canViewEmployees?9:8 ?>" class="empty-state">No registered hardware matches these filters.</td></tr><?php endif; ?></tbody></table></div><p class="muted">CSV export streams all matching records; XLSX export is capped at 50,000 rows.</p><nav class="pagination" aria-label="Hardware asset pages"><?php for($n=1;$n<=$assetPages;$n++):$pageParams=$_GET;$pageParams['page']=$n;?><a class="btn" href="?<?=htmlspecialchars(http_build_query($pageParams),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" <?=$n===$assetPage?'aria-current="page"':''?>><?=$n?></a><?php endfor;?></nav>
</section>
<?php if($activeJobs): ?><script>window.setTimeout(()=>window.location.reload(),5000);</script><?php endif; ?>
<?php if($canAddHardware): ?><script>
(()=>{const form=document.getElementById('asset-bulk-form');if(!form)return;const prefix=document.getElementById('bulk-prefix'),start=document.getElementById('bulk-start'),quantity=document.getElementById('bulk-quantity'),padding=document.getElementById('bulk-padding'),range=document.getElementById('preview-range'),sample=document.getElementById('preview-sample'),mode=document.getElementById('bulk-serial-mode'),serialPrefix=document.getElementById('bulk-serial-prefix'),serialModePrefix=document.querySelector('[data-bulk-serial-prefix]'),manualBox=document.querySelector('[data-bulk-manual-serials]'),serialText=document.getElementById('bulk-manufacturer-serials'),serialFile=document.getElementById('bulk-manufacturer-file'),serialPreview=document.getElementById('serial-preview');let tagTimer,serialTimer;
const updateTag=()=>{clearTimeout(tagTimer);tagTimer=setTimeout(async()=>{if(!prefix.value.trim()){range.textContent='Enter a prefix to preview the first and last tags.';sample.textContent='';return;}const params=new URLSearchParams({preview:'1',prefix:prefix.value,starting_number:start.value,quantity:quantity.value,padding_digits:padding.value});try{const response=await fetch('hardware-assets.php?'+params.toString(),{headers:{Accept:'application/json'}});const result=await response.json();if(!response.ok)throw new Error(result.error||'Unable to preview this range.');range.textContent=result.first+' → '+result.last+' · '+result.quantity+' tags';sample.textContent='Sample: '+result.sample.join(', ');if(!start.value)start.dataset.suggested=result.starting_number;}catch(error){range.textContent=error.message;sample.textContent='';}},180);};
const updateMode=()=>{serialModePrefix.hidden=mode.value!=='automatic';manualBox.hidden=mode.value!=='manual';if(mode.value==='manual'){const count=serialFile.files.length?0:serialText.value.split(/\r?\n/).filter(value=>value.trim()).length;if(count)quantity.value=String(count);serialPreview.textContent=serialFile.files.length?'Serial count will be read from the selected import file.':count?count+' manufacturer serial(s) entered.':'Enter or import one manufacturer serial per asset.';}else if(mode.value==='automatic'){serialPreview.textContent='Previewing internal serial numbers…';clearTimeout(serialTimer);serialTimer=setTimeout(async()=>{try{const params=new URLSearchParams({serial_preview:'1',serial_prefix:serialPrefix.value});const response=await fetch('hardware-assets.php?'+params.toString(),{headers:{Accept:'application/json'}});const result=await response.json();if(!response.ok)throw new Error(result.error||'Unable to preview serials.');serialPreview.textContent='Internal Serial Numbers: '+result.sample.join(', ');}catch(error){serialPreview.textContent=error.message;}},180);}else serialPreview.textContent='Manufacturer serial number: Pending. No internal serial will be generated.';};
[prefix,start,quantity,padding].forEach(input=>input.addEventListener('input',updateTag));[prefix,quantity,padding].forEach(input=>input.addEventListener('change',updateTag));[mode,serialPrefix].forEach(input=>input.addEventListener('input',updateMode));mode.addEventListener('change',updateMode);serialText.addEventListener('input',updateMode);serialFile.addEventListener('change',updateMode);updateTag();updateMode();
const singleMode=document.querySelector('[data-single-serial-mode]'),singleManual=document.querySelector('[data-single-manual]'),singleAuto=document.querySelector('[data-single-automatic]'),singlePreview=document.querySelector('[data-single-serial-preview]'),singlePrefix=document.getElementById('single-serial-prefix');if(singleMode){const updateSingle=()=>{singleManual.hidden=singleMode.value!=='manual';singleAuto.hidden=singleMode.value!=='automatic';document.getElementById('single-serial-number').required=singleMode.value==='manual';if(singleMode.value==='automatic'){const params=new URLSearchParams({serial_preview:'1',serial_prefix:singlePrefix.value});fetch('hardware-assets.php?'+params.toString(),{headers:{Accept:'application/json'}}).then(response=>response.json()).then(result=>{singlePreview.textContent=result.first?'Preview: '+result.first:'Preview unavailable';}).catch(()=>{singlePreview.textContent='Preview unavailable';});}};singleMode.addEventListener('change',updateSingle);singlePrefix.addEventListener('input',updateSingle);updateSingle();}
})();
</script><?php endif; ?>
<?php if($canViewEmployees&&$canAssignHardware): ?><script>
(()=>{document.querySelectorAll('.asset-reconcile-form').forEach(form=>{const search=form.querySelector('[data-reconcile-asset-search]'),assetId=form.querySelector('[data-reconcile-asset-id]'),list=form.querySelector('.asset-tag-suggestions');let timer;search.addEventListener('input',()=>{assetId.value='';clearTimeout(timer);const term=search.value.trim();if(term.length<2){list.hidden=true;list.replaceChildren();return;}timer=setTimeout(async()=>{const query=new URLSearchParams({reserved_assets:'1',product_id:form.dataset.reconcileProduct,q:term});try{const response=await fetch('hardware-assets.php?'+query.toString(),{headers:{Accept:'application/json'}});const rows=await response.json();list.replaceChildren();rows.forEach(row=>{const option=document.createElement('button');option.type='button';option.className='asset-tag-option';option.setAttribute('role','option');option.textContent=row.asset_tag;option.addEventListener('click',()=>{assetId.value=row.id;search.value=row.asset_tag;list.hidden=true;});list.append(option);});if(!rows.length){const empty=document.createElement('span');empty.className='muted';empty.textContent='No reserved tags match this product.';list.append(empty);}list.hidden=false;}catch(_){list.hidden=true;}},180);});form.addEventListener('submit',event=>{if(!assetId.value){event.preventDefault();search.setCustomValidity('Choose a reserved asset tag from the suggestions.');search.reportValidity();}else search.setCustomValidity('');});search.addEventListener('input',()=>search.setCustomValidity(''));});})();
</script><?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
