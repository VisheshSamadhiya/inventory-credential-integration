<?php
require_once __DIR__ . '/inventory_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('vendors', 'view');
$pageTitle = 'Vendors';
$db = db();
$canAddVendor = canPerform('vendors', 'add');
$canEditVendor = canPerform('vendors', 'edit');
$canChangeVendorStatus = canPerform('vendors', 'status');
$canViewOrders = canViewResource('orders');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $operation = match ($action) {
        'save' => 'add',
        'toggle' => 'status',
        default => 'edit',
    };
    requirePermission('vendors', $operation);
    verifyCsrf();
    try {
        if ($action === 'save') {
            $name = preg_replace('/\s+/u', ' ', trim((string)($_POST['vendor_name'] ?? ''))) ?? trim((string)($_POST['vendor_name'] ?? ''));
            $contact = trim((string)($_POST['contact_person'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $gstin = strtoupper(trim((string)($_POST['gstin'] ?? '')));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $length = static fn(string $value): int|false => preg_match_all('/./us', $value);
            if ($name === '' || $length($name) > 180 || $length($contact) > 150 || $length($phone) > 40 || $length($email) > 190 || $length($address) > 2000 || $length($notes) > 4000) {
                throw new DomainException('Enter a vendor name and keep each field within its length limit.');
            }
            if ($phone !== '' && !preg_match('/^[0-9+(). -]{7,40}$/D', $phone)) throw new DomainException('Enter a valid phone number.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter a valid email address.');
            if ($gstin !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9]$/D', $gstin)) throw new DomainException('Enter a valid 15-character GSTIN.');
            $db->beginTransaction();
            $insert = $db->prepare('INSERT INTO vendors(vendor_name,contact_person,phone,email,address,gstin,notes,created_by) VALUES(?,?,?,?,?,?,?,?)');
            $insert->execute([$name, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null, $gstin ?: null, $notes ?: null, currentUser()['id']]);
            $id = (int)$db->lastInsertId();
            audit('vendor_created', 'vendor', $id, $name);
            $db->commit();
            setFlash('success', 'Vendor created successfully.');
        } elseif ($action === 'toggle') {
            $id = filter_var($_POST['vendor_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) throw new DomainException('Select a valid vendor.');
            $db->beginTransaction();
            $find = $db->prepare('SELECT vendor_name,active FROM vendors WHERE id=? FOR UPDATE');
            $find->execute([$id]);
            $vendor = $find->fetch();
            if (!$vendor) throw new DomainException('Vendor was not found.');
            $active = (int)!$vendor['active'];
            $db->prepare('UPDATE vendors SET active=? WHERE id=?')->execute([$active, $id]);
            audit($active ? 'vendor_activated' : 'vendor_deactivated', 'vendor', (int)$id, $vendor['vendor_name']);
            $db->commit();
            setFlash('success', $active ? 'Vendor activated.' : 'Vendor deactivated.');
        } else throw new DomainException('Choose a valid vendor action.');
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', $e->getMessage());
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Vendor save failed (action=' . preg_replace('/[^a-z_]/', '', $action) . '): ' . $e->getMessage());
        setFlash('error', (string)$e->getCode() === '23000' ? 'A vendor with that name or GSTIN already exists.' : 'Unable to save the vendor. Please try again.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Vendor update failed (action=' . preg_replace('/[^a-z_]/', '', $action) . '): ' . get_class($e) . ': ' . $e->getMessage());
        setFlash('error', 'Unable to save the vendor. Please try again.');
    }
    $query = http_build_query(array_intersect_key($_GET, ['status' => true, 'q' => true]));
    header('Location: vendors.php' . ($query ? '?' . $query : ''));
    exit;
}

$filter = (string)($_GET['status'] ?? 'active');
if (!in_array($filter, ['active', 'inactive', 'all'], true)) $filter = 'active';
$search = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($filter !== 'all') { $where[] = 'v.active=?'; $params[] = $filter === 'active' ? 1 : 0; }
if ($search !== '') {
    $where[] = '(v.vendor_name LIKE ? OR v.contact_person LIKE ? OR v.phone LIKE ? OR v.email LIKE ? OR v.gstin LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}
$sql = 'SELECT v.id,v.vendor_name,v.contact_person,v.phone,v.email,v.gstin,v.active,v.created_at,' . ($canViewOrders ? 'COUNT(t.id)' : '0') . ' order_count FROM vendors v' . ($canViewOrders ? ' LEFT JOIN inventory_transactions t ON t.vendor_id=v.id AND t.transaction_type=\'ordered\'' : '') . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ($canViewOrders ? ' GROUP BY v.id' : '') . ' ORDER BY v.active DESC,v.vendor_name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$vendors = $stmt->fetchAll();
require __DIR__ . '/header.php';
?>
<section class="card">
    <?php if ($canAddVendor): ?><details class="vendor-create"><summary class="btn btn-glow">Add vendor</summary><form method="post" class="vendor-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="action" value="save"><div><label for="vendor-name">Vendor Name</label><input id="vendor-name" name="vendor_name" maxlength="180" required></div><div><label for="vendor-contact">Contact Person</label><input id="vendor-contact" name="contact_person" maxlength="150"></div><div><label for="vendor-phone">Phone</label><input id="vendor-phone" name="phone" type="tel" maxlength="40"></div><div><label for="vendor-email">Email</label><input id="vendor-email" name="email" type="email" maxlength="190"></div><div><label for="vendor-gstin">GSTIN</label><input id="vendor-gstin" name="gstin" maxlength="15" autocapitalize="characters"></div><div class="form-wide"><label for="vendor-address">Address</label><textarea id="vendor-address" name="address" maxlength="2000"></textarea></div><div class="form-wide"><label for="vendor-notes">Notes</label><textarea id="vendor-notes" name="notes" maxlength="4000"></textarea></div><div class="form-actions"><button class="btn btn-glow" type="submit">Save vendor</button></div></form></details><?php endif; ?>
    <form method="get" class="toolbar vendor-filters"><div><label for="vendor-search">Search vendors</label><input id="vendor-search" name="q" value="<?=htmlspecialchars($search)?>" placeholder="Name, contact, phone, email or GSTIN"></div><div><label for="vendor-status">Status</label><select id="vendor-status" name="status"><option value="active" <?=$filter==='active'?'selected':''?>>Active</option><option value="inactive" <?=$filter==='inactive'?'selected':''?>>Inactive</option><option value="all" <?=$filter==='all'?'selected':''?>>All vendors</option></select></div><button class="btn" type="submit">Search</button><a class="btn" href="vendors.php">Clear</a></form>
    <div class="table-scroll"><table class="vendor-table"><thead><tr><th>Vendor</th><th>Contact Person</th><th>Phone</th><th>Email</th><th>GSTIN</th><?php if($canViewOrders): ?><th>Orders</th><?php endif; ?><th>Status</th><?php if($canEditVendor || $canChangeVendorStatus): ?><th>Actions</th><?php endif; ?></tr></thead><tbody><?php foreach ($vendors as $vendor): ?><tr><td><a href="vendor.php?id=<?=$vendor['id']?>"><?=htmlspecialchars($vendor['vendor_name'])?></a></td><td><?=htmlspecialchars($vendor['contact_person'] ?: '—')?></td><td><?=htmlspecialchars($vendor['phone'] ?: '—')?></td><td><?=htmlspecialchars($vendor['email'] ?: '—')?></td><td><?=htmlspecialchars($vendor['gstin'] ?: '—')?></td><?php if($canViewOrders): ?><td><?=$vendor['order_count']?></td><?php endif; ?><td><span class="state-pill <?=$vendor['active']?'installed':'uninstalled'?>"><?=$vendor['active']?'Active':'Inactive'?></span></td><?php if($canEditVendor || $canChangeVendorStatus): ?><td class="action-stack"><?php if($canEditVendor): ?><a class="btn" href="vendor.php?id=<?=$vendor['id']?>#edit-vendor">Edit details</a><?php endif; ?><?php if($canChangeVendorStatus): ?><form method="post" onsubmit="return confirm('<?=$vendor['active']?'Deactivate':'Activate'?> this vendor?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="vendor_id" value="<?=$vendor['id']?>"><button class="btn <?=$vendor['active']?'btn-danger':'btn-success'?>" type="submit"><?=$vendor['active']?'Deactivate':'Activate'?></button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$vendors): ?><tr><td colspan="<?=5+(int)$canViewOrders+1+(int)($canEditVendor || $canChangeVendorStatus)?>" class="empty-state">No vendors match the selected filters.</td></tr><?php endif; ?></tbody></table></div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
