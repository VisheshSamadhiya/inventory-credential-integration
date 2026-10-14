<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function credentialKeyBytes(): string
{
    $configured = getenv('CREDENTIAL_ENCRYPTION_KEY');
    if (!is_string($configured) || trim($configured) === '') {
        throw new RuntimeException('Credential encryption is not configured.');
    }
    $configured = trim($configured);
    $decoded = base64_decode($configured, true);
    if (is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return $decoded;
    if (strlen($configured) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return $configured;
    throw new RuntimeException('Credential encryption is not configured.');
}

/** New values use Sodium secretbox; decryption accepts existing AES-GCM and secretbox rows. */
function encryptCredentialValue(string $plaintext): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, credentialKeyBytes()));
}

function decryptCredentialValue(string $encoded): string
{
    $decoded = base64_decode($encoded, true);
    if ($decoded === false) throw new RuntimeException('Stored credential is not decryptable.');
    $json = json_decode($decoded, true);
    if (is_array($json) && isset($json['iv'], $json['tag'], $json['data'])) {
        $iv = base64_decode((string)$json['iv'], true);
        $tag = base64_decode((string)$json['tag'], true);
        $ciphertext = base64_decode((string)$json['data'], true);
        if ($iv === false || $tag === false || $ciphertext === false || strlen($iv) !== 12 || strlen($tag) !== 16) {
            throw new RuntimeException('Stored credential is not decryptable.');
        }
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', credentialKeyBytes(), OPENSSL_RAW_DATA, $iv, $tag, '');
        if ($plaintext === false) throw new RuntimeException('Stored credential is not decryptable.');
        return $plaintext;
    }
    if (strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Stored credential is not decryptable.');
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, credentialKeyBytes());
    if ($plaintext === false) throw new RuntimeException('Stored credential is not decryptable.');
    return $plaintext;
}

function credentialAccessLog(int $credentialId, string $action): void
{
    $stmt = db()->prepare('INSERT INTO credential_access_logs (credential_id,user_id,action,ip_address,user_agent) VALUES (?,?,?,?,?)');
    $stmt->execute([$credentialId, (int)currentUser()['id'], $action, $_SERVER['REMOTE_ADDR'] ?? null, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
}

function credentialS3Client(): ?object
{
    static $client = null;
    static $checked = false;
    if ($checked) return $client;
    $checked = true;
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    $region = getenv('AWS_REGION');
    $bucket = getenv('AWS_S3_BUCKET');
    if (!is_string($region) || $region === '' || !is_string($bucket) || $bucket === '' || !class_exists('Aws\\S3\\S3Client')) return null;
    $client = new Aws\S3\S3Client(['version' => 'latest', 'region' => $region]);
    return $client;
}

function credentialS3Bucket(): ?string
{
    $bucket = getenv('AWS_S3_BUCKET');
    return is_string($bucket) && $bucket !== '' ? $bucket : null;
}

function credentialCanAccessDepartment(?int $departmentId): bool
{
    if (canAdmin()) return true;
    if ($departmentId === null || $departmentId < 1) return false;
    $stmt = db()->prepare('SELECT 1 FROM credential_department_access WHERE user_id=? AND department_id=? LIMIT 1');
    $stmt->execute([(int)currentUser()['id'], $departmentId]);
    return (bool)$stmt->fetchColumn();
}

function credentialRecord(int $id, bool $includeInactive = false): ?array
{
    $stmt = db()->prepare('SELECT c.*, e.full_name employee_name, e.employee_id employee_code, e.department employee_department, cd.name department_name, si.software_name FROM credentials c LEFT JOIN employees e ON e.id=c.employee_id LEFT JOIN credential_departments cd ON cd.id=c.department_id LEFT JOIN software_installations si ON si.id=c.software_installation_id WHERE c.id=?' . ($includeInactive ? '' : ' AND c.active=1') . ' LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function credentialHttpUrl(?string $url): ?string
{
    if (!is_string($url) || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true) ? $url : null;
}

function credentialScopeSql(string $alias = 'c'): array
{
    if (canAdmin()) return ['1=1', []];
    $userId = (int)currentUser()['id'];
    $predicate = "({$alias}.user_id=? OR EXISTS (SELECT 1 FROM credential_department_access cda JOIN credential_departments cd ON cd.id=cda.department_id WHERE cda.user_id=? AND cd.id=COALESCE({$alias}.department_id,(SELECT ced.id FROM employees ce JOIN credential_departments ced ON LOWER(TRIM(ced.name))=LOWER(TRIM(ce.department)) WHERE ce.id={$alias}.employee_id LIMIT 1))))";
    return [$predicate, [$userId, $userId]];
}

function credentialDepartmentForEmployee(?int $employeeId): ?int
{
    if (!$employeeId) return null;
    $stmt = db()->prepare('SELECT cd.id FROM employees e JOIN credential_departments cd ON LOWER(TRIM(cd.name))=LOWER(TRIM(e.department)) WHERE e.id=? LIMIT 1');
    $stmt->execute([$employeeId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int)$value;
}

function credentialCanAccess(array $credential): bool
{
    if (canAdmin()) return true;
    $userId = (int)currentUser()['id'];
    if ((int)($credential['user_id'] ?? 0) === $userId) return true;
    $departmentId = isset($credential['department_id']) ? (int)$credential['department_id'] : 0;
    if ($departmentId < 1) $departmentId = credentialDepartmentForEmployee((int)($credential['employee_id'] ?? 0)) ?? 0;
    return credentialCanAccessDepartment($departmentId > 0 ? $departmentId : null);
}

function requireCredentialRecord(int $id, bool $includeInactive = false): array
{
    $record = credentialRecord($id, $includeInactive);
    if (!$record || !credentialCanAccess($record)) {
        http_response_code(404);
        exit('Credential not found.');
    }
    return $record;
}

function requireCredentialEmployeeAccess(int $employeeId): array
{
    $stmt = db()->prepare('SELECT id,employee_id,full_name,department FROM employees WHERE id=? AND active=1 LIMIT 1');
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    if (!$employee) throw new DomainException('Employee not found.');
    if (!canAdmin() && !credentialCanAccessDepartment(credentialDepartmentForEmployee($employeeId))) {
        throw new DomainException('You do not have credential access for this employee department.');
    }
    return $employee;
}

function requireRecentCredentialReauth(?string $password): void
{
    $last = (int)($_SESSION['credential_reauthenticated_at'] ?? 0);
    if ($last > 0 && time() - $last <= 300) return;
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=? AND active=1 LIMIT 1');
    $stmt->execute([(int)currentUser()['id']]);
    $hash = $stmt->fetchColumn();
    if (!is_string($password) || !is_string($hash) || !password_verify($password, $hash)) {
        http_response_code(428);
        exit('Recent sign-in required.');
    }
    $_SESSION['credential_reauthenticated_at'] = time();
}

function auditCredentialChange(string $action, int $id, string $label): void
{
    audit($action, 'credential', $id, $label);
}
