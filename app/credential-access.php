<?php
declare(strict_types=1);
require_once __DIR__ . '/credential_helpers.php';
requirePermission('credentials', 'manage_access');
$db = db();
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_department') {
            $name = trim((string)($_POST['department_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) throw new DomainException('Enter a valid department name.');
            $db->prepare('INSERT IGNORE INTO credential_departments(name) VALUES(?)')->execute([$name]);
            $id = (int)$db->lastInsertId();
            audit('credential_department_added', 'credential_department', $id ?: null, $name);
        } elseif (in_array($action, ['grant','revoke'], true)) {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$userId || !$departmentId) throw new DomainException('Choose a user and department.');
            $exists = $db->prepare('SELECT u.full_name,cd.name FROM users u CROSS JOIN credential_departments cd WHERE u.id=? AND u.active=1 AND cd.id=?');
            $exists->execute([(int)$userId, (int)$departmentId]); $row = $exists->fetch();
            if (!$row) throw new DomainException('The user or department is unavailable.');
            if ($action === 'grant') {
                $db->prepare('INSERT IGNORE INTO credential_department_access(user_id,department_id,granted_by) VALUES(?,?,?)')->execute([(int)$userId,(int)$departmentId,(int)currentUser()['id']]);
                audit('credential_department_access_granted', 'user', (int)$userId, $row['name']);
            } else {
                $db->prepare('DELETE FROM credential_department_access WHERE user_id=? AND department_id=?')->execute([(int)$userId,(int)$departmentId]);
                audit('credential_department_access_revoked', 'user', (int)$userId, $row['name']);
            }
        } else throw new DomainException('Unsupported access change.');
        setFlash('success','Credential access settings saved.');
        redirect('credential-access.php');
    } catch (DomainException $exception) { $error = $exception->getMessage(); }
      catch (Throwable $exception) { error_log('Credential access update failed: ' . get_class($exception)); $error = 'Unable to update credential access.'; }
}
$users = $db->query('SELECT id,full_name,username,role FROM users WHERE active=1 ORDER BY full_name')->fetchAll();
$departments = $db->query('SELECT id,name FROM credential_departments WHERE active=1 ORDER BY name')->fetchAll();
$grants = $db->query('SELECT a.user_id,a.department_id,u.full_name,cd.name FROM credential_department_access a JOIN users u ON u.id=a.user_id JOIN credential_departments cd ON cd.id=a.department_id ORDER BY u.full_name,cd.name')->fetchAll();
$pageTitle = 'Credential access'; require __DIR__ . '/header.php';
?>
<section class="card"><div class="section-heading"><div><p class="eyebrow">CREDENTIALS</p><h2>Department access</h2><p class="muted">Credential permissions and department access are managed independently from inventory and software permissions.</p></div></div>
<?php if($error): ?><div class="alert" role="alert"><?=htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div><?php endif; ?>
<form method="post" class="credential-access-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="add_department"><label>Register department</label><input name="department_name" maxlength="150" placeholder="Department name" required><button class="btn btn-glow" type="submit">Add department</button></form>
<h3>Grant department access</h3><form method="post" class="credential-access-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="grant"><label>User</label><select name="user_id" required><option value="">Select user</option><?php foreach($users as $u): ?><option value="<?=$u['id']?>"><?=htmlspecialchars($u['full_name'].' · '.$u['username'].' · '.$u['role'])?></option><?php endforeach; ?></select><label>Department</label><select name="department_id" required><option value="">Select department</option><?php foreach($departments as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select><button class="btn btn-glow" type="submit">Grant access</button></form>
<h3>Current access</h3><div class="table-scroll"><table><thead><tr><th>User</th><th>Department</th><th>Action</th></tr></thead><tbody><?php foreach($grants as $grant): ?><tr><td><?=htmlspecialchars($grant['full_name'])?></td><td><?=htmlspecialchars($grant['name'])?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="user_id" value="<?=$grant['user_id']?>"><input type="hidden" name="department_id" value="<?=$grant['department_id']?>"><button class="btn btn-danger" type="submit">Revoke</button></form></td></tr><?php endforeach; ?><?php if(!$grants): ?><tr><td class="empty-state" colspan="3">No credential department access has been granted.</td></tr><?php endif; ?></tbody></table></div></section>
<?php require __DIR__ . '/footer.php'; ?>
