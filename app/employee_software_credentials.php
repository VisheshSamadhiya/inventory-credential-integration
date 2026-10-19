<?php
declare(strict_types=1);

function employeeCredentialActionPermission(string $action): ?array
{
    return match ($action) {
        'credential_create' => ['credentials', 'create'],
        'credential_link', 'credential_update', 'credential_unlink' => ['credentials', 'edit'],
        'credential_delete' => ['credentials', 'delete'],
        default => null,
    };
}

/** Validate optional credential rows submitted with an employee software record. */
function normalizeEmployeeCredentialInputs(mixed $rows): array
{
    if ($rows === null || $rows === '') return [];
    if (!is_array($rows)) throw new DomainException('Credential entries are invalid.');
    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new DomainException('Credential entries are invalid.');
        $service = trim((string)($row['service_name'] ?? ''));
        $username = trim((string)($row['credential_username'] ?? ''));
        $password = (string)($row['credential_password'] ?? '');
        $url = trim((string)($row['service_url'] ?? ''));
        $notes = trim((string)($row['notes'] ?? ''));
        if ($service === '' && $username === '' && $password === '' && $url === '' && $notes === '') continue;
        if ($service === '' || $password === '') throw new DomainException('Each new credential needs a service name and password.');
        if (mb_strlen($service) > 180 || mb_strlen($username) > 190 || strlen($password) > 4096 || strlen($url) > 500 || mb_strlen($notes) > 4000) {
            throw new DomainException('One or more credential fields are too long.');
        }
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new DomainException('Enter a valid HTTP or HTTPS service URL.');
        }
        $normalized[] = [
            'service_name' => $service,
            'credential_username' => $username !== '' ? $username : null,
            'credential_password' => $password,
            'service_url' => $url !== '' ? $url : null,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }
    return $normalized;
}

/** Enforce one optional credential action in the software form. */
function employeeSoftwareCredentialChoice(string $mode, mixed $rows, mixed $linkId): array
{
    if (!in_array($mode, ['', 'link', 'create'], true)) throw new DomainException('Choose one credential action.');
    $entries = normalizeEmployeeCredentialInputs($rows);
    $id = filter_var($linkId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($linkId !== null && $linkId !== '' && !$id) throw new DomainException('Choose a valid existing credential.');
    if ($mode === 'link') {
        if ($entries) throw new DomainException('Choose either an existing credential or a new credential.');
        if (!$id) throw new DomainException('Select an existing credential to link.');
        return ['mode' => 'link', 'entries' => [], 'link_id' => (int)$id];
    }
    if ($mode === 'create') {
        if ($id) throw new DomainException('Choose either an existing credential or a new credential.');
        return ['mode' => $entries ? 'create' : '', 'entries' => $entries, 'link_id' => null];
    }
    if ($entries || $id) throw new DomainException('Choose one credential action before submitting.');
    return ['mode' => '', 'entries' => [], 'link_id' => null];
}

function employeeCredentialCanLinkToSoftware(array $credential, int $employeeId, int $softwareId): bool
{
    return (int)($credential['employee_id'] ?? 0) === $employeeId
        && (int)($credential['active'] ?? 0) === 1
        && in_array((int)($credential['software_installation_id'] ?? 0), [0, $softwareId], true);
}

/** Build the only employee-software credential insert payload; passwords are encrypted here. */
function employeeSoftwareCredentialInsertValues(array $entry, int $employeeId, ?int $departmentId, int $softwareId, int $actorId): array
{
    if ($employeeId < 1 || $softwareId < 1 || $actorId < 1) throw new InvalidArgumentException('Employee credential associations are invalid.');
    $password = (string)($entry['credential_password'] ?? '');
    if ($password === '') throw new DomainException('Credential password is required.');
    return [
        $employeeId,
        $departmentId && $departmentId > 0 ? $departmentId : null,
        $softwareId,
        (string)$entry['service_name'],
        $entry['service_url'] ?: null,
        $entry['credential_username'] ?: null,
        encryptCredentialValue($password),
        $entry['notes'] ?: null,
        $actorId,
    ];
}
