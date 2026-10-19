<?php
declare(strict_types=1);
require_once __DIR__ . '/credential_helpers.php';
requireLogin();
$db = db();
$storageRoot = getenv('CREDENTIAL_ATTACHMENT_PATH') ?: dirname(__DIR__) . '/storage/credential-attachments';
if (!is_dir($storageRoot) && !mkdir($storageRoot, 0700, true) && !is_dir($storageRoot)) {
    http_response_code(500); exit('Attachment storage unavailable.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requirePermission('credentials', 'edit');
    verifyCsrf();
    $action = (string)($_POST['action'] ?? 'upload');
    if ($action === 'delete') {
        $attachmentId = filter_var($_POST['attachment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $q = $db->prepare('SELECT a.*,c.id credential_id,c.service_name FROM credential_attachments a JOIN credentials c ON c.id=a.credential_id WHERE a.id=? AND c.active=1');
        $q->execute([(int)$attachmentId]); $attachment = $q->fetch();
        if (!$attachment || !credentialCanAccess($record = credentialRecord((int)$attachment['credential_id']) ?? [])) { http_response_code(404); exit('Attachment not found.'); }
        if ($attachment['storage_backend'] === 'local') {
            $path = $storageRoot . '/' . basename((string)$attachment['storage_key']);
            if (is_file($path)) unlink($path);
        } else {
            $client = credentialS3Client(); $bucket = credentialS3Bucket();
            if (!$client || !$bucket) { http_response_code(503); exit('Remote attachment storage is not configured.'); }
            $client->deleteObject(['Bucket' => $bucket, 'Key' => (string)$attachment['storage_key']]);
        }
        $db->prepare('DELETE FROM credential_attachments WHERE id=?')->execute([(int)$attachmentId]);
        credentialAccessLog((int)$attachment['credential_id'], 'attachment_deleted');
        auditCredentialChange('credential_attachment_deleted', (int)$attachment['credential_id'], (string)$attachment['original_filename']);
        setFlash('success', 'Attachment deleted.');
        redirect('credentials.php');
    }

    $credentialId = filter_var($_POST['credential_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$credentialId) { http_response_code(400); exit('Credential not found.'); }
    $credential = requireCredentialRecord((int)$credentialId);
    $file = $_FILES['attachment'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        setFlash('error', 'Choose a valid file to upload.'); redirect('credentials.php');
    }
    $size = (int)$file['size'];
    if ($size < 1 || $size > 10 * 1024 * 1024) { setFlash('error', 'Attachment size must be between 1 byte and 10 MB.'); redirect('credentials.php'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $allowed = ['application/pdf','image/jpeg','image/png','image/gif','image/webp','text/plain','text/csv','application/zip','application/msword','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    if (!is_string($mime) || !in_array($mime, $allowed, true)) { setFlash('error', 'That attachment type is not allowed.'); redirect('credentials.php'); }
    $originalName = basename(str_replace('\\', '/', (string)$file['name']));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? '';
    if ($originalName === '' || $originalName === '.' || $originalName === '..') { setFlash('error', 'Invalid filename.'); redirect('credentials.php'); }
    $storageKey = bin2hex(random_bytes(24));
    $target = $storageRoot . '/' . $storageKey;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) { setFlash('error', 'Unable to store this attachment.'); redirect('credentials.php'); }
    chmod($target, 0600);
    try {
        $db->prepare("INSERT INTO credential_attachments (credential_id,storage_backend,storage_key,original_filename,content_type,file_size,uploaded_by) VALUES (?,'local',?,?,?,?,?)")->execute([(int)$credentialId,$storageKey,mb_substr($originalName,0,255,'UTF-8'),$mime,$size,(int)currentUser()['id']]);
    } catch (Throwable $exception) {
        if (is_file($target)) unlink($target);
        error_log('Credential attachment metadata write failed: ' . get_class($exception));
        setFlash('error', 'Unable to save attachment details.'); redirect('credentials.php');
    }
    credentialAccessLog((int)$credentialId, 'attachment_uploaded');
    auditCredentialChange('credential_attachment_uploaded', (int)$credentialId, mb_substr($originalName,0,200,'UTF-8'));
    setFlash('success', 'Attachment uploaded.');
    redirect('credentials.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); header('Allow: GET, POST'); exit('Method not allowed.'); }
requirePermission('credentials', 'view');
$attachmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$q = $db->prepare('SELECT a.*,c.id credential_id FROM credential_attachments a JOIN credentials c ON c.id=a.credential_id WHERE a.id=? AND c.active=1');
$q->execute([(int)$attachmentId]); $attachment = $q->fetch();
if (!$attachment || !credentialCanAccess($record = credentialRecord((int)$attachment['credential_id']) ?? [])) { http_response_code(404); exit('Attachment not found.'); }
$remoteBody = null;
$path = null;
if ($attachment['storage_backend'] === 'local') {
    $path = $storageRoot . '/' . basename((string)$attachment['storage_key']);
    if (!is_file($path)) { http_response_code(404); exit('Attachment not found.'); }
} else {
    $client = credentialS3Client(); $bucket = credentialS3Bucket();
    if (!$client || !$bucket) { http_response_code(503); exit('Remote attachment storage is not configured.'); }
    try { $remoteBody = $client->getObject(['Bucket' => $bucket, 'Key' => (string)$attachment['storage_key']])['Body']; }
    catch (Throwable) { http_response_code(404); exit('Attachment not found.'); }
}
credentialAccessLog((int)$attachment['credential_id'], 'attachment_downloaded');
auditCredentialChange('credential_attachment_downloaded', (int)$attachment['credential_id'], (string)$attachment['original_filename']);
header('Content-Type: ' . (string)$attachment['content_type']);
header('Content-Length: ' . (string)$attachment['file_size']);
$displayName = (string)$attachment['original_filename'];
$asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $displayName) ?: 'attachment';
header('Content-Disposition: attachment; filename="' . addcslashes($asciiName, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
header('X-Content-Type-Options: nosniff');
if ($path !== null) readfile($path); else echo $remoteBody->getContents();
