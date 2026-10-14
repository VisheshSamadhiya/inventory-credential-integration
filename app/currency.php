<?php
declare(strict_types=1);

/** Format a stored monetary value for display without altering its numeric value. */
function formatInr(mixed $amount): string
{
    if ($amount === null || $amount === '' || !is_numeric($amount)) return '—';
    return '₹' . number_format((float)$amount, 2, '.', ',');
}

/** Excel number format for numeric INR cells. */
function inrExcelNumberFormat(): string
{
    return '₹#,##0.00';
}
