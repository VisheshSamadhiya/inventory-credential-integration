<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/asset_tag_helpers.php';

$once = in_array('--once', $argv ?? [], true);
$idleSeconds = 2;
do {
    $result = processNextAssetTagBatch(db());
    if ($result === null) {
        if ($once) break;
        sleep($idleSeconds);
        continue;
    }
    if (isset($result['error'])) fwrite(STDERR, 'Asset tag job ' . $result['id'] . ': ' . $result['error'] . PHP_EOL);
    else fwrite(STDOUT, 'Asset tag job ' . $result['id'] . ': ' . $result['offset'] . '/' . $result['quantity'] . PHP_EOL);
} while (!$once);
