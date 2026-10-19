<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/inventory_helpers.php';
require_once __DIR__ . '/../app/product_selection.php';
require_once __DIR__ . '/../app/category_inventory_helpers.php';
require_once __DIR__ . '/../app/hardware_assignment_helpers.php';
require_once __DIR__ . '/../app/asset_tag_helpers.php';
require_once __DIR__ . '/order_email_test.php';
require_once __DIR__ . '/order_notifications_test.php';
require_once __DIR__ . '/purchase_order_receipt_test.php';
require_once __DIR__ . '/credential_integration_test.php';
require_once __DIR__ . '/audit_log_test.php';
require_once __DIR__ . '/inventory_cost_test.php';
require_once __DIR__ . '/employee_hardware_valuation_test.php';
require_once __DIR__ . '/history_detail_test.php';
require_once __DIR__ . '/../app/employee_software_credentials.php';

$checks = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$checks): void {
    $checks++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $operation, string $message) use (&$checks): void {
    $checks++;
    try {
        $operation();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException($message . ': expected DomainException');
};

$assertSame(['delta' => 5, 'recorded_quantity' => 5, 'new_stock' => 15], calculateInventoryChange(10, 'in', 5), 'Stock received');
$assertSame(true, assetTagStockChangeWithinReservationFloor(5, 15, 22), 'Positive receipts bypass the reduction-only reservation floor');
$assertSame(false, assetTagStockChangeWithinReservationFloor(-15, 18, 22), 'A stock reduction below the registered or queued asset floor is blocked');
$assertSame(true, assetTagStockChangeWithinReservationFloor(-11, 22, 22), 'A stock reduction to the asset reservation floor is allowed');
$assertSame(['delta' => -5, 'recorded_quantity' => -5, 'new_stock' => 5], calculateInventoryChange(10, 'out', 5), 'Stock issued');
$assertSame(['delta' => -2, 'recorded_quantity' => -2, 'new_stock' => 8], calculateInventoryChange(10, 'adjustment', -2), 'Negative adjustment');
$assertSame(['delta' => 0, 'recorded_quantity' => 5, 'new_stock' => 10], calculateInventoryChange(10, 'ordered', 5), 'Ordered stock does not become available');
$assertSame(['delta' => 5, 'recorded_quantity' => 5, 'new_stock' => 5], calculateInventoryChange(0, 'opening', 5), 'Opening balance');
$assertThrows(static fn() => calculateInventoryChange(2, 'opening', 1), 'Opening balance cannot be repeated');
$assertThrows(static fn() => calculateInventoryChange(2, 'out', 3), 'Negative stock is rejected');
$assertThrows(static fn() => calculateInventoryChange(2, 'unexpected', 1), 'Unknown movement is rejected');
$assertSame(['active' => 0, 'inactive' => 10, 'total' => 10], categoryInventoryStockTotals(0, 10), 'Category stock with ten unassigned units');
$assertSame(['active' => 3, 'inactive' => 7, 'total' => 10], categoryInventoryStockTotals(3, 7), 'Category stock after partial assignment of three units');
$assertSame(['active' => 1, 'inactive' => 9, 'total' => 10], categoryInventoryStockTotals(1, 9), 'Category stock after return of two units');
$selectionProducts = [
    ['id'=>11,'category_id'=>1,'sub_category_id'=>101,'name'=>'Laptop'],
    ['id'=>12,'category_id'=>1,'sub_category_id'=>102,'name'=>'Switch'],
    ['id'=>13,'category_id'=>1,'sub_category_id'=>null,'name'=>'General device'],
    ['id'=>21,'category_id'=>2,'sub_category_id'=>201,'name'=>'Router'],
];
$assertSame([11,12,13], array_column(filterInventoryProductsForSelection($selectionProducts, 1), 'id'), 'Category-only inventory selection returns every category product');
$assertSame([11], array_column(filterInventoryProductsForSelection($selectionProducts, 1, 101), 'id'), 'Category and sub-category selection returns matching products');
$assertSame(true, inventoryProductSelectionIsValid($selectionProducts, 11, 1, 101), 'Individual product selection opens a product in the selected sub-category');
$assertSame(false, inventoryProductSelectionIsValid($selectionProducts, 21, 1), 'Individual product selection rejects a product outside the selected category');
$categoryDetailProducts = [
    ['id'=>11,'sub_category_id'=>101,'assigned_quantity'=>3,'available_quantity'=>7],
    ['id'=>12,'sub_category_id'=>102,'assigned_quantity'=>0,'available_quantity'=>4],
    ['id'=>13,'sub_category_id'=>null,'assigned_quantity'=>1,'available_quantity'=>2],
];
$assertSame([11,12,13], array_column(filterCategoryInventoryRows($categoryDetailProducts), 'id'), 'Category details show every product without filters');
$assertSame([11], array_column(filterCategoryInventoryRows($categoryDetailProducts, 101), 'id'), 'Category details filter by sub-category');
$assertSame([12], array_column(filterCategoryInventoryRows($categoryDetailProducts, 0, 12), 'id'), 'Category details filter by product');
$assertSame(['active'=>4,'inactive'=>13,'total'=>17], categoryInventoryDetailsTotals($categoryDetailProducts), 'Category totals use assigned and available quantities');
foreach ([1, 2, 7, 42] as $categoryId) {
    $assertSame('category-inventory.php?category_id=' . $categoryId, categoryInventoryDetailsUrl($categoryId), 'Dashboard category card routes by category ID');
}
$assertSame(['assigned'=>3,'available'=>17,'total'=>20], inventoryDrilldownStock(3,17), 'Product and total drill-down reconcile assigned and available quantities');
$assertSame(17, inventoryDrilldownBulkAvailable(20,3), 'Available drill-down aggregates stock not represented by listed assets');
$assertSame(0, inventoryDrilldownBulkAvailable(2,4), 'Available drill-down never creates a negative bulk quantity');
$assertSame(2, inventoryDrilldownIncludedAssetCount(2,4), 'Available drill-down only allocates ledger quantity to registered assets once');
$assertSame('inventory-details.php?view=product&category_id=7&product_id=42', inventoryDrilldownUrl('product',7,42), 'Product drill-down route carries validated category and product IDs');
$assertSame('inventory-details.php?view=brand&category_id=7&brand=Dell', inventoryDrilldownUrl('brand',7,0,'Dell'), 'Brand drill-down route preserves the selected brand');
$assertSame(['inventory:view','products:view','assignments:view','employees:view'], inventoryDrilldownRequiredPermissions('assigned'), 'Assigned view requires employee and assignment permissions');
$assertSame(['inventory:view','products:view','hardware:view'], inventoryDrilldownRequiredPermissions('available'), 'Available view requires hardware permissions');
$assertSame(['inventory:view','products:view','assignments:view','employees:view','hardware:view'], inventoryDrilldownRequiredPermissions('total'), 'Total view requires every detail permission used in its breakdown');
$assertSame(['inventory:view','products:view'], inventoryDrilldownRequiredPermissions('brand'), 'Brand view requires product and inventory permissions');
$assertSame([], inventoryDrilldownRequiredPermissions('unknown'), 'Unknown drill-down views receive no permission profile');
$assertSame('DTPL-CPU', normalizeAssetTagPrefix(' dtpl-cpu '), 'Asset tag prefixes normalize consistently');
$assertSame('DTPL-CPU-007', buildAssetTag('DTPL-CPU',7,3), 'Asset tag generator applies minimum zero padding');
$assertSame('DTPL-CPU-1000', buildAssetTag('DTPL-CPU',1000,3), 'Asset tag padding does not truncate a larger range');
$assertSame('SN-CPU-000001', buildInternalSerialNumber('sn-cpu',1), 'Automatic internal serials use a normalized configurable prefix and six digit minimum padding');
$assertSame(['MFG-A','MFG-B'], normalizeManufacturerSerials([' MFG-A ','MFG-B'],2), 'Manual manufacturer serials are trimmed and counted one per asset');
$assertThrows(static fn()=>normalizeManufacturerSerials(['MFG-A','mfg-a']), 'Manual manufacturer serial batches reject case-insensitive duplicates');
$assertThrows(static fn()=>normalizeManufacturerSerials(['MFG-A'],2), 'Manual manufacturer serial batches require one value per asset');
$csvSerialPath=sys_get_temp_dir().'/manufacturer-serials-'.bin2hex(random_bytes(5)).'.csv';
file_put_contents($csvSerialPath,"Product,Manufacturer Serial Number\nLaptop,MFG-CSV-1\nLaptop,MFG-CSV-2\n");
$assertSame(['MFG-CSV-1','MFG-CSV-2'],parseManufacturerSerialImport($csvSerialPath,'csv'),'CSV import reads the manufacturer serial column and skips its header');
unlink($csvSerialPath);
$assertSame(['product_id'=>1,'prefix'=>'SERIAL-TEST','starting_number'=>null,'quantity'=>2,'padding_digits'=>6,'asset_location'=>null,'asset_condition'=>null,'registration_type'=>'available','serial_mode'=>'automatic','serial_prefix'=>'SN','manufacturer_serials'=>[]],validateAssetTagBulkInput(['product_id'=>'1','prefix'=>'SERIAL-TEST','quantity'=>'2','padding_digits'=>'6','registration_type'=>'available','serial_mode'=>'automatic','serial_prefix'=>'sn']),'Bulk automatic serial mode validates and normalizes its prefix');
$manualSerialInput=validateAssetTagBulkInput(['product_id'=>'1','prefix'=>'SERIAL-MANUAL','quantity'=>'2','padding_digits'=>'3','registration_type'=>'available','serial_mode'=>'manual','manufacturer_serials'=>['MAN-1','MAN-2']]);
$assertSame(['MAN-1','MAN-2'],$manualSerialInput['manufacturer_serials'],'Bulk manual mode carries validated manufacturer serials into the registration job');
$assertSame('fill_later',validateAssetTagBulkInput(['product_id'=>'1','prefix'=>'SERIAL-PENDING','quantity'=>'1','padding_digits'=>'3','registration_type'=>'available','serial_mode'=>'fill_later'])['serial_mode'],'Bulk Fill Later mode allows a NULL manufacturer serial');
$assertSame(true, assetTagRangeHasExistingNumber([12=>true],10,20), 'Asset tag range conflict check catches an existing numeric tag');
$assertSame(false, assetTagRangeHasExistingNumber([12=>true],13,20), 'Asset tag range conflict check allows a disjoint numeric range');
$validBulkInput = validateAssetTagBulkInput(['product_id'=>'1','prefix'=>'dtpl-cpu','starting_number'=>'201','quantity'=>'800','padding_digits'=>'4','registration_type'=>'available','asset_location'=>'HQ','asset_condition'=>'Good']);
$assertSame(['product_id'=>1,'prefix'=>'DTPL-CPU','starting_number'=>201,'quantity'=>800,'padding_digits'=>4,'asset_location'=>'HQ','asset_condition'=>'Good','registration_type'=>'available','serial_mode'=>'fill_later','serial_prefix'=>null,'manufacturer_serials'=>[]], $validBulkInput, 'Bulk tag input is normalized and validated');
$assertThrows(static fn()=>normalizeAssetTagPrefix('bad prefix'), 'Asset tag prefixes reject spaces and unsafe separators');
$assertThrows(static fn()=>validateAssetTagBulkInput(['product_id'=>'1','prefix'=>'ASSET','quantity'=>'1000001','padding_digits'=>'3']), 'Bulk registration caps the requested quantity');
$assertThrows(static fn()=>buildAssetTag(str_repeat('A',99),1,3), 'Generated asset tags enforce the database length limit');
$tagWorkerSource = file_get_contents(__DIR__ . '/../app/bin/process_asset_tag_jobs.php');
$tagHelperSource = file_get_contents(__DIR__ . '/../app/asset_tag_helpers.php');
$tagPageSource = file_get_contents(__DIR__ . '/../app/hardware-assets.php');
$tagMigrationSource = file_get_contents(__DIR__ . '/../database/migrations/20261016_add_asset_tag_generator.sql');
$serialMigrationSource = file_get_contents(__DIR__ . '/../database/migrations/20261016_add_hardware_serial_modes.sql');
$assertSame(false, str_contains((string)$tagHelperSource, 'INSERT INTO inventory_transactions'), 'Tag registration never changes inventory transaction history');
$assertSame(true, str_contains((string)$tagHelperSource, 'FOR UPDATE SKIP LOCKED'), 'Concurrent workers claim separate jobs with row locking');
$assertSame(true, str_contains((string)$tagHelperSource, 'asset_tag_registry'), 'Tag generation uses the permanent asset tag registry');
$assertSame(true, str_contains((string)$tagHelperSource, 'request_hash'), 'Bulk retries verify the original request payload');
$assertSame(true, str_contains((string)$tagHelperSource, 'reconcileAssetTagAssignment'), 'Assigned tag reconciliation is explicit');
$assertSame(true, str_contains((string)$tagPageSource, 'Export CSV') && str_contains((string)$tagPageSource, 'Export XLSX') && str_contains((string)$tagPageSource, 'Print labels'), 'Hardware assets page includes exports and printable labels');
$assertSame(true, str_contains((string)$tagMigrationSource, 'INSERT IGNORE INTO asset_tag_registry') && str_contains((string)$tagMigrationSource, 'ON DELETE SET NULL'), 'Migration preserves existing tags as durable reservations');
$assertSame(true,str_contains((string)$serialMigrationSource,'internal_serial_number')&&str_contains((string)$serialMigrationSource,'manufacturer_serial_registry')&&str_contains((string)$serialMigrationSource,'INSERT IGNORE INTO manufacturer_serial_registry'),'Serial migration adds independent internal numbers and backfills current manufacturer serials safely');
$assetPageSource=file_get_contents(__DIR__.'/../app/hardware-assets.php');
$assetDetailSource=file_get_contents(__DIR__.'/../app/asset-details.php');
$employeeHardwareSource=file_get_contents(__DIR__.'/../app/employee.php');
$navigationSource=file_get_contents(__DIR__.'/../app/header.php');
$assertSame(true,str_contains((string)$assetPageSource,"'register_asset'")&&str_contains((string)$assetPageSource,"'create_job'")&&str_contains((string)$assetPageSource,"requirePermission('hardware','add')"),'Inventory Hardware Assets owns single and bulk registration under hardware RBAC');
$assertSame(true,str_contains((string)$assetPageSource,'available_assets')&&str_contains((string)$assetPageSource,"requirePermission('hardware','hardware_assignment')")&&str_contains((string)$assetPageSource,'LIMIT 20'),'Inventory asset search is permission checked and bounded');
$assertSame(false,str_contains((string)$employeeHardwareSource,'hardware_register')||str_contains((string)$employeeHardwareSource,'availableAssets')||str_contains((string)$employeeHardwareSource,'Available hardware'),'Employee Details does not register or enumerate company-wide available hardware');
$assertSame(true,str_contains((string)$employeeHardwareSource,"'hardware_assign'")&&str_contains((string)$employeeHardwareSource,"'hardware_replace'")&&str_contains((string)$employeeHardwareSource,"'hardware_close'")&&str_contains((string)$employeeHardwareSource,'verifyCsrf()'),'Employee hardware assignment, replacement and return/remove actions remain protected');
$assertSame(true,str_contains((string)$navigationSource,'nav-inventory-dropdown')&&str_contains((string)$navigationSource,'Hardware Assets'),'Hardware Assets is nested under Inventory navigation');
$assertSame(true,str_contains((string)$assetPageSource,'Manual manufacturer serials')&&str_contains((string)$assetPageSource,'Automatic internal serials')&&str_contains((string)$assetPageSource,'Fill Later')&&str_contains((string)$assetPageSource,'manufacturer_serial_file'),'Hardware registration exposes all serial modes and CSV/XLSX import');
$assertSame(true,str_contains((string)$assetDetailSource,'updateAssetManufacturerSerial')&&str_contains((string)$assetDetailSource,'Internal serial number'),'Asset Details supports later manufacturer serial entry while showing internal serials');
$drilldownSource = file_get_contents(__DIR__ . '/../app/inventory-details.php');
$categorySource = file_get_contents(__DIR__ . '/../app/category-inventory.php');
foreach (['Product Details','Brand Details','Assigned Inventory Details','Available Inventory Details','Total Inventory Details','Registered hardware','Inventory history','Assigned inventory','Available inventory','Total reconciliation'] as $viewMarker) {
    $assertSame(true, str_contains((string)$drilldownSource, $viewMarker), 'Inventory drill-down page includes ' . $viewMarker);
}
foreach (["'view'=>'product'", "'view'=>'brand'", "'view'=>'assigned'", "'view'=>'available'", "'view'=>'total'"] as $routeMarker) {
    $assertSame(true, str_contains((string)$categorySource, $routeMarker), 'Category Details links to the ' . $routeMarker . ' view');
}
$assertSame(true, str_contains((string)$categorySource, 'category-detail-search') && str_contains((string)$categorySource, '$sortColumns') && str_contains((string)$categorySource, '$detailPages'), 'Category inventory supports search, safe sorting, and pagination');
$dashboardSource = file_get_contents(__DIR__ . '/../app/dashboard.php');
$dashboardDetailsSource = file_get_contents(__DIR__ . '/../app/dashboard-details.php');
$credentialDashboardSource = file_get_contents(__DIR__ . '/../app/credential-dashboard-details.php');
$transactionDetailsSource = file_get_contents(__DIR__ . '/../app/transaction-details.php');
$dashboardStyleSource = file_get_contents(__DIR__ . '/../app/style.css');
foreach (['view=active_products','view=categories','view=available_stock','view=low_stock',"'view'=>'category_active'","'view'=>'category_inactive'","'view'=>'category_total'",'transaction-details.php?id='] as $dashboardRoute) {
    $assertSame(true, str_contains((string)$dashboardSource, $dashboardRoute), 'Dashboard exposes the ' . $dashboardRoute . ' drill-down');
}
foreach (["requirePermission('inventory', 'view')", "requirePermission('products', 'view')", "requirePermission('assignments','view')", "requirePermission('employees','view')", "requirePermission('hardware','view')", 'LIMIT $perPage OFFSET $offset', 'asset_tag', 'serial_number', 'minimum_stock'] as $detailGuard) {
    $assertSame(true, str_contains((string)$dashboardDetailsSource, $detailGuard), 'Inventory dashboard details include ' . $detailGuard);
}
$assertSame(true, str_contains((string)$credentialDashboardSource, 'credentialScopeSql') && str_contains((string)$credentialDashboardSource, "requirePermission('credentials','view')"), 'Credential dashboard drill-down applies credential RBAC scope');
$assertSame(false, str_contains((string)$credentialDashboardSource, 'credential_password_enc') || str_contains((string)$credentialDashboardSource, 'decryptCredentialValue'), 'Credential dashboard drill-down never selects or decrypts passwords');
$assertSame(true, str_contains((string)$transactionDetailsSource, 'inventoryHistoryStockPresentation') && str_contains((string)$transactionDetailsSource, '0 stock impact'), 'Transaction detail uses ledger stock presentation and distinguishes order-only events');
foreach (['dashboard-stat-link:focus-visible', '.category-inventory-values > a', '.transaction-detail-grid', '.table-scroll'] as $responsiveMarker) {
    $assertSame(true, str_contains((string)$dashboardStyleSource, $responsiveMarker), 'Dashboard styles include responsive accessible control ' . $responsiveMarker);
}
$oneSoftwareCredential = normalizeEmployeeCredentialInputs([['service_name'=>'WiFi portal','credential_username'=>'admin@example.test','credential_password'=>'secret-pass','service_url'=>'https://wifi.example.test','notes'=>'Building access']]);
$assertSame(1, count($oneSoftwareCredential), 'Adding software supports one associated credential');
$assertSame([], normalizeEmployeeCredentialInputs([]), 'Software can be saved without credentials');
$assertSame(['mode'=>'','entries'=>[],'link_id'=>null], employeeSoftwareCredentialChoice('', [], null), 'Software can be saved with no credential action');
$assertSame(['mode'=>'','entries'=>[],'link_id'=>null], employeeSoftwareCredentialChoice('create', [['service_name'=>'','credential_password'=>'']], null), 'Selecting create without entering credential fields leaves software credential-free');
$createChoice = employeeSoftwareCredentialChoice('create', [['service_name'=>'Mail','credential_password'=>'secret-pass']], null);
$assertSame('create', $createChoice['mode'], 'The create credential action is selected independently');
$assertSame(1, count($createChoice['entries']), 'Creating software can include one credential in the selected action');
$assertSame(['mode'=>'link','entries'=>[],'link_id'=>22], employeeSoftwareCredentialChoice('link', [], '22'), 'Link existing credential does not create a duplicate');
$assertThrows(static fn() => employeeSoftwareCredentialChoice('create', [['service_name'=>'Mail','credential_password'=>'secret-pass']], '22'), 'The software form rejects simultaneous link and create actions');
$multipleSoftwareCredentials = normalizeEmployeeCredentialInputs([
    ['service_name'=>'WiFi portal','credential_username'=>'admin@example.test','credential_password'=>'secret-pass'],
    ['service_name'=>'Cloud console','credential_username'=>'ops@example.test','credential_password'=>'another-secret'],
]);
$assertSame(2, count($multipleSoftwareCredentials), 'Software supports multiple associated credentials');
$insertValues = employeeSoftwareCredentialInsertValues($oneSoftwareCredential[0], 5, 2, 9, 1);
$assertSame([5,2,9,'WiFi portal','https://wifi.example.test','admin@example.test'], array_slice($insertValues, 0, 6), 'New software credentials keep employee and software associations');
$assertSame('secret-pass', decryptCredentialValue($insertValues[6]), 'Software-associated credentials use the existing encrypted secretbox storage');
$assertSame(false, $insertValues[6] === 'secret-pass', 'Software-associated credentials never store plaintext passwords');
$assertSame(true, employeeCredentialCanLinkToSoftware(['employee_id'=>5,'software_installation_id'=>null,'active'=>1],5,9), 'An existing employee credential can be linked without creating a duplicate');
$assertSame(false, employeeCredentialCanLinkToSoftware(['employee_id'=>6,'software_installation_id'=>null,'active'=>1],5,9), 'A credential belonging to another employee cannot be linked');
$assertSame(false, employeeCredentialCanLinkToSoftware(['employee_id'=>5,'software_installation_id'=>10,'active'=>1],5,9), 'A credential already linked to another software record cannot be moved implicitly');
$assertSame(['credentials','create'], employeeCredentialActionPermission('credential_create'), 'Credential creation uses its dedicated RBAC permission');
$assertSame(['credentials','edit'], employeeCredentialActionPermission('credential_link'), 'Credential linking requires credential edit permission');
$assertSame(['credentials','edit'], employeeCredentialActionPermission('credential_update'), 'Credential editing requires credential edit permission');
$assertSame(['credentials','edit'], employeeCredentialActionPermission('credential_unlink'), 'Credential unlinking requires credential edit permission');
$assertSame(['credentials','delete'], employeeCredentialActionPermission('credential_delete'), 'Credential deletion requires credential delete permission');
$assertSame(null, employeeCredentialActionPermission('credential_reveal'), 'Password reveal is excluded from the software form action path');
$employeePageSource = file_get_contents(__DIR__ . '/../app/employee.php');
$softwareCredentialUi = file_get_contents(__DIR__ . '/../app/employee_software_form.php');
$assertSame(false, str_contains((string)$employeePageSource, 'credential-employee-section'), 'Employee Details no longer renders a separate Associated Credentials section');
$assertSame(true, str_contains((string)$softwareCredentialUi, 'employee-software-credentials') && str_contains((string)$softwareCredentialUi, 'revealCredential'), 'Credential details and the authorized reveal workflow live in the software form');
$assertSame(1, substr_count((string)$softwareCredentialUi, 'Associated Credentials (Optional)'), 'The software modal has one collapsible credential section');
$assertSame(1, substr_count((string)$softwareCredentialUi, 'name="new_credentials[0][service_name]"'), 'The new credential fields appear once');
$assertSame(false, str_contains((string)$softwareCredentialUi, 'quick-create-credential') || str_contains((string)$softwareCredentialUi, 'add-credential-row'), 'The modal has no duplicate credential create controls');
$assertSame(1, substr_count((string)$softwareCredentialUi, '>Save Software</button>'), 'The software modal has one primary Save Software button');
$assertSame(1, substr_count((string)$softwareCredentialUi, '>Cancel</button>'), 'The software modal has one Cancel button');
$assertSame(true, str_contains((string)$softwareCredentialUi, 'name="credential_mode" value="link"') && str_contains((string)$softwareCredentialUi, 'name="credential_mode" value="create"'), 'Link and create credential choices are mutually exclusive radio actions');
$assertSame(true, str_contains((string)$softwareCredentialUi, 'software-dialog-header') && str_contains((string)file_get_contents(__DIR__ . '/../app/style.css'), 'software-dialog-footer'), 'The modal has dedicated sticky header and footer layout');
$assertSame(false, str_contains((string)$softwareCredentialUi, 'credential_password_enc'), 'Encrypted password values are never embedded in the employee software form');

$assertSame(3, activeHardwareAssignmentQuantity(3,0,false), 'A bulk assignment contributes its assigned quantity to the employee count.');
$assertSame(2, activeHardwareAssignmentQuantity(5,3,false), 'A partially returned bulk assignment contributes only its remaining quantity.');
$assertSame(0, activeHardwareAssignmentQuantity(2,2,false), 'A fully returned bulk assignment contributes no active units.');
$assertSame(1, activeHardwareAssignmentQuantity(4,0,true), 'A serialized asset with both asset_id and quantity counts as one physical unit.');
$assertSame('CASE WHEN a.asset_id IS NOT NULL THEN 1 ELSE GREATEST(a.quantity - a.returned_quantity, 0) END', activeHardwareAssignmentQuantitySql('a'), 'Dashboard and inventory views share one duplicate-safe assignment quantity expression.');
$assertSame(true, str_contains(activeHardwareAssignmentEmployeeSql('a'), 'a.employee_record_id IS NULL') && str_contains(activeHardwareAssignmentEmployeeSql('a'), 'assignment_employee.employee_id = a.employee_id'), 'Legacy assignment employee codes are used only when no employee record link exists.');
$employeePageSource = (string)file_get_contents(__DIR__ . '/../app/employee.php');
$dashboardPageSource = (string)file_get_contents(__DIR__ . '/../app/dashboard.php');
$dashboardDetailsPageSource = (string)file_get_contents(__DIR__ . '/../app/dashboard-details.php');
$categoryInventoryPageSource = (string)file_get_contents(__DIR__ . '/../app/category-inventory.php');
$inventoryDetailsPageSource = (string)file_get_contents(__DIR__ . '/../app/inventory-details.php');
$assertSame(true, str_contains($employeePageSource, 'activeHardwareAssignmentQuantitySql') && str_contains($employeePageSource, '(a.employee_record_id=? OR (a.employee_record_id IS NULL AND a.employee_id=?))') && str_contains($employeePageSource, '$employeeHardwareCount'), 'Employee Hardware badge and table count only the selected employee’s active assigned units.');
$assertSame(true, str_contains($employeePageSource, "\$row['status']==='assigned'&&empty(\$row['returned_at'])") && str_contains($employeePageSource, 'WHERE h.employee_id=?'), 'Employee hardware rows filter active assignments after loading history, while events remain scoped to that employee.');
$assertSame(true, str_contains($employeePageSource, '(a.employee_record_id=? OR (a.employee_record_id IS NULL AND a.employee_id=?))'), 'Two employees with active assignments receive separately scoped hardware counts.');
$assertSame(true, str_contains($employeePageSource, "SELECT id FROM assignments WHERE asset_id=? AND status='assigned'") && str_contains($employeePageSource, 'INSERT INTO active_hardware_assignments(asset_id,assignment_id)'), 'Assignment flow rejects already assigned assets and relies on a unique active asset mapping for race safety.');
foreach ([$dashboardPageSource,$dashboardDetailsPageSource,$categoryInventoryPageSource,$inventoryDetailsPageSource] as $inventoryAssignmentSource) {
    $assertSame(true, str_contains($inventoryAssignmentSource, 'activeHardwareAssignmentQuantitySql') || str_contains($inventoryAssignmentSource, '$assignedQuantitySql'), 'Inventory totals use the same duplicate-safe assignment quantity calculation.');
}
$assertSame(true, str_contains($dashboardPageSource, 'activeHardwareAssignmentEmployeeSql') && str_contains($dashboardDetailsPageSource, '$validAssignedEmployeeSql'), 'Inventory assigned totals include assignments linked to real employees only.');
$assertSame(true, str_contains($dashboardDetailsPageSource, 'new_quantity AS available_quantity') && str_contains($dashboardDetailsPageSource, 'FROM inventory_transactions) latest_stock WHERE rn=1) st ON st.product_id=p.id'), 'Dashboard details exposes latest ledger stock through the st.available_quantity alias.');
$assertSame(true, str_contains($dashboardDetailsPageSource, "'SELECT COUNT(*)'.\$base.\$whereSql") && str_contains($dashboardDetailsPageSource, ". \$base.\$whereSql.' ORDER BY '"), 'Dashboard detail count and select queries share the same stock joins and filters.');
$assertSame(true, str_contains($dashboardDetailsPageSource, 'COALESCE(st.available_quantity,0)<=p.minimum_stock') && str_contains($dashboardDetailsPageSource, 'COALESCE(assigned.assigned_quantity,0) assigned_quantity'), 'Low stock and assigned totals use the ledger alias and duplicate-safe assignment calculation.');

$catalog = permissionCatalog();
$emptyDefaults = [];
foreach ($catalog as $module => $definition) foreach ($definition['permissions'] as $capability) $emptyDefaults[$module][$capability] = false;
$viewerDefaults = $emptyDefaults;
foreach ($catalog as $module => $definition) if (in_array('view', $definition['permissions'], true)) $viewerDefaults[$module]['view'] = true;
$editorDefaults = $viewerDefaults;
foreach ([
    'inventory' => ['stock_adjustment'],
    'products' => ['add', 'edit'],
    'categories' => ['add', 'edit', 'delete', 'status'],
    'assignments' => ['add', 'edit', 'delete', 'hardware_assignment'],
    'hardware' => ['add', 'edit', 'delete', 'hardware_assignment'],
] as $module => $operations) foreach ($operations as $operation) $editorDefaults[$module][$operation] = true;
$assertSame(false, $editorDefaults['credentials']['reveal'], 'Editors do not gain password reveal permission through software management');
$assertSame(false, $editorDefaults['credentials']['edit'], 'Editors do not gain credential edit or password reset permission through software management');
$assertSame(false, $editorDefaults['credentials']['delete'], 'Editors do not gain credential delete permission through software management');
$roleDefaults = ['editor' => $editorDefaults, 'viewer' => $viewerDefaults];
$_SESSION['user'] = ['id' => 1, 'role' => 'admin'];
$assertSame(true, canAdmin(), 'Admin role helper');
$assertSame(false, canPerform('unknown_module', 'view'), 'Unknown modules default deny');
$assertSame(false, canPerform('products', 'unknown_action'), 'Unknown actions default deny');
foreach (['editor', 'viewer'] as $role) {
    foreach ($catalog as $module => $definition) {
        foreach ($definition['permissions'] as $capability) {
            $assertSame((bool)$roleDefaults[$role][$module][$capability],
                (bool)buildEffectivePermissionMatrix($catalog, $roleDefaults[$role], null, [])[$module][$capability],
                ucfirst($role) . " default {$module}.{$capability}");
        }
    }
    $full = buildEffectivePermissionMatrix($catalog, $roleDefaults[$role], 'full', []);
    $none = buildEffectivePermissionMatrix($catalog, $roleDefaults[$role], 'none', []);
    $customOverrides = [];
    $toggleExpected = [];
    $offset = 0;
    foreach ($catalog as $module => $definition) foreach ($definition['permissions'] as $capability) {
        $allowed = ($offset++ % 2) === 0;
        $customOverrides[$module][$capability] = $allowed;
        $toggleExpected[$module][$capability] = $allowed;
        $assertSame(true, $full[$module][$capability], ucfirst($role) . " Full Access {$module}.{$capability}");
        $assertSame(false, $none[$module][$capability], ucfirst($role) . " No Access {$module}.{$capability}");
    }
    $custom = buildEffectivePermissionMatrix($catalog, $roleDefaults[$role], 'custom', $customOverrides);
    foreach ($catalog as $module => $definition) foreach ($definition['permissions'] as $capability) {
        $assertSame($toggleExpected[$module][$capability], $custom[$module][$capability], ucfirst($role) . " Custom Access {$module}.{$capability}");
    }
}
$admin = buildEffectivePermissionMatrix($catalog, [], null, [], true);
foreach ($catalog as $module => $definition) foreach ($definition['permissions'] as $capability) $assertSame(true, $admin[$module][$capability], "Admin full {$module}.{$capability}");
$_SESSION['user'] = ['id' => 1, 'role' => 'editor'];
$assertSame(false, canAdmin(), 'Editor cannot administer');
$assertSame(false, canPerform('users', 'view'), 'Editor cannot view users');
$assertSame(false, canPerform('audit_logs', 'view'), 'Editor cannot view audit logs');
$_SESSION['user']['role'] = 'viewer';
$assertSame(false, canAdmin(), 'Viewer cannot administer');
$assertSame(false, canPerform('users', 'view'), 'Viewer cannot view users');
$assertSame(false, canPerform('audit_logs', 'view'), 'Viewer cannot view audit logs');
$assertSame(false, canPerform('hardware', 'assign'), 'Legacy hardware action name defaults deny');
$assertSame(false, canPerform('orders', 'receive'), 'Legacy order action name defaults deny');
$ordersPageSource = file_get_contents(__DIR__ . '/../app/orders.php');
$assertSame(true, is_string($ordersPageSource) && str_contains($ordersPageSource, "canPerform('orders', 'order_receive')"), 'Orders register gates receipt links with the order receipt permission');
$assertSame(true, is_string($ordersPageSource) && str_contains($ordersPageSource, '$canReceiveOrder && $canViewProducts'), 'Orders register does not show a receipt link users cannot open under product view RBAC');
$assertSame(true, is_string($ordersPageSource) && str_contains($ordersPageSource, "\$order['status']==='Confirmed' && (int)\$order['quantity'] > 0 && (int)\$order['received_quantity'] < (int)\$order['quantity']"), 'Orders register only offers receipt for confirmed positive-quantity orders with outstanding quantity');
$assertSame(true, is_string($ordersPageSource) && str_contains($ordersPageSource, 'products.php?category_id=') && str_contains($ordersPageSource, '&amp;receive_order='), 'Orders register routes receipt to the existing product receipt form');
$assertSame(true, is_string($ordersPageSource) && str_contains($ordersPageSource, "SUM(quantity) received_quantity FROM inventory_transactions WHERE transaction_type='in' AND related_order_id IS NOT NULL"), 'Orders register calculates received quantity from linked receipt ledger entries');
$assertSame(null, assertActiveAdminRetained([1, 2], 1, 'editor', 1), 'An Admin can be demoted when another active Admin remains');
$assertSame(null, assertActiveAdminRetained([1], 1, 'admin', 1), 'The active Admin can remain active');
$assertThrows(static fn() => assertActiveAdminRetained([1], 1, 'admin', 0), 'The last active Admin cannot be deactivated');
$assertThrows(static fn() => assertActiveAdminRetained([1], 1, 'editor', 1), 'The last active Admin cannot be demoted');

$_SESSION['csrf'] = 'valid-test-token';
$_POST['csrf'] = 'valid-test-token';
verifyCsrf();
$checks++;
$probe = static function (string $tokenState): string {
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/csrf_rejection_probe.php', $tokenState],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) throw new RuntimeException('Unable to launch the CSRF rejection probe.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($process);
    return $output;
};
$assertSame('CSRF token accepted|http=200', $probe('valid'), 'Valid CSRF token is accepted');
$assertSame('Invalid CSRF token|http=419', $probe('invalid'), 'Invalid CSRF token is rejected');
$assertSame('Invalid CSRF token|http=419', $probe('missing'), 'Missing CSRF token is rejected');
$assertSame('Invalid CSRF token|http=419', $probe('expired'), 'A token from an expired session is rejected');
$assertSame(null, consumeFlash(), 'Empty flash is consumed');
setFlash('success', 'Operation completed.');
$assertSame(['type' => 'success', 'message' => 'Operation completed.'], consumeFlash(), 'Success flash is consumed');
$assertSame(null, consumeFlash(), 'Flash cannot be consumed twice');

echo "PASS: {$checks} checks\n";
