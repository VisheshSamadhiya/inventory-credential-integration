<?php
declare(strict_types=1);

$appDirectory = dirname(__DIR__) . '/app';
$autoload = is_file($appDirectory . '/vendor/autoload.php') ? $appDirectory . '/vendor/autoload.php' : '/var/www/html/vendor/autoload.php';
$builder = is_file($appDirectory . '/history_export.php') ? $appDirectory . '/history_export.php' : '/var/www/html/history_export.php';
require_once $autoload;
require_once $builder;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(historyExportReason('Requested', 'Requested') === 'Requested', 'identical selected reason and remark display once');
$assert(historyExportReason(' Requested ', 'requested  ') === 'Requested', 'reason comparison ignores case and surrounding whitespace');
$assert(historyExportReason('New', 'For a new team member') === 'New — For a new team member', 'distinct remarks are appended to the selected reason');
$assert(historyExportReason('Replacement', '') === 'Replacement', 'empty remark leaves the selected reason');
$assert(historyExportReason('Replacement', null) === 'Replacement', 'null remark leaves the selected reason');
$assert(historyExportReason(null, 'Needs replacement') === 'Needs replacement', 'empty selected reason displays the remark');
$assert(historyExportReason(null, null) === '', 'null reason and remark display as empty');
$csvFixture = [
    'transaction_id'=>18,'transaction_at'=>'2026-10-12 13:14:15','transaction_type'=>'in','quantity'=>2,
    'previous_quantity'=>10,'new_quantity'=>12,'reason'=>'Receipt','notes'=>'INV-18','product_name'=>'CPU',
    'brand'=>'Acme','model'=>'M1','category_name'=>'System','unit_price'=>null,'total_price'=>null,
    'cost_covered_quantity'=>0,'known_cost_amount'=>null,'stock_calculation'=>['quantity'=>2,'stock_before'=>10,'stock_impact'=>2,'stock_after'=>12],
    'assigned_change'=>0,'assigned_before'=>0,'assigned_after'=>0,'physical_before'=>10,'physical_after'=>12,
    'physical_value_impact'=>null,'movement_label'=>'Received','purchase_order_id'=>null,'user_name'=>'Test User',
    'historical_valuation'=>['ordered_value_cents'=>null,'before'=>['available_value_cents'=>10000,'assigned_value_cents'=>0,'physical_value_cents'=>10000,'disposed_value_cents'=>0,'available_unknown_units'=>0,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0],'after'=>['available_value_cents'=>30000,'assigned_value_cents'=>0,'physical_value_cents'=>30000,'disposed_value_cents'=>0,'available_unknown_units'=>0,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0],'available_reconciled'=>true,'assigned_reconciled'=>true,'physical_reconciled'=>true,'disposed_reconciled'=>true],
];
$csvFixture['historical_valuation']['ordered_value_cents'] = null;
$csvOrderFixture = $csvFixture;
$csvOrderFixture['transaction_type'] = 'ordered';
$csvOrderFixture['quantity'] = 2;
$csvOrderFixture['unit_price'] = '110.00';
$csvOrderFixture['total_price'] = '220.00';
$csvOrderFixture['historical_valuation']['ordered_value_cents'] = 22000;
$csvEvent = ['performed_at'=>'2026-10-12 14:00:00','event_id'=>7,'employee_name'=>'Example Employee','employee_id'=>'EMP-10','action'=>'assigned','product_name'=>'Laptop','category_name'=>'Hardware','asset_tag'=>'','asset_id'=>99,'quantity'=>1,'reason'=>'Assigned','performed_by'=>'Test User'];
$csvReplacement = ['performed_at'=>'2026-10-12 15:00:00','event_id'=>8,'employee_name'=>'Example Employee','employee_id'=>'EMP-10','action'=>'replaced','product_name'=>'Laptop Pro','category_name'=>'Hardware','asset_tag'=>'NEW-1','asset_id'=>100,'quantity'=>1,'old_product_name'=>'Laptop','new_product_name'=>'Laptop Pro','old_asset_tag'=>'OLD-1','new_asset_tag'=>'NEW-1','old_serial_number'=>'OLD-S','new_serial_number'=>'NEW-S','reason'=>'Replacement','performed_by'=>'Test User'];
$csv = buildInventoryHistoryCsv([$csvFixture,$csvOrderFixture], [$csvEvent,$csvReplacement], ['Product'=>'CPU']);
$csvRows = array_map(static fn(string $line): array => str_getcsv($line), array_slice(explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $csv))), 4));
$assert(count($csvRows[0]) === 50 && count($csvRows[1]) === 50 && count($csvRows[2]) === 50 && count($csvRows[3]) === 50 && count($csvRows[4]) === 50, 'CSV headers, transaction and hardware rows share the 50-column calculation schema: ' . implode(',', array_map('count', $csvRows)));
$assert($csvRows[1][19] === '12' && $csvRows[1][25] === 'Cost unavailable' && $csvRows[1][26] === 'Cost unavailable', 'CSV preserves saved stock balances and labels missing cost as unavailable');
$assert($csvRows[1][42] === '₹100.00' && $csvRows[1][43] === '₹300.00' && $csvRows[1][46] === '₹100.00' && $csvRows[1][47] === '₹300.00', 'CSV exports the same available and physical valuation before/after values as History');
$assert($csvRows[2][0] === 'Inventory transaction' && $csvRows[2][26] === 'Not received inventory; see Ordered Value' && $csvRows[2][27] === 'Not applicable (order only)' && $csvRows[2][41] === '₹220.00' && $csvRows[2][42] === '₹100.00', 'CSV keeps ordered value separate from stock valuation');
$assert($csvRows[3][0] === 'Hardware assignment event' && $csvRows[3][13] === '1', 'CSV keeps hardware events separate and counts a registered asset once');
$assert($csvRows[4][35] === 'Laptop' && $csvRows[4][36] === 'Laptop Pro' && $csvRows[4][37] === 'OLD-1' && $csvRows[4][38] === 'NEW-1' && $csvRows[4][39] === 'OLD-S' && $csvRows[4][40] === 'NEW-S', 'CSV preserves both sides of a replacement event');
$fixture = static function (int $index, string $type = 'in', ?string $status = null): array {
    $isOrder = $type === 'ordered';
    $quantity = $isOrder ? 3 : ($type === 'out' ? -2 : 5);
    $impact = match ($type) {
        'ordered' => 0,
        'out' => -abs($quantity),
        'in', 'opening' => abs($quantity),
        'adjustment' => $quantity,
        default => 0,
    };
    return [
        'transaction_at' => '2026-10-12 13:14:15', 'transaction_type' => $type,
        'quantity' => $quantity,
        'previous_quantity' => 20, 'new_quantity' => 20 + $impact,
        'reason' => $index === 1 ? str_repeat('Long description for wrapping. ', 18) : 'Routine transaction',
        'order_reason' => $isOrder ? 'Requested' : null,
        'unit_price' => $isOrder ? 100.25 : null, 'total_price' => $isOrder ? 300.75 : null,
        'vendor' => $isOrder ? 'Vendor ' . str_repeat('Long Name ', 8) : '',
        'status' => $status, 'product_name' => $index === 2 ? '=HYPERLINK("https://invalid")' : 'Product ' . $index,
        'category_name' => 'Hardware', 'user_name' => 'Test User',
    ];
};
$hardware = [[
    'performed_at'=>'2026-10-12 14:00:00','employee_name'=>'Example Employee','employee_id'=>'EMP-10',
    'event_id'=>7,'asset_id'=>99,'action'=>'assigned','product_name'=>'Laptop','category_name'=>'Hardware','asset_tag'=>'ASSET-10',
    'serial_number'=>'SERIAL-10','reason'=>str_repeat('Hardware reason text. ', 10),'performed_by'=>'Test User','quantity'=>1,
], [
    'performed_at'=>'2026-10-12 15:00:00','employee_name'=>'Example Employee','employee_id'=>'EMP-10',
    'event_id'=>8,'asset_id'=>100,'action'=>'replaced','product_name'=>'Laptop Pro','category_name'=>'Hardware','asset_tag'=>'NEW-1',
    'serial_number'=>'NEW-S','reason'=>'Replacement','performed_by'=>'Test User','quantity'=>1,
    'old_product_name'=>'Laptop','new_product_name'=>'Laptop Pro','old_asset_tag'=>'OLD-1','new_asset_tag'=>'NEW-1','old_serial_number'=>'OLD-S','new_serial_number'=>'NEW-S',
]];
$utcBoundary = $fixture(1);
$utcBoundary['transaction_at'] = '2026-10-12 20:30:00';
$localOffset = $fixture(1);
$localOffset['transaction_at'] = '2026-10-12 20:30:00+05:30';
$cases = [
    'zero' => [],
    'one' => [array_merge($fixture(1, 'ordered', 'Pending'), ['order_reason'=>' Requested ', 'reason'=>'requested  '])],
    'utc-boundary' => [$utcBoundary],
    'local-offset' => [$localOffset],
    'many' => [
        array_merge($fixture(1, 'ordered', 'Pending'), ['historical_valuation'=>['ordered_value_cents'=>30075,'before'=>['available_value_cents'=>10000,'assigned_value_cents'=>2000,'physical_value_cents'=>12000,'disposed_value_cents'=>0,'available_unknown_units'=>0,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0],'after'=>['available_value_cents'=>10000,'assigned_value_cents'=>2000,'physical_value_cents'=>12000,'disposed_value_cents'=>0,'available_unknown_units'=>0,'assigned_unknown_units'=>0,'disposed_unknown_units'=>0],'available_reconciled'=>true,'assigned_reconciled'=>true,'physical_reconciled'=>true,'disposed_reconciled'=>true]]),
        array_merge($fixture(2, 'ordered', 'Confirmed'), ['order_reason'=>'New', 'reason'=>'For a new team member']),
        array_merge($fixture(3, 'ordered', 'Rejected'), ['order_reason'=>'Replacement ', 'reason'=>' replacement']),
        $fixture(4, 'ordered', 'Received'),
        $fixture(5, 'in'), $fixture(6, 'out'),
        array_merge($fixture(7, 'in'), ['quantity'=>3,'new_quantity'=>23,'reason'=>'Order #1 received']),
        array_merge($fixture(8, 'out'), ['quantity'=>-2,'reason'=>'Assignment to employee']),
        array_merge($fixture(9, 'in'), ['quantity'=>2,'new_quantity'=>22,'reason'=>'Hardware returned']),
    ],
];
for ($i = 10; $i <= 80; $i++) $cases['many'][] = $fixture($i, $i % 2 === 0 ? 'in' : 'out');

foreach ($cases as $case => $rows) {
    $book = buildInventoryHistoryWorkbook($rows, ['Date range'=>'2026-10-01 to 2026-10-12'], $hardware);
    $path = sys_get_temp_dir() . '/inventory-history-' . $case . '-' . getmypid() . '.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
    $book->disconnectWorksheets();
    $reopened = IOFactory::load($path);
    $history = $reopened->getSheetByName('History');
    $assert($history !== null, "$case: History worksheet exists");
    $assert($reopened->getSheetCount() === 4, "$case: workbook includes History, Order Summary, Hardware Activity, and Calculation Details");
    $assert($reopened->getSheetByName('Calculation Details') !== null, "$case: chronological calculation details worksheet exists");
    foreach ($reopened->getAllSheets() as $worksheet) {
        foreach ($worksheet->getCellCollection()->getCoordinates() as $coordinate) {
            if ($worksheet->getCell($coordinate)->getValue() === null || $worksheet->getCell($coordinate)->getValue() === '') continue;
            $alignment = $worksheet->getStyle($coordinate)->getAlignment();
            $assert($alignment->getHorizontal() === 'center' && $alignment->getVertical() === 'center', "$case: {$worksheet->getTitle()} {$coordinate} is centered both ways");
        }
    }
    $assert($history->getCell('A1')->getValue() === 'INVENTORY MANAGEMENT — HISTORY REPORT', "$case: report title");
    $assert(in_array('A1:O1', $history->getMergeCells(), true), "$case: History title spans report width");
    $assert($history->getStyle('A1')->getFill()->getStartColor()->getRGB() === '24152F', "$case: dark-purple branded title");
    $assert($history->getCell('A2')->getValue() !== null && preg_match('/Generated: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} IST.*Applied filters: Date range:/', (string)$history->getCell('A2')->getValue()) === 1, "$case: IST generated timestamp and applied filters");
    $assert($history->getCell('A5')->getValue() === count($rows), "$case: transaction count matches exported rows");
    $assert($history->getCell('A7')->getValue() === 'Pending Orders' && $history->getCell('E7')->getValue() === 'Confirmed Orders' && $history->getCell('J7')->getValue() === 'Rejected Orders', "$case: six required metrics use two rows");
    $assert(in_array('A4:D4', $history->getMergeCells(), true) && in_array('E4:I4', $history->getMergeCells(), true) && in_array('J4:N4', $history->getMergeCells(), true), "$case: summary labels use merged blocks");
    $assert($history->getStyle('A4')->getFill()->getStartColor()->getRGB() === 'F3E5EE', "$case: metric labels use light-purple fill");
    $assert($history->getStyle('A5')->getAlignment()->getHorizontal() === 'center', "$case: metric values are centered");
    $assert($history->getCell('M10')->getValue() === 'Order Status' && $history->getCell('N10')->getValue() === 'User' && $history->getCell('O10')->getValue() === 'Invoice / PO Reference', "$case: order status, user and receipt reference columns are included");
    $assert($history->getStyle('A10')->getFill()->getStartColor()->getRGB() === '493253' && $history->getStyle('A10')->getFont()->getColor()->getRGB() === 'FFFFFF', "$case: transaction headers use purple with white text");
    $assert($history->getAutoFilter()->getRange() === 'A10:O' . max(10, 10 + count($rows)), "$case: filtered history table");
    $assert($history->getFreezePane() === 'A11', "$case: frozen history headers");
    $assert($history->getColumnDimension('A')->getWidth() >= 19 && $history->getColumnDimension('N')->getWidth() >= 18, "$case: History columns have usable widths");
    foreach ($reopened->getAllSheets() as $worksheet) {
        [$printLastColumn, $printLastRow] = historyExportContentBounds($worksheet);
        $printLastColumnName = PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($printLastColumn);
        $assert($worksheet->getPageSetup()->getPrintArea() === "A1:{$printLastColumnName}{$printLastRow}", "$case: {$worksheet->getTitle()} print area matches used content");
        $assert($worksheet->getPageSetup()->getFitToPage() && $worksheet->getPageSetup()->getFitToWidth() === 1 && $worksheet->getPageSetup()->getFitToHeight() === 0, "$case: {$worksheet->getTitle()} prints one page wide");
        $nextColumn = PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($printLastColumn + 1);
        $assert($worksheet->getColumnDimension($printLastColumnName)->getVisible(), "$case: {$worksheet->getTitle()} last used column remains visible");
        $assert(!$worksheet->getColumnDimension($nextColumn)->getVisible() && !$worksheet->getColumnDimension('XFD')->getVisible(), "$case: {$worksheet->getTitle()} unused columns are hidden through XFD");
        $assert(!$worksheet->getRowDimension($printLastRow + 1)->getVisible(), "$case: {$worksheet->getTitle()} empty rows below the report are hidden");
    }
    if ($case === 'zero') $assert($history->getCell('A11')->getValue() === null, 'zero: no fabricated data rows');
    if ($case === 'one') {
        $assert($history->getCell('E11')->getDataType() === DataType::TYPE_NUMERIC, 'one: quantity is numeric');
        $assert($history->getCell('H11')->getDataType() === DataType::TYPE_NUMERIC && $history->getCell('I11')->getDataType() === DataType::TYPE_NUMERIC, 'one: monetary values are numeric cells');
        $assert($history->getStyle('I11')->getNumberFormat()->getFormatCode() === '₹#,##0.00', 'one: INR number format');
        $assert($history->getStyle('J11')->getNumberFormat()->getFormatCode() === '₹#,##0.00', 'one: total INR number format');
        $assert($history->getRowDimension(11)->getRowHeight() > 30, 'one: long description receives a taller row');
        $assert($history->getCell('A11')->getDataType() === DataType::TYPE_NUMERIC, 'one: date is an Excel date serial');
        $assert($history->getStyle('A11')->getNumberFormat()->getFormatCode() === 'dd-mm-yyyy', 'one: date uses the date-only Excel format');
        $assert($history->getCell('A11')->getFormattedValue() === '12-10-2026', 'one: History displays DD-MM-YYYY without time');
        $assert($history->getCell('E11')->getValue() === 3 && $history->getCell('F11')->getValue() === 20 && $history->getCell('G11')->getValue() === 0 && $history->getCell('H11')->getValue() === 20, 'one: order quantity is separate from unchanged stock');
        $assert($history->getCell('J11')->getValue() === 'Not received inventory; see Order Summary', 'one: order total is kept separate from received inventory value');
        $assert($history->getCell('M11')->getValue() === 'Pending', 'one: order status exports');
        $assert($history->getCell('L11')->getValue() === 'Requested', 'one: duplicate order reason and remark are shown once');
    }
    if ($case === 'utc-boundary') {
        $assert($history->getCell('A11')->getDataType() === DataType::TYPE_NUMERIC, 'utc-boundary: converted date remains an Excel date serial');
        $assert($history->getCell('A11')->getFormattedValue() === '13-10-2026', 'utc-boundary: UTC timestamp converts to the next IST date');
        $assert((float)$history->getCell('A11')->getValue() > floor((float)$history->getCell('A11')->getValue()), 'utc-boundary: Excel serial retains the timestamp time fraction');
        $assert($history->getStyle('A11')->getAlignment()->getHorizontal() === 'center' && $history->getStyle('A11')->getAlignment()->getVertical() === 'center', 'utc-boundary: transaction date stays centered');
    }
    if ($case === 'local-offset') {
        $assert($history->getCell('A11')->getFormattedValue() === '12-10-2026', 'local-offset: explicit IST timestamp is not double-converted');
    }
    if ($case === 'many') {
        $assert($history->getCell('B12')->getValue() === "'=HYPERLINK(\"https://invalid\")", 'many: formula-like product name is escaped as text');
        $assert($history->getCell('A5')->getValue() === 80 && $history->getCell('E5')->getValue() === 190 && $history->getCell('J5')->getValue() === 74, 'many: transaction and received/removed totals match exported rows');
        $assert($history->getCell('J8')->getValue() === 1, 'many: Rejected Orders summary count');
        $assert($history->getCell('E8')->getValue() === 1, 'many: Confirmed Orders summary count');
        $assert($history->getCell('A8')->getValue() === 1, 'many: Pending Orders summary count');
        $assert($history->getStyle('A11')->getFill()->getStartColor()->getRGB() === 'FFFFFF' && $history->getStyle('A12')->getFill()->getStartColor()->getRGB() === 'F8F4F8', 'many: data rows alternate white and light purple');
        $assert($history->getHighestDataRow() === 90, 'many: all 80 filtered transactions are exported once');
        $orders = $reopened->getSheetByName('Order Summary');
        $assert($orders->getCell('A5')->getValue() === 'Pending Orders' && $orders->getCell('B5')->getValue() === 1, 'many: Pending order count');
        $assert($orders->getCell('A6')->getValue() === 'Confirmed Orders' && $orders->getCell('B6')->getValue() === 1, 'many: Confirmed order count');
        $assert($orders->getCell('A7')->getValue() === 'Rejected Orders' && $orders->getCell('B7')->getValue() === 1, 'many: Rejected order count');
        $assert($orders->getCell('A8')->getValue() === 'Received Orders' && $orders->getCell('B8')->getValue() === 1, 'many: Received order count');
        $assert($orders->getCell('B10')->getValue() === 1203.0, 'many: order value summary uses stored totals');
        $assert($orders->getCell('B10')->getDataType() === DataType::TYPE_NUMERIC && $orders->getStyle('B10')->getNumberFormat()->getFormatCode() === '₹#,##0.00', 'many: Order Summary currency remains numeric and formatted');
        $assert($orders->getHighestDataRow() === 10, 'many: order summary aggregates rather than duplicating transaction details');
        $assert($orders->getStyle('A1')->getFill()->getStartColor()->getRGB() === '24152F', 'many: Order Summary uses the shared branded title');
        $assert($orders->getAutoFilter()->getRange() === 'A4:B10' && $orders->getFreezePane() === 'A5', 'many: Order Summary filter and frozen header');
        $assert($orders->getColumnDimension('A')->getWidth() >= 26 && $orders->getColumnDimension('B')->getWidth() >= 16, 'many: Order Summary widths survive XLSX round-trip');
        foreach ([11=>'Pending',12=>'Confirmed',13=>'Rejected'] as $rowNum=>$status) {
            $assert($history->getCell("E{$rowNum}")->getValue() === 3 && $history->getCell("G{$rowNum}")->getValue() === 0 && $history->getCell("F{$rowNum}")->getValue() === $history->getCell("H{$rowNum}")->getValue(), "many: {$status} order leaves stock unchanged");
            $assert($history->getCell("M{$rowNum}")->getValue() === $status, "many: {$status} order status is preserved");
        }
        $assert($history->getCell('L12')->getValue() === 'New — For a new team member', 'many: distinct order remark is appended once');
        $assert($history->getCell('L13')->getValue() === 'Replacement', 'many: case-insensitive duplicate order reason is shown once');
        $assert($history->getCell('F16')->getValue() === 20 && $history->getCell('G16')->getValue() === -2 && $history->getCell('H16')->getValue() === 18, 'many: stock-out / assignment uses signed impact');
        $assert($history->getCell('F17')->getValue() === 20 && $history->getCell('G17')->getValue() === 3 && $history->getCell('H17')->getValue() === 23, 'many: receipt increases physical stock');
        $assert($history->getCell('G18')->getValue() === -2 && $history->getCell('H18')->getValue() === 18, 'many: assignment stock-out is reflected');
        $assert($history->getCell('G19')->getValue() === 2 && $history->getCell('H19')->getValue() === 22, 'many: return movement restores stock');
        for ($rowNum = 11; $rowNum <= 90; $rowNum++) {
            $assert($history->getCell("F{$rowNum}")->getValue() + $history->getCell("G{$rowNum}")->getValue() === $history->getCell("H{$rowNum}")->getValue(), "many: Stock After reconciles at row {$rowNum}");
        }
        $assert(in_array($history->getCell('M15')->getValue(), ['', null], true), 'many: non-order transactions have no order status');
        $assert($history->getCell('B12')->getDataType() === DataType::TYPE_STRING, 'many: untrusted text remains a literal string');
        $hardwareSheet = $reopened->getSheetByName('Hardware Activity');
        $assert($hardwareSheet->getCell('C5')->getValue() === 'Example Employee', 'many: hardware events export from supported records');
        $assert($hardwareSheet->getStyle('A1')->getFill()->getStartColor()->getRGB() === '24152F', 'many: Hardware Activity uses the shared branded title');
        $assert($hardwareSheet->getAutoFilter()->getRange() === 'A4:U6' && $hardwareSheet->getFreezePane() === 'A5', 'many: Hardware Activity filter and frozen header');
        $assert($hardwareSheet->getCell('A5')->getDataType() === DataType::TYPE_NUMERIC, 'many: hardware activity date is a real Excel date');
        $assert($hardwareSheet->getStyle('A5')->getNumberFormat()->getFormatCode() === 'dd-mm-yyyy' && $hardwareSheet->getCell('A5')->getFormattedValue() === '12-10-2026', 'many: Hardware Activity displays date only');
        $assert($hardwareSheet->getColumnDimension('A')->getWidth() >= 19 && $hardwareSheet->getColumnDimension('O')->getWidth() >= 18, 'many: Hardware Activity widths survive XLSX round-trip');
        $assert($hardwareSheet->getCell('P6')->getValue() === 'Laptop' && $hardwareSheet->getCell('Q6')->getValue() === 'Laptop Pro' && $hardwareSheet->getCell('R6')->getValue() === 'OLD-1' && $hardwareSheet->getCell('S6')->getValue() === 'NEW-1', 'many: replacement keeps old and new product and asset identifiers');
        $details = $reopened->getSheetByName('Calculation Details');
        $assert($details->getCell('A4')->getValue() === 'Record Type' && $details->getCell('AL4')->getValue() === 'Responsible User' && $details->getCell('AM4')->getValue() === 'Ordered Value (INR)' && $details->getCell('AU4')->getValue() === 'Disposed Value After', 'many: calculation export includes ordered and before/after valuation detail columns');
        $assert($details->getCell('AM5')->getValue() === '₹300.75' && $details->getCell('AN5')->getValue() === '₹100.00' && $details->getCell('AR5')->getValue() === '₹120.00', 'many: Calculation Details exports valuation and order value using the same replay');
        $assert($details->getCell('AF5')->getValue() === 'Not received inventory; see Ordered Value' && $details->getCell('AG5')->getValue() === 'Not applicable (order only)', 'many: calculation details do not call ordered cost received stock value');
        $assert($details->getAutoFilter()->getRange() === 'A4:AU' . (4 + count($rows)), 'many: calculation details export follows the filtered transaction rows');
    }
    unlink($path);
    $reopened->disconnectWorksheets();
}
echo "PASS: CSV/XLSX export calculations, zero/one/80-row workbooks, numeric/date/INR formatting, status summaries, long text, formula safety, and hardware activity\n";
