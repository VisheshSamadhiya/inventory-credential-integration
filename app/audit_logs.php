<?php
require_once __DIR__ . '/auth.php';
requirePermission('audit_logs', 'view');
$pageTitle = 'Audit Logs';
$search = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($search !== '') {
    $where = 'WHERE a.action LIKE ? OR a.entity_type LIKE ? OR a.details LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?';
    $term = '%' . $search . '%';
    $params = [$term, $term, $term, $term, $term];
}
$stmt = db()->prepare(
    'SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.created_at,
            COALESCE(u.full_name, "System") AS user_name
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id '
    . $where . ' ORDER BY a.created_at DESC, a.id DESC LIMIT 250'
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
require __DIR__ . '/header.php';
?>
<section class="card">
    <form method="get" class="toolbar"><div><label for="q">Search audit logs</label><input id="q" name="q" value="<?=htmlspecialchars($search)?>" placeholder="User, action, or details"></div><button class="btn" type="submit">Search</button></form>
    <div class="table-scroll"><table><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody>
    <?php foreach($rows as $row): ?><tr><td><?=htmlspecialchars($row['created_at'])?></td><td><?=htmlspecialchars($row['user_name'])?></td><td><?=htmlspecialchars(str_replace('_',' ', $row['action']))?></td><td><?=htmlspecialchars(trim(($row['entity_type']??'').' '.($row['entity_id']??''))?:'—')?></td><td><?=htmlspecialchars($row['details']??'')?></td></tr><?php endforeach; ?>
    <?php if(!$rows): ?><tr><td colspan="5" class="muted">No audit entries found.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if(count($rows)===250): ?><p class="muted">Showing the latest 250 matching entries.</p><?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
