<?php
declare(strict_types=1);
require_once __DIR__ . '/currency.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/** Keep untrusted values as literal text and strip XML-invalid control characters. */
function historyExportText(mixed $value): string
{
    $text = (string)($value ?? '');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $text)) $text = "'" . $text;
    return $text;
}

function historyExportSetText(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, mixed $value): void
{
    $sheet->setCellValueExplicit($cell, historyExportText($value), DataType::TYPE_STRING);
}

/**
 * Convert export timestamps to the deployment's local timezone.
 * MariaDB DATETIME values have no timezone marker; this application writes them
 * while both PHP and MariaDB run in UTC. Values with an explicit offset keep
 * that offset as their source timezone, avoiding a second UTC conversion.
 */
function historyExportLocalDateTime(mixed $value): DateTimeImmutable
{
    $timestamp = trim((string)$value);
    $ist = new DateTimeZone('Asia/Kolkata');
    $hasTimezone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $timestamp) === 1;
    $sourceTimezone = $hasTimezone ? null : new DateTimeZone('UTC');
    return (new DateTimeImmutable($timestamp, $sourceTimezone))->setTimezone($ist);
}

/** Center and wrap every populated table row, then grow rows to fit their longest cell. */
function historyExportFitTableRows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $firstRow, int $lastRow, int $lastColumn): void
{
    if ($lastRow < $firstRow) return;
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColumn);
    $sheet->getStyle("A{$firstRow}:{$lastCol}{$lastRow}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(true);

    for ($row = $firstRow; $row <= $lastRow; $row++) {
        $rowLines = 1;
        for ($column = 1; $column <= $lastColumn; $column++) {
            $cell = $sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column) . $row);
            $value = (string)$cell->getFormattedValue();
            if ($value === '') continue;
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column);
            $capacity = max(1, (int)floor($sheet->getColumnDimension($columnLetter)->getWidth() * 0.9));
            $cellLines = 0;
            foreach (preg_split('/\R/u', $value) ?: [$value] as $line) {
                $textWidth = function_exists('mb_strwidth') ? mb_strwidth($line, 'UTF-8') : strlen($line);
                $cellLines += max(1, (int)ceil(max(1, $textWidth) / $capacity));
            }
            $rowLines = max($rowLines, $cellLines);
        }
        $sheet->getRowDimension($row)->setRowHeight(min(380, max(30, $rowLines * 15)));
    }
}

function historyExportBaseStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $lastColumn, int $titleRow, int $headerRow, int $lastRow): void
{
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColumn);
    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setScale(0);
    $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.3)->setRight(0.3);
    $sheet->getStyle("A{$titleRow}:{$lastCol}{$titleRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '24152F']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $sheet->getRowDimension($titleRow)->setRowHeight(30);
    $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '493253']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DAB9CF']]],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(32);
    if ($lastRow > $headerRow) {
        $sheet->getStyle("A" . ($headerRow + 1) . ":{$lastCol}{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E7E1E8']]],
        ]);
        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $fillColor = (($row - $headerRow) % 2 === 0) ? 'F8F4F8' : 'FFFFFF';
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fillColor);
            $sheet->getRowDimension($row)->setRowHeight(30);
        }
    }
    $sheet->getStyle("A" . ($headerRow + 1) . ":{$lastCol}" . max($headerRow + 1, $lastRow))->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(true);
}

/**
 * Build the inventory history workbook from exactly the rows selected by History filters.
 * Hardware Activity is a separate, explicitly all-time sheet because those events are not inventory transactions.
 */
function buildInventoryHistoryWorkbook(array $rows, array $filters, array $hardwareActivity = []): Spreadsheet
{
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('History');
    $labels = ['opening' => 'Opening balance', 'ordered' => 'Ordered', 'in' => 'Received', 'out' => 'Removed', 'adjustment' => 'Adjustment'];
    $sheet->mergeCells('A1:M1');
    historyExportSetText($sheet, 'A1', 'INVENTORY MANAGEMENT — HISTORY REPORT');
    $generated = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s') . ' IST';
    $filterText = $filters ? implode(' | ', array_map(static fn($key, $value): string => $key . ': ' . historyExportText($value), array_keys($filters), $filters)) : 'None';
    $sheet->mergeCells('A2:M2');
    $metadata = 'Generated: ' . $generated . '    Applied filters: ' . $filterText;
    historyExportSetText($sheet, 'A2', $metadata);
    $sheet->getStyle('A2:M2')->applyFromArray(['font' => ['size' => 9, 'color' => ['rgb' => '615967']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER]]);
    $metadataLength = function_exists('mb_strwidth') ? mb_strwidth($metadata, 'UTF-8') : strlen($metadata);
    $sheet->getRowDimension(2)->setRowHeight(min(380, max(30, (int)ceil(max(1, $metadataLength) / 170) * 15)));

    $totals = ['transactions' => count($rows), 'received' => 0, 'removed' => 0, 'Pending' => 0, 'Confirmed' => 0, 'Rejected' => 0, 'Received' => 0];
    foreach ($rows as $row) {
        $kind = (string)$row['transaction_type'];
        $qty = (int)$row['quantity'];
        if ($kind === 'in') $totals['received'] += max(0, $qty);
        if ($kind === 'out') $totals['removed'] += abs($qty);
        if ($kind === 'ordered') {
            $status = (string)($row['status'] ?? '');
            if (isset($totals[$status])) $totals[$status]++;
        }
    }
    $metrics = [
        ['Total Transactions', $totals['transactions'], false], ['Total Received Quantity', $totals['received'], false],
        ['Total Removed Quantity', $totals['removed'], false],
        ['Pending Orders', $totals['Pending'], false], ['Confirmed Orders', $totals['Confirmed'], false],
        ['Rejected Orders', $totals['Rejected'], false],
    ];
    $metricColumns = [['A', 'D'], ['E', 'I'], ['J', 'M']];
    foreach ($metrics as $index => [$label, $value]) {
        $block = intdiv($index, 3);
        $slot = $index % 3;
        $row = $block === 0 ? 4 : 7;
        [$startColumn, $endColumn] = $metricColumns[$slot];
        $labelRange = $startColumn . $row . ':' . $endColumn . $row;
        $valueRange = $startColumn . ($row + 1) . ':' . $endColumn . ($row + 1);
        $sheet->mergeCells($labelRange);
        $sheet->mergeCells($valueRange);
        historyExportSetText($sheet, $startColumn . $row, $label);
        $sheet->setCellValue($startColumn . ($row + 1), $value);
        $sheet->getStyle($labelRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '493253']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3E5EE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($valueRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '34233F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(25);
        $sheet->getRowDimension($row + 1)->setRowHeight(24);
    }
    $headers = ['Transaction Date', 'Product', 'Category', 'Action', 'Quantity', 'Stock Before', 'Stock After', 'Unit Price (₹)', 'Total Price (₹)', 'Vendor', 'Reason', 'Order Status', 'User'];
    $headerRow = 10;
    foreach ($headers as $column => $header) historyExportSetText($sheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1) . $headerRow, $header);
    $rowNum = $headerRow + 1;
    foreach ($rows as $row) {
        $isOrder = $row['transaction_type'] === 'ordered';
        $date = historyExportLocalDateTime($row['transaction_at']);
        $sheet->setCellValue("A{$rowNum}", ExcelDate::PHPToExcel($date));
        foreach ([
            'B' => $row['product_name'] ?? '', 'C' => $row['category_name'] ?? '',
            'D' => $labels[$row['transaction_type']] ?? $row['transaction_type'],
        ] as $col => $value) historyExportSetText($sheet, $col . $rowNum, $value);
        $sheet->setCellValue("E{$rowNum}", (int)$row['quantity']);
        $sheet->setCellValue("F{$rowNum}", (int)$row['previous_quantity']);
        $sheet->setCellValue("G{$rowNum}", (int)$row['new_quantity']);
        if ($row['unit_price'] !== null) $sheet->setCellValue("H{$rowNum}", (float)$row['unit_price']);
        else historyExportSetText($sheet, "H{$rowNum}", formatInr(null));
        if ($row['total_price'] !== null) $sheet->setCellValue("I{$rowNum}", (float)$row['total_price']);
        else historyExportSetText($sheet, "I{$rowNum}", formatInr(null));
        historyExportSetText($sheet, "J{$rowNum}", $row['vendor'] ?? '');
        $reason = (string)($row['reason'] ?? '');
        if ($isOrder && !empty($row['order_reason']) && $reason !== '') $reason = (string)$row['order_reason'] . ' — ' . $reason;
        elseif ($reason === '') $reason = (string)($row['order_reason'] ?? '');
        historyExportSetText($sheet, "K{$rowNum}", $reason);
        historyExportSetText($sheet, "L{$rowNum}", $isOrder ? ($row['status'] ?? '') : '');
        historyExportSetText($sheet, "M{$rowNum}", $row['user_name'] ?? 'System');
        $rowNum++;
    }
    $lastRow = $rowNum - 1;
    historyExportBaseStyle($sheet, 13, 1, $headerRow, $lastRow);
    $sheet->getStyle('A11:A' . max(11, $lastRow))->getNumberFormat()->setFormatCode('dd-mm-yyyy');
    $sheet->getStyle('H11:I' . max(11, $lastRow))->getNumberFormat()->setFormatCode(inrExcelNumberFormat());
    $sheet->getStyle('H11:I' . max(11, $lastRow))->getFont()->setName('Nirmala UI');
    $sheet->getStyle('E11:G' . max(11, $lastRow))->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0;0');
    $sheet->setAutoFilter("A{$headerRow}:M" . max($headerRow, $lastRow));
    $sheet->freezePane('A11');
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(10, 10);
    $widthLimits = ['A'=>[19,23],'B'=>[18,34],'C'=>[16,28],'D'=>[15,22],'E'=>[11,16],'F'=>[12,18],'G'=>[12,18],'H'=>[15,20],'I'=>[15,20],'J'=>[18,32],'K'=>[20,48],'L'=>[13,18],'M'=>[18,30]];
    foreach ($widthLimits as $col => $_) $sheet->getColumnDimension($col)->setAutoSize(true);
    $sheet->calculateColumnWidths();
    foreach ($widthLimits as $col => [$minimum, $maximum]) {
        $dimension = $sheet->getColumnDimension($col);
        $measured = $dimension->getWidth();
        $dimension->setWidth(max($minimum, min($maximum, $measured)))->setAutoSize(false);
    }
    historyExportFitTableRows($sheet, 11, $lastRow, 13);

    $orders = array_values(array_filter($rows, static fn(array $row): bool => ($row['transaction_type'] ?? '') === 'ordered'));
    $orderSheet = $book->createSheet();
    $orderSheet->setTitle('Order Summary');
    $orderSheet->mergeCells('A1:B1'); historyExportSetText($orderSheet, 'A1', 'ORDER SUMMARY');
    $orderSheet->mergeCells('A2:B2'); historyExportSetText($orderSheet, 'A2', 'Summary of order records in the filtered History results. Order quantities do not represent received stock.');
    $orderSheet->getStyle('A2:B2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $orderSheet->getRowDimension(2)->setRowHeight(36);
    $statusCounts = ['Pending'=>0,'Confirmed'=>0,'Rejected'=>0,'Received'=>0];
    foreach ($orders as $order) if (isset($statusCounts[$order['status'] ?? ''])) $statusCounts[$order['status']]++;
    historyExportSetText($orderSheet, 'A4', 'Order measure');
    historyExportSetText($orderSheet, 'B4', 'Value');
    $summaryRows = [
        ['Pending Orders', $statusCounts['Pending']], ['Confirmed Orders', $statusCounts['Confirmed']],
        ['Rejected Orders', $statusCounts['Rejected']], ['Received Orders', $statusCounts['Received']],
        ['Total Ordered Quantity', array_sum(array_map(static fn(array $row): int => max(0, (int)$row['quantity']), $orders))],
    ];
    $orderValue = array_sum(array_map(static fn(array $row): float => (float)($row['total_price'] ?? 0), $orders));
    $summaryRows[] = ['Total Order Value (₹)', $orderValue];
    $r = 5;
    foreach ($summaryRows as [$label, $value]) {
        historyExportSetText($orderSheet, "A{$r}", $label);
        $orderSheet->setCellValue("B{$r}", $value);
        $r++;
    }
    historyExportBaseStyle($orderSheet, 2, 1, 4, $r - 1);
    $orderSheet->getStyle('B5:B' . ($r - 1))->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0;0');
    $orderSheet->getStyle('B10')->getNumberFormat()->setFormatCode(inrExcelNumberFormat());
    $orderSheet->getStyle('B10')->getFont()->setName('Nirmala UI');
    $orderWidthLimits = ['A'=>[26,40],'B'=>[16,24]];
    foreach ($orderWidthLimits as $col=>$_) $orderSheet->getColumnDimension($col)->setAutoSize(true);
    $orderSheet->calculateColumnWidths();
    foreach ($orderWidthLimits as $col=>[$minimum,$maximum]) {
        $dimension = $orderSheet->getColumnDimension($col);
        $dimension->setWidth(max($minimum,min($maximum,$dimension->getWidth())))->setAutoSize(false);
    }
    historyExportFitTableRows($orderSheet, 5, $r - 1, 2);
    $orderSheet->setAutoFilter('A4:B' . ($r - 1));
    $orderSheet->freezePane('A5');
    $orderSheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 4);

    $hardwareSheet = $book->createSheet();
    $hardwareSheet->setTitle('Hardware Activity');
    $hardwareSheet->mergeCells('A1:J1'); historyExportSetText($hardwareSheet, 'A1', 'HARDWARE ACTIVITY');
    $hardwareSheet->mergeCells('A2:J2'); historyExportSetText($hardwareSheet, 'A2', 'All recorded hardware assignment events; this separate activity log is not scoped by History filters.');
    $hardwareSheet->getStyle('A2:J2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $hardwareSheet->getRowDimension(2)->setRowHeight(30);
    $hardwareHeaders = ['Date','Employee','Employee ID','Action','Product','Category','Asset Tag','Serial Number','Reason','Performed By'];
    foreach ($hardwareHeaders as $i=>$header) historyExportSetText($hardwareSheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '4', $header);
    $r = 5;
    foreach ($hardwareActivity as $event) {
        $hardwareSheet->setCellValue("A{$r}", ExcelDate::PHPToExcel(historyExportLocalDateTime($event['performed_at'])));
        foreach (['B'=>'employee_name','C'=>'employee_id','D'=>'action','E'=>'product_name','F'=>'category_name','G'=>'asset_tag','H'=>'serial_number','I'=>'reason','J'=>'performed_by'] as $col=>$key) historyExportSetText($hardwareSheet, $col . $r, $event[$key] ?? '');
        $r++;
    }
    historyExportBaseStyle($hardwareSheet, 10, 1, 4, $r - 1);
    $hardwareSheet->getStyle('A5:A' . max(5, $r - 1))->getNumberFormat()->setFormatCode('dd-mm-yyyy');
    $hardwareWidthLimits = ['A'=>[19,23],'B'=>[20,32],'C'=>[16,22],'D'=>[14,20],'E'=>[20,34],'F'=>[16,28],'G'=>[18,28],'H'=>[18,28],'I'=>[20,48],'J'=>[18,30]];
    foreach ($hardwareWidthLimits as $col=>$_) $hardwareSheet->getColumnDimension($col)->setAutoSize(true);
    $hardwareSheet->calculateColumnWidths();
    foreach ($hardwareWidthLimits as $col=>[$minimum,$maximum]) {
        $dimension = $hardwareSheet->getColumnDimension($col);
        $dimension->setWidth(max($minimum,min($maximum,$dimension->getWidth())))->setAutoSize(false);
    }
    historyExportFitTableRows($hardwareSheet, 5, $r - 1, 10);
    $hardwareSheet->setAutoFilter('A4:J' . max(4, $r - 1));
    $hardwareSheet->freezePane('A5');
    $hardwareSheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 4);
    $book->setActiveSheetIndex(0);
    return $book;
}
