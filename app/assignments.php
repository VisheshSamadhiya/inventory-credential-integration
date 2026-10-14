<?php
require_once __DIR__ . '/inventory_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('assignments', 'view');
$db = db();

// Search employees on demand instead of loading the whole directory into the page.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (array_key_exists('employee_search', $_GET) || array_key_exists('product_search', $_GET))) {
    header('Content-Type: application/json; charset=utf-8');
    $searchType = array_key_exists('employee_search', $_GET) ? 'employee' : 'product';
    requirePermission($searchType === 'employee' ? 'employees' : 'products', 'view');
    $term = trim((string)($_GET[$searchType . '_search'] ?? ''));
    if (strlen($term) < 2) {
        echo json_encode([]);
        exit;
    }
    $like = '%' . $term . '%';
    if ($searchType === 'employee') {
        $stmt = $db->prepare(
            'SELECT id, employee_id, full_name, department
             FROM employees
             WHERE active = 1 AND (full_name LIKE ? OR employee_id LIKE ? OR department LIKE ?)
             ORDER BY full_name LIMIT 20'
        );
        $stmt->execute([$like, $like, $like]);
    } else {
        $stmt = $db->prepare('SELECT id, name FROM products WHERE active = 1 AND name LIKE ? ORDER BY name LIMIT 20');
        $stmt->execute([$like]);
    }
    echo json_encode($stmt->fetchAll(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
}

$pageTitle = 'Assignments';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('assignments', 'add');
    requirePermission('inventory', 'stock_adjustment');
    verifyCsrf();
    try {
        $employeePk = filter_var($_POST['employee_record_id'] ?? null, FILTER_VALIDATE_INT);
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$employeePk || $employeePk < 1) throw new DomainException('Please select an employee.');
        if (!$productId || $productId < 1) throw new DomainException('Please select a product.');
        if (!$quantity || $quantity < 1) throw new DomainException('Please enter a valid quantity.');

        $db->beginTransaction();
        $employeeQuery = $db->prepare('SELECT employee_id, full_name, department FROM employees WHERE id = ? AND active = 1 FOR UPDATE');
        $employeeQuery->execute([$employeePk]);
        $employee = $employeeQuery->fetch();
        if (!$employee) throw new DomainException('Please select an active employee.');
        $departmentText = trim((string)$employee['department']) !== '' ? trim((string)$employee['department']) : 'No department';
        $assignmentReason = 'Assignment → ' . $employee['full_name'] . ' (' . $employee['employee_id'] . '), ' . $departmentText;

        $productQuery = $db->prepare('SELECT name FROM products WHERE id = ? AND active = 1 FOR UPDATE');
        $productQuery->execute([$productId]);
        $productName = $productQuery->fetchColumn();
        if ($productName === false) throw new DomainException('Please select an active product.');
        $previousStock = currentStock((int)$productId);
        if ($quantity > $previousStock) throw new DomainException('There is not enough stock for this assignment.');
        $movement = calculateInventoryChange($previousStock, 'out', $quantity);
        $newStock = $movement['new_stock'];

        $stmt = $db->prepare(
            'INSERT INTO assignments
                (product_id, quantity, assigned_to, employee_id, employee_record_id, department, assigned_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $productId,
            $quantity,
            $employee['full_name'],
            $employee['employee_id'],
            $employeePk,
            $employee['department'],
            currentUser()['id'],
            trim((string)($_POST['notes'] ?? '')) ?: null,
        ]);
        $assignmentId = (int)$db->lastInsertId();

        $transaction = $db->prepare(
            'INSERT INTO inventory_transactions
                (product_id, transaction_type, quantity, previous_quantity, new_quantity, reason, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $transaction->execute([
            $productId, 'out', -$quantity, $previousStock, $newStock, $assignmentReason,
            'Assignment #' . $assignmentId . ' to ' . $employee['full_name'] . ' (' . $employee['employee_id'] . ')',
            currentUser()['id'],
        ]);
        audit(
            'assignment',
            'assignment',
            $assignmentId,
            "Assigned {$quantity} {$productName} to {$employee['full_name']} ({$employee['employee_id']}), {$departmentText}; stock {$previousStock} → {$newStock}"
        );
        recalculateInventoryMonths((int)$productId);
        $db->commit();
        setFlash('success', 'Product assigned successfully.');
    } catch (DomainException $exception) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Inventory assignment failed: ' . $exception->getMessage());
        setFlash('error', 'Unable to save the assignment. Please try again.');
    }
    header('Location: assignments.php');
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$employeeFilter = trim((string)($_GET['employee'] ?? ''));
$departmentFilter = trim((string)($_GET['department'] ?? ''));
$productFilter = trim((string)($_GET['product'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$allowedStatuses = ['assigned', 'returned', 'cancelled'];
if (($_SESSION['flash']['message'] ?? '') === 'Employee added successfully.') {
    unset($_SESSION['flash']);
}

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(a.assigned_to LIKE ? OR a.employee_id LIKE ? OR a.department LIKE ? OR p.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($employeeFilter !== '') { $where[] = '(a.employee_id LIKE ? OR a.assigned_to LIKE ?)'; $employeeLike = '%' . $employeeFilter . '%'; array_push($params, $employeeLike, $employeeLike); }
if ($departmentFilter !== '') { $where[] = 'a.department = ?'; $params[] = $departmentFilter; }
if ($productFilter !== '') { $where[] = 'p.name LIKE ?'; $params[] = '%' . $productFilter . '%'; }
if (in_array($statusFilter, $allowedStatuses, true)) { $where[] = 'a.status = ?'; $params[] = $statusFilter; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateFrom)) { $where[] = 'a.assigned_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateTo)) { $where[] = 'a.assigned_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$assignmentSql = 'SELECT a.assigned_to, a.employee_id, a.department, a.quantity, a.assigned_at, a.status,
                         p.name AS product_name
                  FROM assignments a JOIN products p ON p.id = a.product_id' . $whereSql .
                 ' ORDER BY a.assigned_at DESC, a.id DESC LIMIT 200';
$assignmentQuery = $db->prepare($assignmentSql);
$assignmentQuery->execute($params);
$assignments = $assignmentQuery->fetchAll();
$productsAvailable = (bool)$db->query('SELECT EXISTS(SELECT 1 FROM products WHERE active = 1)')->fetchColumn();
$departments = $db->query("SELECT DISTINCT department FROM assignments WHERE department IS NOT NULL AND department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
$employeeForFilterValue = $employeeFilter;
require __DIR__ . '/header.php';
?>
<?php if (canPerform('assignments', 'add') && canPerform('inventory', 'stock_adjustment')): ?>
<section class="card">
    <h2>Assign product to employee</h2>
    <form method="post" class="assignment-form" id="assignment-form">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>">
        <div class="employee-picker">
            <label for="employee_search">Employee</label>
            <input id="employee_search" type="search" autocomplete="off" placeholder="Search name, employee ID, or department" aria-controls="employee_results">
            <input id="employee_record_id" name="employee_record_id" type="hidden" required>
            <div id="employee_results" class="search-results" role="listbox" hidden></div>
            <span id="employee_selected" class="muted" aria-live="polite">No employee selected</span>
        </div>
        <div><label for="employee_display">Employee ID</label><input id="employee_display" class="auto-filled" readonly placeholder="Auto-filled"></div>
        <div><label for="department_display">Department</label><input id="department_display" class="auto-filled" readonly placeholder="Auto-filled"></div>
        <div class="employee-picker">
            <label for="product_search">Product</label>
            <input id="product_search" type="search" autocomplete="off" placeholder="Search active products" aria-controls="product_results">
            <input id="product_id" name="product_id" type="hidden" required>
            <div id="product_results" class="search-results" role="listbox" hidden></div>
            <span id="product_selected" class="muted" aria-live="polite">No product selected</span>
        </div>
        <div><label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="1" step="1" value="1" required></div>
        <div class="form-wide"><label for="assignment_notes">Notes (optional)</label><input id="assignment_notes" name="notes"></div>
        <div class="form-actions"><button class="btn btn-glow" type="submit">Assign Product</button></div>
    </form>
    <?php if (!$productsAvailable): ?><p class="muted">No active products are available for assignment.</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card assignment-list section-spaced">
    <div class="section-heading"><h2>Assignments</h2><button type="button" class="icon-button" id="assignment-search-toggle" aria-expanded="<?=($_GET?'true':'false')?>" aria-controls="assignment-filters" aria-label="Search and filter assignments" title="Search and filter assignments"><span aria-hidden="true">⌕</span></button></div>
    <form method="get" id="assignment-filters" class="filter-panel" <?=($_GET?'':'hidden')?> >
        <div class="toolbar compact-toolbar">
            <div><label for="assignment-q">Search</label><input id="assignment-q" name="q" type="search" value="<?=htmlspecialchars($search)?>" placeholder="Employee, ID, department, or product"></div>
            <div><label for="assignment-employee">Employee</label><input id="assignment-employee" name="employee" value="<?=htmlspecialchars($employeeForFilterValue)?>" placeholder="Name or employee ID"></div>
            <div><label for="assignment-department">Department</label><select id="assignment-department" name="department"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?=htmlspecialchars($department)?>" <?=$departmentFilter===$department?'selected':''?>><?=htmlspecialchars($department)?></option><?php endforeach; ?></select></div>
            <div><label for="assignment-product">Product</label><input id="assignment-product" name="product" value="<?=htmlspecialchars($productFilter)?>" placeholder="Product name"></div>
            <div><label for="assignment-status">Status</label><select id="assignment-status" name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $status): ?><option value="<?=$status?>" <?=$statusFilter===$status?'selected':''?>><?=htmlspecialchars(ucfirst($status))?></option><?php endforeach; ?></select></div>
            <div><label for="assignment-from">From</label><input id="assignment-from" name="date_from" type="date" value="<?=htmlspecialchars($dateFrom)?>"></div>
            <div><label for="assignment-to">To</label><input id="assignment-to" name="date_to" type="date" value="<?=htmlspecialchars($dateTo)?>"></div>
            <button class="btn" type="submit">Apply filters</button><a class="btn" href="assignments.php">Clear</a>
        </div>
    </form>
    <div class="table-scroll"><table><thead><tr><th>Employee</th><th>Employee ID</th><th>Department</th><th>Product</th><th>Quantity</th><th>Date</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($assignments as $assignment): ?><tr><td><?=htmlspecialchars($assignment['assigned_to'])?></td><td><?=htmlspecialchars($assignment['employee_id']??'—')?></td><td><?=htmlspecialchars($assignment['department']??'—')?></td><td><?=htmlspecialchars($assignment['product_name'])?></td><td><?=$assignment['quantity']?></td><td><?=htmlspecialchars($assignment['assigned_at'])?></td><td><span class="badge"><?=htmlspecialchars(ucfirst($assignment['status']))?></span></td></tr><?php endforeach; ?>
    <?php if (!$assignments): ?><tr><td colspan="7" class="muted"><?=($_GET?'No matching assignments found.':'No assignments found.')?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if (count($assignments)===200): ?><p class="muted">Showing the latest 200 matching assignments. Add search terms or filters to narrow results.</p><?php endif; ?>
</section>
<script>
(function(){
 const input=document.getElementById('employee_search');
 if(input){const id=document.getElementById('employee_record_id'),code=document.getElementById('employee_display'),department=document.getElementById('department_display'),selected=document.getElementById('employee_selected'),results=document.getElementById('employee_results');let timer;
  function clearChoice(){id.value='';code.value='';department.value='';selected.textContent='No employee selected';}
  input.addEventListener('input',function(){clearChoice();clearTimeout(timer);const term=input.value.trim();if(term.length<2){results.hidden=true;results.replaceChildren();return;}timer=setTimeout(async function(){try{const response=await fetch('assignments.php?employee_search='+encodeURIComponent(term),{headers:{'Accept':'application/json'}});if(!response.ok)throw new Error('Employee search failed');const people=await response.json();results.replaceChildren();if(!people.length){const empty=document.createElement('div');empty.className='search-result-empty';empty.textContent='No matching employees';results.append(empty);}people.forEach(function(person){const option=document.createElement('button');option.type='button';option.className='search-result';option.setAttribute('role','option');option.textContent=person.full_name+' — '+person.employee_id+(person.department?' — '+person.department:'');option.addEventListener('click',function(){id.value=person.id;code.value=person.employee_id;department.value=person.department||'';selected.textContent=person.full_name;input.value=person.full_name;results.hidden=true;});results.append(option);});results.hidden=false;}catch(error){results.replaceChildren();const message=document.createElement('div');message.className='search-result-empty';message.textContent='Employee search is unavailable. Try again.';results.append(message);results.hidden=false;}},180);});
  document.addEventListener('click',function(event){if(!event.target.closest('.employee-picker'))results.hidden=true;});
  document.getElementById('assignment-form').addEventListener('submit',function(event){const productId=document.getElementById('product_id'),productSearch=document.getElementById('product_search');if(!id.value){event.preventDefault();input.setCustomValidity('Please select an employee.');input.reportValidity();}else if(!productId.value){event.preventDefault();productSearch.setCustomValidity('Please select a product.');productSearch.reportValidity();}else{input.setCustomValidity('');productSearch.setCustomValidity('');}});
  input.addEventListener('input',function(){input.setCustomValidity('');});
 }
 const productInput=document.getElementById('product_search');
 if(productInput){const productId=document.getElementById('product_id'),selected=document.getElementById('product_selected'),results=document.getElementById('product_results');let timer;
  productInput.addEventListener('input',function(){productId.value='';selected.textContent='No product selected';productInput.setCustomValidity('');clearTimeout(timer);const term=productInput.value.trim();if(term.length<2){results.hidden=true;results.replaceChildren();return;}timer=setTimeout(async function(){try{const response=await fetch('assignments.php?product_search='+encodeURIComponent(term),{headers:{'Accept':'application/json'}});if(!response.ok)throw new Error('Product search failed');const products=await response.json();results.replaceChildren();if(!products.length){const empty=document.createElement('div');empty.className='search-result-empty';empty.textContent='No matching active products';results.append(empty);}products.forEach(function(product){const option=document.createElement('button');option.type='button';option.className='search-result';option.setAttribute('role','option');option.textContent=product.name;option.addEventListener('click',function(){productId.value=product.id;selected.textContent=product.name;productInput.value=product.name;results.hidden=true;});results.append(option);});results.hidden=false;}catch(error){results.replaceChildren();const message=document.createElement('div');message.className='search-result-empty';message.textContent='Product search is unavailable. Try again.';results.append(message);results.hidden=false;}},180);});
  document.addEventListener('click',function(event){if(!event.target.closest('#assignment-form .employee-picker')||event.target.closest('#product_search'))results.hidden=true;});
 }
 const toggle=document.getElementById('assignment-search-toggle'),filters=document.getElementById('assignment-filters');if(toggle&&filters)toggle.addEventListener('click',function(){const opening=filters.hidden;filters.hidden=!opening;toggle.setAttribute('aria-expanded',String(opening));if(opening)filters.querySelector('input[type="search"]').focus();});
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
