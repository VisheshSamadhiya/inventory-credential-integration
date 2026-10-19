<?php
declare(strict_types=1);
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/history_helpers.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

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

/** Combine a selected reason and free-text remark without repeating the same value. */
function historyExportReason(mixed $reason, mixed $remark): string
{
    $reason = trim((string)($reason ?? ''));
    $remark = trim((string)($remark ?? ''));
    if ($reason === '') return $remark;
    if ($remark === '') return $reason;
    $normalizedReason = function_exists('mb_strtolower') ? mb_strtolower($reason, 'UTF-8') : strtolower($reason);
    $normalizedRemark = function_exists('mb_strtolower') ? mb_strtolower($remark, 'UTF-8') : strtolower($remark);
    return $normalizedReason === $normalizedRemark ? $reason : $reason . ' — ' . $remark;
}

/** CSV export mirrors the filtered on-screen transactions and hardware-event log. */
function buildInventoryHistoryCsv(array $rows, array $hardwareActivity, array $filters = []): string
{
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) throw new RuntimeException('Could not create the history CSV stream.');
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, ['Inventory History Report'], ',', '"', '\\');
    fputcsv($stream, ['Generated', (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s') . ' IST'], ',', '"', '\\');
    fputcsv($stream, ['Applied filters', implode(' | ', array_map(static fn($key,$value): string => $key . ': ' . (string)$value, array_keys($filters), $filters)) ?: 'None'], ',', '"', '\\');
    fputcsv($stream, [], ',', '"', '\\');
    $valuationColumns = ['Available Value Before','Available Value After','Assigned Value Before','Assigned Value After','Physical Value Before','Physical Value After','Disposed Value Before','Disposed Value After'];
    $headers = array_merge(['Record Type','Date','Record ID','Product','Brand','Model','Category','Action','Employee','Employee ID','Asset Tag','Serial Number','Internal Serial Number','Quantity','Ordered Quantity','Received as of event','Remaining as of event','Available Before','Available Impact','Available After','Assigned Change','Assigned Before','Assigned After','Physical Before','Physical After','Unit Cost (INR)','Transaction Value (INR)','Physical Value Impact (INR)','Vendor','Reason','Notes / Reference','Purchase Order','Order Status','Responsible User','Location','Previous Product','Replacement Product','Previous Asset Tag','Replacement Asset Tag','Previous Serial','Replacement Serial','Ordered Value (INR)'], $valuationColumns);
    fputcsv($stream, array_map('historyExportText', $headers), ',', '"', '\\');
    $textCell = static fn(mixed $value): string => historyExportText($value);
    foreach ($rows as $row) {
        $stock = $row['stock_calculation'] ?? inventoryHistoryStockPresentation($row);
        $physicalImpact = $row['physical_value_impact'] ?? inventoryHistoryPhysicalValueImpact($row);
        $record = $row['assignment_record'] ?? null;
        $unitCost = $row['unit_price'] ?? null;
        $total = $row['total_price'] ?? null;
        $cells = [
            'Inventory transaction', historyExportLocalDateTime($row['transaction_at'] ?? 'now')->format('Y-m-d H:i:s'), (int)($row['transaction_id'] ?? $row['id'] ?? 0),
            $row['product_name'] ?? '', $row['brand'] ?? '', $row['model'] ?? '', $row['category_name'] ?? '',
            $row['movement_label'] ?? inventoryHistoryMovementLabel($row), $row['employee_name'] ?? ($record['assigned_to'] ?? ''),
            $row['employee_number'] ?? ($record['employee_id'] ?? ''), $row['asset_tag'] ?? '', $row['serial_number'] ?? '', $row['internal_serial_number'] ?? '',
            (int)$stock['quantity'], $row['order_quantity'] ?? '', $row['received_as_of'] ?? '', $row['order_remaining_as_of'] ?? '',
            $stock['stock_before'] ?? 'Historical balance unavailable', $stock['stock_impact'] ?? 'Historical balance unavailable', $stock['stock_after'] ?? 'Historical balance unavailable',
            $row['assigned_change'] ?? inventoryHistoryAssignedImpact($row), $row['assigned_before'] ?? 'Historical balance unavailable', $row['assigned_after'] ?? 'Historical balance unavailable',
            $row['physical_before'] ?? 'Historical balance unavailable', $row['physical_after'] ?? 'Historical balance unavailable',
            $unitCost === null ? 'Cost unavailable' : '₹' . number_format((float)$unitCost, 2, '.', ','),
            ($row['transaction_type'] ?? '') === 'ordered' ? 'Not received inventory; see Ordered Value' : inventoryHistoryCostDisplay($row),
            ($row['transaction_type'] ?? '') === 'ordered' ? 'Not applicable (order only)' : ($physicalImpact === null ? 'Cost unavailable' : '₹' . number_format((float)$physicalImpact, 2, '.', ',')),
            $row['vendor'] ?? '', historyExportReason($row['order_reason'] ?? '', $row['reason'] ?? ''),
            trim((string)($row['notes'] ?? '') . ' ' . (string)($row['reference_number'] ?? '')),
            !empty($row['purchase_order_id']) ? 'PO #' . (int)$row['purchase_order_id'] : '', $row['purchase_order_status'] ?? $row['status'] ?? '',
            $row['user_name'] ?? 'System', $row['asset_location'] ?? '', '', '', '', '', '', '',
            isset($row['historical_valuation']['ordered_value_cents']) ? '₹' . number_format($row['historical_valuation']['ordered_value_cents'] / 100, 2, '.', ',') : '',
            ...array_map(static fn(string $bucket, string $moment): string => inventoryHistoryValuationStateDisplay($row['historical_valuation'] ?? null, $moment, $bucket), ['available','available','assigned','assigned','physical','physical','disposed','disposed'], ['before','after','before','after','before','after','before','after']),
        ];
        fputcsv($stream, array_map($textCell, $cells), ',', '"', '\\');
    }
    foreach ($hardwareActivity as $event) {
        $cells = [
            'Hardware assignment event', historyExportLocalDateTime($event['performed_at'] ?? 'now')->format('Y-m-d H:i:s'), (int)($event['event_id'] ?? 0),
            $event['product_name'] ?? '', '', '', $event['category_name'] ?? '', ucfirst((string)($event['action'] ?? '')),
            $event['employee_name'] ?? '', $event['employee_id'] ?? '', $event['asset_tag'] ?? '', $event['serial_number'] ?? '', $event['internal_serial_number'] ?? '',
            !empty($event['asset_id']) ? 1 : ($event['quantity'] ?? ''), '', '', '', '', '', '', '', '', '', '', '',
            'Cost unavailable', 'Cost unavailable', 'Cost unavailable', '', $event['reason'] ?? '', 'Assignment #' . (int)($event['assignment_id'] ?? 0), '', '',
            $event['performed_by'] ?? 'System', $event['location'] ?? '',
            $event['old_product_name'] ?? '', $event['new_product_name'] ?? '', $event['old_asset_tag'] ?? '', $event['new_asset_tag'] ?? '', $event['old_serial_number'] ?? '', $event['new_serial_number'] ?? '',
            'Not applicable', 'Not applicable','Not applicable','Not applicable','Not applicable','Not applicable','Not applicable','Not applicable','Not applicable',
        ];
        fputcsv($stream, array_map($textCell, $cells), ',', '"', '\\');
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    return is_string($csv) ? $csv : '';
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
        ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
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

/** Find actual populated content, including the far edge of merged report headers. */
function historyExportContentBounds(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
{
    $lastColumn = 1;
    $lastRow = 1;
    foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
        $cell = $sheet->getCell($coordinate);
        $value = $cell->getValue();
        if ($value === null || $value === '') continue;
        [$column, $row] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($coordinate);
        $lastColumn = max($lastColumn, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column));
        $lastRow = max($lastRow, (int)$row);
    }
    foreach ($sheet->getMergeCells() as $range) {
        [, $last] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries($range);
        $lastColumn = max($lastColumn, (int)$last[0]);
        $lastRow = max($lastRow, (int)$last[1]);
    }
    return [$lastColumn, $lastRow];
}

/** Hide the unused worksheet canvas while keeping the workbook editable and unprotected. */
function historyExportFinalizePrintLayout(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    [$lastColumn, $lastRow] = historyExportContentBounds($sheet);
    $lastColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColumn);
    $sheet->getPageSetup()->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageSetup()->setPrintArea("A1:{$lastColumnName}{$lastRow}");

    for ($column = $lastColumn + 1; $column <= 16384; $column++) {
        $columnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column);
        $sheet->getColumnDimension($columnName)->setVisible(false);
    }
    $lastHiddenRow = min(1048576, max($lastRow + 100, 500));
    for ($row = $lastRow + 1; $row <= $lastHiddenRow; $row++) {
        $sheet->getRowDimension($row)->setVisible(false);
    }
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
    $sheet->mergeCells('A1:O1');
    historyExportSetText($sheet, 'A1', 'INVENTORY MANAGEMENT — HISTORY REPORT');
    $generated = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s') . ' IST';
    $filterText = $filters ? implode(' | ', array_map(static fn($key, $value): string => $key . ': ' . historyExportText($value), array_keys($filters), $filters)) : 'None';
    $sheet->mergeCells('A2:O2');
    $metadata = 'Generated: ' . $generated . '    Applied filters: ' . $filterText;
    historyExportSetText($sheet, 'A2', $metadata);
    $sheet->getStyle('A2:O2')->applyFromArray(['font' => ['size' => 9, 'color' => ['rgb' => '615967']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER]]);
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
    $metricColumns = [['A', 'D'], ['E', 'I'], ['J', 'N']];
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
    $headers = ['Transaction Date', 'Product', 'Category', 'Action', 'Quantity', 'Stock Before', 'Stock Impact', 'Stock After', 'Unit Cost (INR)', 'Transaction Value (INR)', 'Vendor', 'Reason', 'Order Status', 'User', 'Invoice / PO Reference'];
    $headerRow = 10;
    foreach ($headers as $column => $header) historyExportSetText($sheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1) . $headerRow, $header);
    $rowNum = $headerRow + 1;
    foreach ($rows as $row) {
        $isOrder = $row['transaction_type'] === 'ordered';
        $stock = inventoryHistoryStockPresentation($row);
        $actionLabel=inventoryHistoryMovementLabel($row);
        $date = historyExportLocalDateTime($row['transaction_at']);
        $sheet->setCellValue("A{$rowNum}", ExcelDate::PHPToExcel($date));
        foreach ([
            'B' => $row['product_name'] ?? '', 'C' => $row['category_name'] ?? '',
            'D' => $actionLabel,
        ] as $col => $value) historyExportSetText($sheet, $col . $rowNum, $value);
        $sheet->setCellValue("E{$rowNum}", $stock['quantity']);
        if ($stock['stock_before'] === null) historyExportSetText($sheet, "F{$rowNum}", 'Historical balance unavailable'); else $sheet->setCellValue("F{$rowNum}", $stock['stock_before']);
        if ($stock['stock_impact'] === null) historyExportSetText($sheet, "G{$rowNum}", 'Historical balance unavailable'); else $sheet->setCellValue("G{$rowNum}", $stock['stock_impact']);
        if ($stock['stock_after'] === null) historyExportSetText($sheet, "H{$rowNum}", 'Historical balance unavailable'); else $sheet->setCellValue("H{$rowNum}", $stock['stock_after']);
        if ($row['unit_price'] !== null) $sheet->setCellValue("I{$rowNum}", (float)$row['unit_price']);
        else historyExportSetText($sheet, "I{$rowNum}", 'Cost unavailable');
        if ($isOrder) historyExportSetText($sheet, "J{$rowNum}", 'Not received inventory; see Order Summary');
        elseif ($row['total_price'] !== null) $sheet->setCellValue("J{$rowNum}", (float)$row['total_price']);
        elseif (($row['cost_covered_quantity'] ?? 0) > 0) historyExportSetText($sheet, "J{$rowNum}", inventoryHistoryCostDisplay($row));
        else historyExportSetText($sheet, "J{$rowNum}", 'Cost unavailable');
        historyExportSetText($sheet, "K{$rowNum}", $row['vendor'] ?? '');
        $reason = historyExportReason($isOrder ? ($row['order_reason'] ?? '') : '', $row['reason'] ?? '');
        historyExportSetText($sheet, "L{$rowNum}", $reason);
        historyExportSetText($sheet, "M{$rowNum}", $isOrder ? ($row['status'] ?? '') : '');
        historyExportSetText($sheet, "N{$rowNum}", $row['user_name'] ?? 'System');
        $references = array_filter([$row['reference_number'] ?? '', !empty($row['related_order_id']) ? 'PO #' . (int)$row['related_order_id'] : '']);
        historyExportSetText($sheet, "O{$rowNum}", implode(' · ', $references));
        $rowNum++;
    }
    $lastRow = $rowNum - 1;
    historyExportBaseStyle($sheet, 15, 1, $headerRow, $lastRow);
    $sheet->getStyle('A11:A' . max(11, $lastRow))->getNumberFormat()->setFormatCode('dd-mm-yyyy');
    $sheet->getStyle('I11:J' . max(11, $lastRow))->getNumberFormat()->setFormatCode(inrExcelNumberFormat());
    $sheet->getStyle('I11:J' . max(11, $lastRow))->getFont()->setName('Nirmala UI');
    $sheet->getStyle('E11:H' . max(11, $lastRow))->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0;0');
    $sheet->setAutoFilter("A{$headerRow}:O" . max($headerRow, $lastRow));
    $sheet->freezePane('A11');
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(10, 10);
    $widthLimits = ['A'=>[19,23],'B'=>[18,34],'C'=>[16,28],'D'=>[15,22],'E'=>[20,28],'F'=>[12,18],'G'=>[12,18],'H'=>[12,18],'I'=>[15,20],'J'=>[15,20],'K'=>[18,32],'L'=>[20,48],'M'=>[13,18],'N'=>[18,30],'O'=>[18,28]];
    foreach ($widthLimits as $col => $_) $sheet->getColumnDimension($col)->setAutoSize(true);
    $sheet->calculateColumnWidths();
    foreach ($widthLimits as $col => [$minimum, $maximum]) {
        $dimension = $sheet->getColumnDimension($col);
        $measured = $dimension->getWidth();
        $dimension->setWidth(max($minimum, min($maximum, $measured)))->setAutoSize(false);
    }
    historyExportFitTableRows($sheet, 11, $lastRow, 15);

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
    $hardwareSheet->mergeCells('A1:U1'); historyExportSetText($hardwareSheet, 'A1', 'HARDWARE ACTIVITY');
    $hardwareSheet->mergeCells('A2:U2'); historyExportSetText($hardwareSheet, 'A2', 'Hardware events matching the selected employee, product, category, search and date filters. Replacement rows retain both prior and replacement product and asset details. These events are separate from stock movements and are not double-counted.');
    $hardwareSheet->getStyle('A2:N2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $hardwareSheet->getRowDimension(2)->setRowHeight(30);
    $hardwareHeaders = ['Date','Event ID','Employee','Employee ID','Action','Product','Category','Asset Tag','Serial Number','Internal Serial','Assignment Record Quantity','Assigned Date','Location','Reason','Performed By','Previous Product','Replacement Product','Previous Asset Tag','Replacement Asset Tag','Previous Serial','Replacement Serial'];
    foreach ($hardwareHeaders as $i=>$header) historyExportSetText($hardwareSheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '4', $header);
    $r = 5;
    foreach ($hardwareActivity as $event) {
        $hardwareSheet->setCellValue("A{$r}", ExcelDate::PHPToExcel(historyExportLocalDateTime($event['performed_at'])));
        foreach (['C'=>'employee_name','D'=>'employee_id','E'=>'action','F'=>'product_name','G'=>'category_name','H'=>'asset_tag','I'=>'serial_number','J'=>'internal_serial_number','M'=>'location','N'=>'reason','O'=>'performed_by','P'=>'old_product_name','Q'=>'new_product_name','R'=>'old_asset_tag','S'=>'new_asset_tag','T'=>'old_serial_number','U'=>'new_serial_number'] as $col=>$key) historyExportSetText($hardwareSheet, $col . $r, $event[$key] ?? '');
        historyExportSetText($hardwareSheet, "B{$r}", (string)($event['event_id'] ?? ''));
        $hardwareSheet->setCellValue("K{$r}", !empty($event['asset_id']) ? 1 : (int)($event['quantity'] ?? 0));
        if (!empty($event['assigned_at'])) $hardwareSheet->setCellValue("L{$r}", ExcelDate::PHPToExcel(historyExportLocalDateTime($event['assigned_at'])));
        $r++;
    }
    historyExportBaseStyle($hardwareSheet, 21, 1, 4, $r - 1);
    $hardwareSheet->getStyle('A5:A' . max(5, $r - 1))->getNumberFormat()->setFormatCode('dd-mm-yyyy');
    $hardwareSheet->getStyle('L5:L' . max(5, $r - 1))->getNumberFormat()->setFormatCode('dd-mm-yyyy');
    $hardwareWidthLimits = ['A'=>[19,23],'B'=>[10,12],'C'=>[20,32],'D'=>[16,22],'E'=>[14,20],'F'=>[20,34],'G'=>[16,28],'H'=>[18,28],'I'=>[18,28],'J'=>[18,28],'K'=>[16,20],'L'=>[19,23],'M'=>[18,30],'N'=>[20,48],'O'=>[18,30],'P'=>[20,34],'Q'=>[20,34],'R'=>[18,28],'S'=>[18,28],'T'=>[18,28],'U'=>[18,28]];
    foreach ($hardwareWidthLimits as $col=>$_) $hardwareSheet->getColumnDimension($col)->setAutoSize(true);
    $hardwareSheet->calculateColumnWidths();
    foreach ($hardwareWidthLimits as $col=>[$minimum,$maximum]) {
        $dimension = $hardwareSheet->getColumnDimension($col);
        $dimension->setWidth(max($minimum,min($maximum,$dimension->getWidth())))->setAutoSize(false);
    }
    historyExportFitTableRows($hardwareSheet, 5, $r - 1, 21);
    $hardwareSheet->setAutoFilter('A4:U' . max(4, $r - 1));
    $hardwareSheet->freezePane('A5');
    $hardwareSheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 4);

    $detailSheet = $book->createSheet();
    $detailSheet->setTitle('Calculation Details');
    $detailHeaders = ['Record Type','Transaction Date','Transaction ID','Product','Brand','Model','Category','Action','Quantity','Available Before','Available Impact','Available After','Assigned Change','Assigned Before','Assigned After','Physical Before','Physical After','Employee','Employee ID','Assignment ID','Asset Tag','Serial Number','Internal Serial','Location','Purchase Order','Ordered Quantity','Received as of Event','Remaining as of Event','Received Current','Remaining Current','Unit Purchase Cost (INR)','Transaction Value (INR)','Physical Value Impact (INR)','Vendor / Contact','Reason','Notes / Reference','Order Status','Responsible User','Ordered Value (INR)','Available Value Before','Available Value After','Assigned Value Before','Assigned Value After','Physical Value Before','Physical Value After','Disposed Value Before','Disposed Value After'];
    $lastDetailColumn = count($detailHeaders);
    $lastDetailColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastDetailColumn);
    $detailSheet->mergeCells("A1:{$lastDetailColumnName}1");
    historyExportSetText($detailSheet, 'A1', 'CHRONOLOGICAL INVENTORY CALCULATION DETAILS');
    $detailSheet->mergeCells("A2:{$lastDetailColumnName}2");
    historyExportSetText($detailSheet, 'A2', 'Recorded quantities and balances are shown as saved. Assigned and physical balances are included only when the full assignment replay reconciles to current assignment records. Missing historical values remain explicitly unavailable.');
    $detailSheet->getStyle("A2:{$lastDetailColumnName}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $detailSheet->getRowDimension(2)->setRowHeight(42);
    foreach ($detailHeaders as $i => $header) historyExportSetText($detailSheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '4', $header);
    $detailRow = 5;
    $setDetail = static function (Worksheet $worksheet, string $cell, mixed $value): void {
        if (is_int($value) || is_float($value)) $worksheet->setCellValue($cell, $value);
        else historyExportSetText($worksheet, $cell, $value);
    };
    foreach ($rows as $row) {
        $stock = $row['stock_calculation'] ?? inventoryHistoryStockPresentation($row);
        $physicalImpact = $row['physical_value_impact'] ?? inventoryHistoryPhysicalValueImpact($row);
        $assignment = $row['assignment_record'] ?? null;
        $date = historyExportLocalDateTime($row['transaction_at'] ?? 'now');
        $detailSheet->setCellValue("B{$detailRow}", ExcelDate::PHPToExcel($date));
        $values = [
            1=>'Inventory transaction',3=>$row['transaction_id']??$row['id']??'',4=>$row['product_name']??'',5=>$row['brand']??'',6=>$row['model']??'',7=>$row['category_name']??'',
            8=>$row['movement_label']??inventoryHistoryMovementLabel($row),9=>(int)$stock['quantity'],
            10=>$stock['stock_before']??'Historical balance unavailable',11=>$stock['stock_impact']??'Historical balance unavailable',12=>$stock['stock_after']??'Historical balance unavailable',
            13=>$row['assigned_change']??inventoryHistoryAssignedImpact($row),14=>$row['assigned_before']??'Historical balance unavailable',15=>$row['assigned_after']??'Historical balance unavailable',
            16=>$row['physical_before']??'Historical balance unavailable',17=>$row['physical_after']??'Historical balance unavailable',
            18=>$row['employee_name']??($assignment['assigned_to']??''),19=>$row['employee_number']??($assignment['employee_id']??''),20=>$row['assignment_id']??'',
            21=>$row['asset_tag']??'',22=>$row['serial_number']??'',23=>$row['internal_serial_number']??'',24=>$row['asset_location']??'',
            25=>!empty($row['purchase_order_id'])?'PO #'.(int)$row['purchase_order_id']:'',26=>$row['order_quantity']??'',27=>$row['received_as_of']??'',
            28=>$row['order_remaining_as_of']??'',29=>$row['received_current']??'',30=>$row['order_remaining_current']??'',
            31=>$row['unit_price']===null?'Cost unavailable':(float)$row['unit_price'],32=>($row['transaction_type']??'')==='ordered'?'Not received inventory; see Ordered Value':($row['total_price']===null?inventoryHistoryCostDisplay($row):(float)$row['total_price']),
            33=>($row['transaction_type']??'')==='ordered'?'Not applicable (order only)':($physicalImpact===null?'Cost unavailable':(float)$physicalImpact),
            34=>trim((string)($row['vendor']??'').' '.(string)($row['vendor_contact_snapshot']??'')),
            35=>historyExportReason($row['order_reason']??'', $row['reason']??''),36=>trim((string)($row['notes']??'').' '.(string)($row['reference_number']??'').' '.(string)($row['order_reference']??'')),
            37=>$row['purchase_order_status']??$row['status']??'',38=>$row['user_name']??'System',
            39=>isset($row['historical_valuation']['ordered_value_cents']) ? '₹' . number_format($row['historical_valuation']['ordered_value_cents'] / 100, 2, '.', ',') : '',
            40=>inventoryHistoryValuationStateDisplay($row['historical_valuation']??null,'before','available'),
            41=>inventoryHistoryValuationStateDisplay($row['historical_valuation']??null,'after','available'),
            42=>inventoryHistoryValuationStateDisplay($row['historical_valuation']??null,'before','assigned'),
            43=>inventoryHistoryValuationStateDisplay($row['historical_valuation']??null,'after','assigned'),
            44=>inventoryHistoryValuationStateDisplay($row['historical_valuation']??null,'before','physical'),
            45=>inventoryHistoryValuationStateDisplay($row['historical_valuation']??null,'after','physical'),
            46=>inventoryHistoryValuationStateDisplay($row['historical_valuation']??null,'before','disposed'),
            47=>inventoryHistoryValuationStateDisplay($row['historical_valuation']??null,'after','disposed'),
        ];
        foreach ($values as $column=>$value) $setDetail($detailSheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column) . $detailRow, $value);
        $detailRow++;
    }
    historyExportBaseStyle($detailSheet, $lastDetailColumn, 1, 4, $detailRow - 1);
    $detailSheet->getStyle('B5:B' . max(5, $detailRow - 1))->getNumberFormat()->setFormatCode('dd-mm-yyyy hh:mm:ss');
    foreach (['AE','AF','AG'] as $column) $detailSheet->getStyle($column . '5:' . $column . max(5, $detailRow - 1))->getNumberFormat()->setFormatCode(inrExcelNumberFormat());
    $detailSheet->setAutoFilter('A4:' . $lastDetailColumnName . max(4, $detailRow - 1));
    $detailSheet->freezePane('A5');
    $detailWidths = ['A'=>24,'B'=>22,'C'=>14,'D'=>24,'E'=>16,'F'=>16,'G'=>20,'H'=>24,'I'=>13,'J'=>17,'K'=>17,'L'=>17,'M'=>15,'N'=>15,'O'=>15,'P'=>15,'Q'=>15,'R'=>24,'S'=>16,'T'=>15,'U'=>19,'V'=>19,'W'=>20,'X'=>20,'Y'=>16,'Z'=>17,'AA'=>18,'AB'=>19,'AC'=>17,'AD'=>18,'AE'=>20,'AF'=>22,'AG'=>24,'AH'=>32,'AI'=>48,'AJ'=>48,'AK'=>18,'AL'=>24,'AM'=>22,'AN'=>28,'AO'=>28,'AP'=>28,'AQ'=>28,'AR'=>28,'AS'=>28,'AT'=>28,'AU'=>28];
    foreach ($detailWidths as $column=>$width) $detailSheet->getColumnDimension($column)->setWidth($width);
    historyExportFitTableRows($detailSheet, 5, $detailRow - 1, $lastDetailColumn);
    $detailSheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4,4);
    foreach ([$sheet, $orderSheet, $hardwareSheet, $detailSheet] as $worksheet) historyExportFinalizePrintLayout($worksheet);
    $book->setActiveSheetIndex(0);
    return $book;
}
