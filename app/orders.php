<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/order_notifications.php';
require_once __DIR__ . '/purchase_order_helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('orders', 'view');
$pageTitle = 'Orders';
$db = db();
$canApproveOrder = canPerform('orders', 'order_approval');
$canRejectOrder = canPerform('orders', 'order_rejection');
$canReceiveOrder = canPerform('orders', 'order_receive');
$canViewProducts = canPerform('products', 'view');
$canViewVendors = canViewResource('vendors');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $permission = match ($action) {
        'confirm' => 'order_approval',
        'reject' => 'order_rejection',
        default => 'edit',
    };
    requirePermission('orders', $permission);
    verifyCsrf();
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if (!$orderId) throw new DomainException('Select a valid order.');
        if (!in_array($action, ['confirm', 'reject'], true)) throw new DomainException('Choose a valid order action.');
        $rejectionReason = trim((string)($_POST['rejection_reason'] ?? ''));
        if (strlen($rejectionReason) > 500) throw new DomainException('The rejection reason cannot exceed 500 characters.');
        $db->beginTransaction();
        $find = $db->prepare("SELECT t.id,t.status,t.quantity,t.transaction_at,t.order_reason,t.reason,t.rejection_reason,p.name product_name,u.full_name requester_name,u.email requester_email FROM inventory_transactions t JOIN products p ON p.id=t.product_id JOIN users u ON u.id=t.created_by WHERE t.id=? AND t.transaction_type='ordered' FOR UPDATE");
        $find->execute([$orderId]);
        $order = $find->fetch();
        if (!$order || $order['status'] !== 'Pending') throw new DomainException('This order is unavailable or no longer pending.');
        if ($action === 'reject') {
            $status = 'Rejected';
            $update = $db->prepare("UPDATE inventory_transactions SET status='Rejected',rejected_at=NOW(),rejected_by=?,rejection_reason=? WHERE id=? AND transaction_type='ordered' AND status='Pending'");
            $update->execute([currentUser()['id'], $rejectionReason !== '' ? $rejectionReason : null, $orderId]);
        } else {
            $status = 'Confirmed';
            $update = $db->prepare("UPDATE inventory_transactions SET status='Confirmed',confirmed_at=NOW(),confirmed_by=? WHERE id=? AND transaction_type='ordered' AND status='Pending'");
            $update->execute([currentUser()['id'], $orderId]);
        }
        if ($update->rowCount() !== 1) throw new DomainException('This order is no longer pending.');
        $auditDetails = 'Order ' . strtolower($status) . '; stock unchanged';
        if ($action === 'reject' && $rejectionReason !== '') $auditDetails .= '; reason: ' . $rejectionReason;
        audit($action === 'reject' ? 'order_rejected' : 'order_confirmed', 'order', (int)$orderId, $auditDetails);
        $timeColumn = $action === 'reject' ? 'rejected_at' : 'confirmed_at';
        $timeQuery = $db->prepare("SELECT {$timeColumn} FROM inventory_transactions WHERE id=?");
        $timeQuery->execute([$orderId]);
        $actor = currentUser();
        $notificationOrder = [
            'id' => (int)$order['id'],
            'product_name' => (string)$order['product_name'],
            'quantity' => (int)$order['quantity'],
            'requester_name' => (string)($order['requester_name'] ?? 'Requester'),
            'actor_name' => (string)($actor['full_name'] ?? $actor['username'] ?? 'Authorized user'),
            'event_at' => (string)$timeQuery->fetchColumn(),
            'rejection_reason' => $action === 'reject' ? ($rejectionReason !== '' ? $rejectionReason : 'No reason provided') : '',
        ];
        $eventType = $action === 'reject' ? 'rejected' : 'approved';
        $requesterEmail = trim((string)($order['requester_email'] ?? ''));
        if (filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) === false) {
            error_log(sprintf('Requester notification omitted (order_id=%d event=%s): requester account has no valid notification email; configured admin notifications will still be queued.', (int)$orderId, $eventType));
            $requesterEmail = '';
        }
        $recipients = orderNotificationRecipients(envv('ADMIN_EMAILS'), $requesterEmail);
        try {
            enqueueOrderNotifications($db, $eventType, $notificationOrder, $recipients);
        } catch (Throwable $notificationException) {
            error_log(sprintf('Order notification could not be queued (order_id=%d event=%s error_code=%s).', (int)$orderId, $eventType, (string)$notificationException->getCode()));
        }
        $db->commit();
        setFlash('success', $action === 'reject' ? 'Order rejected successfully.' : 'Order confirmed successfully.');
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', $e->getMessage());
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log(sprintf('Order transition failed (order_id=%s action=%s): %s: %s', $orderId ?: 'invalid', $action ?: 'missing', get_class($e), $e->getMessage()));
        setFlash('error', 'Unable to update this order. Please try again.');
    }
    header('Location: orders.php' . (isset($_GET['status']) ? '?status=' . rawurlencode((string)$_GET['status']) : ''));
    exit;
}
$filter = (string)($_GET['status'] ?? 'all');
if (!in_array($filter, ['all', 'Pending', 'Confirmed', 'Rejected'], true)) $filter = 'all';
$where = "t.transaction_type='ordered'";
$params = [];
if ($filter !== 'all') { $where .= ' AND t.status=?'; $params[] = $filter; }
$stmt = $db->prepare("SELECT t.id,t.product_id,t.quantity,t.unit_price,t.total_price,t.vendor_id,COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) vendor_name,t.reason,t.transaction_at,t.status,t.confirmed_at,t.rejected_at,t.rejection_reason,cu.full_name confirmed_user,ru.full_name rejected_user,p.name product_name,p.category_id,p.sub_category_id,c.name category_name,COALESCE(receipts.received_quantity,0) received_quantity FROM inventory_transactions t JOIN products p ON p.id=t.product_id JOIN categories c ON c.id=p.category_id LEFT JOIN vendors v ON v.id=t.vendor_id LEFT JOIN users cu ON cu.id=t.confirmed_by LEFT JOIN users ru ON ru.id=t.rejected_by LEFT JOIN (SELECT related_order_id,SUM(quantity) received_quantity FROM inventory_transactions WHERE transaction_type='in' AND related_order_id IS NOT NULL GROUP BY related_order_id) receipts ON receipts.related_order_id=t.id WHERE $where AND t.transaction_type='ordered' ORDER BY t.transaction_at DESC,t.id DESC");
$stmt->execute($params);
$orders = $stmt->fetchAll();
require __DIR__ . '/header.php';
?>
<section class="inventory-screen inventory-orders-screen card">
    <div class="section-heading"><h2>Order register</h2><?php if (canPerform('orders', 'add')): ?><a class="btn btn-glow" href="inventory.php">Create order</a><?php endif; ?></div>
    <form method="get" class="toolbar orders-filter"><div><label for="status">Order status</label><select id="status" name="status"><option value="all" <?=$filter==='all'?'selected':''?>>All Orders</option><option value="Pending" <?=$filter==='Pending'?'selected':''?>>Pending</option><option value="Confirmed" <?=$filter==='Confirmed'?'selected':''?>>Confirmed</option><option value="Rejected" <?=$filter==='Rejected'?'selected':''?>>Rejected</option></select></div><button class="btn" type="submit">Filter</button><a class="btn" href="orders.php">Clear</a></form>
    <div class="table-scroll"><table class="orders-table"><thead><tr><th>Order ID</th><th>Product</th><th>Category</th><th>Quantity</th><th>Unit Price</th><th>Total Price</th><th>Vendor</th><th>Reason</th><th>Created Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($orders as $order): ?><tr><td>#<?=$order['id']?></td><td><?=htmlspecialchars($order['product_name'])?></td><td><?=htmlspecialchars($order['category_name'])?></td><td><?=$order['quantity']?><small class="muted status-detail"><?=htmlspecialchars(purchaseOrderQuantitySummary((int)$order['quantity'], (int)$order['received_quantity']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></small></td><td><?=formatInr($order['unit_price'])?></td><td><?=formatInr($order['total_price'])?></td><td><?php if($order['vendor_id'] && $canViewVendors): ?><a href="vendor.php?id=<?=$order['vendor_id']?>"><?=htmlspecialchars($order['vendor_name'] ?? '—')?></a><?php else: ?><?=htmlspecialchars($order['vendor_name'] ?? '—')?><?php endif; ?></td><td><?=htmlspecialchars($order['reason'])?></td><td><?=htmlspecialchars($order['transaction_at'])?></td><td><span class="order-status <?=strtolower((string)$order['status'])?>"><?=htmlspecialchars($order['status'] ?? '—')?></span><?php if($order['confirmed_at']): ?><small class="muted status-detail"> by <?=htmlspecialchars($order['confirmed_user'] ?? 'Former user')?> · <?=htmlspecialchars($order['confirmed_at'])?></small><?php elseif($order['rejected_at']): ?><small class="muted status-detail"> by <?=htmlspecialchars($order['rejected_user'] ?? 'Former user')?> · <?=htmlspecialchars($order['rejected_at'])?></small><?php if($order['rejection_reason']): ?><small class="muted status-detail">Reason: <?=htmlspecialchars($order['rejection_reason'])?></small><?php endif; ?><?php endif; ?></td><td class="orders-actions-cell"><?php if($canReceiveOrder && $canViewProducts && $order['status']==='Confirmed' && (int)$order['quantity'] > 0 && (int)$order['received_quantity'] < (int)$order['quantity']): ?><div class="inline-actions order-actions"><a class="btn order-action-button receive-action" href="products.php?category_id=<?=(int)$order['category_id']?>&amp;sub_category_id=<?=(int)($order['sub_category_id'] ?? 0)?>&amp;product_id=<?=(int)$order['product_id']?>&amp;receive_order=<?=(int)$order['id']?>">Receive Order</a></div><?php elseif(($canApproveOrder || $canRejectOrder) && $order['status']==='Pending'): ?><div class="inline-actions order-actions"><?php if($canApproveOrder): ?><form method="post" onsubmit="this.querySelector('button').disabled=true"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><input type="hidden" name="order_id" value="<?=$order['id']?>"><input type="hidden" name="action" value="confirm"><button class="btn order-action-button confirm-action" type="submit">Confirm Order</button></form><?php endif; ?><?php if($canRejectOrder): ?><form method="post" onsubmit="return prepareOrderRejection(this)"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><input type="hidden" name="order_id" value="<?=$order['id']?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="rejection_reason" value=""><button class="btn order-action-button reject-action" type="submit">Reject</button></form><?php endif; ?></div><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="11" class="muted">No orders found for this filter.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<script>function prepareOrderRejection(form){const reason=window.prompt('Optional rejection reason (leave blank for none):');if(reason===null)return false;if(reason.length>500){window.alert('The rejection reason cannot exceed 500 characters.');return false;}form.elements.rejection_reason.value=reason.trim();form.querySelector('button').disabled=true;return true;}</script>
<?php require __DIR__ . '/footer.php'; ?>
