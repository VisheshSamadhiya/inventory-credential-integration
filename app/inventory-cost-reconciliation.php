<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/history_helpers.php';
require_once __DIR__ . '/inventory_valuation.php';
requirePermission('history', 'view');
if (!canAdmin()) {
    http_response_code(403);
    exit('403 Forbidden');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$pdo = db();
$reconciliationReady = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_cost_reconciliations'")->fetchColumn();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'reconcile_cost') {
    verifyCsrf();
    if (!$reconciliationReady) {
        setFlash('error', 'Cost reconciliation is not available in this installation yet.');
    } else {
        try {
            reconcileHistoricalInventoryCost($pdo, $_POST, (int)(currentUser()['id'] ?? 0));
            setFlash('success', 'Historical cost recorded.');
        } catch (Throwable $error) {
            setFlash('error', $error instanceof DomainException ? $error->getMessage() : 'Unable to record this cost. Check the selected source and try again.');
        }
    }
    header('Location: inventory-cost-reconciliation.php');
    exit;
}

$candidates = ['receipt'=>[],'asset'=>[],'assignment'=>[],'disposal'=>[]];
$entries = [];
if ($reconciliationReady) {
    $candidates['receipt'] = $pdo->query("SELECT t.id,p.name product_name,t.quantity,t.reason,o.id order_id,o.status order_status,COALESCE(o.unit_price,o.total_price/NULLIF(ABS(o.quantity),0)) po_unit_cost FROM inventory_transactions t JOIN products p ON p.id=t.product_id LEFT JOIN inventory_transactions o ON o.id=t.related_order_id LEFT JOIN inventory_cost_layers l ON l.source_transaction_id=t.id WHERE t.transaction_type IN ('in','opening') AND (t.status='Received' OR t.transaction_type='opening') AND (t.related_order_id IS NULL OR o.status IN ('Confirmed','Received')) AND l.id IS NULL AND LOWER(COALESCE(t.reason,'')) NOT LIKE '%assignment%' AND LOWER(COALESCE(t.reason,'')) NOT LIKE '%hardware returned%' ORDER BY t.transaction_at DESC,t.id DESC")->fetchAll();
    $candidates['asset'] = $pdo->query("SELECT a.id,p.name product_name,a.asset_tag,a.serial_number FROM inventory_assets a JOIN products p ON p.id=a.product_id WHERE a.acquisition_unit_cost IS NULL ORDER BY a.id DESC")->fetchAll();
    $candidates['assignment'] = $pdo->query("SELECT a.id,p.name product_name,a.quantity-a.returned_quantity quantity,e.full_name employee_name FROM assignments a JOIN products p ON p.id=a.product_id JOIN employees e ON e.id=a.employee_id WHERE a.status='assigned' AND a.asset_id IS NULL AND a.quantity>a.returned_quantity ORDER BY a.id DESC")->fetchAll();
    $candidates['disposal'] = $pdo->query("SELECT t.id,p.name product_name,ABS(t.quantity) quantity,t.reason FROM inventory_transactions t JOIN products p ON p.id=t.product_id WHERE t.transaction_type='out' AND (LOWER(t.reason) LIKE '%dispos%' OR LOWER(t.reason) LIKE '%damag%' OR LOWER(t.reason) LIKE '%write%off%') ORDER BY t.transaction_at DESC,t.id DESC")->fetchAll();
    $entries = $pdo->query('SELECT r.*,p.name product_name,u.full_name user_name FROM inventory_cost_reconciliations r JOIN products p ON p.id=r.product_id JOIN users u ON u.id=r.created_by ORDER BY r.created_at DESC,r.id DESC LIMIT 100')->fetchAll();
}

$pageTitle = 'Cost Reconciliation';
require __DIR__ . '/header.php';
?>
<section class="card history-page history-maintenance-page">
    <div class="history-section-heading"><h2>Historical cost reconciliation</h2><a class="btn" href="history.php">Back to History</a></div>
    <?php if (!$reconciliationReady): ?>
        <p class="muted">Cost reconciliation is not available in this installation yet.</p>
    <?php else: ?>
        <p class="muted">Record a verified source for historical costs. Saved entries preserve the original stock transactions.</p>
        <form method="post" class="history-reconciliation-form">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES)?>">
            <input type="hidden" name="action" value="reconcile_cost">
            <label for="reconcile-kind">Source type</label>
            <select id="reconcile-kind" name="kind" required><option value="receipt">Receipt</option><option value="asset">Registered asset</option><option value="assignment">Active assignment</option><option value="disposal">Disposal / write-off</option></select>
            <label for="reconcile-source">Record</label>
            <select id="reconcile-source" name="source_id" required><option value="">Choose a record</option><?php foreach ($candidates as $kind=>$candidateRows): foreach ($candidateRows as $candidate): $label='#'.$candidate['id'].' · '.$candidate['product_name']; if($kind==='receipt')$label.=' · qty '.abs((int)$candidate['quantity']).($candidate['order_id']?' · PO #'.$candidate['order_id'].' ('.$candidate['order_status'].')':'').($candidate['po_unit_cost']!==null?' · suggested '.formatInr($candidate['po_unit_cost']):''); elseif($kind==='asset')$label.=' · '.($candidate['asset_tag']?:$candidate['serial_number']?:'asset'); elseif($kind==='assignment')$label.=' · '.$candidate['employee_name'].' · qty '.$candidate['quantity']; else $label.=' · qty '.$candidate['quantity'].' · '.$candidate['reason']; ?><option value="<?=$candidate['id']?>" data-kind="<?=$kind?>" hidden><?=htmlspecialchars($label)?></option><?php endforeach; endforeach; ?></select>
            <label for="reconcile-qty">Quantity</label><input id="reconcile-qty" name="quantity" type="number" min="1" step="1" required>
            <label for="reconcile-cost">Verified unit cost (₹)</label><input id="reconcile-cost" name="unit_cost" type="number" min="0" step="0.01" required>
            <label for="reconcile-reference">Source reference</label><input id="reconcile-reference" name="source_reference" maxlength="180" required>
            <label for="reconcile-notes">Notes</label><textarea id="reconcile-notes" name="notes" required></textarea>
            <button class="btn" type="submit">Record cost</button>
        </form>
        <?php if ($entries): ?><div class="table-scroll"><table class="history-table"><thead><tr><th>Date</th><th>Kind</th><th>Product</th><th>Quantity</th><th>Unit cost</th><th>Source reference</th><th>Notes</th><th>Recorded by</th></tr></thead><tbody><?php foreach ($entries as $entry): ?><tr><td><?=htmlspecialchars($entry['created_at'])?></td><td><?=htmlspecialchars(ucfirst($entry['kind']))?></td><td><?=htmlspecialchars($entry['product_name'])?></td><td><?=$entry['quantity']?></td><td><?=htmlspecialchars(formatInr($entry['unit_cost']))?></td><td><?=htmlspecialchars($entry['source_reference'])?></td><td><?=htmlspecialchars($entry['notes'])?></td><td><?=htmlspecialchars($entry['user_name'])?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <script>(function(){const kind=document.getElementById('reconcile-kind'),source=document.getElementById('reconcile-source');function sync(){Array.from(source.options).forEach(function(option){if(option.value)option.hidden=option.dataset.kind!==kind.value;});source.value='';}kind.addEventListener('change',sync);sync();})();</script>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
