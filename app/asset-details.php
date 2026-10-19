<?php
declare(strict_types=1);
require_once __DIR__ . '/asset_tag_helpers.php';

$assetId = filter_var($_GET['id'] ?? $_POST['asset_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$assetId) { http_response_code(404); exit('Hardware asset not found.'); }
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost) {
    requirePermission('hardware','edit');
    verifyCsrf();
    try {
        updateAssetManufacturerSerial(db(),(int)$assetId,(string)($_POST['serial_number']??''),(int)(currentUser()['id']??0));
        header('Location: asset-details.php?id='.(int)$assetId.'&message='.rawurlencode('Manufacturer serial number updated.'));
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = (string)($exception->errorInfo[1]??'')==='1062'
            ? 'That manufacturer serial number is already registered or reserved.'
            : 'Unable to update the manufacturer serial number.';
    }
} else {
    requirePermission('hardware','view');
}
$pdo=db();
$query=$pdo->prepare("SELECT ia.*,p.name product_name,c.name category_name,e.full_name employee_name,e.employee_id
    FROM inventory_assets ia JOIN products p ON p.id=ia.product_id LEFT JOIN categories c ON c.id=p.category_id
    LEFT JOIN active_hardware_assignments aha ON aha.asset_id=ia.id LEFT JOIN assignments a ON a.id=aha.assignment_id
    LEFT JOIN employees e ON e.id=a.employee_record_id WHERE ia.id=? LIMIT 1");
$query->execute([(int)$assetId]);$asset=$query->fetch();
if (!$asset) { http_response_code(404); exit('Hardware asset not found.'); }
$pageTitle='Asset Details';$safe=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$canEditHardware=canPerform('hardware','edit');
require __DIR__ . '/header.php';
?>
<section class="card asset-detail-card">
    <div class="section-heading"><div><p class="eyebrow">REGISTERED HARDWARE</p><h2>Asset Details</h2></div><a class="btn" href="hardware-assets.php">Back to hardware assets</a></div>
    <?php if (!empty($_GET['message'])): ?><div class="alert success" role="status"><?=$safe($_GET['message'])?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error" role="alert"><?=$safe($error)?></div><?php endif; ?>
    <dl class="asset-detail-grid"><div><dt>Asset tag</dt><dd><?=$safe($asset['asset_tag'])?></dd></div><div><dt>Product</dt><dd><?=$safe($asset['product_name'])?></dd></div><div><dt>Category</dt><dd><?=$safe($asset['category_name']??'—')?></dd></div><div><dt>Status</dt><dd><?=$safe(ucfirst($asset['status']))?></dd></div><div><dt>Manufacturer serial number</dt><dd><?=$safe($asset['serial_number']?:'Pending')?></dd></div><div><dt>Internal serial number</dt><dd><?=$safe($asset['internal_serial_number']?:'—')?></dd></div><div><dt>Location</dt><dd><?=$safe($asset['location']?:'—')?></dd></div><div><dt>Condition</dt><dd><?=$safe($asset['asset_condition']?:'—')?></dd></div><div><dt>Assigned to</dt><dd><?=$asset['employee_name']?$safe($asset['employee_name'].' · '.$asset['employee_id']):'—'?></dd></div><div><dt>Registered</dt><dd><?=$safe($asset['created_at'])?></dd></div></dl>
    <?php if($canEditHardware): ?><form method="post" class="profile-form asset-serial-edit-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><input type="hidden" name="asset_id" value="<?=(int)$assetId?>"><div class="form-wide"><label for="manufacturer-serial">Manufacturer serial number</label><input id="manufacturer-serial" name="serial_number" maxlength="150" value="<?=$safe($asset['serial_number']??'')?>" placeholder="Enter actual manufacturer serial number or leave blank as Pending"><small>Saving an actual serial preserves the asset's internal serial number.</small></div><div class="form-actions"><button class="btn btn-glow" type="submit">Save manufacturer serial</button></div></form><?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
