<?php
require_once __DIR__ . '/inventory_helpers.php'; require_once __DIR__ . '/asset_tag_helpers.php'; require_once __DIR__ . '/order_notifications.php'; if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')requirePermission('inventory','view'); $pageTitle='Stock movements';
$canAdjustInventory=canPerform('inventory','stock_adjustment');$canCreateOrder=canPerform('orders','add');$canAddInventoryProduct=canPerform('products','add')&&canViewResource('categories');
$categories=canViewResource('categories')?db()->query('SELECT id,name FROM categories WHERE active=1 ORDER BY name')->fetchAll():[];
$orderVendors=canPerform('orders','add')&&canViewResource('vendors')?db()->query('SELECT id,vendor_name,contact_person,phone,email,address,gstin FROM vendors WHERE active=1 ORDER BY vendor_name')->fetchAll():[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 $db=db(); $addingProduct=isset($_POST['add_product']); $pid=(int)($_POST['product_id']??0);
 $action=(string)($_POST['action']??'movement');
 $type=(string)($_POST['transaction_type']??'');
 $permission=$action==='receive_order'?['orders','order_receive']:($type==='ordered'?['orders','add']:['inventory','stock_adjustment']);
 if($addingProduct) requirePermission('products','add');
 requirePermission($permission[0],$permission[1]);
 verifyCsrf();
 if($action==='receive_order'){
  $orderId=(int)($_POST['order_id']??0);
  try {
   if($orderId<1) throw new DomainException('Choose an order to receive.');
   $q=$db->prepare("SELECT t.product_id,p.category_id,p.sub_category_id FROM inventory_transactions t JOIN products p ON p.id=t.product_id WHERE t.id=? AND t.transaction_type='ordered' AND t.status='Confirmed' AND t.quantity>(SELECT COALESCE(SUM(r.quantity),0) FROM inventory_transactions r WHERE r.related_order_id=t.id AND r.transaction_type='in')");$q->execute([$orderId]);$order=$q->fetch();
   if(!$order) throw new DomainException('Only confirmed purchase orders can be received.');
   header('Location: products.php?' . http_build_query(['category_id'=>(int)$order['category_id'],'sub_category_id'=>(int)($order['sub_category_id']??0),'product_id'=>(int)$order['product_id'],'receive_order'=>$orderId]));exit;
  } catch(DomainException $e){if($db->inTransaction())$db->rollBack();setFlash('error',$e->getMessage());}
  catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Order receiving failed: '.$e->getMessage());setFlash('error','Unable to receive the order. Please try again.');}
  header('Location: inventory.php');exit;
 }
 $quantityInput=trim((string)($_POST['quantity']??''));
 $quantityPattern=$type==='adjustment'?'/^-?[0-9]{1,10}$/D':'/^[0-9]{1,10}$/D';
 if(!preg_match($quantityPattern,$quantityInput)||(int)$quantityInput>2147483647||(int)$quantityInput< -2147483648){setFlash('error','Enter a valid quantity.');header('Location: inventory.php?transaction_type='.rawurlencode($type));exit;}
 $qty=(int)$quantityInput;
 $reason=trim((string)($_POST['reason']??''));
 $dateInput=trim((string)($type==='ordered'?($_POST['transaction_at']??''):($_POST['movement_at']??'')));
 $date=$dateInput!==''?str_replace('T',' ',$dateInput).(strlen($dateInput)===16?':00':''):date('Y-m-d H:i:s');
 $allowedTypes=['ordered','out','adjustment','opening'];
 $categoryId=(int)($_POST['category_id']??0); $name=trim((string)($_POST['name']??''));
 $requestKey=strtolower(trim((string)($_POST['order_request_key']??'')));
 try {
  if(!in_array($type,$allowedTypes,true))throw new DomainException('Choose a valid stock operation.');
  if(!preg_match('/^[a-f0-9]{64}$/D',$requestKey)||($type==='ordered'?!orderRequestTokenMatches($requestKey,$_SESSION['order_request_token']??null,$_SESSION['last_order_request_token']??null):!hash_equals((string)($_SESSION['order_request_token']??''),$requestKey)))throw new DomainException('The stock form has expired. Refresh the page and try again.');
  if(($qty<1&&$type!=='adjustment')||($type!=='ordered'&&$reason===''))throw new DomainException('Enter a positive quantity and a reason.');
  $unit=trim((string)($_POST['unit']??''));
  if($addingProduct&&$unit!==''&&!preg_match('/^[0-9]+$/D',$unit))throw new DomainException('Unit must contain numbers only.');
  if($addingProduct&&($categoryId<=0||$name===''))throw new DomainException('Select a category and enter a product name.');
  if(!$addingProduct&&$pid<=0)throw new DomainException('Please select a product.');
  $unitPrice=null;$totalPrice=null;$orderReason=null;$vendor=null;$vendorRecord=null;$expectedDate=null;$status=null;
  if($type==='ordered'){
   $priceInput=trim((string)($_POST['unit_price']??''));$reasonChoice=(string)($_POST['order_reason']??'');$expectedDate=trim((string)($_POST['expected_date']??''))?:null;
   if(!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,2})?$/D',$priceInput)||!in_array($reasonChoice,['Requested','New','Replacement'],true))throw new DomainException('Enter a valid non-negative unit price and order reason.');
   if($expectedDate!==null&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$expectedDate))throw new DomainException('Enter a valid expected date.');
   [$whole,$fraction]=array_pad(explode('.',$priceInput,2),2,'');$unitCents=((int)$whole*100)+(int)str_pad($fraction,2,'0');
   if($unitCents>0&&$qty>intdiv(999999999999999,$unitCents))throw new DomainException('Total price exceeds the supported limit.');
   $totalCents=$unitCents*$qty;$unitPrice=$whole.'.'.str_pad($fraction,2,'0');$totalPrice=intdiv($totalCents,100).'.'.str_pad((string)($totalCents%100),2,'0',STR_PAD_LEFT);$orderReason=$reasonChoice;$status='Pending';$reason=$orderReason;
  }
  $db->beginTransaction();
  $duplicate=$db->prepare('SELECT id FROM inventory_transactions WHERE request_key=? LIMIT 1 FOR UPDATE');$duplicate->execute([$requestKey]);if($duplicate->fetchColumn()){ $db->rollBack();$_SESSION['last_order_request_token']=$requestKey;$_SESSION['order_request_token']=bin2hex(random_bytes(32));setFlash('success',$type==='ordered'?'This order was already submitted.':'This stock change was already submitted.');header('Location: inventory.php?transaction_type='.rawurlencode($type));exit; }
  if($type==='ordered'){$vendorRecord=activeVendorForOrder($db,$_POST['vendor_id']??null);$vendor=$vendorRecord['vendor_name'];}
  if($addingProduct){
   $subRaw=trim((string)($_POST['sub_category_id']??'')); $subId=$subRaw===''?null:(int)$subRaw;
   $cat=$db->prepare('SELECT id FROM categories WHERE id=? AND active=1'); $cat->execute([$categoryId]); if(!$cat->fetchColumn())throw new DomainException('Please select an active category.');
   if($subId!==null){$sub=$db->prepare('SELECT id FROM sub_categories WHERE id=? AND category_id=? AND active=1');$sub->execute([$subId,$categoryId]);if(!$sub->fetchColumn())throw new DomainException('The selected sub-category does not belong to that category.');}
   $insert=$db->prepare('INSERT INTO products(category_id,sub_category_id,name,brand,model,unit,minimum_stock,serialized,description) VALUES(?,?,?,?,?,?,?,?,?)');
   $insert->execute([$categoryId,$subId,$name,trim((string)($_POST['brand']??''))?:null,trim((string)($_POST['model']??''))?:null,$unit,max(0,(int)($_POST['minimum_stock']??0)),isset($_POST['serialized'])?1:0,trim((string)($_POST['description']??''))?:null]);
   $pid=(int)$db->lastInsertId();
  }
  $check=$db->prepare('SELECT id FROM products WHERE id=? AND active=1 FOR UPDATE');$check->execute([$pid]);if(!$check->fetchColumn())throw new DomainException('Selected product is unavailable.');
  $prev=currentStock($pid);$movement=calculateInventoryChange($prev,$type,$qty);$delta=$movement['delta'];$new=$movement['new_stock'];$recordedQuantity=$movement['recorded_quantity'];assertAssetTagStockChangeAllowed($db,$pid,$delta,$new);
  $s=$db->prepare('INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,unit_price,total_price,order_reason,vendor,vendor_id,vendor_name_snapshot,vendor_contact_snapshot,vendor_phone_snapshot,vendor_email_snapshot,vendor_address_snapshot,vendor_gstin_snapshot,expected_date,status,notes,created_by,transaction_at,request_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $s->execute([$pid,$type,$recordedQuantity,$prev,$new,$reason,$unitPrice,$totalPrice,$orderReason,$vendor,$vendorRecord['id']??null,$vendorRecord['vendor_name']??null,$vendorRecord['contact_person']??null,$vendorRecord['phone']??null,$vendorRecord['email']??null,$vendorRecord['address']??null,$vendorRecord['gstin']??null,$expectedDate,$status,trim((string)($_POST['notes']??'')),currentUser()['id'],$date,$requestKey]);$createdOrderId=$type==='ordered'?(int)$db->lastInsertId():0;
  $movementId=(int)$db->lastInsertId();
  if($delta<0){
   $cost=allocateInventoryCost($db,$pid,abs($delta),'stock_removal',null,$movementId);
   if($cost!==null)$db->prepare('UPDATE inventory_transactions SET unit_price=?,total_price=? WHERE id=?')->execute([$cost['unit_price'],$cost['total_price'],$movementId]);
  }
  if($addingProduct)audit('create','product',$pid,$name);
  audit('inventory_transaction','product',$pid,$type.' '.$recordedQuantity.'; stock '.$prev.' → '.$new.'; '.$reason);
  recalculateInventoryMonths($pid);
  if($type==='ordered'){
   $productNameQ=$db->prepare('SELECT name FROM products WHERE id=?');$productNameQ->execute([$pid]);$requester=currentUser();$notificationOrder=['id'=>$createdOrderId,'product_name'=>(string)$productNameQ->fetchColumn(),'quantity'=>$qty,'requester_name'=>(string)($requester['full_name']??$requester['username']??'Requester'),'reason'=>$orderReason,'order_date'=>$date];
   $recipients=orderEmailRecipients(envv('ADMIN_EMAILS'));
   if(!$recipients)error_log(sprintf('Order notification skipped (order_id=%d event=new_order): no valid ADMIN_EMAILS recipient.',(int)$notificationOrder['id']));
   try{enqueueOrderNotifications($db,'new_order',$notificationOrder,$recipients);}catch(Throwable $notificationException){error_log(sprintf('Order notification could not be queued (order_id=%d event=new_order error_code=%s).',(int)$notificationOrder['id'],(string)$notificationException->getCode()));}
  }
  $db->commit();$_SESSION['last_order_request_token']=$requestKey;$_SESSION['order_request_token']=bin2hex(random_bytes(32));setFlash('success',$addingProduct?'Product and stock movement saved successfully.':'Stock updated successfully.');
 }catch(DomainException $e){if($db->inTransaction())$db->rollBack();setFlash('error',$e->getMessage());}
 catch(PDOException $e){if($db->inTransaction())$db->rollBack();if(isset($requestKey)&&preg_match('/^[a-f0-9]{64}$/D',$requestKey)){$duplicate=$db->prepare('SELECT id FROM inventory_transactions WHERE request_key=? LIMIT 1');$duplicate->execute([$requestKey]);if($duplicate->fetchColumn()){$_SESSION['last_order_request_token']=$requestKey;$_SESSION['order_request_token']=bin2hex(random_bytes(32));setFlash('success',$type==='ordered'?'This order was already submitted.':'This stock change was already submitted.');header('Location: inventory.php?transaction_type='.rawurlencode($type));exit;}}error_log('Inventory movement failed: '.$e->getMessage());setFlash('error',(int)($e->errorInfo[1]??0)===1062?'A product with those details already exists.':'Unable to record the stock change. Please try again.');}
 catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Inventory movement failed: '.$e->getMessage());setFlash('error','Unable to record the stock change. Please try again.');}
 header('Location: inventory.php?transaction_type='.rawurlencode(in_array($type,$allowedTypes,true)?$type:'out'));exit;
}
if(!isset($_SESSION['order_request_token'])||!preg_match('/^[a-f0-9]{64}$/D',(string)$_SESSION['order_request_token']))$_SESSION['order_request_token']=bin2hex(random_bytes(32));
$products=db()->query('SELECT id,name FROM products WHERE active=1 ORDER BY name')->fetchAll();
$pendingOrders=canPerform('orders','order_receive')?db()->query("SELECT t.id,t.quantity,t.unit_price,t.total_price,COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) vendor,t.expected_date,t.status,p.name product_name,p.id product_id,p.category_id,p.sub_category_id,COALESCE(SUM(r.quantity),0) received_quantity FROM inventory_transactions t JOIN products p ON p.id=t.product_id LEFT JOIN vendors v ON v.id=t.vendor_id LEFT JOIN inventory_transactions r ON r.related_order_id=t.id AND r.transaction_type='in' WHERE t.transaction_type='ordered' AND t.status='Confirmed' AND t.quantity>0 GROUP BY t.id HAVING t.quantity>received_quantity ORDER BY t.transaction_at")->fetchAll():[];
$subcategories=db()->query('SELECT id,category_id,name FROM sub_categories WHERE active=1 ORDER BY name')->fetchAll();
$months=db()->query("SELECT im.*,p.name product_name FROM inventory_months im JOIN products p ON p.id=im.product_id ORDER BY im.year DESC,im.month DESC,p.name")->fetchAll();
$tx=db()->query("SELECT t.*,p.name product_name,u.full_name FROM inventory_transactions t JOIN products p ON p.id=t.product_id JOIN users u ON u.id=t.created_by ORDER BY t.transaction_at DESC,t.id DESC LIMIT 50")->fetchAll();
$transactionLabels=['ordered'=>'Ordered','in'=>'Received','out'=>'Used','opening'=>'Opening balance','adjustment'=>'Adjustment'];
$selectedMovementType=(string)($_GET['transaction_type']??'out');if(!in_array($selectedMovementType,['out','adjustment','opening','ordered'],true))$selectedMovementType='out';
require __DIR__.'/header.php';
?>
<div class="inventory-screen inventory-stock-screen grid two">
<?php if($canAdjustInventory||$canCreateOrder): ?><div class="card"><h2>Stock Movement</h2><form method="post" class="stacked-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="movement"><input type="hidden" name="order_request_key" value="<?=htmlspecialchars((string)$_SESSION['order_request_token'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">
<?php if($canAddInventoryProduct): ?><label class="check"><input type="checkbox" name="add_product" id="add_product"> Write a new product and add it to Products</label><?php endif; ?>
<div id="existing_product"><label>Product</label><select name="product_id" id="product_id" required><?php foreach($products as $p): ?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option><?php endforeach;?></select></div>
<div id="new_product" hidden>
<label>Category</label><select name="category_id" id="category_id"><option value="">Select category</option><?php foreach($categories as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach;?></select>
<label>Sub-category</label><select name="sub_category_id" id="sub_category_id"><option value="">No sub-category</option><?php foreach($subcategories as $s): ?><option value="<?=$s['id']?>" data-category="<?=$s['category_id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach;?></select>
<label>Product name</label><input name="name" id="new_product_name">
<label>Brand</label><input name="brand"><label>Model</label><input name="model">
<label>Minimum stock</label><input name="minimum_stock" type="number" min="0" value="0">
<label>Unit (numbers only)</label><input name="unit" inputmode="numeric" pattern="[0-9]*" data-numeric-only>
<label class="check"><input name="serialized" type="checkbox"> Serialized asset</label><label>Description</label><textarea name="description"></textarea>
</div>
<label>Type</label><select name="transaction_type" id="transaction_type"><?php if($canAdjustInventory): ?><option value="out" <?=$selectedMovementType==='out'?'selected':''?>>Remove stock</option><option value="adjustment" <?=$selectedMovementType==='adjustment'?'selected':''?>>Adjustment (+/-)</option><option value="opening" <?=$selectedMovementType==='opening'?'selected':''?>>Opening Balance</option><?php endif; ?><?php if($canCreateOrder): ?><option value="ordered" <?=$selectedMovementType==='ordered'?'selected':''?>>Order Stock</option><?php endif; ?></select>
<label>Quantity</label><input name="quantity" id="movement_quantity" type="number" min="1" step="1" required>
<div id="order_fields"><label>Unit Price (₹)</label><input name="unit_price" type="number" min="0" step="0.01" inputmode="decimal"><label>Total Price (₹)</label><output id="total_price">₹0.00</output><label>Reason</label><select name="order_reason"><option value="">Choose reason</option><option>Requested</option><option>New</option><option>Replacement</option></select><?php renderVendorPicker($orderVendors,'movement-vendor'); ?><label>Order Date</label><input name="transaction_at" type="datetime-local" value="<?=date('Y-m-d\TH:i')?>"><label>Expected Date</label><input name="expected_date" type="date"><label>Status</label><input value="Pending" readonly></div>
<div id="movement_reason" hidden><label>Reason</label><input name="reason" placeholder="Assignment, Damage, Correction..."><label>Date/time</label><input name="movement_at" type="datetime-local" value="<?=date('Y-m-d\TH:i')?>"></div><label>Notes</label><textarea name="notes"></textarea><button class="btn btn-glow">Record Movement</button></form></div><?php endif;?>
<div class="card"><h2>Monthly Snapshots</h2><div class="table-scroll"><table><tr><th>Product</th><th>Month</th><th>Opening</th><th>Received</th><th>Used</th><th>Remaining</th></tr><?php foreach($months as $m): ?><tr><td><?=htmlspecialchars($m['product_name'])?></td><td><?=sprintf('%02d/%d',$m['month'],$m['year'])?></td><td><?=$m['opening_quantity']?></td><td><?=$m['total_in']?></td><td><?=$m['total_out']?></td><td><strong><?=$m['closing_quantity']?></strong></td></tr><?php endforeach;?></table></div></div>
</div>
<div class="card section-spaced"><h2>Recent Transactions</h2><div class="table-scroll"><table><tr><th>Date</th><th>Product</th><th>Type</th><th>Previous</th><th>Change</th><th>New</th><th>Reason</th><th>By</th></tr><?php foreach($tx as $t): ?><tr><td><?=$t['transaction_at']?></td><td><?=htmlspecialchars($t['product_name'])?></td><td><?=htmlspecialchars($transactionLabels[$t['transaction_type']]??$t['transaction_type'])?></td><td><?=$t['previous_quantity']?></td><td><?=$t['transaction_type']==='ordered'?'+'.$t['quantity']:$t['quantity']?></td><td><?=$t['new_quantity']?></td><td><?=htmlspecialchars($t['reason'])?></td><td><?=htmlspecialchars($t['full_name'])?></td></tr><?php endforeach;?></table></div></div>
<?php if(canPerform("orders","order_receive")&&$pendingOrders): ?><div class="card section-spaced"><h2>Orders Awaiting Receipt</h2><div class="table-scroll"><table><tr><th>Product</th><th>Qty Remaining</th><th>Status</th><th>Order Total</th><th>Vendor</th><th>Expected</th><th>Action</th></tr><?php foreach($pendingOrders as $o): ?><tr><td><?=htmlspecialchars($o['product_name'])?></td><td><?=$o['quantity']-$o['received_quantity']?> of <?=$o['quantity']?></td><td><?=htmlspecialchars($o['status'])?></td><td><?=formatInr($o['total_price'])?></td><td><?=htmlspecialchars($o['vendor'])?></td><td><?=htmlspecialchars($o['expected_date']??'—')?></td><td><a class="btn" href="products.php?<?=htmlspecialchars(http_build_query(['category_id'=>(int)$o['category_id'],'sub_category_id'=>(int)($o['sub_category_id']??0),'product_id'=>(int)$o['product_id'],'receive_order'=>(int)$o['id']]))?>">Receive Order</a></td></tr><?php endforeach;?></table></div></div><?php endif;?>
<?php require __DIR__.'/footer.php'; ?>
<script>
(function(){
document.querySelectorAll('[data-numeric-only]').forEach(function(field){const validate=function(){field.setCustomValidity(field.value!==''&&!/^[0-9]+$/.test(field.value)?'Unit must contain numbers only.':'');};field.addEventListener('input',validate);field.form.addEventListener('submit',validate);});
 const toggle=document.getElementById('add_product'),existing=document.getElementById('existing_product'),fresh=document.getElementById('new_product');
 if(!toggle)return;
 const product=document.getElementById('product_id'),category=document.getElementById('category_id'),name=document.getElementById('new_product_name'),sub=document.getElementById('sub_category_id');
 function update(){existing.hidden=toggle.checked;fresh.hidden=!toggle.checked;product.required=!toggle.checked;category.required=toggle.checked;name.required=toggle.checked;}
 function filterSubs(){for(const option of sub.options){if(!option.value)continue;const show=option.dataset.category===category.value;option.hidden=!show;if(!show&&option.selected)sub.value='';}}
 toggle.addEventListener('change',update);category.addEventListener('change',filterSubs);update();filterSubs();
})();
const typeField=document.getElementById('transaction_type'); if(typeField){const orderFields=document.getElementById('order_fields'),movementReason=document.getElementById('movement_reason');function updateOrderFields(){const ordered=typeField.value==='ordered';orderFields.hidden=!ordered;movementReason.hidden=ordered;orderFields.querySelectorAll('input,select').forEach(function(el){el.required=ordered&&(['unit_price','order_reason'].includes(el.name)||el.matches('[data-vendor-search]'));});movementReason.querySelectorAll('input').forEach(function(el){el.required=!ordered&&el.name==='reason';});}typeField.addEventListener('change',updateOrderFields);updateOrderFields();const qty=document.getElementById('movement_quantity'),price=document.querySelector('[name="unit_price"]'),total=document.getElementById('total_price');function calc(){total.textContent=window.formatCurrencyINR((Number(qty.value)||0)*(Number(price.value)||0));}qty.addEventListener('input',calc);price.addEventListener('input',calc);}
</script>
