<?php
declare(strict_types=1);
require_once __DIR__ . '/credential_helpers.php';
header('Cache-Control: no-store, private, max-age=0, must-revalidate');
header('Pragma: no-cache');
requireLogin();
requirePermission('credentials', 'reveal');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit(json_encode(['error' => 'Method not allowed.'])); }
if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { header('HTTP/1.1 419 Page Expired', true, 419); exit(json_encode(['error' => 'Invalid request.'])); }
$id = filter_var($_POST['credential_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit(json_encode(['error' => 'Credential not found.'])); }
requireRecentCredentialReauth(isset($_POST['reauth_password']) ? (string)$_POST['reauth_password'] : null);
$record = requireCredentialRecord((int)$id);
try {
    $password = decryptCredentialValue((string)$record['credential_password_enc']);
} catch (Throwable) {
    error_log('Credential reveal failed: stored value could not be decrypted.');
    http_response_code(500);
    exit(json_encode(['error' => 'Credential could not be decrypted.']));
}
credentialAccessLog((int)$id, 'revealed');
auditCredentialChange('credential_password_revealed', (int)$id, (string)$record['service_name']);
echo json_encode(['password' => $password], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
