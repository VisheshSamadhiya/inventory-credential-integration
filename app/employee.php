<?php
declare(strict_types=1);
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/credential_helpers.php';
require_once __DIR__ . '/credential_totp_helpers.php';
require_once __DIR__ . '/employee_software_credentials.php';
require_once __DIR__ . '/hardware_assignment_helpers.php';
require_once __DIR__ . '/employee_hardware_valuation.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('employees', 'view');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = trim((string)($_POST['credential_action'] ?? '')) ?: (string)($_POST['action'] ?? '');
    $credentialPermission = employeeCredentialActionPermission($postedAction);
    [$resource, $operation] = $credentialPermission ?? match ($postedAction) {
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
$canViewSoftware = canViewResource('software');
$canAddSoftware = canPerform('software', 'add');
$canEditSoftware = canPerform('software', 'edit');
$canDeleteSoftware = canPerform('software', 'delete');
$credentialTwoFactorReady = credentialTotpSessionVerified();
$canViewCredentials = canViewResource('credentials') && $credentialTwoFactorReady;
$canCreateCredentials = canPerform('credentials', 'create') && $credentialTwoFactorReady;
$canEditCredentials = canPerform('credentials', 'edit') && $credentialTwoFactorReady;
$canDeleteCredentials = canPerform('credentials', 'delete') && $credentialTwoFactorReady;
$canViewSoftwareTab = $canViewSoftware;
$canRevealCredentials = canPerform('credentials', 'reveal') && $credentialTwoFactorReady;
$canManageEmployeeCredentials = $canViewCredentials || $canCreateCredentials || $canEditCredentials || $canDeleteCredentials || $canRevealCredentials;
$canCreateEmployeeCredentials = $canCreateCredentials && (canAdmin() || credentialCanAccessDepartment(credentialDepartmentForEmployee((int)$employeeId)));
if (($activeTab === 'hardware' && !$canViewHardware) || ($activeTab === 'software' && !$canViewSoftwareTab)) $activeTab = $canViewHardware ? 'hardware' : 'software';

function recordEmployeeAssetStock(PDO $db, int $productId, string $type, string $reason, string $notes, int $actor, ?string $unitCost = null): int {
    $lock=$db->prepare('SELECT id FROM products WHERE id=? FOR UPDATE');$lock->execute([$productId]);if(!$lock->fetchColumn())throw new DomainException('The hardware product is unavailable.');
    $previous=currentStock($productId);$movement=calculateInventoryChange($previous,$type,1);
    $totalCost = $unitCost === null ? null : calculateTransactionPrice(1,$unitCost)['total_price'];
    $stmt=$db->prepare('INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,unit_price,total_price,notes,created_by,transaction_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())');
    $stmt->execute([$productId,$type,$movement['recorded_quantity'],$previous,$movement['new_stock'],$reason,$unitCost,$totalCost,$notes,$actor]);
    $transactionId=(int)$db->lastInsertId();
    recalculateInventoryMonths($productId);
    return $transactionId;
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

        if ($action === 'hardware_assign') {
            $assetId = filter_var($_POST['asset_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $condition = (string)($_POST['condition'] ?? '');
            if (!$assetId || !in_array($condition, ['New','Good','Fair','Poor'], true)) throw new DomainException('Choose an available asset and a valid condition.');
            $assetQ = $db->prepare('SELECT ia.id,ia.product_id,ia.asset_tag,ia.serial_number,ia.acquisition_unit_cost,ia.status,p.name product_name,p.category_id FROM inventory_assets ia JOIN products p ON p.id=ia.product_id WHERE ia.id=? FOR UPDATE');
            $assetQ->execute([$assetId]); $asset = $assetQ->fetch();
            if (!$asset) throw new DomainException('Choose a registered hardware asset.');
            $activeQ = $db->prepare("SELECT id FROM assignments WHERE asset_id=? AND status='assigned' LIMIT 1 FOR UPDATE");
            $activeQ->execute([$assetId]);
            $mappingQ=$db->prepare('SELECT assignment_id FROM active_hardware_assignments WHERE asset_id=? FOR UPDATE');$mappingQ->execute([$assetId]);
            if ($asset['status']!=='available'||$activeQ->fetchColumn()||$mappingQ->fetchColumn()) throw new DomainException('This hardware is already assigned to another employee.');
            $priorAssignmentQ=$db->prepare('SELECT id FROM assignments WHERE asset_id=? LIMIT 1');$priorAssignmentQ->execute([$assetId]);
            $historyAction=$priorAssignmentQ->fetchColumn()?'reassigned':'assigned';
            $assetCost=resolveEmployeeHardwareAssetCost($db,(int)$assetId);
            if($assetCost['unit_cost']!==null)$db->prepare('UPDATE inventory_assets SET acquisition_unit_cost=COALESCE(acquisition_unit_cost,?),acquisition_cost_layer_id=COALESCE(acquisition_cost_layer_id,?) WHERE id=?')->execute([$assetCost['unit_cost'],$assetCost['cost_layer_id'],$assetId]);
            $insert = $db->prepare("INSERT INTO assignments(product_id,asset_id,quantity,assigned_to,employee_id,employee_record_id,department,location,assigned_by,status,condition_at_assignment,notes) VALUES(?,?,1,?,?,?,?,?,?, 'assigned',?,?)");
            $insert->execute([$asset['product_id'],$assetId,$person['full_name'],$person['employee_id'],$employeeId,$person['department'],$person['location'],$actor,$condition,trim((string)($_POST['notes']??''))?:null]);
            $assignmentId=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO active_hardware_assignments(asset_id,assignment_id) VALUES(?,?)')->execute([$assetId,$assignmentId]);
            $transactionId=recordEmployeeAssetStock($db,(int)$asset['product_id'],'out','Assignment → '.$person['full_name'].' ('.$person['employee_id'].')','Hardware assignment #'.$assignmentId.'; asset #'.$assetId,$actor,$assetCost['unit_cost']===null?null:(string)$assetCost['unit_cost']);
            $db->prepare("UPDATE inventory_assets SET status='assigned',location=? WHERE id=?")->execute([$person['location'],$assetId]);
            recordEmployeeHardwareHistoryEvent($db,(int)$employeeId,$assignmentId,$historyAction,trim((string)($_POST['reason']??''))?:null,null,(int)$assetId,$actor,$transactionId,1,$assetCost['unit_cost']===null?0:1,$assetCost['unit_cost']===null?null:(string)$assetCost['unit_cost']);
            audit('hardware_assigned','assignment',$assignmentId,'Asset #'.$assetId.' '.$asset['product_name'].' assigned to '.$person['full_name']);
            setFlash('success','Hardware assigned successfully.');
        } elseif ($action === 'hardware_replace') {
            $oldId=filter_var($_POST['assignment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $newId=filter_var($_POST['asset_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $reason=trim((string)($_POST['reason']??''));
            if (!$oldId||!$newId||$reason==='') throw new DomainException('Select a different available asset and enter a replacement reason.');
            $oldQ=$db->prepare("SELECT a.id,a.asset_id,a.product_id,ia.acquisition_unit_cost FROM assignments a JOIN inventory_assets ia ON ia.id=a.asset_id WHERE a.id=? AND a.employee_record_id=? AND a.status='assigned' AND a.asset_id IS NOT NULL FOR UPDATE");
            $oldQ->execute([$oldId,$employeeId]);$old=$oldQ->fetch();if(!$old)throw new DomainException('Select an active registered hardware assignment to replace.');
            if ((int)$old['asset_id']===(int)$newId) throw new DomainException('Select a different available asset.');
            $oldCost=resolveEmployeeHardwareAssetCost($db,(int)$old['asset_id']);
            $newQ=$db->prepare("SELECT ia.id,ia.product_id,ia.acquisition_unit_cost,p.name product_name FROM inventory_assets ia JOIN products p ON p.id=ia.product_id WHERE ia.id=? AND ia.status='available' FOR UPDATE");
            $newQ->execute([$newId]);$new=$newQ->fetch();if(!$new)throw new DomainException('The replacement asset is no longer available.');
            $activeQ=$db->prepare("SELECT id FROM assignments WHERE asset_id=? AND status='assigned' LIMIT 1 FOR UPDATE");$activeQ->execute([$newId]);if($activeQ->fetchColumn())throw new DomainException('The replacement asset is already assigned.');
            $db->prepare('DELETE FROM active_hardware_assignments WHERE assignment_id=?')->execute([$oldId]);
            $db->prepare("UPDATE assignments SET status='replaced',returned_at=NOW() WHERE id=? AND status='assigned'")->execute([$oldId]);
            if($old['asset_id'])$db->prepare("UPDATE inventory_assets SET status='available' WHERE id=?")->execute([$old['asset_id']]);
            $oldTransactionId=recordEmployeeAssetStock($db,(int)$old['product_id'],'in','Hardware returned for replacement → '.$person['full_name'],'Replacement of assignment #'.$oldId,$actor,$oldCost['unit_cost']===null?null:(string)$oldCost['unit_cost']);
            recordEmployeeHardwareHistoryEvent($db,(int)$employeeId,(int)$oldId,'returned',$reason,(int)$old['asset_id'],null,$actor,$oldTransactionId,1,$oldCost['unit_cost']===null?0:1,$oldCost['unit_cost']===null?null:(string)$oldCost['unit_cost']);
            $newCondition=(string)($_POST['condition']??'Good');
            if(!in_array($newCondition,['New','Good','Fair','Poor'],true))throw new DomainException('Choose a valid condition for the replacement asset.');
            $ins=$db->prepare("INSERT INTO assignments(product_id,asset_id,quantity,assigned_to,employee_id,employee_record_id,department,location,assigned_by,status,condition_at_assignment,notes) VALUES(?,?,1,?,?,?,?,?,?, 'assigned',?,?)");
            $ins->execute([$new['product_id'],$newId,$person['full_name'],$person['employee_id'],$employeeId,$person['department'],$person['location'],$actor,$newCondition,trim((string)($_POST['notes']??''))?:null]);
            $newAssignment=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO active_hardware_assignments(asset_id,assignment_id) VALUES(?,?)')->execute([$newId,$newAssignment]);
            $newCost=resolveEmployeeHardwareAssetCost($db,(int)$newId);
            if($newCost['unit_cost']!==null)$db->prepare('UPDATE inventory_assets SET acquisition_unit_cost=COALESCE(acquisition_unit_cost,?),acquisition_cost_layer_id=COALESCE(acquisition_cost_layer_id,?) WHERE id=?')->execute([$newCost['unit_cost'],$newCost['cost_layer_id'],$newId]);
            $newTransactionId=recordEmployeeAssetStock($db,(int)$new['product_id'],'out','Replacement assignment → '.$person['full_name'].' ('.$person['employee_id'].')','Replacement assignment #'.$newAssignment.' for #'.$oldId,$actor,$newCost['unit_cost']===null?null:(string)$newCost['unit_cost']);
            $db->prepare("UPDATE inventory_assets SET status='assigned',location=? WHERE id=?")->execute([$person['location'],$newId]);
            recordEmployeeHardwareHistoryEvent($db,(int)$employeeId,$newAssignment,'assigned',$reason,null,(int)$newId,$actor,$newTransactionId,1,$newCost['unit_cost']===null?0:1,$newCost['unit_cost']===null?null:(string)$newCost['unit_cost']);
            audit('hardware_replaced','assignment',$oldId,'Replacement assignment #'.$newAssignment.'; '.$reason);
            setFlash('success','Hardware replaced. Both assignment records were preserved.');
        } elseif ($action === 'hardware_close') {
            $assignmentId=filter_var($_POST['assignment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $operation=(string)($_POST['operation']??'');$reason=trim((string)($_POST['reason']??''));
            if(!$assignmentId||!in_array($operation,['returned','removed'],true)||($operation==='removed'&&$reason===''))throw new DomainException('Choose a valid action and enter a removal reason.');
            $q=$db->prepare("SELECT a.id,a.asset_id,a.product_id,ia.acquisition_unit_cost FROM assignments a JOIN inventory_assets ia ON ia.id=a.asset_id WHERE a.id=? AND a.employee_record_id=? AND a.status='assigned' AND a.asset_id IS NOT NULL FOR UPDATE");$q->execute([$assignmentId,$employeeId]);$assignment=$q->fetch();if(!$assignment)throw new DomainException('Only a registered asset assignment can be returned or removed here. Reconcile bulk assignments from Hardware Assets first.');
            $assignmentCost=resolveEmployeeHardwareAssetCost($db,(int)$assignment['asset_id']);
            $db->prepare('DELETE FROM active_hardware_assignments WHERE assignment_id=?')->execute([$assignmentId]);
            $db->prepare('UPDATE assignments SET status=?,returned_at=NOW() WHERE id=?')->execute([$operation,$assignmentId]);
            $transactionId=recordEmployeeAssetStock($db,(int)$assignment['product_id'],'in','Hardware '.$operation.' → '.$person['full_name'],'Hardware assignment #'.$assignmentId.' '.$operation.($reason!==''?'; '.$reason:''),$actor,$assignmentCost['unit_cost']===null?null:(string)$assignmentCost['unit_cost']);
            if($assignment['asset_id'])$db->prepare("UPDATE inventory_assets SET status='available' WHERE id=?")->execute([$assignment['asset_id']]);
            recordEmployeeHardwareHistoryEvent($db,(int)$employeeId,(int)$assignmentId,$operation,$reason?:null,(int)$assignment['asset_id'],null,$actor,$transactionId,1,$assignmentCost['unit_cost']===null?0:1,$assignmentCost['unit_cost']===null?null:(string)$assignmentCost['unit_cost']);
            audit('hardware_'.$operation,'assignment',$assignmentId,$reason?:'Hardware returned');
            setFlash('success',$operation==='returned'?'Hardware return recorded.':'Hardware removed from this employee.');
        } elseif (in_array($action, ['credential_create', 'credential_link', 'credential_update', 'credential_unlink', 'credential_delete'], true)) {
            $softwareId=filter_var($_POST['software_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$softwareId)throw new DomainException('Select a software record.');
            $softwareLock=$db->prepare("SELECT id,status FROM software_installations WHERE id=? AND employee_id=? FOR UPDATE");$softwareLock->execute([$softwareId,$employeeId]);$softwareRow=$softwareLock->fetch();
            if(!$softwareRow || ($softwareRow['status']!=='installed' && $action!=='credential_unlink' && $action!=='credential_delete'))throw new DomainException('Installed software record was not found.');
            if($action==='credential_create'){
                if($softwareRow['status']!=='installed')throw new DomainException('Credentials can only be added to installed software.');
                requireCredentialEmployeeAccess((int)$employeeId);
                $departmentId=credentialDepartmentForEmployee((int)$employeeId);
                if($departmentId!==null&&!credentialCanAccessDepartment($departmentId))throw new DomainException('You do not have access to this employee department.');
                $entries=normalizeEmployeeCredentialInputs([$_POST['credential']??($_POST['new_credentials'][0]??[])]);
                if(!$entries)throw new DomainException('Enter credential details.');
                $entry=$entries[0];
                $insert=$db->prepare('INSERT INTO credentials(employee_id,department_id,software_installation_id,service_name,service_url,credential_username,credential_password_enc,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
                $insert->execute(employeeSoftwareCredentialInsertValues($entry,(int)$employeeId,$departmentId?(int)$departmentId:null,(int)$softwareId,$actor));
                $credentialId=(int)$db->lastInsertId();auditCredentialChange('credential_created',$credentialId,$entry['service_name']);credentialAccessLog($credentialId,'created');
                setFlash('success','Credential added to software.');
            } else {
                $credentialId=filter_var($_POST['credential_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if(!$credentialId)throw new DomainException('Credential not found.');
                $credentialQuery=$db->prepare('SELECT * FROM credentials WHERE id=? AND employee_id=? FOR UPDATE');$credentialQuery->execute([$credentialId,$employeeId]);$credential=$credentialQuery->fetch();
                if(!$credential || !credentialCanAccess($credential))throw new DomainException('Credential not found.');
                if($action==='credential_link'){
                    if(!employeeCredentialCanLinkToSoftware($credential,(int)$employeeId,(int)$softwareId))throw new DomainException('Choose an unlinked credential belonging to this employee.');
                    requireCredentialEmployeeAccess((int)$employeeId);
                    $db->prepare('UPDATE credentials SET software_installation_id=?,updated_by=? WHERE id=?')->execute([(int)$softwareId,$actor,(int)$credentialId]);
                    auditCredentialChange('credential_linked',(int)$credentialId,(string)$credential['service_name']);credentialAccessLog((int)$credentialId,'linked');
                    setFlash('success','Existing credential linked to software.');
                } elseif($action==='credential_update'){
                    if((int)$credential['software_installation_id']!==(int)$softwareId)throw new DomainException('Credential is not linked to this software.');
                    $name=trim((string)($_POST['service_name']??''));$username=trim((string)($_POST['credential_username']??''));$password=(string)($_POST['credential_password']??'');$url=trim((string)($_POST['service_url']??''));$notes=trim((string)($_POST['credential_notes']??''));
                    if($name===''||mb_strlen($name)>180||mb_strlen($username)>190||mb_strlen($notes)>4000||strlen($url)>500)throw new DomainException('One or more credential fields are too long or missing.');
                    if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)))throw new DomainException('Enter a valid HTTP or HTTPS service URL.');
                    if($password!==''&&strlen($password)>4096)throw new DomainException('Password is too long.');
                    if($password!=='')$db->prepare('INSERT INTO credential_password_versions(credential_id,password_enc,changed_by,change_reason) VALUES(?,?,?,?)')->execute([(int)$credentialId,$credential['credential_password_enc'],$actor,'Password changed']);
                    $sql='UPDATE credentials SET service_name=?,service_url=?,credential_username=?,notes=?,updated_by=?';$params=[$name,$url?:null,$username?:null,$notes?:null,$actor];
                    if($password!==''){$sql.=',credential_password_enc=?';$params[]=encryptCredentialValue($password);}
                    $sql.=' WHERE id=?';$params[]=(int)$credentialId;$db->prepare($sql)->execute($params);
                    if($password!=='')auditCredentialChange('credential_password_changed',(int)$credentialId,$name);
                    auditCredentialChange('credential_updated',(int)$credentialId,$name);credentialAccessLog((int)$credentialId,'edited');setFlash('success','Credential updated.');
                } elseif($action==='credential_unlink'){
                    if((int)$credential['software_installation_id']!==(int)$softwareId)throw new DomainException('Credential is not linked to this software.');
                    $db->prepare('UPDATE credentials SET software_installation_id=NULL,updated_by=? WHERE id=?')->execute([$actor,(int)$credentialId]);
                    auditCredentialChange('credential_unlinked',(int)$credentialId,(string)$credential['service_name']);credentialAccessLog((int)$credentialId,'unlinked');setFlash('success','Credential unlinked. It remains associated with this employee.');
                } else {
                    if((int)$credential['software_installation_id']!==(int)$softwareId)throw new DomainException('Credential is not linked to this software.');
                    $db->prepare('UPDATE credentials SET active=0,updated_by=? WHERE id=?')->execute([$actor,(int)$credentialId]);
                    auditCredentialChange('credential_deleted',(int)$credentialId,(string)$credential['service_name']);credentialAccessLog((int)$credentialId,'deleted');setFlash('success','Credential removed from the active list.');
                }
            }
        } elseif ($action === 'software_add' || $action === 'software_edit') {
            $credentialChoice=employeeSoftwareCredentialChoice((string)($_POST['credential_mode']??''),$_POST['new_credentials']??[],$_POST['link_credential_id']??null);
            $newCredentialEntries=$credentialChoice['entries'];
            if($newCredentialEntries){
                requirePermission('credentials','create');requireCredentialEmployeeAccess((int)$employeeId);
                $credentialDepartment=credentialDepartmentForEmployee((int)$employeeId);
                if($credentialDepartment!==null&&!credentialCanAccessDepartment($credentialDepartment))throw new DomainException('You do not have access to this employee department.');
            } else {
                $credentialDepartment=credentialDepartmentForEmployee((int)$employeeId);
            }
            if($credentialChoice['mode']==='link')requirePermission('credentials','edit');
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
            if($credentialChoice['mode']==='link'){
                requireCredentialEmployeeAccess((int)$employeeId);
                if($credentialDepartment!==null&&!credentialCanAccessDepartment($credentialDepartment))throw new DomainException('You do not have access to this employee department.');
                $credentialQuery=$db->prepare('SELECT * FROM credentials WHERE id=? AND employee_id=? FOR UPDATE');$credentialQuery->execute([$credentialChoice['link_id'],$employeeId]);$credential=$credentialQuery->fetch();
                if(!$credential||!credentialCanAccess($credential)||!employeeCredentialCanLinkToSoftware($credential,(int)$employeeId,(int)$softwareId))throw new DomainException('Choose an unlinked credential belonging to this employee.');
                $db->prepare('UPDATE credentials SET software_installation_id=?,updated_by=? WHERE id=?')->execute([(int)$softwareId,$actor,(int)$credentialChoice['link_id']]);
                auditCredentialChange('credential_linked',(int)$credentialChoice['link_id'],(string)$credential['service_name']);credentialAccessLog((int)$credentialChoice['link_id'],'linked');
            }
            foreach($newCredentialEntries as $entry){
                $credentialInsert=$db->prepare('INSERT INTO credentials(employee_id,department_id,software_installation_id,service_name,service_url,credential_username,credential_password_enc,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
                $credentialInsert->execute(employeeSoftwareCredentialInsertValues($entry,(int)$employeeId,$credentialDepartment?(int)$credentialDepartment:null,(int)$softwareId,$actor));
                $credentialId=(int)$db->lastInsertId();auditCredentialChange('credential_created',$credentialId,$entry['service_name']);credentialAccessLog($credentialId,'created');
            }
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
        setFlash('error','Unable to save the employee change. Please try again.');
    }
    $activeTab = str_starts_with($action,'software_') || str_starts_with($action,'credential_') ? 'software' : 'hardware';
    header('Location: employee.php?id='.(int)$employeeId.'&tab='.$activeTab.'#'.$activeTab);exit;
}

$hardware=[];$hardwareHistory=[];$employeeHardwareAssignments=[];
$employeeHardwareCount=0;$employeeHardwareMetrics=['active'=>['cents'=>0,'covered'=>0,'units'=>0],'assigned'=>['cents'=>0,'covered'=>0,'units'=>0],'returned'=>['cents'=>0,'covered'=>0,'units'=>0],'active_count'=>0];
$employeeHardwareHistoryMetrics=['assigned'=>['cents'=>0,'covered'=>0,'units'=>0],'returned'=>['cents'=>0,'covered'=>0,'units'=>0]];
if($canViewHardware){
    $employee=$employee??[];
    $employeeCode=(string)($employee['employee_id']??'');
    $activeAssignmentQuantitySql=activeHardwareAssignmentQuantitySql('a');
    $assignmentsQ=$db->prepare("SELECT a.id assignment_id,a.asset_id,a.product_id,a.quantity,a.returned_quantity,{$activeAssignmentQuantitySql} active_assignment_quantity,a.assigned_at,a.returned_at,a.status,a.condition_at_assignment,a.notes,p.name asset_name,c.name category_name,ia.asset_tag,ia.serial_number,ia.internal_serial_number,ia.status asset_status,COALESCE(ia.acquisition_unit_cost,asset_layer.unit_cost,reconciliation.unit_cost) asset_unit_cost,COALESCE(ia.location,a.location,e.location) asset_location,u.full_name assigned_by,ca.id cost_allocation_id,ca.quantity allocation_quantity,ca.returned_quantity allocation_returned_quantity,ca.unit_cost allocation_unit_cost FROM assignments a JOIN products p ON p.id=a.product_id LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN inventory_assets ia ON ia.id=a.asset_id LEFT JOIN inventory_cost_layers asset_layer ON asset_layer.id=ia.acquisition_cost_layer_id LEFT JOIN (SELECT r.asset_id,r.unit_cost,r.id FROM inventory_cost_reconciliations r JOIN (SELECT asset_id,MAX(id) id FROM inventory_cost_reconciliations WHERE kind='asset' GROUP BY asset_id) latest ON latest.id=r.id) reconciliation ON reconciliation.asset_id=ia.id LEFT JOIN employees e ON e.id=a.employee_record_id LEFT JOIN users u ON u.id=a.assigned_by LEFT JOIN inventory_cost_allocations ca ON ca.assignment_id=a.id AND ca.source_type='assignment' WHERE (a.employee_record_id=? OR (a.employee_record_id IS NULL AND a.employee_id=?)) ORDER BY a.assigned_at DESC,a.id DESC,ca.id");
    $assignmentsQ->execute([$employeeId,$employeeCode]);
    $assignmentRowsById=[];
    foreach($assignmentsQ->fetchAll() as $assignmentRow){
        $id=(int)$assignmentRow['assignment_id'];
        if(!isset($assignmentRowsById[$id])){$assignmentRowsById[$id]=$assignmentRow;$assignmentRowsById[$id]['cost_allocations']=[];}
        if($assignmentRow['cost_allocation_id']!==null)$assignmentRowsById[$id]['cost_allocations'][]=['quantity'=>$assignmentRow['allocation_quantity'],'returned_quantity'=>$assignmentRow['allocation_returned_quantity'],'unit_cost'=>$assignmentRow['allocation_unit_cost']];
    }
    $employeeHardwareAssignments=array_values($assignmentRowsById);
    $employeeHardwareMetrics=employeeHardwareSummary($employeeHardwareAssignments);
    $employeeHardwareCount=$employeeHardwareMetrics['active_count'];
    $hardware=array_values(array_filter($employeeHardwareAssignments,static fn(array $row):bool=>$row['status']==='assigned'&&empty($row['returned_at'])));
    foreach($hardware as &$hardwareRow)$hardwareRow['cost_breakdown']=employeeHardwareAssignmentCostBreakdown($hardwareRow);
    unset($hardwareRow);
    // Prefer immutable event snapshots. Legacy rows may resolve only through an
    // exact asset cost, linked transaction price, or assignment cost allocation.
    // Never fall back to a product's current price or an unrelated receipt layer.
    $historyQ=$db->prepare("SELECT h.id event_id,h.assignment_id,h.action,h.reason,h.performed_at,h.transaction_id,
        COALESCE(h.event_quantity,ABS(event_tx.quantity),CASE WHEN h.action='returned' AND return_event_count.event_count=1 AND allocation_cost.returned_units>0 THEN allocation_cost.returned_units WHEN a.asset_id IS NOT NULL THEN 1 ELSE a.quantity END,1) event_quantity,
        CASE WHEN h.acquisition_value IS NOT NULL THEN LEAST(COALESCE(h.event_quantity,ABS(event_tx.quantity),CASE WHEN h.action='returned' AND return_event_count.event_count=1 AND allocation_cost.returned_units>0 THEN allocation_cost.returned_units WHEN a.asset_id IS NOT NULL THEN 1 ELSE a.quantity END,1),COALESCE(NULLIF(h.cost_covered_quantity,0),h.event_quantity,ABS(event_tx.quantity),1))
             WHEN COALESCE(h.cost_covered_quantity,0)>0 THEN h.cost_covered_quantity
             WHEN event_tx.total_price IS NOT NULL THEN COALESCE(h.event_quantity,ABS(event_tx.quantity),CASE WHEN h.action='returned' AND return_event_count.event_count=1 AND allocation_cost.returned_units>0 THEN allocation_cost.returned_units WHEN a.asset_id IS NOT NULL THEN 1 ELSE a.quantity END,1)
             WHEN h.action IN ('assigned','reassigned','tagged') AND allocation_cost.assigned_units>0 THEN LEAST(COALESCE(h.event_quantity,ABS(event_tx.quantity),CASE WHEN a.asset_id IS NOT NULL THEN 1 ELSE a.quantity END,1),allocation_cost.assigned_units)
             WHEN h.action='returned' AND return_event_count.event_count=1 AND allocation_cost.returned_units>0 THEN LEAST(COALESCE(h.event_quantity,ABS(event_tx.quantity),allocation_cost.returned_units,1),allocation_cost.returned_units)
             WHEN COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost,new_asset_reconciliation.unit_cost,old_asset.acquisition_unit_cost,old_asset_layer.unit_cost,old_asset_reconciliation.unit_cost,assigned_asset.acquisition_unit_cost,assigned_asset_layer.unit_cost,assigned_asset_reconciliation.unit_cost) IS NOT NULL THEN COALESCE(h.event_quantity,ABS(event_tx.quantity),CASE WHEN a.asset_id IS NOT NULL THEN 1 ELSE a.quantity END,1)
             ELSE 0 END cost_covered_quantity,
        CASE WHEN h.acquisition_value IS NOT NULL THEN h.acquisition_value
             WHEN event_tx.total_price IS NOT NULL THEN event_tx.total_price
             WHEN h.action IN ('assigned','reassigned','tagged') AND allocation_cost.assigned_units>0 THEN allocation_cost.assigned_value
             WHEN h.action='returned' AND return_event_count.event_count=1 AND allocation_cost.returned_units>0 THEN allocation_cost.returned_value
             WHEN h.action IN ('assigned','reassigned','tagged') AND COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost,new_asset_reconciliation.unit_cost,assigned_asset.acquisition_unit_cost,assigned_asset_layer.unit_cost,assigned_asset_reconciliation.unit_cost) IS NOT NULL THEN COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost,new_asset_reconciliation.unit_cost,assigned_asset.acquisition_unit_cost,assigned_asset_layer.unit_cost,assigned_asset_reconciliation.unit_cost)
             WHEN COALESCE(old_asset.acquisition_unit_cost,old_asset_layer.unit_cost,old_asset_reconciliation.unit_cost,new_asset.acquisition_unit_cost,new_asset_layer.unit_cost,new_asset_reconciliation.unit_cost,assigned_asset.acquisition_unit_cost,assigned_asset_layer.unit_cost,assigned_asset_reconciliation.unit_cost) IS NOT NULL THEN COALESCE(old_asset.acquisition_unit_cost,old_asset_layer.unit_cost,old_asset_reconciliation.unit_cost,new_asset.acquisition_unit_cost,new_asset_layer.unit_cost,new_asset_reconciliation.unit_cost,assigned_asset.acquisition_unit_cost,assigned_asset_layer.unit_cost,assigned_asset_reconciliation.unit_cost)
             ELSE NULL END acquisition_value,
        oldp.name old_asset,newp.name new_asset,assignment_product.name assignment_product,old_asset.asset_tag old_asset_tag,new_asset.asset_tag new_asset_tag,assigned_asset.asset_tag assigned_asset_tag,old_asset.serial_number old_serial,new_asset.serial_number new_serial,e.full_name employee_name,u.full_name performed_by
        FROM hardware_assignment_history h JOIN employees e ON e.id=h.employee_id JOIN assignments a ON a.id=h.assignment_id LEFT JOIN products assignment_product ON assignment_product.id=a.product_id LEFT JOIN users u ON u.id=h.performed_by
        LEFT JOIN inventory_assets old_asset ON old_asset.id=h.old_asset_id LEFT JOIN inventory_cost_layers old_asset_layer ON old_asset_layer.id=old_asset.acquisition_cost_layer_id
        LEFT JOIN (SELECT r.asset_id,r.unit_cost,r.id FROM inventory_cost_reconciliations r JOIN (SELECT asset_id,MAX(id) id FROM inventory_cost_reconciliations WHERE kind='asset' GROUP BY asset_id) latest ON latest.id=r.id) old_asset_reconciliation ON old_asset_reconciliation.asset_id=old_asset.id
        LEFT JOIN products oldp ON oldp.id=old_asset.product_id LEFT JOIN inventory_assets new_asset ON new_asset.id=h.new_asset_id LEFT JOIN inventory_cost_layers new_asset_layer ON new_asset_layer.id=new_asset.acquisition_cost_layer_id
        LEFT JOIN (SELECT r.asset_id,r.unit_cost,r.id FROM inventory_cost_reconciliations r JOIN (SELECT asset_id,MAX(id) id FROM inventory_cost_reconciliations WHERE kind='asset' GROUP BY asset_id) latest ON latest.id=r.id) new_asset_reconciliation ON new_asset_reconciliation.asset_id=new_asset.id LEFT JOIN products newp ON newp.id=new_asset.product_id
        LEFT JOIN inventory_assets assigned_asset ON assigned_asset.id=a.asset_id LEFT JOIN inventory_cost_layers assigned_asset_layer ON assigned_asset_layer.id=assigned_asset.acquisition_cost_layer_id
        LEFT JOIN (SELECT r.asset_id,r.unit_cost,r.id FROM inventory_cost_reconciliations r JOIN (SELECT asset_id,MAX(id) id FROM inventory_cost_reconciliations WHERE kind='asset' GROUP BY asset_id) latest ON latest.id=r.id) assigned_asset_reconciliation ON assigned_asset_reconciliation.asset_id=assigned_asset.id LEFT JOIN inventory_transactions event_tx ON event_tx.id=h.transaction_id
        LEFT JOIN (SELECT assignment_id,SUM(quantity) assigned_units,SUM(quantity*unit_cost) assigned_value,SUM(returned_quantity) returned_units,SUM(returned_quantity*unit_cost) returned_value FROM inventory_cost_allocations WHERE source_type='assignment' GROUP BY assignment_id) allocation_cost ON allocation_cost.assignment_id=a.id
        LEFT JOIN (SELECT assignment_id,COUNT(*) event_count FROM hardware_assignment_history WHERE action='returned' GROUP BY assignment_id) return_event_count ON return_event_count.assignment_id=a.id
        WHERE h.employee_id=? ORDER BY h.performed_at DESC,h.id DESC");
    $historyQ->execute([$employeeId]);$hardwareHistory=$historyQ->fetchAll();
    $employeeHardwareResolvedMetrics=employeeHardwareMergeHistoryMetrics($employeeHardwareAssignments,$hardwareHistory);
    $employeeHardwareMetrics['assigned']=$employeeHardwareResolvedMetrics['assigned'];
    $employeeHardwareMetrics['returned']=$employeeHardwareResolvedMetrics['returned'];
}
$software=[];$softwareHistory=[];
if($canViewSoftware){$softwareQ=$db->prepare('SELECT id,software_name,version,license_type,installation_date,status,notes FROM software_installations WHERE employee_id=? ORDER BY (status="installed") DESC,software_name');$softwareQ->execute([$employeeId]);$software=$softwareQ->fetchAll();$softwareHistoryQ=$db->prepare('SELECT h.action,h.details,h.performed_at,u.full_name performed_by,s.software_name FROM software_history h JOIN software_installations s ON s.id=h.software_installation_id LEFT JOIN users u ON u.id=h.performed_by WHERE h.employee_id=? ORDER BY h.performed_at DESC');$softwareHistoryQ->execute([$employeeId]);$softwareHistory=$softwareHistoryQ->fetchAll();}
$employeeCredentials=[];
if($canViewCredentials&&$canViewSoftware){$credentialsQ=$db->prepare('SELECT c.id,c.employee_id,c.department_id,c.user_id,c.service_name,c.service_url,c.credential_username,c.software_installation_id,c.notes,c.active,si.software_name FROM credentials c LEFT JOIN software_installations si ON si.id=c.software_installation_id WHERE c.employee_id=? AND c.active=1 ORDER BY c.service_name,c.id');$credentialsQ->execute([$employeeId]);foreach($credentialsQ->fetchAll() as $credential){if(credentialCanAccess($credential))$employeeCredentials[]=$credential;}}
require __DIR__ . '/header.php';
?>
<div class="profile-intro"><div><p class="eyebrow">EMPLOYEE PROFILE</p><h2><?=htmlspecialchars($employee['full_name'])?></h2><p class="muted"><?=htmlspecialchars($employee['employee_id'])?> · <?=htmlspecialchars($employee['department']?:'No department')?> · <?=htmlspecialchars($employee['location']?:'No location')?></p></div><a class="btn" href="employees.php">Back to employees</a></div>
<section class="profile-summary card"><div><span>Employee ID</span><strong><?=htmlspecialchars($employee['employee_id'])?></strong></div><div><span>Department</span><strong><?=htmlspecialchars($employee['department']?:'—')?></strong></div><div><span>Location</span><strong><?=htmlspecialchars($employee['location']?:'—')?></strong></div><div><span>Email</span><strong><?= $employee['email']?'<a href="mailto:'.htmlspecialchars($employee['email'],ENT_QUOTES).'">'.htmlspecialchars($employee['email']).'</a>':'—' ?></strong></div><div><span>Employee status</span><strong><?= $employee['active']?'Active':'Inactive' ?></strong></div><div><span>Added</span><strong><?=htmlspecialchars($employee['created_at'])?></strong></div></section>
<div class="profile-tabs" role="tablist" aria-label="Employee assets"><?php if($canViewHardware): ?><a id="hardware-tab" class="profile-tab <?=$activeTab==='hardware'?'active':''?>" href="employee.php?id=<?=$employeeId?>&amp;tab=hardware#hardware" role="tab" aria-selected="<?=$activeTab==='hardware'?'true':'false'?>" aria-controls="hardware" tabindex="<?=$activeTab==='hardware'?'0':'-1'?>">Hardware <span class="count-chip"><?=number_format($employeeHardwareCount)?></span></a><?php endif; ?><?php if($canViewSoftwareTab): ?><a id="software-tab" class="profile-tab <?=$activeTab==='software'?'active':''?>" href="employee.php?id=<?=$employeeId?>&amp;tab=software#software" role="tab" aria-selected="<?=$activeTab==='software'?'true':'false'?>" aria-controls="software" tabindex="<?=$activeTab==='software'?'0':'-1'?>">Software <span class="count-chip"><?=count(array_filter($software,static fn($s)=>$s['status']==='installed'))?></span></a><?php endif; ?></div>
<?php if($canViewHardware): ?><section class="card profile-section" id="hardware" role="tabpanel" aria-labelledby="hardware-tab" <?=$activeTab==='hardware'?'':'hidden'?>> <div class="section-heading"><div><p class="eyebrow">DEVICES & EQUIPMENT</p><h2>Hardware <span class="count-chip"><?=number_format($employeeHardwareCount)?></span></h2></div></div>
<?php if($canAssignHardware): ?>
<div class="hardware-management">
    <div class="hardware-action-buttons" aria-label="Hardware actions">
        <button class="btn btn-glow" type="button" data-hardware-state="assign" aria-controls="assign-hardware-form" aria-expanded="false">Assign Hardware</button>
    </div>
    <div class="hardware-form-panel">
        <form method="post" class="profile-form hardware-action-form" id="assign-hardware-form" hidden>
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="action" value="hardware_assign">
            <div class="form-wide asset-picker" data-asset-picker data-search-url="hardware-assets.php?available_assets=1"><label for="new-asset-search">Search available Inventory hardware</label><input id="new-asset-search" type="search" autocomplete="off" data-asset-search placeholder="Type an asset tag, serial, product or location" aria-autocomplete="list" aria-controls="new-asset-results"><input type="hidden" id="new-asset-id" name="asset_id" required data-asset-id><div id="new-asset-results" class="asset-tag-suggestions" role="listbox" hidden></div><p class="muted" data-asset-selection>No asset selected.</p></div>
            <div><label for="asset-condition">Condition</label><select id="asset-condition" name="condition" required><option>New</option><option selected>Good</option><option>Fair</option><option>Poor</option></select></div>
            <div><label for="assign-reason">Reason</label><input id="assign-reason" name="reason" maxlength="500"></div>
            <div class="form-wide"><label for="asset-notes">Notes</label><textarea id="asset-notes" name="notes"></textarea></div>
            <button class="btn btn-glow" type="submit">Assign asset</button>
        </form>
    </div>
</div>
<script>(function(){const button=document.querySelector('[data-hardware-state="assign"]'),form=document.getElementById('assign-hardware-form');if(button&&form)button.addEventListener('click',function(){const open=form.hidden;form.hidden=!open;button.setAttribute('aria-expanded',String(open));if(open)form.querySelector('[data-asset-search]').focus();});})();</script>
<?php endif; ?>
<div class="employee-hardware-valuation" aria-label="Employee hardware valuation">
    <article class="employee-hardware-metric"><span>Currently Assigned Hardware Value</span><strong><?=htmlspecialchars(employeeHardwareCostDisplay($employeeHardwareMetrics['active']['cents'],$employeeHardwareMetrics['active']['covered'],$employeeHardwareMetrics['active']['units']))?></strong></article>
    <article class="employee-hardware-metric"><span>Total Hardware Value Returned</span><strong><?=htmlspecialchars(employeeHardwareCostDisplay($employeeHardwareMetrics['returned']['cents'],$employeeHardwareMetrics['returned']['covered'],$employeeHardwareMetrics['returned']['units']))?></strong></article>
    <article class="employee-hardware-metric"><span>Total Hardware Value Assigned Historically</span><strong><?=htmlspecialchars(employeeHardwareCostDisplay($employeeHardwareMetrics['assigned']['cents'],$employeeHardwareMetrics['assigned']['covered'],$employeeHardwareMetrics['assigned']['units']))?></strong></article>
    <article class="employee-hardware-metric"><span>Active Assigned Hardware Count</span><strong><?=number_format($employeeHardwareMetrics['active_count'])?></strong></article>
</div>
<div class="table-scroll hardware-assigned-scroll"><table class="hardware-assigned-table <?=$canAssignHardware?'with-actions':''?>"><thead><tr><th>Assigned Product</th><th>Asset Tag</th><th>Category</th><th>Manufacturer Serial Number</th><th>Internal Serial Number</th><th>Assignment Date</th><th>Acquisition Value</th><th>Assigned By</th><th>Location</th><th>Condition</th><th>Status</th><?php if($canAssignHardware): ?><th>Actions</th><?php endif; ?></tr></thead><tbody><?php foreach($hardware as $h): $cost=$h['cost_breakdown']; ?><tr><td><?=htmlspecialchars($h['asset_name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td class="hardware-serial-cell"><?=htmlspecialchars($h['asset_tag']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($h['category_name']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td class="hardware-serial-cell"><?=htmlspecialchars($h['serial_number']?:'Pending',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td class="hardware-serial-cell"><?=htmlspecialchars($h['internal_serial_number']?:'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($h['assigned_at'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td class="hardware-value-cell"><?=htmlspecialchars(employeeHardwareCostDisplay($cost['active_cents'],$cost['active_covered'],$cost['active_units']),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($h['assigned_by']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($h['asset_location']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($h['condition_at_assignment']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><span class="state-pill assigned">Assigned</span></td><?php if($canAssignHardware): ?><td class="action-stack hardware-actions-cell"><?php if($h['asset_id']!==null): ?><select class="hardware-action-select" aria-label="Actions for <?=htmlspecialchars($h['asset_name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" data-hardware-action-select="<?=$h['assignment_id']?>"><option value="" selected>Actions</option><option value="replace">Replace Hardware</option><option value="return">Return Hardware</option><option value="remove">Remove Assignment</option></select>
<dialog class="remove-hardware-dialog" id="replace-hardware-<?=$h['assignment_id']?>" aria-labelledby="replace-hardware-title-<?=$h['assignment_id']?>"><h2 id="replace-hardware-title-<?=$h['assignment_id']?>">Replace assigned hardware</h2><p>Search Inventory for an eligible replacement asset.</p><form method="post" class="remove-hardware-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="action" value="hardware_replace"><input type="hidden" name="assignment_id" value="<?=$h['assignment_id']?>"><div class="asset-picker" data-asset-picker data-search-url="hardware-assets.php?available_assets=1"><label for="replacement-search-<?=$h['assignment_id']?>">Replacement asset</label><input id="replacement-search-<?=$h['assignment_id']?>" type="search" autocomplete="off" data-asset-search placeholder="Search tag, serial, product or location" aria-autocomplete="list"><input type="hidden" name="asset_id" required data-asset-id><div class="asset-tag-suggestions" role="listbox" hidden></div><p class="muted" data-asset-selection>No asset selected.</p></div><label for="replacement-reason-<?=$h['assignment_id']?>">Reason</label><input id="replacement-reason-<?=$h['assignment_id']?>" name="reason" maxlength="500" required><label for="replacement-condition-<?=$h['assignment_id']?>">New asset condition</label><select id="replacement-condition-<?=$h['assignment_id']?>" name="condition"><option>New</option><option selected>Good</option><option>Fair</option><option>Poor</option></select><div class="remove-hardware-actions"><button class="btn" type="button" data-close-hardware-dialog>Cancel</button><button class="btn btn-glow" type="submit">Confirm Replacement</button></div></form></dialog>
<dialog class="remove-hardware-dialog" id="return-hardware-<?=$h['assignment_id']?>" aria-labelledby="return-hardware-title-<?=$h['assignment_id']?>"><h2 id="return-hardware-title-<?=$h['assignment_id']?>">Return assigned hardware</h2><p>Confirm that this hardware should be returned from the employee.</p><form method="post" class="remove-hardware-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="assignment_id" value="<?=$h['assignment_id']?>"><input type="hidden" name="operation" value="returned"><input type="hidden" name="action" value="hardware_close"><div class="remove-hardware-actions"><button class="btn" type="button" data-close-hardware-dialog>Cancel</button><button class="btn btn-success" type="submit">Confirm Return</button></div></form></dialog>
<dialog class="remove-hardware-dialog" id="remove-hardware-<?=$h['assignment_id']?>" aria-labelledby="remove-hardware-title-<?=$h['assignment_id']?>"><h2 id="remove-hardware-title-<?=$h['assignment_id']?>">Remove assigned hardware</h2><p>Enter a reason for removing this asset from the employee.</p><form method="post" class="remove-hardware-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="employee_id" value="<?=$employeeId?>"><input type="hidden" name="assignment_id" value="<?=$h['assignment_id']?>"><input type="hidden" name="operation" value="removed"><input type="hidden" name="action" value="hardware_close"><label for="remove-reason-<?=$h['assignment_id']?>">Removal reason</label><input id="remove-reason-<?=$h['assignment_id']?>" name="reason" maxlength="500" required><div class="remove-hardware-actions"><button class="btn" type="button" data-close-hardware-dialog>Cancel</button><button class="btn btn-danger" type="submit">Confirm Remove</button></div></form></dialog><?php else: ?>—<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?><?php if(!$hardware): ?><tr><td colspan="<?=($canAssignHardware)?12:11?>" class="empty-state">No hardware is currently assigned to this employee.</td></tr><?php endif; ?></tbody></table></div>
<script>document.addEventListener('change',function(event){const selector=event.target.closest('[data-hardware-action-select]');if(!selector)return;const action=selector.value;selector.value='';if(!action)return;const ids={replace:'replace-hardware-',return:'return-hardware-',remove:'remove-hardware-'};const dialog=document.getElementById(ids[action]+selector.dataset.hardwareActionSelect);if(dialog&&typeof dialog.showModal==='function'){dialog.showModal();const first=dialog.querySelector('input:not([type="hidden"]),select');if(first)first.focus();}});document.addEventListener('click',function(event){const cancel=event.target.closest('[data-close-hardware-dialog]');if(cancel){const dialog=cancel.closest('dialog');if(dialog)dialog.close();}});</script>
<script>(function(){document.querySelectorAll('[data-asset-picker]').forEach(function(picker){const input=picker.querySelector('[data-asset-search]'),hidden=picker.querySelector('[data-asset-id]'),results=picker.querySelector('.asset-tag-suggestions'),selection=picker.querySelector('[data-asset-selection]');let timer;function close(){results.hidden=true;results.replaceChildren();}input.addEventListener('input',function(){hidden.value='';selection.textContent='No asset selected.';clearTimeout(timer);const term=input.value.trim();if(term.length<2){close();return;}timer=setTimeout(async function(){const url=new URL(picker.dataset.searchUrl,location.href);url.searchParams.set('q',term);try{const response=await fetch(url,{headers:{Accept:'application/json'}});if(!response.ok)throw new Error('Asset search unavailable.');const assets=await response.json();results.replaceChildren();assets.forEach(function(asset){const option=document.createElement('button');option.type='button';option.className='asset-tag-option';option.setAttribute('role','option');option.textContent=asset.product_name+' · '+asset.asset_tag+' · manufacturer '+(asset.serial_number||'Pending')+' · internal '+(asset.internal_serial_number||'—')+' · '+(asset.location||'No location');option.addEventListener('click',function(){hidden.value=asset.id;input.value=asset.asset_tag+' · '+asset.product_name;selection.textContent='Selected '+asset.asset_tag+' · '+asset.product_name;close();});results.append(option);});if(!assets.length){const empty=document.createElement('span');empty.className='muted';empty.textContent='No eligible available assets match that search.';results.append(empty);}results.hidden=false;}catch(_){close();}},180);});input.addEventListener('keydown',function(event){if(event.key==='Escape')close();if(event.key==='ArrowDown'){const first=results.querySelector('[role="option"]');if(first){event.preventDefault();first.focus();}}});picker.closest('form').addEventListener('submit',function(event){if(!hidden.value){event.preventDefault();input.setCustomValidity('Search and choose an available asset from Inventory.');input.reportValidity();}else input.setCustomValidity('');});input.addEventListener('input',function(){input.setCustomValidity('');});});})();</script>
<details class="history-disclosure"><summary>Hardware history (<?=count($hardwareHistory)?> events)</summary><div class="table-scroll hardware-history-scroll"><table class="hardware-history-table"><thead><tr><th>Date and time</th><th>Asset / product</th><th>Asset tag</th><th>Event</th><th>Employee involved</th><th>Acquisition value</th><th>Assignment / transaction reference</th><th>Reason</th><th>Performed by</th></tr></thead><tbody><?php foreach($hardwareHistory as $h): $eventCost=employeeHardwareCostDisplay(employeeHardwareMoneyCents($h['acquisition_value']??null)??0,(int)($h['cost_covered_quantity']??0),(int)($h['event_quantity']??1)); $eventAsset=(string)($h['new_asset']??$h['old_asset']??$h['assignment_product']??'—'); $eventTag=(string)($h['new_asset_tag']??$h['old_asset_tag']??$h['assigned_asset_tag']??''); ?><tr><td><?=htmlspecialchars($h['performed_at'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($eventAsset,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($eventTag?:'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars(ucfirst((string)$h['action']),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($h['employee_name']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($eventCost,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars('Assignment #'.(int)$h['assignment_id'].($h['transaction_id']?' · Transaction #'.(int)$h['transaction_id']:''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($h['reason']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($h['performed_by']??'System',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td></tr><?php endforeach; ?><?php if(!$hardwareHistory): ?><tr><td colspan="9" class="empty-state">Hardware events will appear here.</td></tr><?php endif; ?></tbody></table></div></details>
</section><?php endif; ?>

<?php if($canViewSoftwareTab): ?><section class="card profile-section" id="software" role="tabpanel" aria-labelledby="software-tab" <?=$activeTab==='software'?'':'hidden'?>><?php if($canViewSoftware): ?><div class="section-heading"><div><p class="eyebrow">APPLICATIONS & LICENSES</p><h2>Software <span class="count-chip"><?=count(array_filter($software,static fn($s)=>$s['status']==='installed'))?></span></h2></div><?php if($canAddSoftware): ?><button type="button" class="btn btn-glow" id="open-add-software">Add software</button><?php endif; ?></div>
<?php require __DIR__ . DIRECTORY_SEPARATOR . "employee_software_table.php"; ?>
<details class="history-disclosure"><summary>Software history (<?=count($softwareHistory)?> events)</summary><div class="table-scroll"><table><thead><tr><th>Software</th><th>Action</th><th>Details</th><th>Performed by</th><th>Date</th></tr></thead><tbody><?php foreach($softwareHistory as $h): ?><tr><td><?=htmlspecialchars($h['software_name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars(ucfirst($h['action']),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><code><?=htmlspecialchars($h['details']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></code></td><td><?=htmlspecialchars($h['performed_by']??'System',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=htmlspecialchars($h['performed_at'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td></tr><?php endforeach; ?><?php if(!$softwareHistory): ?><tr><td colspan="5" class="empty-state">Software changes will appear here.</td></tr><?php endif; ?></tbody></table></div></details>
<?php endif; ?>
</section><?php endif; ?>
<?php require __DIR__ . DIRECTORY_SEPARATOR . "employee_software_form.php"; ?>
