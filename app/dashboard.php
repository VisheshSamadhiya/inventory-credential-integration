<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/credential_helpers.php';
requirePermission('dashboard', 'view');
$pageTitle = 'Dashboard';
$pageHeadingInTemplate = true;

$dashboardName = trim((string)($_SESSION['user']['full_name'] ?? ''));
if ($dashboardName === '') $dashboardName = trim((string)($_SESSION['user']['username'] ?? ''));
$dashboardNameParts = preg_split('/\s+/u', $dashboardName, 2);
$dashboardFirstName = is_array($dashboardNameParts) && ($dashboardNameParts[0] ?? '') !== '' ? $dashboardNameParts[0] : 'there';
$dashboardLocalTime = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
$dashboardHour = (int)$dashboardLocalTime->format('G');
if ($dashboardHour >= 5 && $dashboardHour < 12) {
    $dashboardGreeting = 'Good Morning';
    $dashboardGreetingIcon = 'sunrise';
} elseif ($dashboardHour >= 12 && $dashboardHour < 17) {
    $dashboardGreeting = 'Good Afternoon';
    $dashboardGreetingIcon = 'sun';
} elseif ($dashboardHour >= 17 && $dashboardHour < 21) {
    $dashboardGreeting = 'Good Evening';
    $dashboardGreetingIcon = 'sunset';
} else {
    $dashboardGreeting = 'Good Night';
    $dashboardGreetingIcon = 'moon';
}
$pdo = db();
$canViewProducts = canViewResource('products');
$canViewInventory = canViewResource('inventory');
$canViewCategories = canViewResource('categories');
$canViewHistory = canViewResource('history');
$canViewCredentials = canViewResource('credentials');
$products = $canViewProducts ? (int)$pdo->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn() : 0;
$categories = $canViewCategories ? (int)$pdo->query('SELECT COUNT(*) FROM categories WHERE active = 1')->fetchColumn() : 0;
$stock = $canViewInventory ? (int)$pdo->query(
    'SELECT COALESCE(SUM(COALESCE((SELECT t.new_quantity FROM inventory_transactions t WHERE t.product_id=p.id ORDER BY t.transaction_at DESC,t.id DESC LIMIT 1),0)),0)
     FROM products p WHERE p.active = 1'
)->fetchColumn() : 0;
$lowStock = $canViewInventory ? (int)$pdo->query(
    'SELECT COUNT(*) FROM products p WHERE p.active = 1
     AND COALESCE((SELECT t.new_quantity FROM inventory_transactions t WHERE t.product_id=p.id ORDER BY t.transaction_at DESC,t.id DESC LIMIT 1),0) <= p.minimum_stock'
)->fetchColumn() : 0;
$credentialStats = ['active' => 0, 'employees' => 0];
if ($canViewCredentials) {
    [$credentialScope, $credentialParams] = credentialScopeSql('c');
    $credentialQuery = $pdo->prepare('SELECT COUNT(*) active_count, COUNT(DISTINCT c.employee_id) employee_count FROM credentials c WHERE c.active=1 AND ' . $credentialScope);
    $credentialQuery->execute($credentialParams);
    $credentialStats = $credentialQuery->fetch() ?: $credentialStats;
}
$recent = $canViewHistory ? $pdo->query(
    'SELECT t.transaction_at, t.transaction_type, t.quantity, t.new_quantity, t.reason,
            p.name AS product_name, u.full_name
     FROM inventory_transactions t JOIN products p ON p.id=t.product_id
     LEFT JOIN users u ON u.id=t.created_by
     ORDER BY t.transaction_at DESC,t.id DESC LIMIT 6'
)->fetchAll() : [];
require __DIR__ . '/header.php';
?>
<div class="dashboard-welcome" role="region" aria-label="Dashboard welcome">
    <span class="dashboard-welcome__icon" aria-hidden="true">
        <?php if ($dashboardGreetingIcon === 'sunrise'): ?>
        <svg viewBox="0 0 24 24" focusable="false"><path d="M12 2v7"></path><path d="m4.93 10.93 1.41 1.41"></path><path d="M2 18h2"></path><path d="M20 18h2"></path><path d="m17.66 12.34 1.41-1.41"></path><path d="M22 22H2"></path><path d="M16 18a4 4 0 0 0-8 0"></path></svg>
        <?php elseif ($dashboardGreetingIcon === 'sun'): ?>
        <svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="m4.93 4.93 1.42 1.42"></path><path d="m17.65 17.65 1.42 1.42"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="m6.35 17.65-1.42 1.42"></path><path d="m19.07 4.93-1.42 1.42"></path></svg>
        <?php elseif ($dashboardGreetingIcon === 'sunset'): ?>
        <svg viewBox="0 0 24 24" focusable="false"><path d="M12 10V2"></path><path d="m4.93 10.93 1.41 1.41"></path><path d="M2 18h2"></path><path d="M20 18h2"></path><path d="m17.66 12.34 1.41-1.41"></path><path d="M22 22H2"></path><path d="M16 18a4 4 0 0 0-8 0"></path><path d="M2 14h20"></path></svg>
        <?php else: ?>
        <svg viewBox="0 0 24 24" focusable="false"><path d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z"></path></svg>
        <?php endif; ?>
    </span>
    <div class="dashboard-welcome__copy">
        <p class="dashboard-welcome__name">Hey, <?=htmlspecialchars($dashboardFirstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>!</p>
        <p class="dashboard-welcome__time"><?=htmlspecialchars($dashboardGreeting, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>.</p>
    </div>
</div>
<div class="page-head dashboard-page-head"><div><div class="eyebrow"><?=htmlspecialchars($roleLabel)?> ACCESS</div><h1><?=htmlspecialchars($pageTitle)?></h1></div></div>
<section class="dashboard-stat-section" aria-labelledby="inventory-stats-heading"><div class="dashboard-section-heading"><h2 id="inventory-stats-heading">Inventory</h2><p>Stock and catalog overview</p></div><div class="grid stats dashboard-stats">
    <?php if($canViewProducts): ?><div class="card stat"><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 7 8-4 8 4-8 4-8-4Z"></path><path d="M4 7v10l8 4 8-4V7M12 11v10"></path></svg></span><span>Active products</span><strong><?=$products?></strong></div><?php endif; ?>
    <?php if($canViewCategories): ?><div class="card stat"><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg></span><span>Categories</span><strong><?=$categories?></strong></div><?php endif; ?>
    <?php if($canViewInventory): ?><div class="card stat"><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 7h18M5 7l1 13h12l1-13M9 7V4h6v3"></path><path d="M9 11v5m6-5v5"></path></svg></span><span>Units in stock</span><strong><?=$stock?></strong></div><div class="card stat"><span class="stat-icon stat-icon-warning" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 10 18H2L12 3Z"></path><path d="M12 9v5m0 3h.01"></path></svg></span><span>Low or out of stock</span><strong><?=$lowStock?></strong></div><?php endif; ?>
</div></section>
<?php if($canViewCredentials): ?><section class="dashboard-stat-section credential-stat-section" aria-labelledby="credential-stats-heading"><div class="dashboard-section-heading"><h2 id="credential-stats-heading">Credentials</h2><p>Authorized vault overview</p></div><div class="grid stats dashboard-stats credential-stats"><div class="card stat"><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"></path></svg></span><span>Active credentials</span><strong><?= (int)$credentialStats['active_count']?></strong></div><div class="card stat"><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3 20v-1a6 6 0 0 1 12 0v1m2-10a3 3 0 1 0 0-6m1 9a5 5 0 0 1 3 5v1"></path></svg></span><span>Employees with credentials</span><strong><?= (int)$credentialStats['employee_count']?></strong></div></div></section><?php endif; ?>
<div class="grid two">
    <?php if($canViewProducts): ?><section class="card"><h2>Inventory</h2><p>Find products, check stock, and record additions or removals.</p><a class="btn btn-glow" href="products.php">Open inventory</a></section><?php endif; ?>
    <?php if($canViewCategories): ?><section class="card"><h2>Categories</h2><p>Organize products with categories and sub-categories.</p><a class="btn" href="categories.php">Manage categories</a></section><?php endif; ?>
</div>
<?php if($canViewHistory): ?>
<section class="card section-spaced"><h2>Recent stock changes</h2><div class="table-scroll"><table><thead><tr><th>Date</th><th>Product</th><th>Action</th><th>Qty</th><th>Stock now</th><th>Reason</th><th>User</th></tr></thead><tbody>
<?php foreach($recent as $row): ?><tr><td><?=htmlspecialchars($row['transaction_at'])?></td><td><?=htmlspecialchars($row['product_name'])?></td><td><?=htmlspecialchars(['in'=>'Added','out'=>'Removed','opening'=>'Opening balance','ordered'=>'Ordered','adjustment'=>'Adjusted'][$row['transaction_type']]??$row['transaction_type'])?></td><td><?=$row['quantity']>0?'+':''?><?=$row['quantity']?></td><td><?=$row['new_quantity']?></td><td><?=htmlspecialchars($row['reason'])?></td><td><?=htmlspecialchars($row['full_name']??'System')?></td></tr><?php endforeach; ?>
<?php if(!$recent): ?><tr><td colspan="7" class="muted">No stock changes have been recorded.</td></tr><?php endif; ?>
</tbody></table></div><a href="history.php">View full history</a></section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
