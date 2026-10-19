<?php
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/credential-totp-setup.php';
require_once '/var/www/html/credential_totp_helpers.php';

function credentialTotpCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

credentialTotpCheck(credentialTotpCode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1) === '287082', 'RFC 6238 SHA-1 test vector failed.');
$svg = credentialTotpQrSvg(credentialTotpProvisioningUri(credentialTotpSecret(), 'totp-test'));
$document = new DOMDocument();
credentialTotpCheck($document->loadXML($svg) && $document->documentElement?->localName === 'svg', 'Server QR output is not valid SVG.');

$db = db();
$username = 'totp_integration_' . bin2hex(random_bytes(6));
$db->prepare('INSERT INTO users(full_name,username,password_hash,role) VALUES(?,?,?,\'viewer\')')
    ->execute(['Credential TOTP integration', $username, password_hash('not-used-by-test', PASSWORD_DEFAULT)]);
$userId = (int)$db->lastInsertId();
$_SESSION['user'] = ['id' => $userId, 'username' => $username, 'role' => 'viewer'];
$permissionProbe = static function (string $resource, string $operation, ?array $credentialVerification = null) use ($userId, $username): string {
    $sessionUser = var_export(['id'=>$userId,'username'=>$username,'full_name'=>'Credential TOTP integration','role'=>'viewer','auth_version'=>0], true);
    $verification = var_export($credentialVerification, true);
    $source = '$_SERVER["SCRIPT_NAME"]="/credentials.php";require_once "/var/www/html/auth.php";'
        . '$_SESSION["user"]=' . $sessionUser . ';$_SESSION["credential_totp_verified"]=' . $verification . ';'
        . 'register_shutdown_function(static function(){echo "\nSTATUS=".(http_response_code()?:200);});'
        . 'requirePermission(' . var_export($resource, true) . ',' . var_export($operation, true) . ');echo "ALLOWED";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run route authorization probe.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Route authorization probe failed: ' . $stderr);
    return (string)$stdout;
};
$setupRouteProbe = static function () use ($userId, $username): string {
    $sessionUser = var_export(['id'=>$userId,'username'=>$username,'full_name'=>'Credential TOTP integration','role'=>'viewer','auth_version'=>0], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/credential-totp-setup.php";$_GET["user_id"]=' . $userId . ';'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'require "/var/www/html/credential-totp-setup.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run Admin setup authorization probe.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin setup authorization probe failed: ' . $stderr);
    return (string)$stdout;
};
$unauthorizedUserActionProbe = static function (string $action) use ($userId, $username): string {
    $sessionUser = var_export(['id'=>$userId,'username'=>$username,'full_name'=>'Credential TOTP integration','role'=>'viewer','auth_version'=>0], true);
    $post = var_export(['action'=>$action,'target_user_id'=>$userId,'credential_totp_required'=>'1','confirm_reset'=>'1'], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/users.php";$_SERVER["REQUEST_METHOD"]="POST";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';$_POST=' . $post . ';$_POST["csrf"]=csrf();'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'require "/var/www/html/users.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run non-Admin user-management authorization probe.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Non-Admin user-management probe failed: ' . $stderr);
    return (string)$stdout;
};
$adminRow = $db->query("SELECT id,full_name,username,auth_version FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetch();
if (!is_array($adminRow)) throw new RuntimeException('The database-backed Admin enrollment test requires an active Admin account.');
$adminSessionUser = [
    'id' => (int)$adminRow['id'], 'full_name' => (string)$adminRow['full_name'],
    'username' => (string)$adminRow['username'], 'role' => 'admin', 'auth_version' => (int)$adminRow['auth_version'],
];
$routeCreatedUsername = 'totp_admin_create_' . bin2hex(random_bytes(6));
$routeCreatedUserId = 0;
$customRouteCreatedUserId = 0;
$adminCreateUserRoute = static function (string $username, string $accessMode = 'role', array $permissions = [], string $totpRequired = '1') use ($adminSessionUser): string {
    $sessionUser = var_export($adminSessionUser, true);
    $post = var_export([
        'action'=>'create_user', 'full_name'=>'Admin TOTP Route Test', 'username'=>$username,
        'email'=>'', 'password'=>'Temporary-Test-Password-2026', 'role'=>'viewer',
        'credential_access_mode'=>$accessMode, 'credential_totp_required'=>$totpRequired,
        'user_permissions'=>$permissions,
    ], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/users.php";$_SERVER["REQUEST_METHOD"]="POST";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';$_POST=' . $post . ';$_POST["csrf"]=csrf();'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'require "/var/www/html/users.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run Admin Create User route.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin Create User route failed: ' . $stderr);
    return (string)$stdout;
};
$adminResetUserRoute = static function (int $targetId) use ($adminSessionUser): string {
    $sessionUser = var_export($adminSessionUser, true);
    $post = var_export(['action'=>'reset_credential_totp','target_user_id'=>$targetId,'confirm_reset'=>'1'], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/users.php";$_SERVER["REQUEST_METHOD"]="POST";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';$_POST=' . $post . ';$_POST["csrf"]=csrf();'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'require "/var/www/html/users.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run Admin reset route.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin reset route failed: ' . $stderr);
    return (string)$stdout;
};
$adminDisableUserRoute = static function (int $targetId) use ($adminSessionUser): string {
    $sessionUser = var_export($adminSessionUser, true);
    $post = var_export(['action'=>'disable_credential_totp','target_user_id'=>$targetId,'confirm_disable'=>'1'], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/users.php";$_SERVER["REQUEST_METHOD"]="POST";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';$_POST=' . $post . ';$_POST["csrf"]=csrf();'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'require "/var/www/html/users.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run Admin disable route.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin disable route failed: ' . $stderr);
    return (string)$stdout;
};
$adminEditPolicyRoute = static function (int $targetId, string $username, string $fullName) use ($adminSessionUser): string {
    $sessionUser = var_export($adminSessionUser, true);
    $post = var_export([
        'action'=>'edit_user', 'target_user_id'=>$targetId, 'full_name'=>$fullName,
        'username'=>$username, 'email'=>'', 'role'=>'viewer', 'active'=>'1', 'access_mode'=>'role',
        'credential_totp_required'=>'1', 'new_password'=>'', 'confirm_password'=>'',
    ], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/users.php";$_SERVER["REQUEST_METHOD"]="POST";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';$_POST=' . $post . ';$_POST["csrf"]=csrf();'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'require "/var/www/html/users.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run Admin Edit User policy route.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin Edit User policy route failed: ' . $stderr);
    return (string)$stdout;
};
$adminSelfEditDeactivateRoute = static function () use ($adminSessionUser): string {
    $sessionUser = var_export($adminSessionUser, true);
    $post = var_export([
        'action'=>'edit_user', 'target_user_id'=>(int)$adminSessionUser['id'],
        'full_name'=>(string)$adminSessionUser['full_name'], 'username'=>(string)$adminSessionUser['username'],
        'email'=>'', 'role'=>'admin', 'active'=>'0', 'access_mode'=>'role',
        'credential_totp_required'=>'0', 'new_password'=>'', 'confirm_password'=>'',
    ], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/users.php";$_SERVER["REQUEST_METHOD"]="POST";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';$_POST=' . $post . ';$_POST["csrf"]=csrf();'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'require "/var/www/html/users.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run Admin self Edit User deactivation probe.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin self Edit User deactivation probe failed: ' . $stderr);
    return (string)$stdout;
};
$userEditRouteProbe = static function (int $targetId, bool $asAdmin) use ($adminSessionUser, $userId, $username): string {
    $sessionUser = var_export($asAdmin ? $adminSessionUser : ['id'=>$userId,'username'=>$username,'full_name'=>'Credential TOTP integration','role'=>'viewer','auth_version'=>0], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/users.php";$_SERVER["REQUEST_METHOD"]="GET";$_GET["edit_user"]=' . $targetId . ';'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});ob_start();'
        . 'require "/var/www/html/users.php";$html=ob_get_clean();'
        . 'echo (str_contains($html,"Credential Access &amp; Security") && str_contains($html,"Set Up Authenticator")) ? "EDIT_FORM_OK" : "EDIT_FORM_MISSING";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run Edit User authorization probe.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Edit User authorization probe failed: ' . $stderr);
    return (string)$stdout;
};
$adminUsersListProbe = static function (array $targetIds, string $expectedAction = 'deactivate_user') use ($adminSessionUser): string {
    $sessionUser = var_export($adminSessionUser, true);
    $source = '$_SERVER["SCRIPT_NAME"]="/users.php";$_SERVER["REQUEST_METHOD"]="GET";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';'
        . 'ob_start();require "/var/www/html/users.php";$html=ob_get_clean();'
        . '$ok=str_contains($html,"<th scope=\\"col\\">Name</th><th scope=\\"col\\">Username</th><th scope=\\"col\\">Email</th><th scope=\\"col\\">Role</th><th scope=\\"col\\">Status</th><th scope=\\"col\\">Credential 2FA</th><th scope=\\"col\\">Actions</th>");';
    foreach ($targetIds as $targetId) {
        $editNeedle = var_export('href="users.php?edit_user=' . (int)$targetId . '"', true);
        $actionNeedle = var_export('name="action" value="' . $expectedAction . '"', true);
        $source .= '$needle=' . $editNeedle . ';$pos=strpos($html,$needle);$start=$pos===false?false:strrpos(substr($html,0,$pos),"<tr>");$end=$pos===false?false:strpos($html,"</tr>",$pos);$row=($start===false||$end===false)?"":substr($html,$start,$end-$start);'
            . '$ok=$ok&&$pos!==false&&str_contains($row,' . $actionNeedle . ')&&str_contains($row,"window.confirm(");';
    }
    $source .= 'echo $ok ? "USER_ACTIONS_OK" : "USER_ACTIONS_MISSING";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not render Admin Login Accounts list.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin Login Accounts list failed: ' . $stderr);
    return (string)$stdout;
};
$createUserRouteProbe = static function (bool $asAdmin) use ($adminSessionUser, $userId, $username): string {
    $sessionUser = var_export($asAdmin ? $adminSessionUser : ['id'=>$userId,'username'=>$username,'full_name'=>'Credential TOTP integration','role'=>'viewer','auth_version'=>0], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/create-user.php";$_SERVER["REQUEST_METHOD"]="GET";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});ob_start();'
        . 'require "/var/www/html/create-user.php";$html=ob_get_clean();'
        . '$ok=str_contains($html,"Create User")&&str_contains($html,"Credential Access &amp; Security")&&str_contains($html,"Back to Users")'
        . '&&!str_contains($html,"Login accounts")&&!str_contains($html,"Set Up Authenticator")&&!str_contains($html,"Reset 2FA Enrollment");'
        . 'echo $ok?"CREATE_FORM_OK":"CREATE_FORM_MISSING";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not render dedicated Create User route.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Create User route authorization probe failed: ' . $stderr);
    return (string)$stdout;
};
$adminUserStatusRoute = static function (int $targetId, string $action, bool $confirmed = true) use ($adminSessionUser): string {
    $sessionUser = var_export($adminSessionUser, true);
    $post = var_export(['action'=>$action,'target_user_id'=>$targetId,'confirm_account_status'=>$confirmed ? '1' : '0'], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/users.php";$_SERVER["REQUEST_METHOD"]="POST";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';$_POST=' . $post . ';$_POST["csrf"]=csrf();'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'require "/var/www/html/users.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run Admin account-status route.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin account-status route failed: ' . $stderr);
    return (string)$stdout;
};
$inactiveSessionProbe = static function (int $targetId, string $username, int $authVersion): string {
    $sessionUser = var_export(['id'=>$targetId,'username'=>$username,'full_name'=>'Status test','role'=>'viewer','auth_version'=>$authVersion], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/dashboard.php";require_once "/var/www/html/auth.php";'
        . '$_SESSION["user"]=' . $sessionUser . ';$_SESSION["credential_totp_verified"]=["user_id"=>' . $targetId . ',"generation"=>1,"verified_at"=>time()];'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'requireLogin();echo "SESSION_ALLOWED";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run existing-session invalidation probe.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Existing-session invalidation probe failed: ' . $stderr);
    return (string)$stdout;
};
$inactiveLoginProbe = static function (string $username, string $password): string {
    $source = '$_SERVER["SCRIPT_NAME"]="/login.php";$_SERVER["REQUEST_METHOD"]="POST";require_once "/var/www/html/auth.php";'
        . '$_SESSION["csrf"]="login-test-csrf";$_POST=["username"=>' . var_export($username, true) . ',"password"=>' . var_export($password, true) . ',"csrf"=>"login-test-csrf"];'
        . 'ob_start();require "/var/www/html/login.php";ob_end_clean();echo isset($_SESSION["user"]) ? "LOGIN_ALLOWED" : "LOGIN_DENIED";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run inactive-account login probe.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Inactive-account login probe failed: ' . $stderr);
    return (string)$stdout;
};
$adminSetupAction = static function (string $action, ?string $code = null) use ($adminSessionUser, $userId): string {
    $sessionUser = var_export($adminSessionUser, true);
    $post = var_export(['target_user_id'=>$userId,'action'=>$action,'code'=>$code], true);
    $source = '$_SERVER["SCRIPT_NAME"]="/credential-totp-setup.php";$_SERVER["REQUEST_METHOD"]="POST";'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';$_POST=' . $post . ';$_POST["csrf"]=csrf();'
        . 'register_shutdown_function(static function(){echo "\\nSTATUS=".(http_response_code()?:200);});'
        . 'require "/var/www/html/credential-totp-setup.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run Admin enrollment route.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin enrollment route failed: ' . $stderr);
    return (string)$stdout;
};
$adminSetupGet = static function (?string $expectedSecret = null, bool $qr = false) use ($adminSessionUser, $userId): string {
    $sessionUser = var_export($adminSessionUser, true);
    $query = $qr ? '["user_id"=>' . $userId . ',"qr"=>"1"]' : '["user_id"=>' . $userId . ']';
    $source = '$_SERVER["SCRIPT_NAME"]="/credential-totp-setup.php";$_SERVER["REQUEST_METHOD"]="GET";$_GET=' . $query . ';'
        . 'require_once "/var/www/html/auth.php";$_SESSION["user"]=' . $sessionUser . ';'
        . 'require "/var/www/html/credential-totp-setup.php";';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not render Admin enrollment route.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Admin enrollment page failed: ' . $stderr);
    if ($expectedSecret !== null && !str_contains((string)$stdout, $expectedSecret)) return 'MANUAL_MISSING';
    return (string)$stdout;
};
try {
    $setupProbeResult = $setupRouteProbe();
    credentialTotpCheck(str_contains($setupProbeResult, '403 Forbidden') && str_contains($setupProbeResult, 'STATUS=403'), 'A regular user could directly open Admin TOTP setup for another account.');
    foreach (['edit_user','reset_credential_totp','disable_credential_totp'] as $protectedAction) {
        $unauthorizedResult = $unauthorizedUserActionProbe($protectedAction);
        credentialTotpCheck(str_contains($unauthorizedResult, '403') && str_contains($unauthorizedResult, 'STATUS=403'), 'A regular user could directly post the Admin-only action ' . $protectedAction . '.');
    }
    $createRouteResult = $adminCreateUserRoute($routeCreatedUsername);
    credentialTotpCheck(str_contains($createRouteResult, 'STATUS=302'), 'Admin Create User route did not complete successfully.');
    $createdUserQuery = $db->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
    $createdUserQuery->execute([$routeCreatedUsername]);
    $routeCreatedUserId = (int)$createdUserQuery->fetchColumn();
    credentialTotpCheck($routeCreatedUserId > 0 && (int)credentialTotpState($routeCreatedUserId)['required'] === 1 && credentialTotpState($routeCreatedUserId)['status'] === null, 'Admin-created user did not persist required TOTP without marking enrollment active.');
    $roleDefaultMatrix = effectivePermissionMatrix(['id'=>$routeCreatedUserId,'role'=>'viewer']);
    credentialTotpCheck(userPermissionProfile($routeCreatedUserId) === null && empty($roleDefaultMatrix['credentials']['view']) && !empty($roleDefaultMatrix['inventory']['view']), 'A role-default user did not retain default credential permissions and Inventory access.');
    $customRouteUsername = 'totp_admin_custom_' . bin2hex(random_bytes(6));
    credentialTotpCheck(str_contains($adminCreateUserRoute($customRouteUsername, 'custom', ['credentials'=>['view']], '0'), 'STATUS=302'), 'Admin could not create a user with custom credential permissions.');
    $customRouteUserQuery = $db->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
    $customRouteUserQuery->execute([$customRouteUsername]);
    $customRouteCreatedUserId = (int)$customRouteUserQuery->fetchColumn();
    $customRouteUser = $customRouteCreatedUserId > 0 ? ['id'=>$customRouteCreatedUserId,'role'=>'viewer'] : null;
    $customRouteMatrix = $customRouteUser ? effectivePermissionMatrix($customRouteUser) : [];
    credentialTotpCheck($customRouteCreatedUserId > 0 && userPermissionProfile($customRouteCreatedUserId) === 'custom' && !empty($customRouteMatrix['credentials']['view']) && empty($customRouteMatrix['credentials']['reveal']), 'Custom credential access did not persist the selected view grant without granting reveal.');
    credentialTotpCheck(str_contains($adminUsersListProbe([$routeCreatedUserId, $userId]), 'USER_ACTIONS_OK'), 'Admin Login Accounts did not show the required column order and Edit links for multiple user IDs.');
    credentialTotpCheck(str_contains($createUserRouteProbe(true), 'CREATE_FORM_OK'), 'Admin Create User route did not render separately from the Login Accounts table.');
    $viewerCreateResult = $createUserRouteProbe(false);
    credentialTotpCheck(str_contains($viewerCreateResult, '403 Forbidden') && str_contains($viewerCreateResult, 'STATUS=403'), 'Viewer could directly open the dedicated Create User route.');
    credentialTotpCheck(str_contains($userEditRouteProbe($routeCreatedUserId, true), 'EDIT_FORM_OK'), 'Admin could not open Edit User and see Admin setup controls.');
    $viewerEditResult = $userEditRouteProbe($routeCreatedUserId, false);
    credentialTotpCheck(str_contains($viewerEditResult, 'Forbidden') && str_contains($viewerEditResult, 'STATUS=403'), 'Viewer could directly open Edit User.');
    $disableRouteResult = $adminDisableUserRoute($routeCreatedUserId);
    credentialTotpCheck(str_contains($disableRouteResult, 'STATUS=302'), 'Admin could not disable credential TOTP through Edit User.');
    $disabledCreatedUser = credentialTotpState($routeCreatedUserId);
    credentialTotpCheck(!(int)$disabledCreatedUser['required'] && $disabledCreatedUser['status'] === null && $disabledCreatedUser['secret_enc'] === null && $disabledCreatedUser['pending_secret_enc'] === null, 'Admin disable did not remove the created user’s enrollment.');
    $editPolicyResult = $adminEditPolicyRoute($routeCreatedUserId, $routeCreatedUsername, 'Admin TOTP Route Test');
    credentialTotpCheck(str_contains($editPolicyResult, 'STATUS=302') && (int)credentialTotpState($routeCreatedUserId)['required'] === 1 && credentialTotpState($routeCreatedUserId)['status'] === null, 'Admin could not re-enable TOTP from Edit User without falsely marking enrollment active.');
    credentialTotpCheck(!str_contains($permissionProbe('credentials','view'), 'ALLOWED'), 'Viewer role defaults unexpectedly granted Credentials module access.');
    credentialTotpCheck(str_contains($permissionProbe('inventory','view'), 'ALLOWED'), 'Viewer role defaults did not preserve permitted Inventory access.');
    $db->prepare("INSERT INTO user_permission_profiles(user_id,access_mode) VALUES(?,'full')")->execute([$userId]);
    $secret = credentialTotpSecret();
    credentialTotpSetRequired($userId, true, $userId);
    credentialTotpCheck((int)credentialTotpState($userId)['required'] === 1, 'Create-user TOTP requirement did not persist.');
    $db->beginTransaction();
    credentialTotpSetRequired($userId, false, $userId);
    $db->commit();
    $disabled = credentialTotpState($userId);
    credentialTotpCheck((int)$disabled['required'] === 0 && $disabled['status'] === null && $disabled['secret_enc'] === null && $disabled['pending_secret_enc'] === null, 'Edit-user disable did not clear the requirement and enrollment secret.');
    $db->beginTransaction();
    credentialTotpSetRequired($userId, true, $userId);
    $db->commit();
    $pending = credentialTotpEnsurePendingEnrollment($userId);
    credentialTotpCheck($pending['status'] === 'pending' && is_string($pending['pending_secret_enc']) && $pending['pending_secret_enc'] !== '', 'Required but unconfigured user did not receive an encrypted pending enrollment.');
    $existingPendingSecret = $pending['pending_secret_enc'];
    $pendingAgain = credentialTotpEnsurePendingEnrollment($userId);
    credentialTotpCheck(hash_equals($existingPendingSecret, (string)$pendingAgain['pending_secret_enc']), 'Repeated setup access replaced the pending authenticator secret.');
    $secret = decryptCredentialValue((string)$pending['pending_secret_enc']);
    $state = credentialTotpState($userId);
    credentialTotpCheck((int)$state['required'] === 1 && $state['status'] === 'pending', 'Pending enrollment did not persist.');
    credentialTotpCheck(!str_contains($permissionProbe('credentials','view'), 'ALLOWED'), 'Credential view was accessible before TOTP verification.');
    credentialTotpCheck(str_contains($permissionProbe('inventory','view'), 'ALLOWED'), 'Credential-only TOTP incorrectly blocked permitted Inventory access.');
    credentialTotpCheck(!credentialTotpVerifyAttempt($userId, '000000', true), 'Invalid code was accepted.');
    $step = intdiv(time(), 30);
    $code = credentialTotpCode($secret, $step);
    credentialTotpCheck(credentialTotpVerifyAttempt($userId, $code, true), 'A current enrollment code was rejected.');
    $active = credentialTotpState($userId);
    credentialTotpCheck($active['status'] === 'active' && $active['pending_secret_enc'] === null, 'Successful verification did not activate the encrypted secret.');
    credentialTotpCheck(credentialTotpSessionVerified(), 'A valid verification session was not recognized.');
    $validSession = ['user_id'=>$userId,'generation'=>(int)$active['generation'],'verified_at'=>time()];
    credentialTotpCheck(str_contains($permissionProbe('credentials','view',$validSession), 'ALLOWED'), 'A viewer with credential RBAC and valid TOTP could not access credentials.');
    $db->prepare("UPDATE user_permission_profiles SET access_mode='custom' WHERE user_id=?")->execute([$userId]);
    $db->prepare('INSERT INTO user_permissions(user_id,module,capability,allowed) VALUES(?,?,?,?)')
        ->execute([$userId, 'credentials', 'view', 1]);
    $db->prepare('INSERT INTO user_permissions(user_id,module,capability,allowed) VALUES(?,?,?,?)')
        ->execute([$userId, 'credentials', 'reveal', 0]);
    credentialTotpCheck(str_contains($permissionProbe('credentials','view',$validSession), 'ALLOWED'), 'Custom Credential module access did not enable credential viewing.');
    credentialTotpCheck(!str_contains($permissionProbe('credentials','reveal',$validSession), 'ALLOWED'), 'Viewing credentials incorrectly granted password reveal.');
    $db->prepare('UPDATE user_permissions SET allowed=1 WHERE user_id=? AND module=? AND capability=?')
        ->execute([$userId, 'credentials', 'reveal']);
    credentialTotpCheck(str_contains($permissionProbe('credentials','reveal',$validSession), 'ALLOWED'), 'Explicit Reveal permission was not honored after verification.');
    $db->prepare('UPDATE user_permissions SET allowed=0 WHERE user_id=? AND module=? AND capability=?')
        ->execute([$userId, 'credentials', 'view']);
    $db->prepare('INSERT INTO user_permissions(user_id,module,capability,allowed) VALUES(?,?,?,?)')
        ->execute([$userId, 'credential_audit', 'view', 1]);
    credentialTotpCheck(!str_contains($permissionProbe('credentials','view',$validSession), 'ALLOWED'), 'TOTP bypassed a user with no credential module permission.');
    credentialTotpCheck(!str_contains($permissionProbe('credentials','reveal',$validSession), 'ALLOWED'), 'A stale reveal grant bypassed disabled Credentials module access.');
    credentialTotpCheck(!str_contains($permissionProbe('credential_audit','view',$validSession), 'ALLOWED'), 'Credential audit access bypassed disabled Credentials module access.');
    $db->prepare("UPDATE user_permission_profiles SET access_mode='full' WHERE user_id=?")->execute([$userId]);
    credentialTotpCheck(!credentialTotpVerifyAttempt($userId, $code, false), 'A previously accepted TOTP step was replayed.');
    for ($i = 0; $i < 4; $i++) credentialTotpVerifyAttempt($userId, '999999', false);
    $limited = credentialTotpState($userId);
    credentialTotpCheck($limited['locked_until'] !== null, 'Repeated invalid and replay attempts did not activate rate limiting.');
    $_SESSION['credential_totp_verified'] = ['user_id' => $userId, 'generation' => (int)$active['generation'], 'verified_at' => time()];
    credentialTotpCheck(credentialTotpSessionVerified(), 'A valid credential verification session was not recognized before Admin reset.');
    $resetRouteResult = $adminResetUserRoute($userId);
    credentialTotpCheck(str_contains($resetRouteResult, 'STATUS=302'), 'Authorized Admin reset route did not complete.');
    $reset = credentialTotpState($userId);
    credentialTotpCheck((int)$reset['required'] === 1 && $reset['status'] === 'reset_required' && $reset['secret_enc'] === null && $reset['pending_secret_enc'] === null && (int)$reset['generation'] > (int)$active['generation'], 'Admin reset did not preserve enforcement, clear secrets and invalidate verification state.');
    $auditQuery = $db->prepare("SELECT id,details FROM audit_logs WHERE action='credential_totp_enrollment_reset' AND entity_id=? ORDER BY id DESC LIMIT 1");
    $auditQuery->execute([$userId]);
    $auditRow = $auditQuery->fetch();
    credentialTotpCheck(is_array($auditRow) && !str_contains((string)$auditRow['details'], $secret), 'Reset audit record is missing or contains TOTP secret material.');
    $db->prepare('DELETE FROM audit_logs WHERE id=?')->execute([(int)$auditRow['id']]);
    credentialTotpCheck(!credentialTotpSessionVerified(), 'Admin reset did not invalidate the prior verified session.');
    $startResult = $adminSetupAction('start');
    credentialTotpCheck(str_contains($startResult, 'STATUS=303'), 'Admin did not start enrollment through the CSRF-protected setup route.');
    $reEnrollment = credentialTotpState($userId);
    credentialTotpCheck((int)$reEnrollment['required'] === 1 && $reEnrollment['status'] === 'pending', 'Reset enrollment did not begin a new required setup.');
    $firstReplacementSecret = decryptCredentialValue((string)$reEnrollment['pending_secret_enc']);
    credentialTotpCheck(!str_contains($adminSetupGet($firstReplacementSecret), 'MANUAL_MISSING'), 'Admin setup page did not show the intended account’s manual key.');
    $qrResponse = $adminSetupGet(null, true);
    credentialTotpCheck(str_contains($qrResponse, '<svg') && !str_contains($qrResponse, $firstReplacementSecret), 'Admin QR endpoint did not return a local QR response without plaintext secret text.');
    $db->prepare('UPDATE user_credential_totp SET updated_at=UTC_TIMESTAMP()-INTERVAL 16 MINUTE WHERE user_id=?')->execute([$userId]);
    $expiredPending = credentialTotpState($userId);
    credentialTotpCheck(credentialTotpPendingExpired($expiredPending), 'Pending Admin setup did not expire after its setup window.');
    $expiredSecret = decryptCredentialValue((string)$expiredPending['pending_secret_enc']);
    credentialTotpCheck(!credentialTotpVerifyAttempt($userId, credentialTotpCode($expiredSecret, intdiv(time(), 30)), true), 'An expired enrollment code was accepted.');
    credentialTotpCheck(credentialTotpState($userId)['status'] === 'reset_required', 'Expired enrollment did not require a fresh Admin setup.');
    $freshStartResult = $adminSetupAction('start');
    credentialTotpCheck(str_contains($freshStartResult, 'STATUS=303'), 'Admin could not start a fresh enrollment after expiry.');
    $reEnrollment = credentialTotpState($userId);
    $replacementSecret = decryptCredentialValue((string)$reEnrollment['pending_secret_enc']);
    $invalidCode = '000000';
    while (credentialTotpMatchedStep($replacementSecret, $invalidCode) !== null) $invalidCode = str_pad((string)(((int)$invalidCode + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    $invalidRouteResponse = $adminSetupAction('verify_enrollment', $invalidCode);
    credentialTotpCheck(str_contains($invalidRouteResponse, 'invalid or already used') && credentialTotpState($userId)['status'] === 'pending', 'Admin enrollment route accepted or activated an invalid code.');
    $validRouteResponse = $adminSetupAction('verify_enrollment', credentialTotpCode($replacementSecret, intdiv(time(), 30)));
    credentialTotpCheck(str_contains($validRouteResponse, 'STATUS=303'), 'Admin enrollment route did not complete after a valid current code.');
    credentialTotpCheck(credentialTotpState($userId)['status'] === 'active', 'Re-enrollment did not return the user to Active status.');
    $activationAudit = $db->prepare("SELECT details FROM audit_logs WHERE action='credential_totp_enrollment_activated' AND entity_type='user' AND entity_id=? ORDER BY id DESC LIMIT 1");
    $activationAudit->execute([$userId]);
    $activationDetails = (string)$activationAudit->fetchColumn();
    credentialTotpCheck($activationDetails !== '' && !str_contains($activationDetails, $replacementSecret), 'Admin enrollment activation was not audited safely.');

    $pendingForDeactivation = credentialTotpEnsurePendingEnrollment($routeCreatedUserId);
    $totpBeforeDeactivation = [
        'required' => (int)$pendingForDeactivation['required'], 'status' => $pendingForDeactivation['status'],
        'secret_enc' => $pendingForDeactivation['secret_enc'], 'pending_secret_enc' => $pendingForDeactivation['pending_secret_enc'],
        'generation' => (int)$pendingForDeactivation['generation'],
    ];
    $historyFixture = $db->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)');
    $historyFixture->execute([$routeCreatedUserId, 'user_deactivation_history_fixture', 'user', $routeCreatedUserId, 'Historical actor reference fixture']);
    $historyFixtureId = (int)$db->lastInsertId();
    $cancelledStatus = $adminUserStatusRoute($routeCreatedUserId, 'deactivate_user', false);
    $cancelledAccount = $db->prepare('SELECT active FROM users WHERE id=?');
    $cancelledAccount->execute([$routeCreatedUserId]);
    credentialTotpCheck(str_contains($cancelledStatus, 'STATUS=302') && (int)$cancelledAccount->fetchColumn() === 1, 'An unconfirmed account-status request changed the account.');
    $nonAdminDeactivate = $unauthorizedUserActionProbe('deactivate_user');
    credentialTotpCheck(str_contains($nonAdminDeactivate, '403') && str_contains($nonAdminDeactivate, 'STATUS=403'), 'A non-Admin could deactivate an account directly.');
    $nonAdminReactivate = $unauthorizedUserActionProbe('reactivate_user');
    credentialTotpCheck(str_contains($nonAdminReactivate, '403') && str_contains($nonAdminReactivate, 'STATUS=403'), 'A non-Admin could reactivate an account directly.');
    $selfDeactivate = $adminUserStatusRoute((int)$adminSessionUser['id'], 'deactivate_user');
    $adminStillActive = $db->prepare('SELECT active FROM users WHERE id=?');
    $adminStillActive->execute([(int)$adminSessionUser['id']]);
    credentialTotpCheck(str_contains($selfDeactivate, 'STATUS=302') && (int)$adminStillActive->fetchColumn() === 1, 'Admin self-deactivation was not blocked.');
    $selfEditDeactivate = $adminSelfEditDeactivateRoute();
    $adminStillActive->execute([(int)$adminSessionUser['id']]);
    credentialTotpCheck(str_contains($selfEditDeactivate, 'STATUS=302') && (int)$adminStillActive->fetchColumn() === 1, 'Admin self-deactivation through Edit User was not blocked.');
    $authVersionQuery = $db->prepare('SELECT auth_version FROM users WHERE id=?');
    $authVersionQuery->execute([$routeCreatedUserId]);
    $authVersionBeforeDeactivate = (int)$authVersionQuery->fetchColumn();
    $deactivateResult = $adminUserStatusRoute($routeCreatedUserId, 'deactivate_user');
    $deactivatedUserQuery = $db->prepare('SELECT id,active,auth_version FROM users WHERE id=?');
    $deactivatedUserQuery->execute([$routeCreatedUserId]);
    $deactivatedUser = $deactivatedUserQuery->fetch();
    credentialTotpCheck(str_contains($deactivateResult, 'STATUS=302') && is_array($deactivatedUser) && (int)$deactivatedUser['active'] === 0 && (int)$deactivatedUser['auth_version'] === $authVersionBeforeDeactivate + 1, 'Admin deactivation did not soft-disable the account and invalidate sessions.');
    credentialTotpCheck(str_contains($adminUsersListProbe([$routeCreatedUserId], 'reactivate_user'), 'USER_ACTIONS_OK'), 'Deactivated users must remain listed with an Admin-only Reactivate form.');
    credentialTotpCheck(str_contains($inactiveLoginProbe($routeCreatedUsername, 'Temporary-Test-Password-2026'), 'LOGIN_DENIED'), 'A deactivated account could still log in.');
    credentialTotpCheck(!str_contains($inactiveSessionProbe($routeCreatedUserId, $routeCreatedUsername, $authVersionBeforeDeactivate), 'SESSION_ALLOWED'), 'An existing session with credential TOTP state survived deactivation.');
    $totpAfterDeactivation = credentialTotpState($routeCreatedUserId);
    credentialTotpCheck((int)$totpAfterDeactivation['required'] === $totpBeforeDeactivation['required'] && $totpAfterDeactivation['status'] === $totpBeforeDeactivation['status'] && $totpAfterDeactivation['secret_enc'] === $totpBeforeDeactivation['secret_enc'] && $totpAfterDeactivation['pending_secret_enc'] === $totpBeforeDeactivation['pending_secret_enc'] && (int)$totpAfterDeactivation['generation'] === $totpBeforeDeactivation['generation'], 'Deactivation changed the account’s retained credential TOTP enrollment.');
    $deactivationAudit = $db->prepare("SELECT user_id,action,entity_id,details,created_at FROM audit_logs WHERE action='user_deactivated' AND entity_id=? ORDER BY id DESC LIMIT 1");
    $deactivationAudit->execute([$routeCreatedUserId]);
    $deactivationAuditRow = $deactivationAudit->fetch();
    credentialTotpCheck(is_array($deactivationAuditRow) && (int)$deactivationAuditRow['user_id'] === (int)$adminSessionUser['id'] && (int)$deactivationAuditRow['entity_id'] === $routeCreatedUserId && $deactivationAuditRow['created_at'] !== null && str_contains((string)$deactivationAuditRow['details'], '"target_user_id":' . $routeCreatedUserId), 'Deactivation audit did not record actor, target, action and timestamp.');
    $historyCheck = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE id=? AND user_id=? AND entity_id=?');
    $historyCheck->execute([$historyFixtureId, $routeCreatedUserId, $routeCreatedUserId]);
    credentialTotpCheck((int)$historyCheck->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM users WHERE id=' . $routeCreatedUserId)->fetchColumn() === 1, 'Soft deactivation removed the user or historical audit reference.');
    $reactivateResult = $adminUserStatusRoute($routeCreatedUserId, 'reactivate_user');
    $reactivatedUserQuery = $db->prepare('SELECT active,auth_version FROM users WHERE id=?');
    $reactivatedUserQuery->execute([$routeCreatedUserId]);
    $reactivatedUser = $reactivatedUserQuery->fetch();
    credentialTotpCheck(str_contains($reactivateResult, 'STATUS=302') && is_array($reactivatedUser) && (int)$reactivatedUser['active'] === 1 && (int)$reactivatedUser['auth_version'] === (int)$deactivatedUser['auth_version'] + 1, 'Admin reactivation did not restore account availability and advance the auth version.');
    $reactivationAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action='user_reactivated' AND entity_id=? AND user_id=?");
    $reactivationAudit->execute([$routeCreatedUserId, (int)$adminSessionUser['id']]);
    credentialTotpCheck((int)$reactivationAudit->fetchColumn() === 1, 'Reactivation was not audited under the acting Admin.');

    $logoutPage = file_get_contents('/var/www/html/logout.php');
    credentialTotpCheck(is_string($logoutPage) && str_contains($logoutPage, '$_SESSION = [];') && str_contains($logoutPage, 'session_destroy();'), 'Logout must clear credential verification session state.');
    $authSource = file_get_contents('/var/www/html/auth.php');
    $credentialRoutes = ['credentials.php','credential-access.php','credential-reveal.php','credential-attachment.php','credential-dashboard-details.php'];
    credentialTotpCheck(is_string($authSource) && str_contains($authSource, "\$resource === 'credentials' || \$resource === 'credential_audit'") && str_contains($authSource, 'requireCredentialTwoFactor()'), 'Central credential permission checks do not invoke the TOTP gate.');
    foreach ($credentialRoutes as $route) credentialTotpCheck(is_file('/var/www/html/' . $route), 'Expected credential route is missing: ' . $route);
    credentialTotpCheck(!str_contains($authSource, "\$resource === 'inventory'"), 'Inventory was unexpectedly placed behind credential-only TOTP.');
} finally {
    unset($_SESSION['credential_totp_verified']);
    unset($_SESSION['user']);
    $db->prepare("DELETE FROM audit_logs WHERE action LIKE 'credential_totp_%' AND entity_type='user' AND entity_id=?")->execute([$userId]);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    if ($routeCreatedUserId > 0) {
        $db->prepare("DELETE FROM audit_logs WHERE entity_type='user' AND entity_id=?")->execute([$routeCreatedUserId]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$routeCreatedUserId]);
    }
    if ($customRouteCreatedUserId > 0) {
        $db->prepare("DELETE FROM audit_logs WHERE entity_type='user' AND entity_id=?")->execute([$customRouteCreatedUserId]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$customRouteCreatedUserId]);
    }
}

echo "Credential and user-management database integration checks passed: dedicated Create User authorization/rendering, role-default and custom credential permission persistence, Admin creation/Edit User, TOTP requirement and enrollment flows, Admin-only status changes, session invalidation, reactivation, audit/history retention, RBAC, Inventory independence, and fixture cleanup.\n";
