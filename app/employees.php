<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('employees', 'view');
$pageTitle = 'Employees';
$db = db();
$canAddEmployee = canPerform('employees', 'add');
$canEditEmployee = canPerform('employees', 'edit');
$canChangeEmployeeStatus = canPerform('employees', 'status');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $operation = match ($action) {
        'employee_create' => 'add',
        'employee_update' => 'edit',
        'employee_toggle' => 'status',
        default => 'edit',
    };
    requirePermission('employees', $operation);
    verifyCsrf();
    $departments = $db->query("SELECT department FROM (SELECT department FROM employees WHERE department IS NOT NULL AND department <> '' UNION SELECT department FROM assignments WHERE department IS NOT NULL AND department <> '') existing_departments ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
    try {
        if (!in_array($action, ['employee_create', 'employee_update', 'employee_toggle'], true)) throw new DomainException('Choose a valid employee action.');
        $db->beginTransaction();
        if ($action === 'employee_create') {
            $name = trim((string)($_POST['employee_name'] ?? ''));
            $employeeCode = trim((string)($_POST['employee_code'] ?? ''));
            $departmentChoice = trim((string)($_POST['department'] ?? ''));
            $department = $departmentChoice === '__new__' ? trim((string)($_POST['new_department'] ?? '')) : $departmentChoice;
            $location = trim((string)($_POST['location'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            if ($name === '' || strlen($name) > 150) throw new DomainException('Enter an employee name within 150 characters.');
            if ($employeeCode === '' || strlen($employeeCode) > 100) throw new DomainException('Enter an employee ID within 100 characters.');
            if ($department === '' || strlen($department) > 150) throw new DomainException($departmentChoice === '__new__' ? 'Enter a department name within 150 characters.' : 'Please select or add a department.');
            if ($departmentChoice !== '__new__' && !in_array($department, $departments, true)) throw new DomainException('Please select an existing department.');
            if ($departmentChoice === '__new__' && in_array(strtolower($department), array_map('strtolower', $departments), true)) throw new DomainException('That department already exists. Select it from the list.');
            if (strlen($location) > 150) throw new DomainException('Location must be 150 characters or fewer.');
            if ($email !== '' && (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new DomainException('Enter a valid employee email address.');
            $stmt = $db->prepare('INSERT INTO employees (employee_id, full_name, department, location, email) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$employeeCode, $name, $department, $location ?: null, $email ?: null]);
            $employeeId = (int)$db->lastInsertId();
            audit('create', 'employee', $employeeId, $name . ' (' . $employeeCode . ')');
            setFlash('success', 'Employee added successfully.');
        } else {
            $employeeId = filter_var($_POST['employee_record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$employeeId) throw new DomainException('Select a valid employee.');
            $lock = $db->prepare('SELECT id,full_name,employee_id FROM employees WHERE id=? FOR UPDATE');
            $lock->execute([$employeeId]);
            $employee = $lock->fetch();
            if (!$employee) throw new DomainException('Employee was not found.');
            if ($action === 'employee_update') {
                $name = trim((string)($_POST['employee_name'] ?? ''));
                $employeeCode = trim((string)($_POST['employee_code'] ?? ''));
                $department = trim((string)($_POST['department'] ?? ''));
                $location = trim((string)($_POST['location'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                if ($name === '' || strlen($name) > 150) throw new DomainException('Enter an employee name within 150 characters.');
                if ($employeeCode === '' || strlen($employeeCode) > 100) throw new DomainException('Enter an employee ID within 100 characters.');
                if ($department !== '' && !in_array($department, $departments, true)) throw new DomainException('Please select an existing department.');
                if (strlen($location) > 150) throw new DomainException('Location must be 150 characters or fewer.');
                if ($email !== '' && (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new DomainException('Enter a valid employee email address.');
                $update = $db->prepare('UPDATE employees SET employee_id=?,full_name=?,department=?,location=?,email=? WHERE id=?');
                $update->execute([$employeeCode, $name, $department ?: null, $location ?: null, $email ?: null, $employeeId]);
                audit('update', 'employee', (int)$employeeId, $name . ' (' . $employeeCode . ')');
                setFlash('success', 'Employee details updated successfully.');
            } else {
                $db->prepare('UPDATE employees SET active=IF(active=1,0,1) WHERE id=?')->execute([$employeeId]);
                audit('status_change', 'employee', (int)$employeeId, 'Employee status changed');
                setFlash('success', 'Employee status updated.');
            }
        }
        $db->commit();
    } catch (DomainException $exception) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Employee management failed: ' . $exception->getMessage());
        setFlash('error', (int)($exception->errorInfo[1] ?? 0) === 1062 ? 'That employee ID is already in use.' : 'Unable to save employee changes. Please try again.');
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Employee management failed: ' . $exception->getMessage());
        setFlash('error', 'Unable to save employee changes. Please try again.');
    }
    $redirectQuery = http_build_query(array_intersect_key($_GET, ['q' => true]));
    header('Location: employees.php' . ($redirectQuery ? '?' . $redirectQuery : ''));
    exit;
}

$departments = $db->query("SELECT department FROM (SELECT department FROM employees WHERE department IS NOT NULL AND department <> '' UNION SELECT department FROM assignments WHERE department IS NOT NULL AND department <> '') existing_departments ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
$search = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '') { $where = ' WHERE full_name LIKE ? OR employee_id LIKE ? OR department LIKE ? OR location LIKE ?'; $like = '%' . $search . '%'; $params = [$like, $like, $like, $like]; }
$query = $db->prepare('SELECT id,employee_id,full_name,department,location,email,active FROM employees' . $where . ' ORDER BY active DESC,full_name LIMIT 300');
$query->execute($params);
$employees = $query->fetchAll();
function employeeInitial(string $name): string { return function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1)); }
require __DIR__ . '/header.php';
?>
<section class="card employee-directory-card">
    <div class="section-heading"><div><p class="eyebrow">PEOPLE DIRECTORY</p><h2>Employee profiles</h2></div><?php if ($canAddEmployee): ?><button class="btn btn-primary" type="button" id="open-employee-dialog">Add Employee</button><?php endif; ?></div>
    <form method="get" class="toolbar directory-search"><div><label for="q">Search employees</label><input id="q" name="q" type="search" value="<?=htmlspecialchars($search)?>" placeholder="Name, employee ID, department, location"></div><button class="btn btn-primary" type="submit">Search</button><?php if ($search !== ''): ?><a class="btn" href="employees.php">Clear</a><?php endif; ?></form>
    <div class="employee-directory">
    <?php foreach ($employees as $person): ?>
        <article class="employee-tile">
            <div class="employee-tile-main">
            <div class="employee-avatar" aria-hidden="true"><?=htmlspecialchars(employeeInitial($person['full_name']))?></div>
            <div class="employee-tile-content"><h3><?=htmlspecialchars($person['full_name'])?></h3><p><?=htmlspecialchars($person['employee_id'])?> · <?=htmlspecialchars($person['department'] ?: 'No department')?></p><p class="muted"><?=htmlspecialchars($person['location'] ?: 'Location not set')?><?=($person['email'] ? ' · ' . htmlspecialchars($person['email']) : '')?></p></div>
            <div class="employee-tile-controls">
            <span class="state-pill <?=$person['active'] ? 'installed' : 'uninstalled'?>"><?=$person['active'] ? 'Active' : 'Inactive'?></span>
            <div class="employee-tile-actions"><a class="btn" href="employee.php?id=<?=$person['id']?>">View Details</a>
            <?php if ($canEditEmployee || $canChangeEmployeeStatus): ?>
                <?php if ($canEditEmployee): ?>
                <details class="employee-edit"><summary class="btn" aria-controls="employee-edit-<?=$person['id']?>">Edit</summary></details>
                <?php endif; ?>
                <?php if ($canChangeEmployeeStatus): ?>
                <form method="post" onsubmit="return confirm('⚠️ <?=$person['active'] ? 'Deactivate' : 'Reactivate'?> this employee?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="action" value="employee_toggle"><input type="hidden" name="employee_record_id" value="<?=$person['id']?>"><button class="btn btn-danger" type="submit"><?=$person['active'] ? 'Deactivate' : 'Reactivate'?></button></form>
                <?php endif; ?>
            <?php endif; ?></div>
            </div>
            </div>
            <?php if ($canEditEmployee): ?><form method="post" class="employee-edit-form" id="employee-edit-<?=$person['id']?>" aria-label="Edit employee details" hidden><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="action" value="employee_update"><input type="hidden" name="employee_record_id" value="<?=$person['id']?>"><label>Employee Name<input name="employee_name" maxlength="150" required value="<?=htmlspecialchars($person['full_name'])?>"></label><label>Employee ID<input name="employee_code" maxlength="100" required value="<?=htmlspecialchars($person['employee_id'])?>"></label><label>Department<select name="department"><option value="">Unassigned</option><?php foreach ($departments as $department): ?><option value="<?=htmlspecialchars($department)?>" <?=($person['department'] ?? '') === $department ? 'selected' : ''?>><?=htmlspecialchars($department)?></option><?php endforeach; ?></select></label><label>Location<input name="location" maxlength="150" value="<?=htmlspecialchars($person['location'] ?? '')?>"></label><label>Email<input name="email" type="email" maxlength="190" value="<?=htmlspecialchars($person['email'] ?? '')?>"></label><div class="employee-edit-actions"><button class="btn btn-primary" type="submit">Save Employee</button><button class="btn" type="button" data-cancel-employee-edit>Cancel</button></div></form><?php endif; ?>
        </article>
    <?php endforeach; ?>
    <?php if (!$employees): ?><p class="empty-state"><?=$search !== '' ? 'No employees match that search.' : 'No employees have been added yet.'?></p><?php endif; ?>
    </div>
</section>
<?php if ($canAddEmployee): ?>
<dialog class="employee-dialog" id="employee-dialog" aria-labelledby="employee-dialog-title">
    <div class="dialog-heading"><h2 id="employee-dialog-title">Add Employee</h2><button class="icon-button" type="button" id="close-employee-dialog" aria-label="Close Add Employee form">×</button></div>
    <form method="post" class="employee-create-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="action" value="employee_create">
        <label for="new-employee-name">Employee Name</label><input id="new-employee-name" name="employee_name" maxlength="150" required>
        <label for="new-employee-code">Employee ID</label><input id="new-employee-code" name="employee_code" maxlength="100" required>
        <label for="new-employee-department">Department</label><select id="new-employee-department" name="department" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?=htmlspecialchars($department)?>"><?=htmlspecialchars($department)?></option><?php endforeach; ?><option value="__new__">Add new department…</option></select>
        <div id="new-department-field" hidden><label for="new-department-name">New department</label><input id="new-department-name" name="new_department" maxlength="150" autocomplete="off"></div>
        <label for="new-employee-location">Location</label><input id="new-employee-location" name="location" maxlength="150">
        <label for="new-employee-email">Email</label><input id="new-employee-email" name="email" type="email" maxlength="190">
        <div class="form-actions"><button class="btn btn-primary" type="submit">Add Employee</button></div>
    </form>
</dialog>
<script>
(function(){document.querySelectorAll('.employee-edit').forEach(function(toggle){const form=toggle.closest('.employee-tile').querySelector('.employee-edit-form');function sync(){form.hidden=!toggle.open;if(toggle.open)form.querySelector('[name="employee_name"]').focus();}toggle.addEventListener('toggle',sync);form.querySelector('[data-cancel-employee-edit]').addEventListener('click',function(){form.reset();toggle.open=false;form.hidden=true;});});})();
(function(){const dialog=document.getElementById('employee-dialog'),open=document.getElementById('open-employee-dialog'),close=document.getElementById('close-employee-dialog'),department=document.getElementById('new-employee-department'),newDepartment=document.getElementById('new-department-field'),newName=document.getElementById('new-department-name');if(!dialog)return;function syncDepartment(){const adding=department.value==='__new__';newDepartment.hidden=!adding;newName.required=adding;}department.addEventListener('change',syncDepartment);open.addEventListener('click',function(){dialog.showModal();syncDepartment();document.getElementById('new-employee-name').focus();});close.addEventListener('click',function(){dialog.close();});dialog.addEventListener('click',function(event){if(event.target===dialog)dialog.close();});})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
