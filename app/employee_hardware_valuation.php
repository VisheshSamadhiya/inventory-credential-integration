<?php
declare(strict_types=1);

function employeeHardwareMoneyCents(mixed $value): ?int
{
    if ($value === null || $value === '') return null;
    $normalized = trim((string)$value);
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $normalized, $parts)) return null;
    return ((int)$parts[1] * 100) + (int)str_pad($parts[2] ?? '', 2, '0');
}

function employeeHardwareFormatCents(int $cents): string
{
    return '₹' . number_format($cents / 100, 2, '.', ',');
}

function employeeHardwareCostDisplay(int $knownCents, int $coveredUnits, int $totalUnits): string
{
    $totalUnits = max(0, $totalUnits);
    $coveredUnits = max(0, min($coveredUnits, $totalUnits));
    if ($totalUnits === 0) return employeeHardwareFormatCents(0);
    if ($coveredUnits === 0) return 'Cost unavailable';
    $amount = employeeHardwareFormatCents(max(0, $knownCents));
    $unknownUnits = $totalUnits - $coveredUnits;
    return $coveredUnits < $totalUnits
        ? $amount . ' (Partial; ' . $unknownUnits . ' ' . ($unknownUnits === 1 ? 'unit' : 'units') . ' unknown)'
        : $amount;
}

function employeeHardwareMultiplyMoney(?string $unitCost, int $quantity): ?string
{
    $unitCents = employeeHardwareMoneyCents($unitCost);
    if ($unitCents === null || $quantity < 0) return null;
    $totalCents = $unitCents * $quantity;
    return intdiv($totalCents, 100) . '.' . str_pad((string)($totalCents % 100), 2, '0', STR_PAD_LEFT);
}

function resolveEmployeeHardwareAssetCost(PDO $pdo, int $assetId): array
{
    $query = $pdo->prepare("SELECT ia.acquisition_unit_cost,ia.acquisition_cost_layer_id,
            COALESCE(ia.acquisition_unit_cost,asset_layer.unit_cost,reconciliation.unit_cost) unit_cost,
            COALESCE(ia.acquisition_cost_layer_id,asset_layer.id,reconciliation_layer.id) cost_layer_id,
            COALESCE(asset_layer.source_transaction_id,reconciliation.source_transaction_id,reconciliation_layer.source_transaction_id) receipt_transaction_id
        FROM inventory_assets ia
        LEFT JOIN inventory_cost_layers asset_layer ON asset_layer.id=ia.acquisition_cost_layer_id
        LEFT JOIN inventory_cost_reconciliations reconciliation ON reconciliation.asset_id=ia.id AND reconciliation.kind='asset'
        LEFT JOIN inventory_cost_layers reconciliation_layer ON reconciliation_layer.reconciliation_id=reconciliation.id
        WHERE ia.id=? ORDER BY reconciliation.id DESC LIMIT 1");
    $query->execute([$assetId]);
    return $query->fetch() ?: ['acquisition_unit_cost'=>null,'acquisition_cost_layer_id'=>null,'unit_cost'=>null,'cost_layer_id'=>null,'receipt_transaction_id'=>null];
}

/** Split a bulk assignment's FIFO allocation cost across its current, returned and historical units. */
function employeeHardwareAssignmentCostBreakdown(array $assignment): array
{
    $serializedAsset = !empty($assignment['asset_id']);
    $quantity = $serializedAsset ? 1 : max(0, (int)($assignment['quantity'] ?? 0));
    $returnedQuantity = min($quantity, max(0, (int)($assignment['returned_quantity'] ?? 0)));
    $activeQuantity = max(0, $quantity - $returnedQuantity);

    if ($serializedAsset) {
        $unitCents = employeeHardwareMoneyCents($assignment['asset_unit_cost'] ?? null);
        $covered = $unitCents === null ? 0 : $quantity;
        $activeCovered = $unitCents === null ? 0 : $activeQuantity;
        $returnedCovered = $unitCents === null ? 0 : $returnedQuantity;
        return [
            'assigned_cents' => $unitCents === null ? 0 : $unitCents * $quantity,
            'assigned_covered' => $covered,
            'assigned_units' => $quantity,
            'active_cents' => $unitCents === null ? 0 : $unitCents * $activeQuantity,
            'active_covered' => $activeCovered,
            'active_units' => $activeQuantity,
            'returned_cents' => $unitCents === null ? 0 : $unitCents * $returnedQuantity,
            'returned_covered' => $returnedCovered,
            'returned_units' => $returnedQuantity,
        ];
    }

    $allocations = $assignment['cost_allocations'] ?? [];
    $assignedCents = 0;
    $assignedCovered = 0;
    $activeCents = 0;
    $activeCovered = 0;
    $returnedCents = 0;
    $returnedCovered = 0;
    $activeRemaining = $activeQuantity;
    $returnedRemaining = $returnedQuantity;
    foreach ($allocations as $allocation) {
        $unitCents = employeeHardwareMoneyCents($allocation['unit_cost'] ?? null);
        if ($unitCents === null) continue;
        $allocated = max(0, (int)($allocation['quantity'] ?? 0));
        $returned = min($allocated, max(0, (int)($allocation['returned_quantity'] ?? 0)));
        $stillAssigned = $allocated - $returned;
        $assignedCents += $unitCents * $allocated;
        $assignedCovered += $allocated;
        $activeTake = min($activeRemaining, $stillAssigned);
        $activeCents += $unitCents * $activeTake;
        $activeCovered += $activeTake;
        $activeRemaining -= $activeTake;
        $returnedTake = min($returnedRemaining, $returned);
        $returnedCents += $unitCents * $returnedTake;
        $returnedCovered += $returnedTake;
        $returnedRemaining -= $returnedTake;
    }

    return [
        'assigned_cents' => $assignedCents,
        'assigned_covered' => min($assignedCovered, $quantity),
        'assigned_units' => $quantity,
        'active_cents' => $activeCents,
        'active_covered' => min($activeCovered, $activeQuantity),
        'active_units' => $activeQuantity,
        'returned_cents' => $returnedCents,
        'returned_covered' => min($returnedCovered, $returnedQuantity),
        'returned_units' => $returnedQuantity,
    ];
}

function employeeHardwareDeduplicateEvents(array $events): array
{
    $unique = [];
    $seenTransactions = [];
    foreach ($events as $event) {
        $transactionId = (int)($event['transaction_id'] ?? 0);
        if ($transactionId > 0 && isset($seenTransactions[$transactionId])) continue;
        if ($transactionId > 0) $seenTransactions[$transactionId] = true;
        $unique[] = $event;
    }
    return $unique;
}

function employeeHardwareSummary(array $assignments): array
{
    $summary = [
        'active' => ['cents' => 0, 'covered' => 0, 'units' => 0],
        'assigned' => ['cents' => 0, 'covered' => 0, 'units' => 0],
        'returned' => ['cents' => 0, 'covered' => 0, 'units' => 0],
        'active_count' => 0,
    ];
    foreach ($assignments as $assignment) {
        $cost = employeeHardwareAssignmentCostBreakdown($assignment);
        foreach (['assigned','returned'] as $metric) {
            $summary[$metric]['cents'] += $cost[$metric . '_cents'];
            $summary[$metric]['covered'] += $cost[$metric . '_covered'];
            $summary[$metric]['units'] += $cost[$metric . '_units'];
        }
        if (($assignment['status'] ?? '') === 'assigned' && empty($assignment['returned_at'])) {
            $summary['active']['cents'] += $cost['active_cents'];
            $summary['active']['covered'] += $cost['active_covered'];
            $summary['active']['units'] += $cost['active_units'];
            $summary['active_count'] += max(0,(int)($assignment['active_assignment_quantity'] ?? $cost['active_units']));
        }
    }
    return $summary;
}

function employeeHardwareHistorySummary(array $events): array
{
    $events = employeeHardwareDeduplicateEvents($events);
    $summary = [
        'assigned' => ['cents'=>0,'covered'=>0,'units'=>0],
        'returned' => ['cents'=>0,'covered'=>0,'units'=>0],
    ];
    foreach ($events as $event) {
        $action = (string)($event['action'] ?? '');
        $quantity = max(0, (int)($event['event_quantity'] ?? 1));
        if (in_array($action, ['assigned','reassigned'], true)) {
            $cents = employeeHardwareMoneyCents($event['acquisition_value'] ?? null);
            $covered = min($quantity, max(0, (int)($event['cost_covered_quantity'] ?? 0)));
            $summary['assigned']['units'] += $quantity;
            $summary['assigned']['covered'] += $covered;
            if ($cents !== null) $summary['assigned']['cents'] += $cents;
        } elseif ($action === 'returned') {
            $cents = employeeHardwareMoneyCents($event['acquisition_value'] ?? null);
            $covered = min($quantity, max(0, (int)($event['cost_covered_quantity'] ?? 0)));
            $summary['returned']['units'] += $quantity;
            $summary['returned']['covered'] += $covered;
            if ($cents !== null) $summary['returned']['cents'] += $cents;
        } elseif ($action === 'replaced') {
            // Legacy replacement rows carry both assets in one event. Preserve
            // their distinct outgoing and incoming values without adding the
            // same stock transaction twice.
            $oldCents = employeeHardwareMoneyCents($event['old_asset_unit_cost'] ?? null);
            $newCents = employeeHardwareMoneyCents($event['new_asset_unit_cost'] ?? null);
            $summary['returned']['units'] += 1;
            $summary['assigned']['units'] += 1;
            if ($oldCents !== null) { $summary['returned']['covered']++; $summary['returned']['cents'] += $oldCents; }
            if ($newCents !== null) { $summary['assigned']['covered']++; $summary['assigned']['cents'] += $newCents; }
        }
    }
    return $summary;
}

/**
 * Use one cost source per assignment/event group. Assignment-linked allocations
 * cover legacy rows without snapshots; immutable event snapshots win when they
 * provide better coverage. This prevents the same movement being added once
 * from history and again from its stock transaction.
 */
function employeeHardwareMergeHistoryMetrics(array $assignments, array $events): array
{
    $events = employeeHardwareDeduplicateEvents($events);
    $byAssignment = [];
    foreach ($events as $event) {
        $id = (int)($event['assignment_id'] ?? 0);
        if ($id < 1) continue;
        $action = (string)($event['action'] ?? '');
        $metric = in_array($action, ['assigned','reassigned'], true) ? 'assigned' : ($action === 'returned' ? 'returned' : null);
        if ($metric === null) continue;
        $quantity = max(0, (int)($event['event_quantity'] ?? 1));
        $covered = min($quantity, max(0, (int)($event['cost_covered_quantity'] ?? 0)));
        $cents = employeeHardwareMoneyCents($event['acquisition_value'] ?? null);
        $byAssignment[$id][$metric]['units'] = ($byAssignment[$id][$metric]['units'] ?? 0) + $quantity;
        $byAssignment[$id][$metric]['covered'] = ($byAssignment[$id][$metric]['covered'] ?? 0) + $covered;
        $byAssignment[$id][$metric]['cents'] = ($byAssignment[$id][$metric]['cents'] ?? 0) + ($cents ?? 0);
    }

    $summary = ['assigned'=>['cents'=>0,'covered'=>0,'units'=>0], 'returned'=>['cents'=>0,'covered'=>0,'units'=>0]];
    foreach ($assignments as $assignment) {
        $id = (int)($assignment['assignment_id'] ?? 0);
        $cost = employeeHardwareAssignmentCostBreakdown($assignment);
        foreach (['assigned','returned'] as $metric) {
            $assignmentMetric = [
                'cents'=>$cost[$metric . '_cents'],
                'covered'=>$cost[$metric . '_covered'],
                'units'=>$cost[$metric . '_units'],
            ];
            $eventMetric = $byAssignment[$id][$metric] ?? null;
            if ($metric === 'returned' && $eventMetric !== null) {
                // Count completed return events exactly once. Assignment counters
                // can lag in imported legacy data, so event quantities lead.
                $assignmentMetric['units'] = max($assignmentMetric['units'], $eventMetric['units']);
            }
            if ($eventMetric !== null && $eventMetric['covered'] > $assignmentMetric['covered']) {
                $selected = $eventMetric;
                $selected['units'] = max($assignmentMetric['units'], $eventMetric['units']);
            } else {
                $selected = $assignmentMetric;
            }
            $selected['covered'] = min($selected['covered'], $selected['units']);
            $summary[$metric]['cents'] += $selected['cents'];
            $summary[$metric]['covered'] += $selected['covered'];
            $summary[$metric]['units'] += $selected['units'];
        }
    }
    return $summary;
}

function recordEmployeeHardwareHistoryEvent(
    PDO $pdo,
    int $employeeId,
    int $assignmentId,
    string $action,
    ?string $reason,
    ?int $oldAssetId,
    ?int $newAssetId,
    ?int $performedBy,
    ?int $transactionId,
    int $eventQuantity,
    int $coveredQuantity,
    ?string $acquisitionValue
): void {
    if (!in_array($action, ['assigned','reassigned','returned','removed','replaced','tagged'], true)) {
        throw new InvalidArgumentException('Invalid employee hardware history action.');
    }
    $query = $pdo->prepare('INSERT INTO hardware_assignment_history(employee_id,assignment_id,action,reason,old_asset_id,new_asset_id,performed_by,transaction_id,event_quantity,cost_covered_quantity,acquisition_value) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $query->execute([$employeeId,$assignmentId,$action,$reason,$oldAssetId,$newAssetId,$performedBy,$transactionId,$eventQuantity,$coveredQuantity,$acquisitionValue]);
}
