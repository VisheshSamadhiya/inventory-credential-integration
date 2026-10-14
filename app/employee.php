<?php
declare(strict_types=1);
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/credential_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('employees', 'view');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = (string)($_POST['action'] ?? '');
    [$resource, $operation] = match ($postedAction) {
        'hardware_register' => ['hardware', 'add'],
        'hardware_assign', 'hardware_close' => ['hardware', 'hardware_assignment'],
        'hardware_replace' => ['hardware', 'hardware_assignment'],
        'software_add' => ['software', 'add'],
        'software_edit' => ['software', 'edit'],
        'software_uninstall' => ['software', 'delete'],
        default => ['employees', 'edit'],
    };
    requirePermission($resource, $operation);
    verifyCsrf();
}
$db = db();
$employeeId = filter_var($_GET['id'] ?? $_POST['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$employeeId) { http_response_code(404); exit('Employee not found.'); }
$employeeQuery = $db->prepare('SELECT id,employee_id,full_name,department,location,email,active,created_at,updated_at FROM employees WHERE id=?');
$employeeQuery->execute([$employeeId]);
$employee = $employeeQuery->fetch();
if (!$employee) { http_response_code(404); exit('Employee not found.'); }
$pageTitle = 'Employee Details';
$activeTab = in_array($_GET['tab'] ?? '', ['software', 'hardware'], true) ? (string)$_GET['tab'] : 'hardware';
$canViewHardware = canViewResource('hardware');
$canAssignHardware = canPerform('hardware', 'hardware_assignment');
$canRegisterHardware = canPerform('hardware', 'add');
$canEditHardware = canPerform('hardware', 'edit');
$canViewSoftware = canViewResource('software');
$canAddSoftware = canPerform('software', 'add');
$canEditSoftware = canPerform('software', 'edit');
$canDeleteSoftware = canPerform('software', 'delete');
$canViewCredentials = canViewResource('credentials');
$canManageEmployeeCredentials = $canViewCredentials || $canCreateCredentials || $canEditCredentials;
$canViewSoftwareTab = $canViewSoftware || $canManageEmployeeCredentials;
$canCreateCredentials = canPerform('credentials', 'create');
$canEditCredentials = canPerform('credentials', 'edit');
$canRevealCredentials = canPerform('credentials', 'reveal');
$canCreateEmployeeCredentials = $canCreateCredentials && (canAdmin() || credentialCanAccessDepartment(credentialDepartmentForEmployee((int)$employeeId)));
if (($activeTab === 'hardware' && !$canViewHardware) || ($activeTab === 'software' && !$canViewSoftwareTab)) $activeTab = $canViewHardware ? 'hardware' : 'software';

function recordEmployeeAssetStock(PDO $db, int $productId, string $type, string $reason, string $notes, int $actor): void {
    $lock=$db->prepare('SELECT id FROM products WHERE id=? FOR UPDATE');$lock->execute([$productId]);if(!$lock->fetchColumn())throw new DomainException('The hardware product is unavailable.');
    $last=$db->prepare('SELECT new_quantity FROM inventory_transactions WHERE product_id=? ORDER BY id DESC LIMIT 1');$last->execute([$productId]);$previous=(int)($last->fetchColumn()?:0);$movement=calculateInventoryChange($previous,$type,1);
    $stmt=$db->prepare('INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,notes,created_by,transaction_at) VALUES(?,?,?,?,?,?,?,?,NOW())');
    $stmt->execute([$productId,$type,$movement['recorded_quantity'],$previous,$movement['new_stock'],$reason,$notes,$actor]);
    recalculateInventoryMonths($productId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $postedAction;
    try {
        $db->beginTransaction();
        $lockedEmployee = $db->prepare('SELECT id,employee_id,full_name,department,location FROM employees WHERE id=? FOR UPDATE');
        $lockedEmployee->execute([$employeeId]);
        $person = $lockedEmployee->fetch();
        if (!$person) throw new DomainException('Employee was not found.');
        $actor = (int)currentUser()['id'];

        if ($action === 'hardware_register') {
            $productId=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $assetTag=trim((string)($_POST['asset_tag']??''));$serial=trim((string)($_POST['serial_number']??''));
            $assetLocation=trim((string)($_POST['asset_location']??''));$assetNotes=trim((string)($_POST['asset_notes']??''));
            if(!$productId||$assetTag===''||strlen($assetTag)>100||strlen($serial)>150||strlen($assetLocation)>150||strlen($assetNotes)>4000)throw new DomainException('Enter a product, asset tag, and valid hardware details.');
            $productQ=$db->prepare('SELECT id,name FROM products WHERE id=? AND active=1 FOR UPDATE');$productQ->execute([$productId]);$product=$productQ->fetch();if(!$product)throw new DomainException('Select an active product.');
            $tagQ=$db->prepare('SELECT id FROM inventory_assets WHERE asset_tag=? LIMIT 1');$tagQ->execute([$assetTag]);if($tagQ->fetchColumn())throw new DomainException('This asset tag is already registered.');
            $assetInsert=$db->prepare("INSERT INTO inventory_assets(product_id,asset_tag,serial_number,status,location,notes) VALUES(?,?,?,'available',?,?)");
            $assetInsert->execute([$productId,$assetTag,$serial?:null,$assetLocation?:$person['location'],$assetNotes?:null]);
            $newAsset=(int)$db->lastInsertId();audit('hardware_asset_registered','asset',$newAsset,$product['name'].' · '.$assetTag);
            setFlash('success','Hardware asset registered and available for assignment.');
        } elseif ($action === 'hardware_assign') {
            $assetId = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $condition = (string)($_POST['condition'] ?? '');
            if (!$assetId || !in_array($condition, ['New','Good','Fair','Poor'], true)) throw new DomainException('Choose an available asset and a valid condition.');
            $assetQ = $db->prepare('SELECT ia.id,ia.product_id,ia.asset_tag,ia.serial_number,ia.status,p.name product_name,p.category_id FROM inventory_assets ia JOIN products p ON p.id=ia.product_id WHERE ia.id=? FOR UPDATE');
            $assetQ->execute([$assetId]); $asset = $assetQ->fetch();
            if (!$asset) throw new DomainException('Choose a registered hardware asset.');
            $activeQ = $db->prepare("SELECT id FROM assignments WHERE asset_id=? AND status='assigned' LIMIT 1 FOR UPDATE");
            $activeQ->execute([$assetId]);
            $mappingQ=$db->prepare('SELECT assignment_id FROM active_hardware_assignments WHERE asset_id=? FOR UPDATE');$mappingQ->execute([$assetId]);
            if ($asset['status']!=='available'||$activeQ->fetchColumn()||$mappingQ->fetchColumn()) throw new DomainException('This hardware is already assigned to another employee.');
            $insert = $db->prepare("INSERT INTO assignments(product_id,asset_id,quantity,assigned_to,employee_id,employee_record_id,department,location,assigned_by,status,condition_at_assignment,notes) VALUES(?,?,1,?,?,?,?,?,?, 'assigned',?,?)");
            $insert->execute([$asset['product_id'],$assetId,$person['full_name'],$person['employee_id'],$employeeId,$person['department'],$person['location'],$actor,$condition,trim((string)($_POST['notes']??''))?:null]);
            $assignmentId=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO active_hardware_assignments(asset_id,assignment_id) VALUES(?,?)')->execute([$assetId,$assignmentId]);
            recordEmployeeAssetStock($db,(int)$asset['product_id'],'out','Assignment → '.$person['full_name'].' ('.$person['employee_id'].')','Hardware assignment #'.$assignmentId.'; asset #'.$assetId,$actor);
            $db->prepare("UPDATE inventory_assets SET status='assigned',location=? WHERE id=?")->execute([$person['location'],$assetId]);
            $db->prepare("INSERT INTO hardware_assignment_history(employee_id,assignment_id,action,reason,new_asset_id,performed_by) VALUES(?,?,'assigned',?,?,?)")
                ->execute([$employeeId,$assignmentId,trim((string)($_POST['reason']??''))?:null,$assetId,$actor]);
            audit('hardware_assigned','assignment',$assignmentId,'Asset #'.$assetId.' '.$asset['product_name'].' assigned to '.$person['full_name']);
            setFlash('success','Hardware assigned successfully.');
        } elseif ($action === 'hardware_replace') {
            $oldId=filter_var($_POST['assignment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $newId=filter_var($_POST['asset_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $reason=trim((string)($_POST['reason']??''));
            if (!$oldId||!$newId||$oldId===$newId||$reason==='') throw new DomainException('Select a different available asset and enter a replacement reason.');
            $oldQ=$db->prepare("SELECT a.id,a.asset_id,a.product_id FROM assignments a WHERE a.id=? AND a.employee_record_id=? AND a.status='assigned' FOR UPDATE");
            $oldQ->execute([$oldId,$employeeId]);$old=$oldQ->fetch();if(!$old)throw new DomainException('The current assignment was not found.');
            $newQ=$db->prepare("SELECT ia.id,ia.product_id,p.name product_name FROM inventory_assets ia JOIN products p ON p.id=ia.product_id WHERE ia.id=? AND ia.status='available' FOR UPDATE");
            $newQ->execute([$newId]);$new=$newQ->fetch();if(!$new)throw new DomainException('The replacement asset is no longer available.');
            $activeQ=$db->prepare("SELECT id FROM assignments WHERE asset_id=? AND status='assigned' LIMIT 1 FOR UPDATE");$activeQ->execute([$newId]);if($activeQ->fetchColumn())throw new DomainException('The replacement asset is already assigned.');
            $db->prepare('DELETE FROM active_hardware_assignments WHERE assignment_id=?')->execute([$oldId]);
            $db->prepare("UPDATE assignments SET status='replaced',returned_at=NOW() WHERE id=? AND status='assigned'")->execute([$oldId]);
            if($old['asset_id'])$db->prepare("UPDATE inventory_assets SET status='available' WHERE id=?")->execute([$old['asset_id']]);
            recordEmployeeAssetStock($db,(int)$old['product_id'],'in','Hardware returned for replacement → '.$person['full_name'],'Replacement of assignment #'.$oldId,$actor);
            $newCondition=(string)($_POST['condition']??'Good');
            if(!in_array($newCondition,['New','Good','Fair','Poor'],true))throw new DomainException('Choose a valid condition for the replacement asset.');
            $ins=$db->prepare("INSERT INTO assignments(product_id,asset_id,quantity,assigned_to,employee_id,employee_record_id,department,location,assigned_by,status,condition_at_assignment,notes) VALUES(?,?,1,?,?,?,?,?,?, 'assigned',?,?)");
            $ins->execute([$new['product_id'],$newId,$person['full_name'],$person['employee_id'],$employeeId,$person['department'],$person['location'],$actor,$newCondition,trim((string)($_POST['notes']??''))?:null]);
            $newAssignment=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO active_hardware_assignments(asset_id,assignment_id) VALUES(?,?)')->execute([$newId,$newAssignment]);
            recordEmployeeAssetStock($db,(int)$new['product_id'],'out','Replacement assignment → '.$person['full_name'].' ('.$person['employee_id'].')','Replacement assignment #'.$newAssignment.' for #'.$oldId,$actor);
            $db->prepare("UPDATE inventory_assets SET status='assigned',location=? WHERE id=?")->execute([$person['location'],$newId]);
            $db->prepare("INSERT INTO hardware_assignment_history(employee_id,assignment_id,action,reason,old_asset_id,new_asset_id,performed_by) VALUES(?,?,'replaced',?,?,?,?)")
                ->execute([$employeeId,$oldId,$reason,$old['asset_id'],$newId,$actor]);
            audit('hardware_replaced','assignment',$oldId,'Replacement assignment #'.$newAssignment.'; '.$reason);
            setFlash('success','Hardware replaced. Both assignment records were preserved.');
        } elseif ($action === 'hardware_close') {
            $assignmentId=filter_var($_POST['assignment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $operation=(string)($_POST['operation']??'');$reason=trim((string)($_POST['reason']??''));
            if(!$assignmentId||!in_array($operation,['returned','removed'],true)||($operation==='removed'&&$reason===''))throw new DomainException('Choose a valid action and enter a removal reason.');
            $q=$db->prepare("SELECT id,asset_id,product_id FROM assignments WHERE id=? AND employee_record_id=? AND status='assigned' FOR UPDATE");$q->execute([$assignmentId,$employeeId]);$assignment=$q->fetch();if(!$assignment)throw new DomainException('The active assignment was not found.');
            $db->prepare('DELETE FROM active_hardware_assignments WHERE assignment_id=?')->execute([$assignmentId]);
            $db->prepare('UPDATE assignments SET status=?,returned_at=NOW() WHERE id=?')->execute([$operation,$assignmentId]);
            recordEmployeeAssetStock($db,(int)$assignment['product_id'],'in','Hardware '.$operation.' → '.$person['full_name'],'Hardware assignment #'.$assignmentId.' '.$operation.($reason!==''?'; '.$reason:''),$actor);
            if($assignment['asset_id'])$db->prepare("UPDATE inventory_assets SET status='available' WHERE id=?")->execute([$assignment['asset_id']]);
            $db->prepare('INSERT INTO hardware_assignment_history(employee_id,assignment_id,action,reason,old_asset_id,performed_by) VALUES(?,?,?,?,?,?)')
                ->execute([$employeeId,$assignmentId,$operation,$reason?:null,$assignment['asset_id'],$actor]);
            audit('hardware_'.$operation,'assignment',$assignmentId,$reason?:'Hardware returned');
            setFlash('success',$operation==='returned'?'Hardware return recorded.':'Hardware removed from this employee.');
        } elseif ($action === 'software_add' || $action === 'software_edit') {
            $name=trim((string)($_POST['software_name']??''));$version=trim((string)($_POST['version']??''));$license=trim((string)($_POST['license_type']??''));$date=trim((string)($_POST['installation_date']??''));$notes=trim((string)($_POST['software_notes']??''));
            if($name===''||strlen($name)>180||strlen($version)>100||strlen($license)>100)throw new DomainException('Enter valid software details.');
            if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new DomainException('Enter a valid installation date.');
            if($date!==''){ $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);$errors=DateTimeImmutable::getLastErrors();if(!$parsed||($errors&&($errors['warning_count']||$errors['error_count']))||$parsed->format('Y-m-d')!==$date)throw new DomainException('Enter a valid installation date.'); }
            if($action==='software_add'){
                $s=$db->prepare("INSERT INTO software_installations(employee_id,software_name,version,license_type,installation_date,status,notes,created_by) VALUES(?,?,?,?,?,'installed',?,?)");$s->execute([$employeeId,$name,$version?:null,$license?:null,$date?:null,$notes?:null,$actor]);$softwareId=(int)$db->lastInsertId();$event='added';
            }else{
                $softwareId=filter_var($_POST['software_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$softwareId)throw new DomainException('Select a software record.');
                $existing=$db->prepare("SELECT id FROM software_installations WHERE id=? AND employee_id=? AND status='installed' FOR UPDATE");$existing->execute([$softwareId,$employeeId]);if(!$existing->fetchColumn())throw new DomainException('Installed software record was not found.');
                $s=$db->prepare('UPDATE software_installations SET software_name=?,version=?,license_type=?,installation_date=?,notes=? WHERE id=?');$s->execute([$name,$version?:null,$license?:null,$date?:null,$notes?:null,$softwareId]);$event='edited';
            }
            $snapshot=json_encode(['name'=>$name,'version'=>$version,'license_type'=>$license,'installation_date'=>$date,'notes'=>$notes],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $db->prepare('INSERT INTO software_history(software_installation_id,employee_id,action,details,performed_by) VALUES(?,?,?,?,?)')->execute([$softwareId,$employeeId,$event,$snapshot,$actor]);
            audit('software_'.$event,'software',$softwareId,$name);
            setFlash('success',$event==='added'?'Software added.':'Software details updated.');
        } elseif ($action === 'software_uninstall') {
            $softwareId=filter_var($_POST['software_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$softwareId)throw new DomainException('Select a software record.');
            $q=$db->prepare("SELECT software_name FROM software_installations WHERE id=? AND employee_id=? AND status='installed' FOR UPDATE");$q->execute([$softwareId,$employeeId]);$softwareName=$q->fetchColumn();if($softwareName===false)throw new DomainException('Installed software record was not found.');
            $db->prepare("UPDATE software_installations SET status='uninstalled' WHERE id=?")->execute([$softwareId]);
            $db->prepare("INSERT INTO software_history(software_installation_id,employee_id,action,details,performed_by) VALUES(?,?,'uninstalled',?,?)")->execute([$softwareId,$employeeId,(string)($_POST['reason']??''),$actor]);
            audit('software_uninstalled','software',$softwareId,(string)$softwareName);
            setFlash('success','Software uninstall recorded.');
        } else throw new DomainException('Unknown employee profile action.');
        $db->commit();
    } catch (DomainException $e) {
        if($db->inTransaction())$db->rollBack();setFlash('error',$e->getMessage());
    } catch (Throwable $e) {
        if($db->inTransaction())$db->rollBack();error_log('Employee profile update failed (employee_id='.(int)$employeeId.', action='.preg_replace('/[^a-z_]/','',$action).', type='.get_class($e).', code='.$e->getCode().'): '.$e->getMessage());
        $duplicateDetail=$e instanceof PDOException?(string)($e->errorInfo[2]??''):'';
        $message=$e instanceof PDOException && (string)$e->getCode()==='23000' && $action==='hardware_register' && str_contains($duplicateDetail,'asset_tag')
            ? 'This asset tag is already registered.'
            : ($e instanceof PDOException && (string)$e->getCode()==='23000' && $action==='hardware_register' && str_contains($duplicateDetail,'serial_number')
                ? 'That serial number is already registered.'
                : 'Unable to save the employee change. Please try again.');
        setFlash('error',$message);
    }
    $activeTab = str_starts_with($action,'software_') ? 'software' : 'hardware';
    header('Location: employee.php?id='.(int)$employeeId.'&tab='.$activeTab.'#'.$activeTab);exit;
}

$hardware=[];$availableAssets=[];$hardwareProducts=[];$hardwareHistory=[];
if($canViewHardware){
    $hardwareQ=$db->prepare("SELECT a.id assignment_id,a.asset_id,a.product_id,a.quantity,a.assigned_at,a.returned_at,a.status,a.condition_at_assignment,a.notes,p.name asset_name,c.name category_name,ia.asset_tag,ia.serial_number,ia.status asset_status,u.full_name assigned_by FROM assignments a JOIN products p ON p.id=a.product_id LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN inventory_assets ia ON ia.id=a.asset_id LEFT JOIN users u ON u.id=a.assigned_by WHERE a.employee_record_id=? AND a.status='assigned' ORDER BY a.assigned_at DESC,a.id DESC");$hardwareQ->execute([$employeeId]);$hardware=$hardwareQ->fetchAll();
    $availableAssets=$db->query("SELECT ia.id,ia.asset_tag,ia.serial_number,ia.location,ia.created_at,p.name product_name,c.name category_name FROM inventory_assets ia JOIN products p ON p.id=ia.product_id LEFT JOIN categories c ON c.id=p.category_id WHERE ia.status='available' AND NOT EXISTS(SELECT 1 FROM assignments a WHERE a.asset_id=ia.id AND a.status='assigned') AND NOT EXISTS(SELECT 1 FROM active_hardware_assignments aha WHERE aha.asset_id=ia.id) ORDER BY p.name,ia.asset_tag")->fetchAll();
    $hardwareProducts=$canRegisterHardware?$db->query('SELECT id,name FROM products WHERE active=1 ORDER BY name')->fetchAll():[];
    $historyQ=$db->prepare('SELECT h.action,h.reason,h.performed_at,u.full_name performed_by,oldp.name old_asset,newp.name new_asset,oldia.serial_number old_serial,newia.serial_number new_serial FROM hardware_assignment_history h LEFT JOIN users u ON u.id=h.performed_by LEFT JOIN inventory_assets oldia ON oldia.id=h.old_asset_id LEFT JOIN products oldp ON oldp.id=oldia.product_id LEFT JOIN inventory_assets newia ON newia.id=h.new_asset_id LEFT JOIN products newp ON newp.id=newia.product_id WHERE h.employee_id=? ORDER BY h.performed_at DESC,h.id DESC');$historyQ->execute([$employeeId]);$hardwareHistory=$historyQ->fetchAll();
}
$software=[];$softwareHistory=[];
if($canViewSoftware){$softwareQ=$db->prepare('SELECT id,software_name,version,license_type,installation_date,status,notes FROM software_installations WHERE employee_id=? ORDER BY (status="installed") DESC,software_name');$softwareQ->execute([$employeeId]);$software=$softwareQ->fetchAll();$softwareHistoryQ=$db->prepare('SELECT h.action,h.details,h.performed_at,u.full_name performed_by,s.software_name FROM software_history h JOIN software_installations s ON s.id=h.software_installation_id LEFT JOIN users u ON u.id=h.performed_by WHERE h.employee_id=? ORDER BY h.performed_at DESC');$softwareHistoryQ->execute([$employeeId]);$softwareHistory=$softwareHistoryQ->fetchAll();}
$employeeCredentials=[];
if($canViewCredentials){$credentialsQ=$db->prepare('SELECT c.id,c.employee_id,c.department_id,c.user_id,c.service_name,c.credential_username,c.software_installation_id,c.notes,si.software_name FROM credentials c LEFT JOIN software_installations si ON si.id=c.software_installation_id WHERE c.employee_id=? AND c.active=1 ORDER BY c.service_name');$credentialsQ->execute([$employeeId]);foreach($credentialsQ->fetchAll() as $credential){if(credentialCanAccess($credential))$employeeCredentials[]=$credential;}}
require __DIR__ . '/header.php';
?>
<div class="profile-intro"><div><p class="eyebrow">EMPLOYEE PROFILE</p><h2><?=htmlspecialchars($employee['full_name'])?></h2><p class="muted"><?=htmlspecialchars($employee['employee_id'])?> · <?=htmlspecialchars($employee['department']?:'No department')?> · <?=htmlspecialchars($employee['location']?:'No location')?></p></div><a class="btn" href="employees.php">Back to employees</a></div>
<section class="profile-summary card"><div><span>Employee ID</span><strong><?=htmlspecialchars($employee['employee_id'])?></strong></div><div><span>Department</span><strong><?=htmlspecialchars($employee['department']?:'—')?></strong></div><div><span>Location</span><strong><?=htmlspecialchars($employee['location']?:'—')?></strong></div><div><span>Email</span><strong><?= $employee['email']?'<a href="mailto:'.htmlspecialchars($employee['email'],ENT_QUOTES).'">'.htmlspecialchars($employee['email']).'</a>':'—' ?></strong></div><div><span>Employee status</span><strong><?= $employee['active']?'Active':'Inactive' ?></strong></div><div><span>Added</span><strong><?=htmlspecialchars($employee['created_at'])?></strong></div></section>
<div class="profile-tabs" role="tablist" aria-label="Employee assets"><?php if($canViewHardware): ?><a id="hardware-tab" class="profile-tab <?=$activeTab==='hardware'?'active':''?>" href="employee.php?id=<?=$employeeId?>&amp;tab=hardware#hardware" role="tab" aria-selected="<?=$activeTab==='hardware'?'true':'false'?>" aria-controls="hardware" tabindex="<?=$activeTab==='hardware'?'0':'-1'?>">Hardware <span class="count-chip"><?=count($hardware)?></span></a><?php endif; ?><?php if($canViewSoftwareTab): ?><a id="software-tab" class="profile-tab <?=$activeTab==='software'?'active':''?>" href="employee.php?id=<?=$employeeId?>&amp;tab=software#software" role="tab" aria-selected="<?=$activeTab==='software'?'true':'false'?>" aria-controls="software" tabindex="<?=$activeTab==='software'?'0':'-1'?>">Software <span class="count-chip"><?=count(array_filter($software,static fn($s)=>$s['status']==='installed'))?></span></a><?php endif; ?></div>
<?php if($canViewHardware): ?><section class="card profile-section" id="hardware" role="tabpanel" aria-labelledby="hardware-tab" <?=$activeTab==='hardware'?'':'hidden'?>> <div class="section-heading"><div><p class="eyebrow">DEVICES & EQUIPMENT</p><h2>Hardware <span class="count-chip"><?=count($hardware)?></span></h2></div></div>
<?php if($canRegisterHardware || $canAssignHardware): ?>
<div class="hardware-management">
    <div class="hardware-action-buttons" aria-label="Hardware actions">
        <?php if($canRegisterHardware): ?><button class="btn btn-glow" type="button" data-hardware-state="register" aria-controls="register-hardware-form" aria-expanded="false">Register hardware</button><?php endif; ?>
        <?php if($canAssignHardware): ?><button class="btn btn-glow" type="button" data-hardware-state="assign" aria-controls="assign-hardware-form" aria-expanded="false">Assign hardware</button><?php endif; ?>
    </div>
    <div class="hardware-form-panel">
        <?php if($canRegisterHardware): ?><form method="post" class="profile-form hardware-action-form" id="register-hardware-form" hidden>
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="action" value="hardware_register">
            <div><label>Product</label><select name="product_id" required><option value="">Select product</option><?php foreach($hardwareProducts as $p): ?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option><?php endforeach; ?></select></div>
            <div><label>Asset tag</label><input name="asset_tag" maxlength="100" required></div>
            <div><label>Serial number</label><input name="serial_number" maxlength="150"></div>
            <div><label>Location</label><input name="asset_location" maxlength="150" value="<?=htmlspecialchars($employee['location']??'')?>"></div>
            <div class="form-wide"><label>Notes</label><textarea name="asset_notes"></textarea></div>
            <button class="btn btn-glow" type="submit" <?=$hardwareProducts?'':'disabled'?>>Save asset</button>
        </form><?php endif; ?>
        <?php if($canAssignHardware): ?><form method="post" class="profile-form hardware-action-form" id="assign-hardware-form" hidden>
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="action" value="hardware_assign">
            <div><label for="new-asset">Available asset</label><select id="new-asset" name="asset_id" required><option value="">Select an asset</option><?php foreach($availableAssets as $a): ?><option value="<?=$a['id']?>" data-asset-tag="<?=htmlspecialchars($a['asset_tag'],ENT_QUOTES)?>"><?=htmlspecialchars($a['product_name'].' · '.$a['asset_tag'].' · '.($a['serial_number']?:'no serial'))?></option><?php endforeach; ?></select></div>
            <div><label for="selected-asset-tag">Asset tag</label><input id="selected-asset-tag" type="text" readonly aria-readonly="true" placeholder="Selected asset tag"></div>
            <div><label for="asset-condition">Condition</label><select id="asset-condition" name="condition" required><option>New</option><option selected>Good</option><option>Fair</option><option>Poor</option></select></div>
            <div><label for="assign-reason">Reason</label><input id="assign-reason" name="reason" maxlength="500"></div>
            <div class="form-wide"><label for="asset-notes">Notes</label><textarea id="asset-notes" name="notes"></textarea></div>
            <button class="btn btn-glow" type="submit" <?=$availableAssets?'':'disabled'?>>Assign asset</button>
            <?php if(!$availableAssets): ?><p class="muted">No unassigned hardware assets are available.</p><?php endif; ?>
        </form><?php endif; ?>
    </div>
</div>
<script>(function(){const buttons=Array.from(document.querySelectorAll('[data-hardware-state]'));const forms={register:document.getElementById('register-hardware-form'),assign:document.getElementById('assign-hardware-form')};let active=null;buttons.forEach(function(button){button.addEventListener('click',function(){const state=button.dataset.hardwareState;active=active===state?null:state;Object.keys(forms).forEach(function(key){if(forms[key])forms[key].hidden=key!==active;});buttons.forEach(function(item){const selected=item.dataset.hardwareState===active;item.setAttribute('aria-expanded',String(selected));item.classList.toggle('active',selected);});if(active&&forms[active]){const first=forms[active].querySelector('select,input:not([type="hidden"]),textarea');if(first)first.focus();}});});const assetSelect=document.getElementById('new-asset');const tagField=document.getElementById('selected-asset-tag');if(assetSelect&&tagField){assetSelect.addEventListener('change',function(){const option=assetSelect.selectedOptions[0];tagField.value=option?option.dataset.assetTag||'':'';});}})();</script>
<?php endif; ?>
<div class="hardware-available"><h3>Available hardware <span class="count-chip"><?=count($availableAssets)?></span></h3><?php if($availableAssets): ?><div class="table-scroll"><table><thead><tr><th>Product</th><th>Asset tag</th><th>Serial number</th><th>Category</th><th>Location</th><th>Added</th></tr></thead><tbody><?php foreach($availableAssets as $a): ?><tr><td><?=htmlspecialchars($a['product_name'])?></td><td><?=htmlspecialchars($a['asset_tag'])?></td><td><?=htmlspecialchars($a['serial_number']?:'—')?></td><td><?=htmlspecialchars($a['category_name']?:'—')?></td><td><?=htmlspecialchars($a['location']?:'—')?></td><td><?=htmlspecialchars($a['created_at'])?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="empty-state">No hardware assets are currently available.</p><?php endif; ?></div>
<div class="table-scroll"><table class="hardware-assigned-table"><thead><tr><th>Asset</th><th>Asset tag</th><th>Category</th><th>Serial number</th><th>Assigned date</th><th>Assigned by</th><th>Condition</th><th>Status</th><?php if($canAssignHardware): ?><th>Actions</th><?php endif; ?></tr></thead><tbody><?php foreach($hardware as $h): ?><tr><td><?=htmlspecialchars($h['asset_name'])?></td><td><?=htmlspecialchars($h['asset_tag']??'—')?></td><td><?=htmlspecialchars($h['category_name']??'—')?></td><td><?=htmlspecialchars($h['serial_number']??'—')?></td><td><?=htmlspecialchars($h['assigned_at'])?></td><td><?=htmlspecialchars($h['assigned_by']??'—')?></td><td><?=htmlspecialchars($h['condition_at_assignment']??'—')?></td><td><span class="state-pill assigned">Assigned</span></td><?php if($canAssignHardware): ?><td class="action-stack hardware-actions-cell"><div class="hardware-action-group"><?php if($canAssignHardware): ?><details><summary class="btn">Replace</summary><form method="post" class="mini-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="action" value="hardware_replace"><input type="hidden" name="assignment_id" value="<?=$h['assignment_id']?>"><label>Replacement asset</label><select name="asset_id" required><option value="">Select asset</option><?php foreach($availableAssets as $a): ?><option value="<?=$a['id']?>"><?=htmlspecialchars($a['product_name'].' · '.$a['asset_tag'])?></option><?php endforeach; ?></select><label>Reason</label><input name="reason" maxlength="500" required><label>New asset condition</label><select name="condition"><option>New</option><option selected>Good</option><option>Fair</option><option>Poor</option></select><button class="btn btn-glow" type="submit">Confirm replacement</button></form></details><?php endif; ?><?php if($canAssignHardware): ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="assignment_id" value="<?=$h['assignment_id']?>"><input type="hidden" name="operation" value="returned"><input type="hidden" name="action" value="hardware_close"><button class="btn btn-success" type="submit">Return</button></form><details><summary class="btn btn-danger">Remove</summary><form method="post" class="mini-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="assignment_id" value="<?=$h['assignment_id']?>"><input type="hidden" name="operation" value="removed"><input type="hidden" name="action" value="hardware_close"><label>Removal reason</label><input name="reason" maxlength="500" required><button class="btn" type="submit">Record removal</button></form></details><?php endif; ?></div></td><?php endif; ?></tr><?php endforeach; ?><?php if(!$hardware): ?><tr><td colspan="<?=($canAssignHardware)?9:8?>" class="empty-state">No hardware is currently assigned to this employee.</td></tr><?php endif; ?></tbody></table></div>
<details class="history-disclosure"><summary>Hardware history (<?=count($hardwareHistory)?> events)</summary><div class="table-scroll"><table><thead><tr><th>Action</th><th>Old asset</th><th>New asset</th><th>Reason</th><th>Performed by</th><th>Date</th></tr></thead><tbody><?php foreach($hardwareHistory as $h): ?><tr><td><?=htmlspecialchars(ucfirst($h['action']))?></td><td><?=htmlspecialchars(trim(($h['old_asset']??'').' '.($h['old_serial']??''))?:'—')?></td><td><?=htmlspecialchars(trim(($h['new_asset']??'').' '.($h['new_serial']??''))?:'—')?></td><td><?=htmlspecialchars($h['reason']??'—')?></td><td><?=htmlspecialchars($h['performed_by']??'System')?></td><td><?=htmlspecialchars($h['performed_at'])?></td></tr><?php endforeach; ?><?php if(!$hardwareHistory): ?><tr><td colspan="6" class="empty-state">Hardware events will appear here.</td></tr><?php endif; ?></tbody></table></div></details>
</section><?php endif; ?>

<?php if($canViewSoftwareTab): ?><section class="card profile-section" id="software" role="tabpanel" aria-labelledby="software-tab" <?=$activeTab==='software'?'':'hidden'?>><?php if($canViewSoftware): ?><div class="section-heading"><div><p class="eyebrow">APPLICATIONS & LICENSES</p><h2>Software <span class="count-chip"><?=count(array_filter($software,static fn($s)=>$s['status']==='installed'))?></span></h2></div><?php if($canAddSoftware): ?><button type="button" class="btn btn-glow" onclick="document.getElementById('software-add').showModal()">Add software</button><?php endif; ?></div>
<div class="table-scroll"><table><thead><tr><th>Software</th><th>Version</th><th>License type</th><th>Installation date</th><th>Status</th><th>Notes</th><?php if($canEditSoftware || $canDeleteSoftware): ?><th>Actions</th><?php endif; ?></tr></thead><tbody><?php foreach($software as $s): ?><tr><td><?=htmlspecialchars($s['software_name'])?></td><td><?=htmlspecialchars($s['version']??'—')?></td><td><?=htmlspecialchars($s['license_type']??'—')?></td><td><?=htmlspecialchars($s['installation_date']??'—')?></td><td><span class="state-pill <?=htmlspecialchars($s['status'])?>"><?=htmlspecialchars(ucfirst($s['status']))?></span></td><td><?=htmlspecialchars($s['notes']??'—')?></td><?php if($canEditSoftware || $canDeleteSoftware): ?><td class="action-stack software-actions-cell"><?php if($s['status']==='installed'): ?><div class="software-row-actions"><?php if($canEditSoftware): ?><button type="button" class="btn" data-edit-software='<?=htmlspecialchars(json_encode($s,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP),ENT_QUOTES)?>'>Edit</button><?php endif; ?><?php if($canDeleteSoftware): ?><form method="post" onsubmit="return confirm('Record this software as uninstalled?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="action" value="software_uninstall"><input type="hidden" name="software_id" value="<?=$s['id']?>"><input type="hidden" name="reason" value="Uninstalled by administrator"><button class="btn btn-danger" type="submit">Uninstall</button></form><?php endif; ?></div><?php else: ?>—<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?><?php if(!$software): ?><tr><td colspan="<?=($canEditSoftware || $canDeleteSoftware)?7:6?>" class="empty-state">No software records. Add an installation to get started.</td></tr><?php endif; ?></tbody></table></div>
<details class="history-disclosure"><summary>Software history (<?=count($softwareHistory)?> events)</summary><div class="table-scroll"><table><thead><tr><th>Software</th><th>Action</th><th>Details</th><th>Performed by</th><th>Date</th></tr></thead><tbody><?php foreach($softwareHistory as $h): ?><tr><td><?=htmlspecialchars($h['software_name'])?></td><td><?=htmlspecialchars(ucfirst($h['action']))?></td><td><code><?=htmlspecialchars($h['details']??'—')?></code></td><td><?=htmlspecialchars($h['performed_by']??'System')?></td><td><?=htmlspecialchars($h['performed_at'])?></td></tr><?php endforeach; ?><?php if(!$softwareHistory): ?><tr><td colspan="5" class="empty-state">Software changes will appear here.</td></tr><?php endif; ?></tbody></table></div></details>
<?php endif; ?>
<?php if($canManageEmployeeCredentials): ?><section class="credential-employee-section"><div class="section-heading"><div><p class="eyebrow">SHARED ACCESS RECORDS</p><h3>Associated Credentials <span class="count-chip"><?=count($employeeCredentials)?></span></h3></div><div class="credential-actions"><?php if($canCreateEmployeeCredentials): ?><a class="btn btn-glow" href="credentials.php?employee_id=<?=$employeeId?>&amp;add=1">Add credential</a><?php endif; ?><?php if($canViewCredentials): ?><a class="btn" href="credentials.php?employee_id=<?=$employeeId?>">View all</a><?php endif; ?></div></div><?php if($canViewCredentials): ?><div class="table-scroll"><table><thead><tr><th>Service</th><th>Username</th><th>Software</th><th>Actions</th></tr></thead><tbody><?php foreach($employeeCredentials as $credential): ?><tr><td><?=htmlspecialchars($credential['service_name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($credential['credential_username']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($credential['software_name']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><div class="credential-actions"><a class="btn" href="credentials.php?employee_id=<?=$employeeId?>&amp;view=<?=$credential['id']?>">View</a><?php if($canRevealCredentials): ?><button class="btn" type="button" data-reveal-credential="<?=$credential['id']?>">Reveal</button><?php endif; ?><?php if($canEditCredentials): ?><a class="btn" href="credentials.php?edit=<?=$credential['id']?>">Edit</a><?php endif; ?></div></td></tr><?php endforeach; ?><?php if(!$employeeCredentials): ?><tr><td colspan="4" class="empty-state">No credentials are associated with this employee.</td></tr><?php endif; ?></tbody></table></div><?php else: ?><p class="muted">Credential records require the Credentials view permission.</p><?php endif; ?></section><?php endif; ?>
</section><?php endif; ?>
<?php if($canAddSoftware || $canEditSoftware): ?><dialog class="employee-dialog software-dialog" id="software-add"><div class="dialog-heading"><h2 id="software-dialog-heading">Add software</h2><button class="icon-button" type="button" data-close-dialog="software-add" aria-label="Close">×</button></div><form method="post" class="profile-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="action" id="software-action" value="software_add"><input type="hidden" name="software_id" id="software-id"><div><label for="software-name">Software name</label><input id="software-name" name="software_name" maxlength="180" required></div><div><label for="software-version">Version</label><input id="software-version" name="version" maxlength="100"></div><div><label for="software-license">License type</label><input id="software-license" name="license_type" maxlength="100" placeholder="Subscription, perpetual…"></div><div><label for="software-date">Installation date</label><input id="software-date" name="installation_date" type="date"></div><div class="form-wide"><label for="software-notes">Notes</label><textarea id="software-notes" name="software_notes"></textarea></div><div class="form-actions"><button class="btn btn-glow" type="submit">Save software</button></div></form></dialog>
<script>(function(){const dialog=document.getElementById('software-add');document.querySelectorAll('[data-close-dialog]').forEach(b=>b.addEventListener('click',()=>dialog.close()));dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close()});document.querySelectorAll('[data-edit-software]').forEach(b=>b.addEventListener('click',()=>{const s=JSON.parse(b.dataset.editSoftware);document.getElementById('software-dialog-heading').textContent='Edit software';document.getElementById('software-action').value='software_edit';document.getElementById('software-id').value=s.id;document.getElementById('software-name').value=s.software_name;document.getElementById('software-version').value=s.version||'';document.getElementById('software-license').value=s.license_type||'';document.getElementById('software-date').value=s.installation_date||'';document.getElementById('software-notes').value=s.notes||'';dialog.showModal()}));dialog.addEventListener('close',()=>{dialog.querySelector('form').reset();document.getElementById('software-dialog-heading').textContent='Add software';document.getElementById('software-action').value='software_add';document.getElementById('software-id').value=''})})();</script><?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
