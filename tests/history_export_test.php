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
$fixture = static function (int $index, string $type = 'in', ?string $status = null): array {
    $isOrder = $type === 'ordered';
    return [
        'transaction_at' => '2026-10-12 13:14:15', 'transaction_type' => $type,
        'quantity' => $isOrder ? 3 : ($type === 'out' ? -2 : 5),
        'previous_quantity' => 20, 'new_quantity' => $type === 'ordered' ? 20 : 25,
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
    'action'=>'assigned','product_name'=>'Laptop','category_name'=>'Hardware','asset_tag'=>'ASSET-10',
    'serial_number'=>'SERIAL-10','reason'=>str_repeat('Hardware reason text. ', 10),'performed_by'=>'Test User',
]];
$utcBoundary = $fixture(1);
$utcBoundary['transaction_at'] = '2026-10-12 20:30:00';
$localOffset = $fixture(1);
$localOffset['transaction_at'] = '2026-10-12 20:30:00+05:30';
$cases = [
    'zero' => [],
    'one' => [$fixture(1, 'ordered', 'Pending')],
    'utc-boundary' => [$utcBoundary],
    'local-offset' => [$localOffset],
    'many' => [
        $fixture(1, 'ordered', 'Pending'), $fixture(2, 'ordered', 'Confirmed'),
        $fixture(3, 'ordered', 'Rejected'), $fixture(4, 'ordered', 'Received'),
        $fixture(5, 'in'), $fixture(6, 'out'),
    ],
];
for ($i = 7; $i <= 80; $i++) $cases['many'][] = $fixture($i, $i % 2 === 0 ? 'in' : 'out');

foreach ($cases as $case => $rows) {
    $book = buildInventoryHistoryWorkbook($rows, ['Date range'=>'2026-10-01 to 2026-10-12'], $hardware);
    $path = sys_get_temp_dir() . '/inventory-history-' . $case . '-' . getmypid() . '.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
    $book->disconnectWorksheets();
    $reopened = IOFactory::load($path);
    $history = $reopened->getSheetByName('History');
    $assert($history !== null, "$case: History worksheet exists");
    $assert($reopened->getSheetCount() === 3, "$case: workbook has three worksheets");
    foreach ($reopened->getAllSheets() as $worksheet) {
        foreach ($worksheet->getCellCollection()->getCoordinates() as $coordinate) {
            if ($worksheet->getCell($coordinate)->getValue() === null || $worksheet->getCell($coordinate)->getValue() === '') continue;
            $alignment = $worksheet->getStyle($coordinate)->getAlignment();
            $assert($alignment->getHorizontal() === 'center' && $alignment->getVertical() === 'center', "$case: {$worksheet->getTitle()} {$coordinate} is centered both ways");
        }
    }
    $assert($history->getCell('A1')->getValue() === 'INVENTORY MANAGEMENT — HISTORY REPORT', "$case: report title");
    $assert(in_array('A1:M1', $history->getMergeCells(), true), "$case: History title spans report width");
    $assert($history->getStyle('A1')->getFill()->getStartColor()->getRGB() === '24152F', "$case: dark-purple branded title");
    $assert($history->getCell('A2')->getValue() !== null && preg_match('/Generated: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} IST.*Applied filters: Date range:/', (string)$history->getCell('A2')->getValue()) === 1, "$case: IST generated timestamp and applied filters");
    $assert($history->getCell('A5')->getValue() === count($rows), "$case: transaction count matches exported rows");
    $assert($history->getCell('A7')->getValue() === 'Pending Orders' && $history->getCell('E7')->getValue() === 'Confirmed Orders' && $history->getCell('J7')->getValue() === 'Rejected Orders', "$case: six required metrics use two rows");
    $assert(in_array('A4:D4', $history->getMergeCells(), true) && in_array('E4:I4', $history->getMergeCells(), true) && in_array('J4:M4', $history->getMergeCells(), true), "$case: summary labels use merged blocks");
    $assert($history->getStyle('A4')->getFill()->getStartColor()->getRGB() === 'F3E5EE', "$case: metric labels use light-purple fill");
    $assert($history->getStyle('A5')->getAlignment()->getHorizontal() === 'center', "$case: metric values are centered");
    $assert($history->getCell('M10')->getValue() === 'User', "$case: transaction user column is included");
    $assert($history->getStyle('A10')->getFill()->getStartColor()->getRGB() === '493253' && $history->getStyle('A10')->getFont()->getColor()->getRGB() === 'FFFFFF', "$case: transaction headers use purple with white text");
    $assert($history->getAutoFilter()->getRange() === 'A10:M' . max(10, 10 + count($rows)), "$case: filtered history table");
    $assert($history->getFreezePane() === 'A11', "$case: frozen history headers");
    $assert($history->getColumnDimension('A')->getWidth() >= 19 && $history->getColumnDimension('M')->getWidth() >= 18, "$case: History columns have usable widths");
    if ($case === 'zero') $assert($history->getCell('A11')->getValue() === null, 'zero: no fabricated data rows');
    if ($case === 'one') {
        $assert($history->getCell('E11')->getDataType() === DataType::TYPE_NUMERIC, 'one: quantity is numeric');
        $assert($history->getCell('H11')->getDataType() === DataType::TYPE_NUMERIC && $history->getCell('I11')->getDataType() === DataType::TYPE_NUMERIC, 'one: monetary values are numeric cells');
        $assert($history->getStyle('H11')->getNumberFormat()->getFormatCode() === '₹#,##0.00', 'one: INR number format');
        $assert($history->getStyle('I11')->getNumberFormat()->getFormatCode() === '₹#,##0.00', 'one: total INR number format');
        $assert($history->getRowDimension(11)->getRowHeight() > 30, 'one: long description receives a taller row');
        $assert($history->getCell('A11')->getDataType() === DataType::TYPE_NUMERIC, 'one: date is an Excel date serial');
        $assert($history->getStyle('A11')->getNumberFormat()->getFormatCode() === 'dd-mm-yyyy', 'one: date uses the date-only Excel format');
        $assert($history->getCell('A11')->getFormattedValue() === '12-10-2026', 'one: History displays DD-MM-YYYY without time');
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
        $assert($history->getCell('A5')->getValue() === 80 && $history->getCell('E5')->getValue() === 190 && $history->getCell('J5')->getValue() === 76, 'many: transaction and received/removed totals match exported rows');
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
        $assert($history->getCell('F11')->getValue() === 20 && $history->getCell('G11')->getValue() === 20, 'many: ordered quantity does not increase physical stock');
        $assert(in_array($history->getCell('L15')->getValue(), ['', null], true), 'many: non-order transactions have no order status');
        $assert($history->getCell('B12')->getDataType() === DataType::TYPE_STRING, 'many: untrusted text remains a literal string');
        $hardwareSheet = $reopened->getSheetByName('Hardware Activity');
        $assert($hardwareSheet->getCell('B5')->getValue() === 'Example Employee', 'many: hardware events export from supported records');
        $assert($hardwareSheet->getStyle('A1')->getFill()->getStartColor()->getRGB() === '24152F', 'many: Hardware Activity uses the shared branded title');
        $assert($hardwareSheet->getAutoFilter()->getRange() === 'A4:J5' && $hardwareSheet->getFreezePane() === 'A5', 'many: Hardware Activity filter and frozen header');
        $assert($hardwareSheet->getCell('A5')->getDataType() === DataType::TYPE_NUMERIC, 'many: hardware activity date is a real Excel date');
        $assert($hardwareSheet->getStyle('A5')->getNumberFormat()->getFormatCode() === 'dd-mm-yyyy' && $hardwareSheet->getCell('A5')->getFormattedValue() === '12-10-2026', 'many: Hardware Activity displays date only');
        $assert($hardwareSheet->getColumnDimension('A')->getWidth() >= 19 && $hardwareSheet->getColumnDimension('J')->getWidth() >= 18, 'many: Hardware Activity widths survive XLSX round-trip');
    }
    unlink($path);
    $reopened->disconnectWorksheets();
}
echo "PASS: zero, one, and 80-row XLSX exports; numeric/date/INR formatting, status summaries, long text, formula safety, and hardware activity\n";
