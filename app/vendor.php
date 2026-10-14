<?php
require_once __DIR__ . '/inventory_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('vendors', 'view');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    requirePermission('vendors', $action === 'toggle' ? 'status' : 'edit');
    verifyCsrf();
}
$db = db();
$canEditVendor = canPerform('vendors', 'edit');
$canChangeVendorStatus = canPerform('vendors', 'status');
$canViewOrders = canViewResource('orders');
$id = filter_var($_GET['id'] ?? $_POST['vendor_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(404); exit('Vendor not found.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            if ($name === '' || $length($name) > 180 || $length($contact) > 150 || $length($phone) > 40 || $length($email) > 190 || $length($address) > 2000 || $length($notes) > 4000) throw new DomainException('Enter a vendor name and keep each field within its length limit.');
            if ($phone !== '' && !preg_match('/^[0-9+(). -]{7,40}$/D', $phone)) throw new DomainException('Enter a valid phone number.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter a valid email address.');
            if ($gstin !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9]$/D', $gstin)) throw new DomainException('Enter a valid 15-character GSTIN.');
            $db->beginTransaction();
            $lock = $db->prepare('SELECT id FROM vendors WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            if (!$lock->fetchColumn()) throw new DomainException('Vendor was not found.');
            $update = $db->prepare('UPDATE vendors SET vendor_name=?,contact_person=?,phone=?,email=?,address=?,gstin=?,notes=? WHERE id=?');
            $update->execute([$name, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null, $gstin ?: null, $notes ?: null, $id]);
            audit('vendor_updated', 'vendor', (int)$id, $name);
            $db->commit();
            setFlash('success', 'Vendor details updated. Existing order snapshots were preserved.');
        } elseif ($action === 'toggle') {
            $db->beginTransaction();
            $lock = $db->prepare('SELECT vendor_name,active FROM vendors WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            $vendor = $lock->fetch();
            if (!$vendor) throw new DomainException('Vendor was not found.');
            $active = (int)!$vendor['active'];
            $db->prepare('UPDATE vendors SET active=? WHERE id=?')->execute([$active, $id]);
            audit($active ? 'vendor_activated' : 'vendor_deactivated', 'vendor', (int)$id, $vendor['vendor_name']);
            $db->commit();
            setFlash('success', $active ? 'Vendor activated.' : 'Vendor deactivated.');
        } else throw new DomainException('Choose a valid vendor action.');
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack(); setFlash('error', $e->getMessage());
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack(); error_log('Vendor profile save failed (vendor_id='.(int)$id.'): '.$e->getMessage());
        setFlash('error', (string)$e->getCode()==='23000' ? 'A vendor with that name or GSTIN already exists.' : 'Unable to save vendor changes. Please try again.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack(); error_log('Vendor profile update failed (vendor_id='.(int)$id.'): '.get_class($e).': '.$e->getMessage()); setFlash('error','Unable to save vendor changes. Please try again.');
    }
    header('Location: vendor.php?id='.(int)$id);
    exit;
}
$find = $db->prepare('SELECT * FROM vendors WHERE id=?');
$find->execute([$id]);
$vendor = $find->fetch();
if (!$vendor) { http_response_code(404); exit('Vendor not found.'); }
$pageTitle = $vendor['vendor_name'];
$stats = null;
if ($canViewOrders) {
    $statsQuery = $db->prepare("SELECT COUNT(*) total_orders,SUM(status='Pending') pending_orders,SUM(status='Confirmed') confirmed_orders,SUM(status='Rejected') rejected_orders,COALESCE(SUM(total_price),0) total_value FROM inventory_transactions WHERE vendor_id=? AND transaction_type='ordered'");
    $statsQuery->execute([$id]);
    $stats = $statsQuery->fetch();
}
$statusFilter = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all','Pending','Confirmed','Rejected','Received'], true)) $statusFilter='all';
$search = trim((string)($_GET['q'] ?? ''));
$where = ["t.vendor_id=?", "t.transaction_type='ordered'"];
$params = [(int)$id];
if ($statusFilter !== 'all') { $where[]='t.status=?'; $params[]=$statusFilter; }
if ($search !== '') { $where[]='(p.name LIKE ? OR t.reason LIKE ? OR CAST(t.id AS CHAR) LIKE ?)'; $term='%'.$search.'%'; array_push($params,$term,$term,$term); }
$orders = [];
if ($canViewOrders) {
    $ordersQuery = $db->prepare('SELECT t.id,t.quantity,t.unit_price,t.total_price,t.transaction_at,t.status,t.reason,t.order_reason,t.vendor_name_snapshot,t.vendor_contact_snapshot,t.vendor_phone_snapshot,t.vendor_email_snapshot,t.vendor_address_snapshot,t.vendor_gstin_snapshot,p.name product_name,c.name category_name FROM inventory_transactions t JOIN products p ON p.id=t.product_id LEFT JOIN categories c ON c.id=p.category_id WHERE '.implode(' AND ',$where).' ORDER BY t.transaction_at DESC,t.id DESC');
    $ordersQuery->execute($params);
    $orders = $ordersQuery->fetchAll();
}
require __DIR__ . '/header.php';
?>
<div class="vendor-profile-head"><div><p class="eyebrow">VENDOR PROFILE</p><h2><?=htmlspecialchars($vendor['vendor_name'])?></h2></div><a class="btn" href="vendors.php">Back to vendors</a></div>
<section class="card vendor-profile"><div class="vendor-contact-grid"><div><span>Contact Person</span><strong><?=htmlspecialchars($vendor['contact_person']?:'—')?></strong></div><div><span>Phone</span><strong><?=htmlspecialchars($vendor['phone']?:'—')?></strong></div><div><span>Email</span><strong><?=htmlspecialchars($vendor['email']?:'—')?></strong></div><div><span>GSTIN</span><strong><?=htmlspecialchars($vendor['gstin']?:'—')?></strong></div><div class="vendor-address"><span>Address</span><strong><?=nl2br(htmlspecialchars($vendor['address']?:'—'))?></strong></div><div class="vendor-address"><span>Notes</span><strong><?=nl2br(htmlspecialchars($vendor['notes']?:'—'))?></strong></div><div><span>Status</span><strong><?=$vendor['active']?'Active':'Inactive'?></strong></div></div></section>
<?php if($canViewOrders): ?><div class="grid stats vendor-stats"><div class="card"><span>Total orders</span><strong><?=$stats['total_orders']??0?></strong></div><div class="card"><span>Pending</span><strong><?=$stats['pending_orders']??0?></strong></div><div class="card"><span>Confirmed</span><strong><?=$stats['confirmed_orders']??0?></strong></div><div class="card"><span>Rejected</span><strong><?=$stats['rejected_orders']??0?></strong></div><div class="card"><span>Total order value</span><strong><?=formatInr($stats['total_value'])?></strong></div></div><?php endif; ?>
<?php if($canEditVendor): ?><details class="card vendor-edit" id="edit-vendor"><summary class="btn btn-glow">Edit vendor</summary><form method="post" class="vendor-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="vendor_id" value="<?=$id?>"><input type="hidden" name="action" value="save"><div><label>Vendor Name</label><input name="vendor_name" maxlength="180" required value="<?=htmlspecialchars($vendor['vendor_name'])?>"></div><div><label>Contact Person</label><input name="contact_person" maxlength="150" value="<?=htmlspecialchars($vendor['contact_person']??'')?>"></div><div><label>Phone</label><input name="phone" type="tel" maxlength="40" value="<?=htmlspecialchars($vendor['phone']??'')?>"></div><div><label>Email</label><input name="email" type="email" maxlength="190" value="<?=htmlspecialchars($vendor['email']??'')?>"></div><div><label>GSTIN</label><input name="gstin" maxlength="15" value="<?=htmlspecialchars($vendor['gstin']??'')?>"></div><div class="form-wide"><label>Address</label><textarea name="address" maxlength="2000"><?=htmlspecialchars($vendor['address']??'')?></textarea></div><div class="form-wide"><label>Notes</label><textarea name="notes" maxlength="4000"><?=htmlspecialchars($vendor['notes']??'')?></textarea></div><div class="form-actions"><button class="btn btn-glow" type="submit">Save changes</button></div></form></details><?php endif; ?><?php if($canChangeVendorStatus): ?><form method="post" class="vendor-status-form" onsubmit="return confirm('<?=$vendor['active']?'Deactivate':'Activate'?> this vendor?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="vendor_id" value="<?=$id?>"><input type="hidden" name="action" value="toggle"><button class="btn <?=$vendor['active']?'btn-danger':'btn-success'?>" type="submit"><?=$vendor['active']?'Deactivate vendor':'Activate vendor'?></button></form><?php endif; ?>
<?php if($canViewOrders): ?>
<section class="card"><div class="section-heading"><h2>Order history</h2></div><form method="get" class="toolbar vendor-filters"><input type="hidden" name="id" value="<?=$id?>"><div><label for="vendor-order-search">Search orders</label><input id="vendor-order-search" name="q" value="<?=htmlspecialchars($search)?>" placeholder="Order ID, product or reason"></div><div><label for="vendor-order-status">Status</label><select id="vendor-order-status" name="status"><option value="all" <?=$statusFilter==='all'?'selected':''?>>All</option><?php foreach(['Pending','Confirmed','Rejected','Received'] as $status): ?><option value="<?=$status?>" <?=$statusFilter===$status?'selected':''?>><?=$status?></option><?php endforeach; ?></select></div><button class="btn" type="submit">Filter</button></form><div class="table-scroll"><table class="orders-table"><thead><tr><th>Order ID</th><th>Product</th><th>Category</th><th>Quantity</th><th>Unit Price</th><th>Total Price</th><th>Order Date</th><th>Status</th><th>Vendor at order time</th></tr></thead><tbody><?php foreach($orders as $order): ?><tr><td>#<?=$order['id']?></td><td><?=htmlspecialchars($order['product_name'])?></td><td><?=htmlspecialchars($order['category_name']??'—')?></td><td><?=$order['quantity']?></td><td><?=formatInr($order['unit_price'])?></td><td><?=formatInr($order['total_price'])?></td><td><?=htmlspecialchars($order['transaction_at'])?></td><td><span class="order-status <?=strtolower((string)$order['status'])?>"><?=htmlspecialchars($order['status']??'—')?></span></td><td><?=htmlspecialchars($order['vendor_name_snapshot']?:$vendor['vendor_name'])?></td></tr><?php endforeach; ?><?php if(!$orders): ?><tr><td colspan="9" class="empty-state">No orders match this filter.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
