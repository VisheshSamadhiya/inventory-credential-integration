<?php
declare(strict_types=1);

/**
 * Return the number of physical units represented by one active assignment.
 * Serialized assets represent one unit even if a legacy row also has quantity > 1.
 */
function activeHardwareAssignmentQuantity(int $quantity, int $returnedQuantity, bool $hasAsset): int
{
    return $hasAsset ? 1 : max(0, $quantity - $returnedQuantity);
}

/** SQL equivalent of activeHardwareAssignmentQuantity() for assignment alias $alias. */
function activeHardwareAssignmentQuantitySql(string $alias = 'a'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid assignment table alias.');
    }
    return "CASE WHEN {$alias}.asset_id IS NOT NULL THEN 1 ELSE GREATEST({$alias}.quantity - {$alias}.returned_quantity, 0) END";
}

/**
 * Include assignments linked to an employee record, plus legacy rows that only
 * retain the employee code. A populated employee_record_id always takes priority.
 */
function activeHardwareAssignmentEmployeeSql(string $alias = 'a'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid assignment table alias.');
    }
    return "(({$alias}.employee_record_id IS NOT NULL AND EXISTS (SELECT 1 FROM employees assignment_employee WHERE assignment_employee.id = {$alias}.employee_record_id))"
        . " OR ({$alias}.employee_record_id IS NULL AND EXISTS (SELECT 1 FROM employees assignment_employee WHERE assignment_employee.employee_id = {$alias}.employee_id)))";
}
