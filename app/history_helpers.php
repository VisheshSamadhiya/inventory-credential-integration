<?php
declare(strict_types=1);

/**
 * Present a recorded inventory transaction without consulting current product stock.
 * Order rows carry an ordered quantity, but only a separate receipt movement changes stock.
 */
function inventoryHistoryStockPresentation(array $row): array
{
    $type = (string)($row['transaction_type'] ?? '');
    $quantity = (int)($row['quantity'] ?? 0);
    $stockBefore = array_key_exists('previous_quantity', $row) && $row['previous_quantity'] !== null
        ? (int)$row['previous_quantity'] : null;

    if ($type === 'ordered') {
        return [
            'quantity_label' => 'Ordered Quantity',
            'quantity' => abs($quantity),
            'stock_before' => $stockBefore,
            'stock_impact' => 0,
            'stock_after' => array_key_exists('new_quantity', $row) && $row['new_quantity'] !== null
                ? (int)$row['new_quantity'] : $stockBefore,
        ];
    }

    $recordedAfter = array_key_exists('new_quantity', $row) && $row['new_quantity'] !== null
        ? (int)$row['new_quantity'] : null;
    $derivedImpact = match ($type) {
        'opening', 'in' => abs($quantity),
        'out' => -abs($quantity),
        'adjustment' => $quantity,
        default => null,
    };
    // Saved balances take precedence. If one is absent, derive only the value
    // supported by the movement type and the other recorded balance.
    $stockImpact = $stockBefore !== null && $recordedAfter !== null
        ? $recordedAfter - $stockBefore : $derivedImpact;
    $stockAfter = $recordedAfter ?? ($stockBefore !== null && $stockImpact !== null
        ? $stockBefore + $stockImpact : null);

    return [
        'quantity_label' => 'Quantity',
        'quantity' => abs($quantity),
        'stock_before' => $stockBefore,
        'stock_impact' => $stockImpact,
        'stock_after' => $stockAfter,
    ];
}

/** Extract a persisted assignment link from the stock transaction's recorded text. */
function inventoryHistoryAssignmentId(array $row): ?int
{
    $text = (string)($row['notes'] ?? '') . ' ' . (string)($row['reason'] ?? '');
    if (preg_match('/\bassignment\s*#\s*(\d+)\b/i', $text, $match) !== 1) return null;
    $id = (int)$match[1];
    return $id > 0 ? $id : null;
}

/** Return assignment-unit movement for stock transactions, without counting order rows. */
function inventoryHistoryAssignedImpact(array $row): int
{
    $type = (string)($row['transaction_type'] ?? '');
    $reason = strtolower(trim((string)($row['reason'] ?? '')));
    $notes = strtolower((string)($row['notes'] ?? ''));
    $quantity = abs((int)($row['quantity'] ?? 0));
    if ($quantity === 0) return 0;

    $isAssignmentOut = $type === 'out' && (
        str_starts_with($reason, 'assignment') ||
        str_starts_with($reason, 'replacement assignment') ||
        str_contains($notes, 'assignment #')
    );
    if ($isAssignmentOut) return $quantity;

    $isAssignmentReturn = $type === 'in' && (
        str_starts_with($reason, 'assignment return') ||
        str_starts_with($reason, 'hardware returned') ||
        str_starts_with($reason, 'hardware removed') ||
        str_contains($notes, 'return from assignment #') ||
        str_contains($notes, 'hardware assignment #')
    );
    return $isAssignmentReturn ? -$quantity : 0;
}

/** Build assigned and total physical balances only when the full replay reconciles to live assignments. */
function inventoryHistoryAssignmentTimeline(array $events, int $currentAssigned): array
{
    usort($events, static fn(array $left, array $right): int =>
        [(string)($left['transaction_at'] ?? ''), (int)($left['id'] ?? 0)]
        <=> [(string)($right['transaction_at'] ?? ''), (int)($right['id'] ?? 0)]
    );
    $assigned = 0;
    $timeline = [];
    $valid = true;
    foreach ($events as $event) {
        $id = (int)($event['id'] ?? 0);
        $before = $assigned;
        $assigned += inventoryHistoryAssignedImpact($event);
        if ($assigned < 0) $valid = false;
        $timeline[$id] = ['assigned_before' => $before, 'assigned_after' => $assigned];
    }
    $valid = $valid && $assigned === $currentAssigned;
    return ['reliable' => $valid, 'current' => $assigned, 'rows' => $valid ? $timeline : []];
}

function inventoryHistoryBalanceDisplay(mixed $value): string
{
    return $value === null ? 'Historical balance unavailable' : number_format((int)$value);
}

/** Physical value changes only when stock is received/opened or removed/disposed. */
function inventoryHistoryPhysicalValueImpact(array $row): ?float
{
    $type = (string)($row['transaction_type'] ?? '');
    $assignmentImpact = inventoryHistoryAssignedImpact($row);
    if ($type === 'ordered' || $assignmentImpact !== 0) return 0.0;
    if (!in_array($type, ['opening', 'in', 'out', 'adjustment'], true)) return null;
    $quantity = abs((int)($row['quantity'] ?? 0));
    if (array_key_exists('cost_covered_quantity', $row) && (int)$row['cost_covered_quantity'] < $quantity) return null;
    $value = $row['total_price'] ?? null;
    if ($value === null && ($row['unit_price'] ?? null) !== null) {
        $value = abs((int)($row['quantity'] ?? 0)) * (float)$row['unit_price'];
    }
    if ($value === null) return null;
    $amount = (float)$value;
    if ($type === 'out' || ($type === 'adjustment' && (int)($row['quantity'] ?? 0) < 0)) return -$amount;
    if ($type === 'adjustment' && (int)($row['quantity'] ?? 0) === 0) return 0.0;
    return $amount;
}

/** Describe the cost transfer between available and assigned stock, separately from physical value change. */
function inventoryHistoryAssignmentValuation(array $row): ?array
{
    $assignedChange = inventoryHistoryAssignedImpact($row);
    if ($assignedChange === 0) return null;

    $movement = is_array($row['historical_valuation'] ?? null) ? $row['historical_valuation'] : [];
    $cents = isset($movement['movement_value_cents']) ? (int)$movement['movement_value_cents'] : null;
    $unknownUnits = max(0, (int)($movement['movement_cost_unknown_units'] ?? abs($assignedChange)));
    $coveredUnits = max(0, (int)($movement['movement_cost_covered_units'] ?? 0));
    $direction = $assignedChange > 0 ? 'assigned' : 'available';

    if ($cents === null || $coveredUnits === 0) {
        $valueLabel = 'Cost unavailable';
        $availableChange = null;
        $assignedValueChange = null;
    } else {
        $amount = '₹' . number_format($cents / 100, 2, '.', ',');
        $valueLabel = $unknownUnits > 0 ? $amount . ' known · ' . number_format($unknownUnits) . ' ' . ($unknownUnits === 1 ? 'unit' : 'units') . ' cost unavailable' : $amount;
        $availableChange = $assignedChange > 0 ? -$cents : $cents;
        $assignedValueChange = -$availableChange;
    }

    return [
        'direction' => $direction,
        'value_label' => $valueLabel,
        'available_change_cents' => $availableChange,
        'assigned_change_cents' => $assignedValueChange,
        'physical_change_cents' => 0,
        'covered_units' => $coveredUnits,
        'unknown_units' => $unknownUnits,
    ];
}

function inventoryHistoryAssignmentValuationDisplay(?array $valuation): ?string
{
    if ($valuation === null) return null;
    $destination = $valuation['direction'] === 'assigned' ? 'to assigned' : 'to available';
    if ($valuation['value_label'] === 'Cost unavailable') {
        $units = max(1, (int)($valuation['unknown_units'] ?? 0));
        return 'Cost unavailable · ' . number_format($units) . ' ' . ($units === 1 ? 'unit' : 'units') . ' transferred ' . $destination;
    }
    return (string)$valuation['value_label'] . ' transferred ' . $destination;
}

function inventoryHistoryCostDisplay(array $row): string
{
    $quantity = abs((int)($row['quantity'] ?? 0));
    $covered = (int)($row['cost_covered_quantity'] ?? (($row['total_price'] ?? null) !== null ? $quantity : 0));
    $amount = $row['total_price'] ?? $row['known_cost_amount'] ?? null;
    if ($amount === null || $covered < 1) return 'Cost unavailable';
    $formatted = '₹' . number_format((float)$amount, 2, '.', ',');
    return $covered < $quantity ? $formatted . ' (partial: ' . $covered . ' of ' . $quantity . ' units)' : $formatted;
}

/**
 * Replay the recorded valuation movements for one product without consulting
 * current product prices. Any opening stock without a supported basis stays
 * in the unknown-unit count. A final comparison to the current valuation
 * ledger determines whether the historical replay can be called reconciled.
 */
function inventoryHistoryValuationTimeline(array $events, bool $assignmentTimelineReliable, ?array $currentKnown = null): array
{
    usort($events, static fn(array $left, array $right): int =>
        [(string)($left['transaction_at'] ?? ''), (int)($left['transaction_id'] ?? $left['id'] ?? 0)]
        <=> [(string)($right['transaction_at'] ?? ''), (int)($right['transaction_id'] ?? $right['id'] ?? 0)]
    );
    if (!$events) return ['reconciled'=>false,'rows'=>[],'final'=>[]];

    $first = $events[0];
    $initialAvailable = array_key_exists('previous_quantity', $first) && $first['previous_quantity'] !== null
        ? max(0, (int)$first['previous_quantity']) : null;
    $state = [
        'available_value_cents'=>0, 'assigned_value_cents'=>0, 'disposed_value_cents'=>0,
        'available_unknown_units'=>$initialAvailable, 'assigned_unknown_units'=>$assignmentTimelineReliable ? 0 : null,
        'disposed_unknown_units'=>0, 'available_units'=>$initialAvailable, 'assigned_units'=>$assignmentTimelineReliable ? 0 : null,
        'arithmetic_reliable'=>$initialAvailable !== null && $assignmentTimelineReliable,
    ];
    $rows = [];
    $movementById = [];
    foreach ($events as $event) {
        $id = (int)($event['transaction_id'] ?? $event['id'] ?? 0);
        $before = $state;
        $stock = inventoryHistoryStockPresentation($event);
        $availableDelta = $stock['stock_impact'];
        $assignedDelta = inventoryHistoryAssignedImpact($event);
        $type = (string)($event['transaction_type'] ?? '');
        $movementQuantity = $type === 'ordered' ? 0 : abs((int)($availableDelta ?? 0));
        $covered = min($movementQuantity, max(0, (int)($event['cost_covered_quantity'] ?? (($event['total_price'] ?? null) !== null ? $movementQuantity : 0))));
        $amount = $event['total_price'] ?? $event['known_cost_amount'] ?? null;
        if ($amount === null && ($event['unit_price'] ?? null) !== null && $covered > 0) {
            $amount = $covered * (float)$event['unit_price'];
        }
        $amountCents = $amount !== null && $covered > 0 ? (int)round((float)$amount * 100) : 0;
        $unknownMovement = max(0, $movementQuantity - $covered);
        $isDisposal = inventoryHistoryIsDisposal($event);

        if ($type !== 'ordered' && $movementQuantity > 0) {
            if ($assignedDelta > 0) {
                if ($availableDelta !== -$assignedDelta) $state['arithmetic_reliable'] = false;
                if ($state['available_unknown_units'] !== null) {
                    $state['available_unknown_units'] -= $unknownMovement;
                    if ($state['available_unknown_units'] < 0) $state['arithmetic_reliable'] = false;
                }
                if ($state['assigned_unknown_units'] !== null) $state['assigned_unknown_units'] += $unknownMovement;
                if ($state['available_value_cents'] < $amountCents) {
                    $state['arithmetic_reliable'] = false;
                } else {
                    $state['available_value_cents'] -= $amountCents;
                    $state['assigned_value_cents'] += $amountCents;
                }
            } elseif ($assignedDelta < 0) {
                if ($availableDelta !== abs($assignedDelta)) $state['arithmetic_reliable'] = false;
                if ($state['assigned_unknown_units'] !== null) {
                    $state['assigned_unknown_units'] -= $unknownMovement;
                    if ($state['assigned_unknown_units'] < 0) $state['arithmetic_reliable'] = false;
                }
                if ($state['available_unknown_units'] !== null) $state['available_unknown_units'] += $unknownMovement;
                if ($state['assigned_value_cents'] < $amountCents) {
                    $state['arithmetic_reliable'] = false;
                } else {
                    $state['assigned_value_cents'] -= $amountCents;
                    $state['available_value_cents'] += $amountCents;
                }
            } elseif ((int)$availableDelta > 0) {
                if ($state['available_unknown_units'] !== null) $state['available_unknown_units'] += $unknownMovement;
                $state['available_value_cents'] += $amountCents;
            } elseif ((int)$availableDelta < 0) {
                if ($state['available_unknown_units'] !== null) {
                    $state['available_unknown_units'] -= $unknownMovement;
                    if ($state['available_unknown_units'] < 0) $state['arithmetic_reliable'] = false;
                }
                if ($state['available_value_cents'] < $amountCents) {
                    $state['arithmetic_reliable'] = false;
                } else {
                    $state['available_value_cents'] -= $amountCents;
                    if ($isDisposal) $state['disposed_value_cents'] += $amountCents;
                }
                if ($isDisposal && $state['disposed_unknown_units'] !== null) $state['disposed_unknown_units'] += $unknownMovement;
            }
        }

        if ($availableDelta !== null && $state['available_units'] !== null) {
            $recordedBefore = $stock['stock_before'];
            if ($recordedBefore !== null && $recordedBefore !== $state['available_units']) $state['arithmetic_reliable'] = false;
            $state['available_units'] = $stock['stock_after'];
        } else {
            $state['available_units'] = null;
            $state['arithmetic_reliable'] = false;
        }
        if ($assignmentTimelineReliable && array_key_exists($id, $event['assignment_timeline'] ?? [])) {
            $assignmentState = $event['assignment_timeline'][$id];
            $state['assigned_units'] = (int)$assignmentState['assigned_after'];
        } elseif (array_key_exists('assigned_after', $event) && $event['assigned_after'] !== null) {
            $state['assigned_units'] = (int)$event['assigned_after'];
        } elseif ($assignedDelta !== 0 && $state['assigned_units'] !== null) {
            $state['assigned_units'] += $assignedDelta;
            if ($state['assigned_units'] < 0) $state['arithmetic_reliable'] = false;
        }

        $after = $state;
        $before['physical_value_cents'] = $before['available_value_cents'] + $before['assigned_value_cents'];
        $after['physical_value_cents'] = $after['available_value_cents'] + $after['assigned_value_cents'];
        $movementById[$id] = [
            'before'=>$before,
            'after'=>$after,
            'movement_value_cents'=>$amount !== null && $covered > 0 ? $amountCents : null,
            'movement_cost_covered_units'=>$covered,
            'movement_cost_unknown_units'=>$unknownMovement,
            'disposed'=>$isDisposal,
            'available_reconciled'=>false,
            'assigned_reconciled'=>false,
            'physical_reconciled'=>false,
            'disposed_reconciled'=>false,
            'reconciled'=>false,
            'ordered_value_cents'=>$type === 'ordered' && ($event['total_price'] ?? null) !== null
                ? (int)round((float)$event['total_price'] * 100) : null,
        ];
    }

    $final = $state;
    $bucketReconciled = ['available'=>false,'assigned'=>false,'disposed'=>false];
    if ($currentKnown !== null) {
        foreach ($bucketReconciled as $bucket => $_) {
            $expected = $currentKnown[$bucket . '_value_cents'] ?? null;
            $actual = $final[$bucket . '_value_cents'] ?? null;
            $expectedUnknown = $currentKnown[$bucket . '_unknown_units'] ?? null;
            $actualUnknown = $final[$bucket . '_unknown_units'] ?? null;
            $bucketReconciled[$bucket] = $expected !== null && $actual !== null && abs((int)$expected - (int)$actual) <= 1
                && $expectedUnknown !== null && $actualUnknown !== null && (int)$expectedUnknown === (int)$actualUnknown;
        }
        $expectedPhysical = ($currentKnown['available_value_cents'] ?? null) !== null && ($currentKnown['assigned_value_cents'] ?? null) !== null
            ? (int)$currentKnown['available_value_cents'] + (int)$currentKnown['assigned_value_cents'] : null;
        $expectedPhysicalUnknown = ($currentKnown['available_unknown_units'] ?? null) !== null && ($currentKnown['assigned_unknown_units'] ?? null) !== null
            ? (int)$currentKnown['available_unknown_units'] + (int)$currentKnown['assigned_unknown_units'] : null;
        $bucketReconciled['physical'] = $expectedPhysical !== null && $expectedPhysicalUnknown !== null
            && abs($expectedPhysical - (int)$final['available_value_cents'] - (int)$final['assigned_value_cents']) <= 1
            && $expectedPhysicalUnknown === (int)$final['available_unknown_units'] + (int)($final['assigned_unknown_units'] ?? 0);
    }
    foreach ($movementById as $id => &$movement) {
        $movement['available_reconciled'] = $bucketReconciled['available'] && $final['arithmetic_reliable'];
        $movement['assigned_reconciled'] = $bucketReconciled['assigned'] && $final['arithmetic_reliable'] && $assignmentTimelineReliable;
        $movement['physical_reconciled'] = $bucketReconciled['physical'] && $final['arithmetic_reliable'] && $assignmentTimelineReliable;
        $movement['disposed_reconciled'] = $bucketReconciled['disposed'] && $final['arithmetic_reliable'];
        $movement['reconciled'] = $movement['available_reconciled'] && $movement['assigned_reconciled'] && $movement['disposed_reconciled'];
    }
    unset($movement);
    return ['reconciled'=>!in_array(false, $bucketReconciled, true) && $final['arithmetic_reliable'] && $assignmentTimelineReliable,'rows'=>$movementById,'final'=>$final];
}

function inventoryHistoryIsDisposal(array $row): bool
{
    return preg_match('/dispos|damag|write.?off/i', (string)($row['reason'] ?? '')) === 1;
}

/** Format known cents while keeping unknown units and ledger mismatches explicit. */
function inventoryHistoryValuationDisplay(?int $cents, ?int $unknownUnits, bool $reconciled): string
{
    if ($cents === null) return 'Valuation unavailable';
    $amount = '₹' . number_format($cents / 100, 2, '.', ',');
    $notes = [];
    if ($unknownUnits === null) $notes[] = 'historical balance unavailable';
    elseif ($unknownUnits > 0) $notes[] = number_format($unknownUnits) . ' unit(s) cost unavailable';
    if (!$reconciled) $notes[] = 'historical valuation does not reconcile to current cost records';
    if (!$notes) return $amount;
    if ($cents === 0 && $unknownUnits !== null && $unknownUnits > 0) return 'Cost unavailable (' . implode('; ', $notes) . ')';
    return $amount . ' (incomplete: ' . implode('; ', $notes) . ')';
}

function inventoryHistoryValuationStateDisplay(?array $valuation, string $moment, string $bucket): string
{
    if ($valuation === null || !isset($valuation[$moment]) || !in_array($bucket, ['available','assigned','physical','disposed'], true)) return 'Valuation unavailable';
    $state = $valuation[$moment];
    $valueKey = match ($bucket) {
        'available'=>'available_value_cents', 'assigned'=>'assigned_value_cents',
        'physical'=>'physical_value_cents', 'disposed'=>'disposed_value_cents',
    };
    $unknown = match ($bucket) {
        'available'=>$state['available_unknown_units'] ?? null,
        'assigned'=>$state['assigned_unknown_units'] ?? null,
        'physical'=>isset($state['available_unknown_units'], $state['assigned_unknown_units']) ? (int)$state['available_unknown_units'] + (int)$state['assigned_unknown_units'] : null,
        'disposed'=>$state['disposed_unknown_units'] ?? null,
    };
    return inventoryHistoryValuationDisplay(
        isset($state[$valueKey]) ? (int)$state[$valueKey] : null,
        $unknown === null ? null : (int)$unknown,
        (bool)($valuation[$bucket . '_reconciled'] ?? false)
    );
}

/** Compact one-line amount for the transaction table; full caveats stay in details. */
/** Show the known-cost delta and mark endpoints that remain partial or unreconciled. */
function inventoryHistoryValuationDeltaDisplay(?array $valuation, string $bucket): string
{
    if ($valuation === null || !in_array($bucket, ['available','assigned','physical','disposed'], true)
        || !isset($valuation['before'], $valuation['after'])) return 'Valuation unavailable';
    $valueKey = match ($bucket) {
        'available'=>'available_value_cents', 'assigned'=>'assigned_value_cents',
        'physical'=>'physical_value_cents', 'disposed'=>'disposed_value_cents',
    };
    if (!isset($valuation['before'][$valueKey], $valuation['after'][$valueKey])) return 'Valuation unavailable';
    $delta = (int)$valuation['after'][$valueKey] - (int)$valuation['before'][$valueKey];
    $unknownFor = static function (array $state) use ($bucket): ?int {
        return match ($bucket) {
            'available'=>isset($state['available_unknown_units']) ? (int)$state['available_unknown_units'] : null,
            'assigned'=>isset($state['assigned_unknown_units']) ? (int)$state['assigned_unknown_units'] : null,
            'physical'=>isset($state['available_unknown_units'],$state['assigned_unknown_units']) ? (int)$state['available_unknown_units'] + (int)$state['assigned_unknown_units'] : null,
            'disposed'=>isset($state['disposed_unknown_units']) ? (int)$state['disposed_unknown_units'] : null,
        };
    };
    $beforeUnknown = $unknownFor($valuation['before']);
    $afterUnknown = $unknownFor($valuation['after']);
    $reconciled = (bool)($valuation[$bucket . '_reconciled'] ?? false);
    $amount = ($delta > 0 ? '+' : ($delta < 0 ? '−' : '')) . '₹' . number_format(abs($delta) / 100, 2, '.', ',');
    return $amount . (($beforeUnknown === null || $afterUnknown === null || $beforeUnknown > 0 || $afterUnknown > 0 || !$reconciled) ? ' (partial / unreconciled)' : '');
}

function inventoryHistoryValuationCompactDisplay(?array $valuation, string $moment, string $bucket): string
{
    if ($valuation === null || !isset($valuation[$moment]) || !in_array($bucket, ['available','assigned','physical','disposed'], true)) return 'Unavailable';
    $state = $valuation[$moment];
    $valueKey = match ($bucket) {
        'available'=>'available_value_cents', 'assigned'=>'assigned_value_cents',
        'physical'=>'physical_value_cents', 'disposed'=>'disposed_value_cents',
    };
    if (!isset($state[$valueKey])) return 'Unavailable';
    $unknown = match ($bucket) {
        'available'=>$state['available_unknown_units'] ?? null,
        'assigned'=>$state['assigned_unknown_units'] ?? null,
        'physical'=>isset($state['available_unknown_units'], $state['assigned_unknown_units']) ? (int)$state['available_unknown_units'] + (int)$state['assigned_unknown_units'] : null,
        'disposed'=>$state['disposed_unknown_units'] ?? null,
    };
    if ((int)$state[$valueKey] === 0 && $unknown !== null && $unknown > 0) return 'Cost unavailable';
    $amount = '₹' . number_format((int)$state[$valueKey] / 100, 2, '.', ',');
    $reconciled = (bool)($valuation[$bucket . '_reconciled'] ?? false);
    return $amount . (($unknown === null || $unknown > 0 || !$reconciled) ? '*' : '');
}

function inventoryHistoryMovementLabel(array $row): string
{
    $type=(string)($row['transaction_type']??'');$reason=(string)($row['reason']??'');$notes=(string)($row['notes']??'');
    if($type==='in'&&(preg_match('/hardware removed\s*[→>-]/i',$reason)||preg_match('/hardware assignment\s*#\s*\d+\s*removed/i',$notes)))return 'Removed from employee (returned to stock)';
    if($type==='in'&&str_starts_with($reason,'Hardware returned for replacement'))return 'Replacement return';
    if($type==='out'&&str_starts_with($reason,'Replacement assignment'))return 'Replacement assignment';
    if($type==='in'&&(preg_match('/^Hardware returned/i',$reason)||str_starts_with($reason,'Assignment return')))return 'Returned';
    if($type==='out'&&str_starts_with($reason,'Assignment'))return 'Assigned';
    if($type==='out'&&strcasecmp($reason,'Damaged')===0)return 'Damaged';
    if($type==='out'&&strcasecmp($reason,'Disposed')===0)return 'Disposed';
    return ['opening'=>'Opening balance','ordered'=>'Ordered','in'=>'Received','out'=>'Removed','adjustment'=>'Adjustment'][$type]??$type;
}

function inventoryValuationDisplay(?float $amount, int $unknownUnits): string
{
    if($amount===null)return 'Cost unavailable';
    if($unknownUnits>0)return $amount>0?'₹'.number_format($amount,2,'.',',').' · Partial':'Cost unavailable';
    return '₹'.number_format($amount,2,'.',',');
}
