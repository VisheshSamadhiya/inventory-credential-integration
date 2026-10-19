<?php
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/setup.php';
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
putenv('CREDENTIAL_ENCRYPTION_KEY=' . base64_encode($key));
require_once __DIR__ . '/../app/credential_helpers.php';
require_once __DIR__ . '/../app/credential_totp_helpers.php';

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
check(credentialTotpCode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1) === '287082', 'Credential TOTP must match the RFC 6238 SHA-1 test vector.');
$qrSvg = credentialTotpQrSvg(credentialTotpProvisioningUri(credentialTotpSecret(), 'credential-user'));
$qrDocument = new DOMDocument();
check($qrDocument->loadXML($qrSvg) && $qrDocument->documentElement?->localName === 'svg', 'Credential TOTP QR output must be a valid server-generated SVG.');
$totpHelpers = file_get_contents(__DIR__ . '/../app/credential_totp_helpers.php');
$totpRoute = file_get_contents(__DIR__ . '/../app/credential-totp-setup.php');
$authSource = file_get_contents(__DIR__ . '/../app/auth.php');
$usersSource = file_get_contents(__DIR__ . '/../app/users.php');
$createUserRoute = file_get_contents(__DIR__ . '/../app/create-user.php');
$styleSheet = file_get_contents(__DIR__ . '/../app/style.css');
check(is_string($totpHelpers) && str_contains($totpHelpers, 'last_accepted_step < ?') && str_contains($totpHelpers, 'CREDENTIAL_TOTP_MAX_FAILURES'), 'Credential TOTP must reject replayed codes and rate-limit failures.');
check(is_string($totpRoute) && str_contains($totpRoute, 'verifyCsrf();') && str_contains($totpRoute, 'Cache-Control: no-store') && str_contains($totpRoute, 'credentialTotpEnsurePendingEnrollment(') && str_contains($totpRoute, 'credentialTotpQrSvg(') && str_contains($totpRoute, 'credentialTotpRedirectAfterVerification()'), 'Credential TOTP setup must use CSRF, no-store responses, local QR generation, and safe return navigation.');
check(is_string($totpRoute) && strpos($totpRoute, 'if (!canAdmin())') < strpos($totpRoute, 'credentialTotpEnsurePendingEnrollment(') && str_contains($totpRoute, "requirePermission('users', 'edit')") && str_contains($totpRoute, 'credentialTotpVerifyAttempt($targetUserId, (string)($_POST[\'code\'] ?? \'\'), true, false)'), 'Only authorized Admins may configure and verify another user enrollment, without receiving that user’s verified session.');
check(is_string($totpRoute) && !str_contains($totpRoute, 'credentialTotpEnsurePendingEnrollment($userId)') && str_contains($totpRoute, 'An Admin must finish authenticator setup'), 'Regular users must have verification-only access and cannot self-enroll.');
check(is_string($authSource) && str_contains($authSource, "\$resource === 'credentials' || \$resource === 'credential_audit'") && str_contains($authSource, 'requireCredentialTwoFactor()') && !str_contains($authSource, "\$resource === 'inventory'"), 'Credential routes must be gated centrally without gating Inventory.');
check(is_string($usersSource)
    && strpos($usersSource, 'requireLogin();') < strpos($usersSource, "array_key_exists('edit_user', \$_GET) && !canAdmin()")
    && str_contains($usersSource, "requirePermission('users', 'view');"), 'Users and Edit User must validate the current session before applying the separate admin-only edit gate.');
check(is_string($authSource) && str_contains($authSource, "in_array(\$resource, ['credentials', 'credential_audit'], true)") && str_contains($authSource, "empty(\$matrix['credentials']['view'])"), 'Credential operations and audit access must require the module view grant.');
check(is_string($usersSource) && str_contains($usersSource, 'credential_totp_required') && str_contains($usersSource, 'reset_credential_totp') && str_contains($usersSource, 'credential_totp_enrollment_reset'), 'Admin user management must configure and audit credential TOTP resets.');
check(is_string($usersSource) && str_contains($usersSource, 'Credential Access &amp; Security') && str_contains($usersSource, 'renderCredentialPermissionControls') && str_contains($usersSource, '$generalCatalog'), 'Create and Edit User must render a dedicated credential access panel outside the general matrix.');
check(is_string($usersSource) && strpos($usersSource, 'id="access-mode"') < strpos($usersSource, 'id="user-permission-matrix"') && str_contains($usersSource, 'Select Custom Access above to change individual permissions.') && str_contains($usersSource, 'credential-access-mode-help'), 'The authoritative access mode must be visible beside credential controls and explain their read-only state.');
check(is_string($usersSource) && str_contains($usersSource, '$selectedHasTotpEnrollment') && str_contains($usersSource, 'if ($selectedHasTotpEnrollment)') && str_contains($usersSource, 'There is no authenticator enrollment to reset.'), 'Admin reset must be offered and accepted only when a persisted enrollment exists.');
check(is_string($usersSource) && str_contains($usersSource, 'Only an Admin can configure this authenticator.') && str_contains($usersSource, "name=\"credential_totp_required\" value=\"1\"") && str_contains($usersSource, "credentialTotpSetRequired((int)\$targetUserId, \$newTotpRequired"), 'Credential TOTP enforcement must remain independently editable and persisted with user changes.');
check(is_string($usersSource) && str_contains($usersSource, "\$credentialAccessMode === 'custom'") && str_contains($usersSource, "\$savePermission->execute") && str_contains($usersSource, "audit('user_permissions_updated'"), 'Custom credential permissions on user creation must persist and be audited.');
$accountDetailsPosition = strpos($usersSource, 'class="user-account-details"');
$credentialPanelPosition = strpos($usersSource, 'class="credential-totp-panel credential-access-panel"');
$passwordResetPosition = strpos($usersSource, 'class="user-password-reset"');
$generalPermissionsPosition = strpos($usersSource, 'class="user-permissions-settings"');
check($accountDetailsPosition !== false && $credentialPanelPosition !== false && $passwordResetPosition !== false && $generalPermissionsPosition !== false
    && $accountDetailsPosition < $credentialPanelPosition && $credentialPanelPosition < $passwordResetPosition && $passwordResetPosition < $generalPermissionsPosition, 'Edit User sections must follow account, credential security, password reset, and general permissions order.');
check(is_string($styleSheet) && str_contains($styleSheet, '.users-accounts-table { width: 100%; min-width: 1195px; table-layout: fixed; }') && str_contains($styleSheet, '.users-list-card .users-accounts-scroll { max-width: 100%; overflow-x: auto;') && str_contains($styleSheet, '.users-account-action-group { display: inline-flex;') && str_contains($styleSheet, '.users-account-actions .btn-outline-danger { border-color: var(--danger);') && str_contains($styleSheet, '.topbar {'."\n".'  position: relative;') && str_contains($styleSheet, '.permission-table-scroll { max-height:') && str_contains($styleSheet, 'overflow-x: auto; overflow-y: auto;'), 'User pages must keep account actions aligned and the responsive table horizontally readable.');
check(is_string($usersSource) && str_contains($usersSource, '<th scope="col">Name</th><th scope="col">Username</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Credential 2FA</th><th scope="col">Actions</th>') && str_contains($usersSource, '</td><td class="users-account-actions"><div class="users-account-action-group">') && str_contains($usersSource, 'href="users.php?edit_user=<?=(int)$user[\'id\']?>"'), 'Login Accounts header and row must use the required seven-column order, with each Edit action last and linked to its actual user ID.');
check(is_string($usersSource) && str_contains($usersSource, "'deactivate_user', 'reactivate_user'") && str_contains($usersSource, 'verifyCsrf();') && str_contains($usersSource, 'assertActiveAdminRetained($activeAdmins') && str_contains($usersSource, "(int)\$targetUserId === (int)currentUser()['id']") && str_contains($usersSource, 'UPDATE users SET active=0, auth_version=auth_version+1') && str_contains($usersSource, 'UPDATE users SET active=1, auth_version=auth_version+1') && str_contains($usersSource, 'audit($auditAction'), 'User status changes must use Admin-only CSRF-protected soft deactivation/reactivation and invalidate sessions.');
check(is_string($usersSource) && str_contains($usersSource, 'window.confirm(') && str_contains($usersSource, "'Delete (deactivate) ' . \$user['full_name'] . ' (' . \$user['username'] . ')?") && str_contains($usersSource, 'name="action" value="deactivate_user"') && str_contains($usersSource, 'name="action" value="reactivate_user"'), 'Login Accounts must confirm the selected name and username and submit status changes through POST forms.');
check(is_string($usersSource) && is_string($createUserRoute) && str_contains($createUserRoute, "\$usersPageMode = 'create';") && str_contains($createUserRoute, "require __DIR__ . '/users.php';") && str_contains($usersSource, "if (\$createPageRequested) requirePermission('users', 'add');"), 'The dedicated Create User route must reuse the existing controller and enforce create permission.');
$createTemplatePosition = strpos($usersSource, '<?php elseif ($createPageRequested): ?>');
$listTemplatePosition = strpos($usersSource, '<?php else: ?>' . "\n" . '<div class="page-head users-list-page-head">', $createTemplatePosition ?: 0);
$createTemplate = $createTemplatePosition === false || $listTemplatePosition === false ? '' : substr($usersSource, $createTemplatePosition, $listTemplatePosition - $createTemplatePosition);
$listTemplate = $listTemplatePosition === false ? '' : substr($usersSource, $listTemplatePosition);
check($createTemplate !== '' && str_contains($createTemplate, 'Require TOTP 2FA for Credentials') && !str_contains($createTemplate, 'Set Up Authenticator') && !str_contains($createTemplate, 'Verify Enrollment') && !str_contains($createTemplate, 'Reset 2FA Enrollment') && !str_contains($createTemplate, 'users-list-card'), 'Create User must show the security panel without the account list or premature authenticator controls.');
check(str_contains($createTemplate, 'Back to Users') && str_contains($createTemplate, 'name="full_name"') && str_contains($createTemplate, 'name="username"') && str_contains($createTemplate, 'name="email"') && str_contains($createTemplate, 'name="password"') && str_contains($createTemplate, 'name="role"') && str_contains($createTemplate, 'Account Status') && str_contains($createTemplate, 'href="users.php">Cancel</a>'), 'The Create User route must provide account fields, status, and a working cancel link.');
check(str_contains($listTemplate, '<h1>Users &amp; Permissions</h1>') && str_contains($listTemplate, 'href="create-user.php">+ Create User</a>') && str_contains($listTemplate, 'users-list-card') && !str_contains($listTemplate, 'class="user-create-form"'), 'The Users page must render a full-width list with a separate Create User action.');
check(str_contains($usersSource, "header('Location: users.php?edit_user=' . \$createdUserId);") && str_contains($usersSource, "header('Location: create-user.php');"), 'User creation must return to the correct created user and validation failures must return to Create User.');
check(is_string($usersSource) && str_contains($usersSource, 'newly_created_user_id') && str_contains($usersSource, 'Edit User / Configure Authenticator'), 'Newly created accounts must receive an explicit Edit User / Configure Authenticator follow-up action.');
check(credentialTotpSafeReturnTarget('/inventory/credentials.php?view=27') === '/inventory/credentials.php?view=27'
    && credentialTotpSafeReturnTarget('https://attacker.example/credentials.php') === null
    && credentialTotpSafeReturnTarget('/credentials.php?secret=value') === null
    && credentialTotpSafeReturnTarget('/other.php') === null, 'TOTP return targets must remain local, credential-authorized pages without secret-bearing query fields.');

$required = ['view','create','edit','reveal','delete','audit','manage_access'];
$catalog = permissionCatalog();
check(($catalog['credentials']['permissions'] ?? []) === $required, 'Credential RBAC capability catalog is incomplete.');
$defaultMatrix = buildEffectivePermissionMatrix($catalog, [], null, []);
foreach ($required as $capability) check(empty($defaultMatrix['credentials'][$capability]), 'Credential capability was granted by default.');
$adminMatrix = buildEffectivePermissionMatrix($catalog, [], null, [], true);
foreach ($required as $capability) check(!empty($adminMatrix['credentials'][$capability]), 'Admin capability is missing.');
$editorViewMatrix = buildEffectivePermissionMatrix($catalog, ['credentials' => ['view' => true]], null, []);
$viewerViewMatrix = buildEffectivePermissionMatrix($catalog, ['credentials' => ['view' => true]], null, []);
check(!empty($editorViewMatrix['credentials']['view']) && !empty($viewerViewMatrix['credentials']['view']), 'Editors and viewers with an effective credential view grant must be eligible for the Credentials navigation entry.');
check(empty($editorViewMatrix['credentials']['reveal']) && empty($editorViewMatrix['credentials']['manage_access']), 'Credential view permission must not grant reveal or access-management capabilities.');
$createEditOnlyMatrix = buildEffectivePermissionMatrix($catalog, ['credentials' => ['create' => true, 'edit' => true]], null, []);
check(empty($createEditOnlyMatrix['credentials']['view']), 'Create/edit permission without view permission must not make the Credentials navigation entry visible.');
$disabledCredentialMatrix = buildEffectivePermissionMatrix($catalog, ['credentials' => ['view' => false, 'reveal' => true], 'credential_audit' => ['view' => true, 'export' => true]], 'custom', []);
check(empty($disabledCredentialMatrix['credentials']['view']) && !empty($disabledCredentialMatrix['credentials']['reveal']) && !empty($disabledCredentialMatrix['credential_audit']['view']), 'The permission fixture must include inconsistent custom grants for the server-side view prerequisite to reject.');

$_SESSION['csrf'] = 'credential-test-token';
check(csrfTokenMatches('credential-test-token'), 'The current session CSRF token should match.');
check(!csrfTokenMatches('wrong-token') && !csrfTokenMatches(null) && !csrfTokenMatches(['credential-test-token']), 'Invalid, missing, and non-string CSRF values must be rejected safely.');
$recoveredValues = credentialRecoveryValues(['action' => 'create', 'service_name' => 'Mail', 'employee_id' => '12', 'notes' => 'Context', 'credential_password' => 'never-store-this', 'csrf' => 'old-token'], 'create');
check(($recoveredValues['service_name'] ?? '') === 'Mail' && ($recoveredValues['employee_id'] ?? '') === '12' && ($recoveredValues['notes'] ?? '') === 'Context', 'Credential recovery should retain safe submitted fields.');
check(!isset($recoveredValues['credential_password'], $recoveredValues['csrf']), 'Credential recovery must not retain passwords or stale CSRF values.');

$credentialsPage = file_get_contents(__DIR__ . '/../app/credentials.php');
$credentialAccessPage = file_get_contents(__DIR__ . '/../app/credential-access.php');
$headerPage = file_get_contents(__DIR__ . '/../app/header.php');
$styleSheet = file_get_contents(__DIR__ . '/../app/style.css');
$credentialUi = file_get_contents(__DIR__ . '/../app/credential-ui.js');
$appConfig = file_get_contents(__DIR__ . '/../app/config.php');
$loginPage = file_get_contents(__DIR__ . '/../app/login.php');
$revealEndpoint = file_get_contents(__DIR__ . '/../app/credential-reveal.php');
check(is_string($credentialsPage) && str_contains($credentialsPage, 'credential-edit-department-display') && str_contains($credentialsPage, 'credential-department-display'), 'Add/Edit forms must display the employee department.');
check(is_string($credentialsPage) && str_contains($credentialsPage, 'readonly') && !str_contains($credentialsPage, 'name="department_id"'), 'Department displays must be read-only and not submit a department selection.');
check(is_string($credentialsPage) && !str_contains($credentialsPage, "\$_POST['department_id']") && str_contains($credentialsPage, 'credentialDepartmentForEmployee((int)$employeeId)'), 'Credential saves must derive department from the employee record.');
check(is_string($credentialUi) && str_contains($credentialsPage, 'data-department-name') && str_contains($credentialUi, 'No department assigned') && str_contains($credentialUi, "addEventListener('change', updateDepartment)"), 'Changing the employee must immediately update the read-only department display.');
check(is_string($credentialsPage) && str_contains($credentialsPage, 'CASE WHEN c.employee_id IS NOT NULL THEN NULLIF(TRIM(e.department),'), 'Credential listings must display the employee current department.');
$credentialHelpers = file_get_contents(__DIR__ . '/../app/credential_helpers.php');
check(is_string($credentialHelpers) && str_contains($credentialHelpers, 'CASE WHEN c.employee_id IS NOT NULL THEN NULLIF(TRIM(e.department),'), 'Credential detail views must display the employee current department.');
$dockerfile = file_get_contents(__DIR__ . '/../Dockerfile');
check(is_string($dockerfile) && str_contains($dockerfile, 'libonig-dev') && preg_match('/docker-php-ext-install[^\n]*mbstring/', $dockerfile) === 1, 'The PHP image must install mbstring for credential text validation.');
$logMessage = credentialSafeFailureLogMessage(new RuntimeException('Failure contains password=the-secret and username=private-user'), ['credential_password' => 'the-secret', 'credential_username' => 'private-user']);
check(str_contains($logMessage, 'RuntimeException: Failure') && str_contains($logMessage, __FILE__ . ':'), 'Credential failure logs must include exception details and source location.');
check(!str_contains($logMessage, 'the-secret') && !str_contains($logMessage, 'private-user'), 'Credential failure logs must redact submitted sensitive values.');
$nestedLogMessage = credentialSafeFailureLogMessage(new RuntimeException('Nested secret: nested-password'), ['new_credentials' => [['credential_password' => 'nested-password']]]);
check(!str_contains($nestedLogMessage, 'nested-password'), 'Credential failure logs must redact nested submitted values.');
$credentialsPage = file_get_contents(__DIR__ . '/../app/credentials.php');
check(is_string($credentialsPage) && str_contains($credentialsPage, "unset(\$_SESSION['flash']);") && str_contains($credentialsPage, 'credentialSafeFailureLogMessage($exception, $_POST)'), 'Credential failure handling must clear stale success flashes and log sanitized diagnostic details.');
$credentialAccessMigration = file_get_contents(__DIR__ . '/../database/migrations/20261014_add_credentials_module.sql');
$credentialSchema = file_get_contents(__DIR__ . '/../database/schema.sql');
check(is_string($credentialAccessPage) && !str_contains($credentialAccessPage, "redirect('credential-access.php')") && str_contains($credentialAccessPage, "header('Location: credential-access.php')") && str_contains($credentialAccessPage, 'exit;'), 'Department access uses the project redirect pattern and cannot throw after saving.');
check(is_string($credentialAccessPage) && strpos($credentialAccessPage, '$db->commit();') < strpos($credentialAccessPage, 'setFlash($changed ? \'success\''), 'Department access success is reported only after its transaction commits.');
check(is_string($credentialAccessPage) && str_contains($credentialAccessPage, '$db->beginTransaction();') && str_contains($credentialAccessPage, '$db->rollBack();') && str_contains($credentialAccessPage, 'credentialSafeFailureLogMessage($exception'), 'Department access and its audit event roll back together and log sanitized diagnostic details.');
check(is_string($credentialAccessPage) && str_contains($credentialAccessPage, 'INSERT IGNORE INTO credential_department_access') && str_contains($credentialAccessPage, 'SELECT 1 FROM credential_department_access WHERE user_id=? AND department_id=?') && str_contains($credentialAccessPage, '$insert->rowCount() > 0'), 'Duplicate access grants are verified and do not create duplicate audit events.');
check(is_string($credentialAccessPage) && str_contains($credentialAccessPage, 'cd.active department_active') && str_contains($credentialAccessPage, 'u.active user_active') && str_contains($credentialAccessPage, 'FOR UPDATE'), 'Only active users and departments can receive grants, with their state locked during the update.');
check(is_string($credentialAccessPage) && str_contains($credentialAccessPage, 'DELETE FROM credential_department_access WHERE user_id=? AND department_id=?') && str_contains($credentialAccessPage, '$delete->rowCount() > 0') && str_contains($credentialAccessPage, 'ORDER BY u.full_name,cd.name'), 'Revocation and access listing use the expected grant table.');
check(is_string($credentialAccessPage) && str_contains($credentialAccessPage, "requirePermission('credentials', 'manage_access')") && str_contains($credentialAccessPage, 'verifyCsrf();'), 'Department access retains admin RBAC and CSRF enforcement.');
check(is_string($headerPage) && str_contains($headerPage, 'if(canViewResource(\'credentials\'))') && !str_contains($headerPage, "canPerform('credentials','create')") && !str_contains($headerPage, "canPerform('credentials','edit')"), 'Credentials navigation is gated only by effective view permission.');
check(is_string($styleSheet) && str_contains($styleSheet, '@media (max-width: 850px)') && str_contains($styleSheet, '.search-toggle { display: inline-grid;') && str_contains($styleSheet, '.topbar.search-open .global-search { display: flex; }'), 'Responsive navigation switches to a compact menu and expandable search at tablet widths.');
check(is_string($styleSheet) && str_contains($styleSheet, 'width: min(640px, calc(100vw - 32px))') && str_contains($styleSheet, 'max-height: calc(100dvh - 32px)') && str_contains($styleSheet, '.remove-hardware-actions .btn { min-width: 136px;'), 'Hardware action dialogs have responsive width, bounded height, and aligned non-wrapping actions.');
check(is_string($styleSheet) && str_contains($styleSheet, 'html.inventory-layout-route .table-scroll th') && str_contains($styleSheet, 'scroll-margin-top: 16px') && str_contains($styleSheet, '.history-disclosure .table-scroll { max-width: 100%;'), 'Inventory headings, employee tab scroll offsets, and history table overflow are addressed.');
check(is_string($credentialsPage) && str_contains($credentialsPage, "requirePermission('credentials', 'view')"), 'Direct credential page access remains protected by server-side view authorization.');
check(is_string($credentialHelpers) && str_contains($credentialHelpers, 'credentialScopeSql') && str_contains($credentialHelpers, 'credentialCanAccessDepartment'), 'Department filtering remains enforced independently of navigation visibility.');
check(is_string($credentialAccessMigration) && is_string($credentialSchema)
    && str_contains($credentialAccessMigration, 'PRIMARY KEY (user_id, department_id)')
    && str_contains($credentialSchema, 'PRIMARY KEY (user_id, department_id)')
    && str_contains($credentialAccessMigration, 'granted_by BIGINT UNSIGNED NULL')
    && str_contains($credentialSchema, 'granted_by BIGINT UNSIGNED NULL'), 'The credentials migration and fresh-install schema define the access columns and duplicate-preventing key.');
$createHandlerStart = is_string($credentialsPage) ? strpos($credentialsPage, 'if ($action === \'create\') {') : false;
$createHandlerEnd = $createHandlerStart !== false ? strpos($credentialsPage, 'if (!$id) throw new DomainException', $createHandlerStart) : false;
$createHandler = $createHandlerStart !== false && $createHandlerEnd !== false ? substr($credentialsPage, $createHandlerStart, $createHandlerEnd - $createHandlerStart) : '';
check(str_contains($createHandler, '$db->beginTransaction();') && str_contains($createHandler, '$db->commit();'), 'Credential creation and its audit/access events must commit atomically.');
check(str_contains($createHandler, 'software_installation_id,service_name') && str_contains($createHandler, '$softwareId ?: null'), 'Credential creation must preserve the optional installed software association.');
$createStart = is_string($credentialsPage) ? strpos($credentialsPage, 'id="credential-create"') : false;
$editStart = is_string($credentialsPage) ? strpos($credentialsPage, 'id="credential-edit"') : false;
$createForm = $createStart !== false ? substr($credentialsPage, $createStart, (strpos($credentialsPage, '</dialog>', $createStart) ?: $createStart) - $createStart) : '';
$editForm = $editStart !== false ? substr($credentialsPage, $editStart, (strpos($credentialsPage, '</dialog>', $editStart) ?: $editStart) - $editStart) : '';
check(str_contains($createForm, 'name="csrf"') && str_contains($editForm, 'name="csrf"'), 'Add and Edit Credential forms must include the session CSRF token.');
check(is_string($credentialsPage) && str_contains($credentialsPage, 'csrfTokenMatches($_POST[\'csrf\'] ?? null)') && str_contains($credentialsPage, 'credentialRecoveryValues($_POST, $action)'), 'Credential POST requests must validate CSRF and preserve safe form values on failure.');
check(is_string($credentialsPage) && str_contains($credentialsPage, 'session expired or this form token is invalid') && str_contains($credentialsPage, 're-enter the password'), 'Credential CSRF failures must explain recovery and password re-entry.');
check(is_string($revealEndpoint) && strpos($revealEndpoint, "requirePermission('credentials', 'reveal')") < strpos($revealEndpoint, 'decryptCredentialValue('), 'The reveal endpoint must authorize users server-side before decryption.');
check(is_string($revealEndpoint) && strpos($revealEndpoint, "header('Cache-Control: no-store") < strpos($revealEndpoint, 'requirePermission(') && str_contains($revealEndpoint, 'hash_equals(csrf()'), 'Reveal responses must be no-store and retain CSRF validation.');
check(is_string($revealEndpoint) && str_contains($revealEndpoint, 'credentialAccessLog((int)$id, \'revealed\')') && str_contains($revealEndpoint, "auditCredentialChange('credential_password_revealed'"), 'Successful reveals must retain access and audit logging.');
check(is_string($credentialUi) && str_contains($credentialUi, 'input[name="csrf"]') && !str_contains($credentialUi, 'departmentDisplay.innerHTML'), 'Employee department updates must leave CSRF inputs intact.');
check(is_string($appConfig) && str_contains($appConfig, 'session_status() !== PHP_SESSION_ACTIVE') && str_contains($appConfig, 'session.cookie_secure') && str_contains($appConfig, "'secure' => \$sessionSecureCookie"), 'Session startup and production-only Secure cookie behavior must remain configured.');
check(is_string($appConfig) && str_contains($appConfig, "\$_SESSION = ['session_expired' => true]") && is_string($loginPage) && str_contains($loginPage, 'credential_form_recovery'), 'Expired credential sessions must require sign-in and then restore safe form fields.');

echo "Credential integration checks passed: encryption compatibility, TOTP setup safeguards, RBAC, CSRF, and department-access controls.\n";
