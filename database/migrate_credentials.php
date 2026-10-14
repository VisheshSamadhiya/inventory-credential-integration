<?php
declare(strict_types=1);

/*
 * Controlled, ciphertext-preserving import from the former Credential Manager DB.
 * Defaults to a read-only dry run. Never prints encrypted or decrypted values.
 * Apply only after mapping review and explicit production approval.
 */
$_SERVER['SCRIPT_NAME'] = '/setup.php';
require_once __DIR__ . '/../app/credential_helpers.php';

$options = array_slice($argv, 1);
$apply = in_array('--apply', $options, true);
if ($apply && getenv('ALLOW_CREDENTIAL_DATA_IMPORT') !== 'YES') {
    fwrite(STDERR, "Refusing import: set ALLOW_CREDENTIAL_DATA_IMPORT=YES after the approved backup and cutover plan.\n");
    exit(2);
}
$target = db();
if (in_array('--verify-target', $options, true)) {
    $count=0;foreach(rows($target,"SELECT credential_password_enc FROM credentials WHERE source_system='credential-manager'") as $row){decryptCredentialValue((string)$row['credential_password_enc']);$count++;}
    $historyCount=0;foreach(rows($target,"SELECT password_enc FROM credential_password_versions WHERE source_system='credential-manager'") as $row){decryptCredentialValue((string)$row['password_enc']);$historyCount++;}
    printf("Imported ciphertexts decrypted successfully without output: %d credentials, %d history values.\n",$count,$historyCount);exit(0);
}
$source = new PDO(
    'mysql:host=' . (getenv('SOURCE_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('SOURCE_DB_PORT') ?: '3306') . ';dbname=' . (getenv('SOURCE_DB_NAME') ?: 'credential_manager') . ';charset=utf8mb4',
    (string)getenv('SOURCE_DB_USER'), (string)getenv('SOURCE_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);
$source->exec('SET SESSION TRANSACTION READ ONLY');

function sourceHasTable(PDO $db, string $table): bool
{
    $q=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return (bool)$q->fetchColumn();
}
function sourceHasColumn(PDO $db, string $table, string $column): bool
{
    $q=$db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return (bool)$q->fetchColumn();
}
function rows(PDO $db,string $sql):array{return $db->query($sql)->fetchAll();}
function matchedUser(PDO $target,string $username):?int{$q=$target->prepare('SELECT id FROM users WHERE BINARY username=BINARY ? LIMIT 2');$q->execute([$username]);$r=$q->fetchAll();return count($r)===1?(int)$r[0]['id']:null;}
function matchedEmployee(PDO $target,string $employeeCode):?int{$q=$target->prepare('SELECT id FROM employees WHERE BINARY employee_id=BINARY ? LIMIT 2');$q->execute([$employeeCode]);$r=$q->fetchAll();return count($r)===1?(int)$r[0]['id']:null;}
function matchedDepartment(PDO $target,string $name):?int{$q=$target->prepare('SELECT id FROM credential_departments WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 2');$q->execute([$name]);$r=$q->fetchAll();return count($r)===1?(int)$r[0]['id']:null;}
function targetEmployeeDepartment(PDO $target,int $employeeId):?string{$q=$target->prepare('SELECT department FROM employees WHERE id=? LIMIT 1');$q->execute([$employeeId]);$v=$q->fetchColumn();return $v===false||$v===null?null:trim((string)$v);}
function targetDepartmentName(PDO $target,int $departmentId):?string{$q=$target->prepare('SELECT name FROM credential_departments WHERE id=? LIMIT 1');$q->execute([$departmentId]);$v=$q->fetchColumn();return $v===false?null:trim((string)$v);}
function oldMapping(PDO $target,string $entity,int $sourceId):?array{$q=$target->prepare("SELECT target_id,status FROM credential_migration_mappings WHERE source_system='credential-manager' AND source_entity=? AND source_id=?");$q->execute([$entity,$sourceId]);return $q->fetch()?:null;}
function rememberMapping(PDO $target,string $entity,int $sourceId,?int $targetId,string $status,?string $label=null):void{$q=$target->prepare("INSERT INTO credential_migration_mappings(source_system,source_entity,source_id,target_id,status,source_label) VALUES('credential-manager',?,?,?,?,?) ON DUPLICATE KEY UPDATE target_id=VALUES(target_id),status=VALUES(status),source_label=VALUES(source_label)");$q->execute([$entity,$sourceId,$targetId,$status,$label]);}
function chosenMapping(PDO $target,string $entity,int $sourceId,?int $suggested,?string $label=null):?int{$old=oldMapping($target,$entity,$sourceId);if($old&&in_array($old['status'],['matched','manual'],true)&&$old['target_id']!==null)return(int)$old['target_id'];return $suggested;}
function verifyCiphertexts(PDO $source):int{$count=0;foreach(rows($source,'SELECT credential_password_enc FROM credentials') as $r){decryptCredentialValue((string)$r['credential_password_enc']);$count++;}if(sourceHasTable($source,'credential_password_versions'))foreach(rows($source,'SELECT password_enc FROM credential_password_versions')as$r){decryptCredentialValue((string)$r['password_enc']);$count++;}return$count;}
function persistSourceMap(PDO $target,string $entity,int $id,?int $targetId,?string $label=null):void{$old=oldMapping($target,$entity,$id);if($old&&in_array($old['status'],['matched','manual'],true)&&$old['target_id']!==null)return;rememberMapping($target,$entity,$id,$targetId,$targetId===null?'unmapped':'matched',$label);}

$legacyDepartments=rows($source,'SELECT id,name FROM departments');
$legacyEmployees=rows($source,'SELECT id,employee_code,full_name,department_id FROM employees');
$legacyUsers=rows($source,'SELECT id,username,full_name FROM users');
$legacyCredentials=rows($source,'SELECT * FROM credentials');
$deptMap=[];$employeeMap=[];$userMap=[];$legacyEmployeeById=[];$unresolved=[];
foreach($legacyEmployees as $legacyEmployee)$legacyEmployeeById[(int)$legacyEmployee['id']]=$legacyEmployee;
foreach($legacyDepartments as $d){$suggested=matchedDepartment($target,(string)$d['name']);$deptMap[(int)$d['id']]=chosenMapping($target,'department',(int)$d['id'],$suggested,(string)$d['name']);if($deptMap[(int)$d['id']]===null)$unresolved[]='department #'.$d['id'];}
foreach($legacyEmployees as $e){$suggested=matchedEmployee($target,(string)$e['employee_code']);$employeeMap[(int)$e['id']]=chosenMapping($target,'employee',(int)$e['id'],$suggested,(string)$e['employee_code']);if($employeeMap[(int)$e['id']]===null)$unresolved[]='employee #'.$e['id'].' ('.$e['employee_code'].')';}
foreach($legacyUsers as $u){$suggested=matchedUser($target,(string)$u['username']);$userMap[(int)$u['id']]=chosenMapping($target,'user',(int)$u['id'],$suggested,(string)$u['username']);if($userMap[(int)$u['id']]===null)$unresolved[]='user #'.$u['id'].' ('.$u['username'].')';}
foreach($legacyCredentials as $c){
    $legacyEmployeeId=(int)($c['employee_id']??0);
    if($legacyEmployeeId<1||empty($employeeMap[$legacyEmployeeId]))continue;
    $employeeDept=targetEmployeeDepartment($target,(int)$employeeMap[$legacyEmployeeId]);
    if($employeeDept===null||$employeeDept==='')continue;
    $legacyEmployee=$legacyEmployeeById[$legacyEmployeeId]??null;
    $legacyDeptId=(int)($c['department_id']??($legacyEmployee['department_id']??0));
    if($legacyDeptId<1)continue;
    $mappedDeptId=$deptMap[$legacyDeptId]??null;
    $mappedDeptName=$mappedDeptId?targetDepartmentName($target,(int)$mappedDeptId):null;
    $sourceDeptName=null;
    foreach($legacyDepartments as $legacyDept){if((int)$legacyDept['id']===$legacyDeptId){$sourceDeptName=trim((string)$legacyDept['name']);break;}}
    $mappedDeptName ??= $sourceDeptName;
    if($mappedDeptName!==null && mb_strtolower($mappedDeptName)!==mb_strtolower($employeeDept))$unresolved[]='department mapping conflicts with canonical employee #'.$employeeMap[$legacyEmployeeId].' for credential #'.$c['id'];
}
$verified=verifyCiphertexts($source);

printf("Credential Manager import preview (%s)\n",$apply?'apply mode':'dry run');
printf("Source employees: %d; exact/mapped Inventory employee IDs: %d\n",count($legacyEmployees),count(array_filter($employeeMap)));
printf("Source departments: %d; mapped credential departments: %d\n",count($legacyDepartments),count(array_filter($deptMap)));
printf("Source users: %d; mapped Inventory users: %d\n",count($legacyUsers),count(array_filter($userMap)));
printf("Credential rows: %d; encrypted credential/history values verified without output: %d\n",count($legacyCredentials),$verified);
if (!$apply) {
    if ($unresolved) printf("Mappings requiring review: %d (first: %s)\n",count($unresolved),$unresolved[0]);
    echo "Dry run made no database changes.\n";
    exit($unresolved?1:0);
}

$needs = [];
foreach($legacyCredentials as $c){
    $legacyEmployeeId=(int)($c['employee_id']??0);
    if($legacyEmployeeId>0 && (!isset($employeeMap[$legacyEmployeeId]) || $employeeMap[$legacyEmployeeId]===null))$needs[]='employee #'.$legacyEmployeeId.' used by credential #'.$c['id'];
    if($legacyEmployeeId>0 && isset($employeeMap[$legacyEmployeeId])){
        $employeeDept=targetEmployeeDepartment($target,(int)$employeeMap[$legacyEmployeeId]);
        $legacyEmployee=$legacyEmployeeById[$legacyEmployeeId]??null;
        $legacyDeptId=(int)($c['department_id']??($legacyEmployee['department_id']??0));
        if($employeeDept!==null && $employeeDept!=='' && $legacyDeptId>0){$mappedDeptId=$deptMap[$legacyDeptId]??null;$mappedDeptName=$mappedDeptId?targetDepartmentName($target,(int)$mappedDeptId):null;$sourceDeptName=null;foreach($legacyDepartments as $legacyDept){if((int)$legacyDept['id']===$legacyDeptId){$sourceDeptName=trim((string)$legacyDept['name']);break;}}$mappedDeptName??=$sourceDeptName;if($mappedDeptName!==null&&mb_strtolower($mappedDeptName)!==mb_strtolower($employeeDept))$needs[]='department mapping conflicts with canonical employee #'.$employeeMap[$legacyEmployeeId].' for credential #'.$c['id'];}
    }
}
if($needs){fwrite(STDERR,"Import stopped before writes: required identity mappings are unresolved. First: ".$needs[0]."\n");exit(3);}

$target->beginTransaction();
try {
    foreach($legacyDepartments as $d){$id=(int)$d['id'];$suggested=matchedDepartment($target,(string)$d['name']);if($suggested===null){$target->prepare('INSERT IGNORE INTO credential_departments(name) VALUES(?)')->execute([(string)$d['name']]);$suggested=matchedDepartment($target,(string)$d['name']);}$deptMap[$id]=chosenMapping($target,'department',$id,$suggested,(string)$d['name']);persistSourceMap($target,'department',$id,$deptMap[$id],(string)$d['name']);}
    foreach($legacyEmployees as $e)persistSourceMap($target,'employee',(int)$e['id'],$employeeMap[(int)$e['id']],(string)$e['employee_code']);
    foreach($legacyUsers as $u)persistSourceMap($target,'user',(int)$u['id'],$userMap[(int)$u['id']],(string)$u['username']);

    $credentialMap=[];
    foreach($legacyCredentials as $c){
        $sourceId=(int)$c['id'];$old=oldMapping($target,'credential',$sourceId);
        if($old&&$old['target_id']!==null){$credentialMap[$sourceId]=(int)$old['target_id'];continue;}
        $already=$target->prepare("SELECT id FROM credentials WHERE source_system='credential-manager' AND source_id=?");$already->execute([$sourceId]);$existing=$already->fetchColumn();if($existing){$credentialMap[$sourceId]=(int)$existing;persistSourceMap($target,'credential',$sourceId,(int)$existing,(string)$c['service_name']);continue;}
        $empId=!empty($c['employee_id'])?$employeeMap[(int)$c['employee_id']]:null;
        $deptId=!empty($c['department_id'])?$deptMap[(int)$c['department_id']]:null;
        if(!$deptId&&$empId){$oldEmployee=$legacyEmployeeById[(int)$c['employee_id']]??null;if($oldEmployee&&!empty($oldEmployee['department_id']))$deptId=$deptMap[(int)$oldEmployee['department_id']]??null;}
        $owner=!empty($c['user_id'])?($userMap[(int)$c['user_id']]??null):null;
        $created=$userMap[(int)$c['created_by']]??null;$updated=!empty($c['updated_by'])?($userMap[(int)$c['updated_by']]??null):null;
        $active=isset($c['active'])?(int)$c['active']:1;
        $insert=$target->prepare("INSERT INTO credentials(employee_id,department_id,user_id,software_installation_id,service_name,service_url,credential_username,credential_password_enc,notes,created_by,updated_by,active,created_at,updated_at,source_system,source_id) VALUES(?,?,?,NULL,?,?,?,?,?,?,?,?,?,?, 'credential-manager',?)");
        $insert->execute([$empId,$deptId,$owner,(string)$c['service_name'],$c['service_url']??null,$c['credential_username']??null,(string)$c['credential_password_enc'],$c['notes']??null,$created,$updated,$active,$c['created_at']??null,$c['updated_at']??null,$sourceId]);
        $newId=(int)$target->lastInsertId();$credentialMap[$sourceId]=$newId;persistSourceMap($target,'credential',$sourceId,$newId,(string)$c['service_name']);
    }

    if(sourceHasTable($source,'credential_password_versions'))foreach(rows($source,'SELECT * FROM credential_password_versions')as$v){$cid=$credentialMap[(int)$v['credential_id']]??null;$uid=$userMap[(int)$v['changed_by']]??null;if(!$cid)throw new RuntimeException('A password history credential mapping is unresolved.');$q=$target->prepare("INSERT IGNORE INTO credential_password_versions(credential_id,password_enc,changed_by,change_reason,created_at,source_system,source_id) VALUES(?,?,?,?,?,'credential-manager',?)");$q->execute([$cid,(string)$v['password_enc'],$uid,$v['change_reason']??null,$v['created_at']??null,(int)$v['id']]);$newId=(int)$target->lastInsertId();if(!$newId){$q=$target->prepare("SELECT id FROM credential_password_versions WHERE source_system='credential-manager' AND source_id=?");$q->execute([(int)$v['id']]);$newId=(int)$q->fetchColumn();}persistSourceMap($target,'password_version',(int)$v['id'],$newId);}
    if(sourceHasTable($source,'credential_access_logs'))foreach(rows($source,'SELECT * FROM credential_access_logs')as$l){$cid=$credentialMap[(int)$l['credential_id']]??null;$uid=$userMap[(int)$l['user_id']]??null;if(!$cid)continue;$q=$target->prepare("INSERT IGNORE INTO credential_access_logs(credential_id,user_id,action,ip_address,user_agent,created_at,source_system,source_id) VALUES(?,?,?,?,?,?,'credential-manager',?)");$q->execute([$cid,$uid,(string)$l['action'],$l['ip_address']??null,$l['user_agent']??null,$l['created_at']??null,(int)$l['id']]);$newId=(int)$target->lastInsertId();if(!$newId){$q=$target->prepare("SELECT id FROM credential_access_logs WHERE source_system='credential-manager' AND source_id=?");$q->execute([(int)$l['id']]);$newId=(int)$q->fetchColumn();}persistSourceMap($target,'access_log',(int)$l['id'],$newId);}
    if(sourceHasTable($source,'credential_attachments'))foreach(rows($source,'SELECT * FROM credential_attachments')as$a){$cid=$credentialMap[(int)$a['credential_id']]??null;$uid=$userMap[(int)$a['uploaded_by']]??null;if(!$cid)throw new RuntimeException('An attachment credential mapping is unresolved.');$key=(string)($a['s3_key']??$a['storage_key']??'');if($key==='')throw new RuntimeException('An attachment has no storage key.');$q=$target->prepare("INSERT IGNORE INTO credential_attachments(credential_id,storage_backend,storage_key,original_filename,content_type,file_size,uploaded_by,created_at,source_system,source_id) VALUES(?,'s3',?,?,?,?,?,?, 'credential-manager',?)");$q->execute([$cid,$key,(string)$a['original_filename'],(string)$a['content_type'],(int)$a['file_size'],$uid,$a['created_at']??null,(int)$a['id']]);$newId=(int)$target->lastInsertId();if(!$newId){$q=$target->prepare("SELECT id FROM credential_attachments WHERE source_system='credential-manager' AND source_id=?");$q->execute([(int)$a['id']]);$newId=(int)$q->fetchColumn();}persistSourceMap($target,'attachment',(int)$a['id'],$newId);}
    if(sourceHasTable($source,'user_department_permissions'))foreach(rows($source,'SELECT * FROM user_department_permissions')as$grant){$uid=$userMap[(int)$grant['user_id']]??null;$did=$deptMap[(int)$grant['department_id']]??null;if($uid&&$did)$target->prepare('INSERT IGNORE INTO credential_department_access(user_id,department_id,granted_by) VALUES(?,?,NULL)')->execute([$uid,$did]);}
    if(sourceHasTable($source,'audit_logs'))foreach(rows($source,'SELECT * FROM audit_logs')as$a){$uid=!empty($a['user_id'])?($userMap[(int)$a['user_id']]??null):null;$exists=$target->prepare("SELECT id FROM audit_logs WHERE source_system='credential-manager' AND source_id=? LIMIT 1");$exists->execute([(int)$a['id']]);if($exists->fetchColumn())continue;$target->prepare("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address,source_system,source_id,created_at) VALUES(?,?,?,?,?,?,'credential-manager',?,?)")->execute([$uid,(string)$a['action'],$a['entity_type']??null,$a['entity_id']??null,$a['details']??null,$a['ip_address']??null,(int)$a['id'],$a['created_at']??null]);persistSourceMap($target,'audit_log',(int)$a['id'],(int)$target->lastInsertId());}
    $target->commit();
    echo "Import committed. Encrypted credential and history bytes were copied unchanged; no plaintext was written or logged.\n";
} catch(Throwable $e) {
    if($target->inTransaction())$target->rollBack();
    error_log('Credential data import rolled back: '.get_class($e));
    fwrite(STDERR,"Import rolled back. Review mappings, schema, key compatibility, and attachment storage configuration. No plaintext was logged.\n");
    exit(4);
}
