<?php
declare(strict_types=1);
require_once __DIR__ . '/credential_helpers.php';

$writeAction = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (string)($_POST['action'] ?? '') : '';
if (in_array($writeAction, ['create', 'update'], true) && !currentUser() && !empty($_SESSION['session_expired'])) {
    $_SESSION['credential_form_recovery'] = credentialRecoveryValues($_POST, $writeAction);
    $_SESSION['credential_recovery_notice'] = 'Your session expired before the credential was saved. Sign in again to restore the non-secret fields; passwords must be re-entered.';
    header('Location: login.php');
    exit;
}

requireLogin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $pageOperation = canPerform('credentials', 'view') ? 'view' : (canPerform('credentials', 'create') ? 'create' : (canPerform('credentials', 'edit') ? 'edit' : 'view'));
    requirePermission('credentials', $pageOperation);
}

$db = db();
$formValues = [];
$recoveryAction = null;
if (($_GET['recover'] ?? '') === '1' && is_array($_SESSION['credential_form_recovery'] ?? null)) {
    $formValues = $_SESSION['credential_form_recovery'];
    $recoveryAction = in_array(($formValues['action'] ?? ''), ['create', 'update'], true) ? $formValues['action'] : null;
    unset($_SESSION['credential_form_recovery']);
}
$error = $recoveryAction ? (string)($_SESSION['credential_recovery_notice'] ?? 'Your session expired. Review the restored fields and save again.') : '';
if ($recoveryAction) unset($_SESSION['credential_recovery_notice']);
$editRequestId = $_GET['edit'] ?? (($writeAction === 'update') ? ($_POST['credential_id'] ?? null) : (($recoveryAction === 'update') ? ($formValues['credential_id'] ?? null) : null));
$editId = filter_var($editRequestId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($editId) requirePermission('credentials', 'edit');
$editing = $editId ? requireCredentialRecord((int)$editId) : null;
$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($viewId) requirePermission('credentials', 'view');
$viewing = $viewId ? requireCredentialRecord((int)$viewId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') requirePermission('credentials', 'create');
    if ($action === 'update') requirePermission('credentials', 'edit');
    if ($action === 'delete') requirePermission('credentials', 'delete');
    $csrfFailed = false;
    if (in_array($action, ['create', 'update'], true) && !csrfTokenMatches($_POST['csrf'] ?? null)) {
        $csrfFailed = true;
        $formValues = credentialRecoveryValues($_POST, $action);
        $error = 'Your session expired or this form token is invalid. Your non-secret entries are still here; re-enter the password if needed and save again.';
    } else {
        verifyCsrf();
        if (in_array($action, ['create', 'update'], true)) $formValues = credentialRecoveryValues($_POST, $action);
    }
    $id = filter_var($_POST['credential_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$csrfFailed) try {
        if ($action === 'create') {
            requirePermission('credentials', 'create');
            $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $departmentId = null;
            $softwareId = filter_var($_POST['software_installation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $name = trim((string)($_POST['service_name'] ?? ''));
            $password = (string)($_POST['credential_password'] ?? '');
            $serviceUrl = trim((string)($_POST['service_url'] ?? ''));
            if ($serviceUrl !== '' && (!filter_var($serviceUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($serviceUrl, PHP_URL_SCHEME)), ['http','https'], true))) throw new DomainException('Enter a valid HTTP or HTTPS service URL.');
            if ($name === '' || mb_strlen($name) > 180 || $password === '') throw new DomainException('Enter a service name and password.');
            if (strlen($password) > 4096 || strlen($serviceUrl) > 500 || mb_strlen(trim((string)($_POST['credential_username'] ?? ''))) > 190 || mb_strlen(trim((string)($_POST['notes'] ?? ''))) > 4000) throw new DomainException('One or more fields are too long.');
            if ($employeeId) {
                requireCredentialEmployeeAccess((int)$employeeId);
                $departmentId = credentialDepartmentForEmployee((int)$employeeId);
                if ($departmentId !== null && !credentialCanAccessDepartment($departmentId)) {
                    throw new DomainException('You do not have access to this employee department.');
                }
            }
            if (!$employeeId && !$userId) throw new DomainException('Link this credential to an employee or user.');
            if ($softwareId) {
                $check = $db->prepare("SELECT id FROM software_installations WHERE id=? AND employee_id=? AND status='installed'");
                $check->execute([(int)$softwareId, (int)$employeeId]);
                if (!$employeeId || !$check->fetchColumn()) throw new DomainException('Choose installed software for the selected employee.');
            }
            if ($userId && !canAdmin() && (int)$userId !== (int)currentUser()['id']) throw new DomainException('Only an Admin can associate a credential with another user.');
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO credentials (employee_id,department_id,user_id,software_installation_id,service_name,service_url,credential_username,credential_password_enc,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$employeeId ?: null, $departmentId ?: null, $userId ?: null, $softwareId ?: null, $name, $serviceUrl ?: null, trim((string)($_POST['credential_username'] ?? '')) ?: null, encryptCredentialValue($password), trim((string)($_POST['notes'] ?? '')) ?: null, (int)currentUser()['id']]);
            $id = (int)$db->lastInsertId();
            auditCredentialChange('credential_created', $id, $name);
            credentialAccessLog($id, 'created');
            $db->commit();
            setFlash('success', 'Credential added.');
            redirect('credentials.php');
        }

        if (!$id) throw new DomainException('Credential not found.');
        $record = requireCredentialRecord((int)$id, $action === 'delete');
        if ($action === 'update') {
            requirePermission('credentials', 'edit');
            $name = trim((string)($_POST['service_name'] ?? ''));
            $serviceUrl = trim((string)($_POST['service_url'] ?? ''));
            if ($serviceUrl !== '' && (!filter_var($serviceUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($serviceUrl, PHP_URL_SCHEME)), ['http','https'], true))) throw new DomainException('Enter a valid HTTP or HTTPS service URL.');
            if ($name === '' || mb_strlen($name) > 180) throw new DomainException('Enter a valid service name.');
            $password = (string)($_POST['credential_password'] ?? '');
            if (strlen($password) > 4096) throw new DomainException('Password is too long.');
            $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $departmentId = null;
            $softwareId = filter_var($_POST['software_installation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($employeeId) {
                requireCredentialEmployeeAccess((int)$employeeId);
                $departmentId = credentialDepartmentForEmployee((int)$employeeId);
                if ($departmentId !== null && !credentialCanAccessDepartment($departmentId)) {
                    throw new DomainException('You do not have access to this employee department.');
                }
            }
            if ($softwareId) {
                $check = $db->prepare("SELECT id FROM software_installations WHERE id=? AND employee_id=? AND status='installed'");
                $check->execute([(int)$softwareId, (int)$employeeId]);
                if (!$employeeId || !$check->fetchColumn()) throw new DomainException('Choose installed software for the selected employee.');
            }
            if (strlen($serviceUrl) > 500 || mb_strlen(trim((string)($_POST['credential_username'] ?? ''))) > 190 || mb_strlen(trim((string)($_POST['notes'] ?? ''))) > 4000) throw new DomainException('One or more fields are too long.');
            if ($password !== '' && strlen($password) > 4096) throw new DomainException('Password is too long.');
            $db->beginTransaction();
            if ($password !== '') {
                $db->prepare('INSERT INTO credential_password_versions (credential_id,password_enc,changed_by,change_reason) VALUES (?,?,?,?)')->execute([(int)$id, $record['credential_password_enc'], (int)currentUser()['id'], 'Password changed']);
                $db->prepare('UPDATE credentials SET credential_password_enc=? WHERE id=?')->execute([encryptCredentialValue($password), (int)$id]);
            }
            $stmt = $db->prepare('UPDATE credentials SET employee_id=?,department_id=?,software_installation_id=?,service_name=?,service_url=?,credential_username=?,notes=?,updated_by=? WHERE id=?');
            $stmt->execute([$employeeId ?: null, $departmentId ?: null, $softwareId ?: null, $name, $serviceUrl ?: null, trim((string)($_POST['credential_username'] ?? '')) ?: null, trim((string)($_POST['notes'] ?? '')) ?: null, (int)currentUser()['id'], (int)$id]);
            if ($password !== '') auditCredentialChange('credential_password_changed', (int)$id, $name);
            auditCredentialChange('credential_updated', (int)$id, $name);
            credentialAccessLog((int)$id, 'edited');
            $db->commit();
            setFlash('success', 'Credential updated.');
            redirect('credentials.php');
        }
        if ($action === 'delete') {
            requirePermission('credentials', 'delete');
            $db->beginTransaction();
            $db->prepare('UPDATE credentials SET active=0,updated_by=? WHERE id=?')->execute([(int)currentUser()['id'], (int)$id]);
            auditCredentialChange('credential_deleted', (int)$id, (string)$record['service_name']);
            credentialAccessLog((int)$id, 'deleted');
            $db->commit();
            setFlash('success', 'Credential removed from the active list.');
            redirect('credentials.php');
        }
        throw new DomainException('Unsupported credential action.');
    } catch (DomainException $exception) {
        if ($db->inTransaction()) $db->rollBack();
        unset($_SESSION['flash']);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        unset($_SESSION['flash']);
        error_log('Credential operation failed: ' . credentialSafeFailureLogMessage($exception, $_POST));
        $error = 'Unable to save this credential. Please try again.';
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$selectedEmployeeSource = ($writeAction === 'create' || $recoveryAction === 'create')
    ? ($formValues['employee_id'] ?? $_GET['employee_id'] ?? null)
    : ($_GET['employee_id'] ?? null);
$selectedEmployee = filter_var($selectedEmployeeSource, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedSoftware = filter_var($_GET['software_installation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$autoOpen = !empty($_GET['add']) || $writeAction === 'create' || $recoveryAction === 'create';
[$scope, $scopeParams] = credentialScopeSql();
$sql = 'SELECT c.id,c.employee_id,c.department_id,c.software_installation_id,c.service_name,c.service_url,c.credential_username,c.notes,c.created_at,c.updated_at,e.full_name employee_name,e.employee_id employee_code,CASE WHEN c.employee_id IS NOT NULL THEN NULLIF(TRIM(e.department), \'\') ELSE cd.name END department_name,si.software_name FROM credentials c LEFT JOIN employees e ON e.id=c.employee_id LEFT JOIN credential_departments cd ON cd.id=c.department_id LEFT JOIN software_installations si ON si.id=c.software_installation_id WHERE c.active=1 AND ' . $scope;
$params = $scopeParams;
if ($selectedEmployee) { $sql .= ' AND c.employee_id=?'; $params[] = (int)$selectedEmployee; }
if ($search !== '') { $sql .= ' AND (c.service_name LIKE ? OR c.credential_username LIKE ? OR e.full_name LIKE ? OR e.employee_id LIKE ?)'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like, $like); }
$sql .= ' ORDER BY c.updated_at DESC LIMIT 300';
$credentials = [];
if (canPerform('credentials', 'view')) { $query = $db->prepare($sql); $query->execute($params); $credentials = $query->fetchAll(); }
$employees = $db->query('SELECT id,employee_id,full_name,department FROM employees WHERE active=1 ORDER BY full_name')->fetchAll();
if ($selectedEmployee && !$editId && $writeAction !== 'update' && $recoveryAction !== 'update') {
    $employees = array_values(array_filter($employees, static fn($e) => (int)$e['id'] === (int)$selectedEmployee));
}
$createEmployeeId = filter_var($formValues['employee_id'] ?? ($selectedEmployee ?: null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$createSoftwareId = filter_var($formValues['software_installation_id'] ?? ($selectedSoftware ?: null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editEmployeeId = filter_var($formValues['employee_id'] ?? ($editing['employee_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editSoftwareId = filter_var($formValues['software_installation_id'] ?? ($editing['software_installation_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editingFormValues = $editing ? array_merge($editing, $formValues) : [];
$createDepartmentName = 'No department assigned';
$editDepartmentName = 'No department assigned';
foreach ($employees as $employee) {
    if ($createEmployeeId && (int)$employee['id'] === (int)$createEmployeeId && trim((string)($employee['department'] ?? '')) !== '') {
        $createDepartmentName = trim((string)$employee['department']);
    }
    if ($editEmployeeId && (int)$employee['id'] === (int)$editEmployeeId && trim((string)($employee['department'] ?? '')) !== '') $editDepartmentName = trim((string)$employee['department']);
}
$software = $db->query("SELECT s.id,s.employee_id,s.software_name,e.full_name employee_name FROM software_installations s JOIN employees e ON e.id=s.employee_id WHERE s.status='installed' AND e.active=1 ORDER BY e.full_name,s.software_name")->fetchAll();
$pageTitle = 'Credentials';
require __DIR__ . '/header.php';
?>
<section class="card credentials-page">
  <div class="section-heading"><div><p class="eyebrow">SECURE SERVICE ACCESS</p><h2>Credentials</h2><p class="muted">Passwords stay encrypted and hidden until an authorized reveal.</p></div><div class="credential-actions"><?php if(canPerform('credentials','manage_access')): ?><a class="btn" href="credential-access.php">Manage access</a><?php endif; ?><?php if(canPerform('credentials','create')): ?><button class="btn btn-glow" type="button" onclick="document.getElementById('credential-create').showModal()">Add credential</button><?php endif; ?></div></div>
  <?php if($error): ?><div class="alert" role="alert"><?=htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div><?php endif; ?>
  <form method="get" class="credential-search"><?php if($selectedEmployee): ?><input type="hidden" name="employee_id" value="<?=$selectedEmployee?>"><?php endif; ?><label for="credential-search">Search credentials</label><input id="credential-search" name="q" value="<?=htmlspecialchars($search,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" maxlength="100" placeholder="Service, username or employee"><button class="btn" type="submit">Search</button></form>
  <div class="table-scroll"><table><thead><tr><th>Service</th><th>Username</th><th>Employee / user</th><th>Software</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($credentials as $c): $safeServiceUrl=credentialHttpUrl($c['service_url']); ?><tr class="credential-row">
    <td><div class="credential-service"><strong><?=htmlspecialchars($c['service_name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></strong><?php if($safeServiceUrl): ?><a class="credential-service-link" href="<?=htmlspecialchars($safeServiceUrl,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" rel="noopener noreferrer" target="_blank">Open service <span aria-hidden="true">↗</span></a><?php endif; ?></div></td>
    <td><span class="credential-username"><?=htmlspecialchars($c['credential_username']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></span></td>
    <td><div class="credential-person"><span><?=htmlspecialchars($c['employee_name']??($c['department_name']??'User credential'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></span><small><?=htmlspecialchars($c['department_name']??'No department',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></small></div></td>
    <td><span class="credential-software"><?=htmlspecialchars($c['software_name']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></span></td>
    <td><time class="credential-updated"><?=htmlspecialchars($c['updated_at'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></time></td>
    <td><div class="credential-actions"><div class="credential-action-group">
      <a class="btn credential-action-view" href="credentials.php?view=<?=$c['id']?><?= $selectedEmployee ? '&amp;employee_id='.(int)$selectedEmployee : '' ?>">View</a><?php if(canPerform('credentials','reveal')): ?><button class="btn credential-action-reveal" type="button" data-reveal-credential="<?=$c['id']?>">Reveal</button><?php endif; ?>
    </div><?php if(canPerform('credentials','edit')||canPerform('credentials','delete')): ?><div class="credential-action-group credential-action-group--manage"><?php if(canPerform('credentials','edit')): ?><a class="btn credential-action-edit" href="credentials.php?edit=<?=$c['id']?>">Edit</a><?php endif; ?><?php if(canPerform('credentials','delete')): ?><form method="post" onsubmit="return confirm('Remove this credential from active records?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="credential_id" value="<?=$c['id']?>"><button class="btn btn-danger credential-action-delete" type="submit">Delete</button></form><?php endif; ?></div><?php endif; ?></div></td>
  </tr>
  <tr class="credential-secondary"><td colspan="6"><details><summary>History, attachments and access log</summary><div class="credential-detail-grid"><div><h3>Password history</h3><?php $historyQ=$db->prepare('SELECT v.created_at,v.change_reason,u.full_name FROM credential_password_versions v LEFT JOIN users u ON u.id=v.changed_by WHERE v.credential_id=? ORDER BY v.created_at DESC LIMIT 20');$historyQ->execute([(int)$c['id']]);$history=$historyQ->fetchAll(); ?><ul><?php foreach($history as $h): ?><li><?=htmlspecialchars($h['created_at'])?> · <?=htmlspecialchars($h['full_name']??'Former user')?> · <?=htmlspecialchars($h['change_reason']??'Password changed')?></li><?php endforeach; ?><?php if(!$history): ?><li class="muted">No password changes recorded.</li><?php endif; ?></ul></div><div><h3>Attachments</h3><?php $filesQ=$db->prepare('SELECT id,original_filename,file_size,created_at FROM credential_attachments WHERE credential_id=? ORDER BY created_at DESC');$filesQ->execute([(int)$c['id']]);$files=$filesQ->fetchAll(); ?><ul><?php foreach($files as $file): ?><li><a href="credential-attachment.php?id=<?=$file['id']?>"><?=htmlspecialchars($file['original_filename'])?></a> · <?=number_format((int)$file['file_size'])?> bytes<?php if(canPerform('credentials','edit')): ?> <form method="post" action="credential-attachment.php" class="inline-attachment-delete" onsubmit="return confirm('Delete this attachment?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="attachment_id" value="<?=$file['id']?>"><button class="btn btn-danger" type="submit">Delete</button></form><?php endif; ?></li><?php endforeach; ?><?php if(!$files): ?><li class="muted">No attachments.</li><?php endif; ?></ul><?php if(canPerform('credentials','edit')): ?><form method="post" enctype="multipart/form-data" action="credential-attachment.php" class="attachment-upload"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="credential_id" value="<?=$c['id']?>"><label>Upload file (10 MB max)</label><input type="file" name="attachment" required><button class="btn" type="submit">Upload</button></form><?php endif; ?></div><div><h3>Recent access</h3><?php if(canPerform('credentials','audit')): $logsQ=$db->prepare('SELECT l.action,l.created_at,u.full_name FROM credential_access_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.credential_id=? ORDER BY l.created_at DESC LIMIT 10');$logsQ->execute([(int)$c['id']]);$logs=$logsQ->fetchAll(); ?><ul><?php foreach($logs as $log): ?><li><?=htmlspecialchars($log['created_at'])?> · <?=htmlspecialchars($log['full_name']??'Former user')?> · <?=htmlspecialchars($log['action'])?></li><?php endforeach; ?><?php if(!$logs): ?><li class="muted">No access events.</li><?php endif; ?></ul><?php else: ?><p class="muted">Access log permission required.</p><?php endif; ?></div></div></details></td></tr><?php endforeach; ?>
  <?php if(!$credentials): ?><tr><td colspan="6" class="empty-state">No credentials found in your authorized scope.</td></tr><?php endif; ?></tbody></table></div>
</section>
<?php if($viewing): ?><dialog class="employee-dialog credential-dialog" id="credential-view" open><div class="dialog-heading"><h2><?=htmlspecialchars($viewing['service_name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></h2><a class="icon-button" href="credentials.php<?= $selectedEmployee ? '?employee_id='.(int)$selectedEmployee : '' ?>" aria-label="Close">×</a></div><dl class="credential-view-list"><dt>Username</dt><dd><?=htmlspecialchars($viewing['credential_username']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></dd><dt>Employee</dt><dd><?=htmlspecialchars($viewing['employee_name']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></dd><dt>Department</dt><dd><?=htmlspecialchars(trim((string)($viewing['department_name'] ?? '')) !== '' ? trim((string)$viewing['department_name']) : 'No department assigned',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></dd><dt>Software</dt><dd><?=htmlspecialchars($viewing['software_name']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></dd><dt>Notes</dt><dd><?=nl2br(htmlspecialchars($viewing['notes']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))?></dd><?php $viewUrl=credentialHttpUrl($viewing['service_url']); if($viewUrl): ?><dt>Service</dt><dd><a href="<?=htmlspecialchars($viewUrl,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" target="_blank" rel="noopener noreferrer">Open service</a></dd><?php endif; ?></dl><div class="form-actions"><?php if(canPerform('credentials','reveal')): ?><button class="btn" type="button" data-reveal-credential="<?=$viewing['id']?>">Reveal password</button><?php endif; ?><?php if(canPerform('credentials','edit')): ?><a class="btn" href="credentials.php?edit=<?=$viewing['id']?>">Edit</a><?php endif; ?></div></dialog><?php endif; ?>
<?php if(canPerform('credentials','create')): ?><dialog class="employee-dialog credential-dialog" id="credential-create" data-auto-open="<?=$autoOpen?'1':'0'?>"><div class="dialog-heading"><h2>Add credential</h2><button class="icon-button" type="button" data-close-credential aria-label="Close">×</button></div><form method="post" class="profile-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="create"><div><label>Service name</label><input name="service_name" maxlength="180" value="<?=htmlspecialchars((string)($formValues['service_name'] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" required></div><div><label>Service URL</label><input name="service_url" type="url" maxlength="500" value="<?=htmlspecialchars((string)($formValues['service_url'] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"></div><div><label>Username</label><input name="credential_username" maxlength="190" autocomplete="off" value="<?=htmlspecialchars((string)($formValues['credential_username'] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"></div><div><label>Password</label><input name="credential_password" type="password" maxlength="4096" autocomplete="new-password" required></div><div><label>Employee</label><select name="employee_id" id="credential-employee"><option value="">No employee</option><?php foreach($employees as $employee): ?><option value="<?=$employee['id']?>" data-department-name="<?=htmlspecialchars(trim((string)($employee['department'] ?? '')),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" <?=($createEmployeeId && (int)$createEmployeeId===(int)$employee['id'])?'selected':''?>><?=htmlspecialchars($employee['full_name'].' · '.$employee['employee_id'])?></option><?php endforeach; ?></select></div><div><label for="credential-department-display">Department</label><input id="credential-department-display" type="text" value="<?=htmlspecialchars($createDepartmentName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" readonly></div><?php if($software): ?><div><label>Installed software</label><select name="software_installation_id" id="credential-software"><option value="">No software link</option><?php foreach($software as $s): ?><option value="<?=$s['id']?>" data-employee="<?=$s['employee_id']?>" <?=($createSoftwareId && (int)$createSoftwareId===(int)$s['id'])?'selected':''?>><?=htmlspecialchars($s['employee_name'].' · '.$s['software_name'])?></option><?php endforeach; ?></select></div><?php endif; ?><div class="form-wide"><label>Notes</label><textarea name="notes" maxlength="4000"><?=htmlspecialchars((string)($formValues['notes'] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></textarea></div><div class="form-actions"><button class="btn btn-glow" type="submit">Save credential</button></div></form></dialog><?php endif; ?>
<?php if($editing && canPerform('credentials','edit')): ?><dialog class="employee-dialog credential-dialog" id="credential-edit" open><div class="dialog-heading"><h2>Edit <?=htmlspecialchars($editing['service_name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></h2><a class="icon-button" href="credentials.php" aria-label="Close">×</a></div><form method="post" class="profile-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="credential_id" value="<?=$editing['id']?>"><div><label>Service name</label><input name="service_name" maxlength="180" value="<?=htmlspecialchars((string)($editingFormValues['service_name'] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" required></div><div><label>Service URL</label><input name="service_url" type="url" maxlength="500" value="<?=htmlspecialchars((string)($editingFormValues['service_url'] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"></div><div><label>Username</label><input name="credential_username" maxlength="190" value="<?=htmlspecialchars((string)($editingFormValues['credential_username'] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"></div><div><label>New password (leave blank to keep current)</label><input name="credential_password" type="password" maxlength="4096" autocomplete="new-password"></div><div><label>Employee</label><select name="employee_id" id="credential-edit-employee"><option value="">No employee</option><?php foreach($employees as $employee): ?><option value="<?=$employee['id']?>" data-department-name="<?=htmlspecialchars(trim((string)($employee['department'] ?? '')),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" <?=($editEmployeeId && (int)$editEmployeeId===(int)$employee['id'])?'selected':''?>><?=htmlspecialchars($employee['full_name'].' · '.$employee['employee_id'])?></option><?php endforeach; ?></select></div><div><label for="credential-edit-department-display">Department</label><input id="credential-edit-department-display" type="text" value="<?=htmlspecialchars($editDepartmentName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" readonly></div><?php if($software): ?><div><label>Installed software</label><select name="software_installation_id" id="credential-edit-software"><option value="">No software link</option><?php foreach($software as $s): ?><option value="<?=$s['id']?>" data-employee="<?=$s['employee_id']?>" <?=($editSoftwareId && (int)$editSoftwareId===(int)$s['id'])?'selected':''?>><?=htmlspecialchars($s['employee_name'] . ' · ' . $s['software_name'])?></option><?php endforeach; ?></select></div><?php endif; ?><div class="form-wide"><label>Notes</label><textarea name="notes" maxlength="4000"><?=htmlspecialchars((string)($editingFormValues['notes'] ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></textarea></div><div class="form-actions"><button class="btn btn-glow" type="submit">Save changes</button><a class="btn" href="credentials.php">Cancel</a></div></form></dialog><?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
