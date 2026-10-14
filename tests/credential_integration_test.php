<?php
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/setup.php';
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
putenv('CREDENTIAL_ENCRYPTION_KEY=' . base64_encode($key));
require_once __DIR__ . '/../app/credential_helpers.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$fixture = 'credential integration crypto fixture';
$sodiumCipher = encryptCredentialValue($fixture);
check(decryptCredentialValue($sodiumCipher) === $fixture, 'Sodium secretbox round trip failed.');

$iv = random_bytes(12);
$tag = '';
$ciphertext = openssl_encrypt($fixture, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '');
$legacyAes = base64_encode(json_encode([
    'v' => 1,
    'iv' => base64_encode($iv),
    'tag' => base64_encode($tag),
    'data' => base64_encode($ciphertext),
], JSON_THROW_ON_ERROR));
check(decryptCredentialValue($legacyAes) === $fixture, 'Existing AES-GCM compatibility failed.');

$required = ['view','create','edit','reveal','delete','audit','manage_access'];
$catalog = permissionCatalog();
check(($catalog['credentials']['permissions'] ?? []) === $required, 'Credential RBAC capability catalog is incomplete.');
$defaultMatrix = buildEffectivePermissionMatrix($catalog, [], null, []);
foreach ($required as $capability) check(empty($defaultMatrix['credentials'][$capability]), 'Credential capability was granted by default.');
$adminMatrix = buildEffectivePermissionMatrix($catalog, [], null, [], true);
foreach ($required as $capability) check(!empty($adminMatrix['credentials'][$capability]), 'Admin capability is missing.');

echo "Credential integration checks passed: Sodium/AES compatibility and isolated RBAC defaults.\n";
