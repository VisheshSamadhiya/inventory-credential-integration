<?php
declare(strict_types=1);
require_once __DIR__ . '/currency.php';

function orderReportRows(PDO $db): array
{
    return $db->query("SELECT t.id,t.quantity,COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) vendor,t.transaction_at,t.status,t.total_price,p.name product_name FROM inventory_transactions t JOIN products p ON p.id=t.product_id LEFT JOIN vendors v ON v.id=t.vendor_id WHERE t.transaction_type='ordered' AND (t.status IN ('Pending','Confirmed','Rejected') OR t.status IS NULL) ORDER BY CASE t.status WHEN 'Pending' THEN 0 WHEN 'Confirmed' THEN 1 WHEN 'Rejected' THEN 2 ELSE 3 END,t.transaction_at DESC,t.id DESC")->fetchAll();
}

function orderReportCounts(array $orders): array
{
    $counts = ['Pending'=>0,'Confirmed'=>0,'Rejected'=>0,'NULL Status'=>0,'Total'=>count($orders)];
    foreach ($orders as $order) {
        $status = $order['status'] ?? null;
        $key = in_array($status, ['Pending','Confirmed','Rejected'], true) ? $status : 'NULL Status';
        $counts[$key]++;
    }
    return $counts;
}

function orderEmailEscape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function orderEventEmail(string $eventType, array $order): array
{
    $events = [
        'new_order' => ['subject' => 'New inventory order awaiting approval', 'heading' => 'New order submitted'],
        'approved' => ['subject' => 'Inventory order approved', 'heading' => 'Order approved'],
        'rejected' => ['subject' => 'Inventory order rejected', 'heading' => 'Order rejected'],
    ];
    if (!isset($events[$eventType])) throw new InvalidArgumentException('Unknown order notification event.');

    $fields = [
        'Order ID' => '#' . (string)($order['id'] ?? ''),
        'Product' => (string)($order['product_name'] ?? ''),
        'Quantity' => (string)($order['quantity'] ?? ''),
    ];
    if ($eventType === 'new_order') {
        $fields += [
            'Requester' => (string)($order['requester_name'] ?? ''),
            'Reason' => (string)($order['reason'] ?? ''),
            'Order date' => (string)($order['order_date'] ?? ''),
            'Status' => 'Pending',
        ];
    } elseif ($eventType === 'approved') {
        $fields += [
            'Requester' => (string)($order['requester_name'] ?? ''),
            'Status' => 'Approved',
            'Approver' => (string)($order['actor_name'] ?? ''),
            'Approved at' => (string)($order['event_at'] ?? ''),
        ];
    } else {
        $fields += [
            'Requester' => (string)($order['requester_name'] ?? ''),
            'Status' => 'Rejected',
            'Rejection reason' => (string)($order['rejection_reason'] ?? 'No reason provided'),
            'Rejected by' => (string)($order['actor_name'] ?? ''),
            'Rejected at' => (string)($order['event_at'] ?? ''),
        ];
    }

    $htmlRows = '';
    $textRows = [];
    foreach ($fields as $label => $value) {
        $displayValue = $value !== '' ? $value : '—';
        $htmlRows .= '<tr><th align="left" style="padding:9px 12px;border-bottom:1px solid #E2E8F0;color:#64748B;font-size:13px">'
            . orderEmailEscape($label) . '</th><td style="padding:9px 12px;border-bottom:1px solid #E2E8F0;color:#15243A;font-size:14px">'
            . orderEmailEscape($displayValue) . '</td></tr>';
        $textRows[] = $label . ': ' . $displayValue;
    }
    $heading = $events[$eventType]['heading'];
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:24px 10px;background:#F5F7FB;font-family:Arial,Helvetica,sans-serif;color:#15243A">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#fff;border-radius:10px">'
        . '<tr><td style="padding:20px 24px;background:#15243A;color:#fff;font-size:20px;font-weight:bold">Inventory Manager</td></tr>'
        . '<tr><td style="padding:24px"><h1 style="margin:0 0 16px;font-size:21px">' . orderEmailEscape($heading) . '</h1>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">' . $htmlRows . '</table>'
        . '</td></tr><tr><td style="padding:16px 24px;background:#F8FAFC;color:#64748B;font-size:12px">Automatic order notification from Inventory Manager.</td></tr>'
        . '</table></body></html>';
    return [
        'subject' => 'Inventory Manager — ' . $events[$eventType]['subject'],
        'html' => $html,
        'text' => "Inventory Manager — {$heading}\n\n" . implode("\n", $textRows) . "\n",
    ];
}

function orderEmailViewUrl(string $appUrl, bool $allowInternal = false): ?string
{
    $url = trim($appUrl);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) return null;
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
    if (isset($parts['user']) || isset($parts['pass'])) return null;
    if ($allowInternal) return $url;
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.lan') || str_ends_with($host, '.test') || str_ends_with($host, '.home.arpa') || !str_contains($host, '.')) return null;
    $ipHost = trim($host, '[]');
    if (filter_var($ipHost, FILTER_VALIDATE_IP) !== false && filter_var($ipHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return null;
    if (filter_var($ipHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && filter_var($ipHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) return $url;
    return $url;
}

function orderReportHtml(array $orders, string $appUrl, ?DateTimeImmutable $now = null, bool $allowInternalUrl = false): string
{
    $counts = orderReportCounts($orders);
    $date = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y, h:i A') . ' IST';
    $statuses = ['Pending'=>['#fff4d6','#8a5b00'],'Confirmed'=>['#e4f5e9','#176b35'],'Rejected'=>['#fde8e8','#a12626'],'NULL Status'=>['#ececf0','#555560']];
    $rows = '';
    foreach ($orders as $order) {
        $status = $order['status'] ?? null;
        $label = in_array($status, ['Pending','Confirmed','Rejected'], true) ? $status : 'NULL Status';
        [$bg,$color] = $statuses[$label];
        $pending = $label === 'Pending' ? ' style="background-color:#fffaf0"' : '';
        $rows .= '<tr'.$pending.'><td style="padding:11px 8px;border-bottom:1px solid #E2E8F0">#'.orderEmailEscape($order['id'] ?? '').'</td><td style="padding:11px 8px;border-bottom:1px solid #E2E8F0">'.orderEmailEscape($order['product_name'] ?? '').'</td><td style="padding:11px 8px;border-bottom:1px solid #E2E8F0">'.orderEmailEscape($order['quantity'] ?? '').'</td><td style="padding:11px 8px;border-bottom:1px solid #E2E8F0">'.orderEmailEscape(($order['vendor'] ?? '') !== '' ? $order['vendor'] : '—').'</td><td style="padding:11px 8px;border-bottom:1px solid #E2E8F0">'.orderEmailEscape($order['transaction_at'] ?? '').'</td><td style="padding:11px 8px;border-bottom:1px solid #E2E8F0"><span style="background:'.$bg.';color:'.$color.';padding:4px 8px;border-radius:12px;font-weight:bold">'.orderEmailEscape($label).'</span></td><td style="padding:11px 8px;border-bottom:1px solid #E2E8F0">'.orderEmailEscape(formatInr($order['total_price'] ?? null)).'</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="7" style="padding:18px;text-align:center;color:#64748B">No matching orders were found.</td></tr>';
    $cards = '';
    foreach (['Pending','Confirmed','Rejected','NULL Status'] as $key) $cards .= '<td style="padding:8px;width:25%"><table role="presentation" width="100%" style="background:#F8FAFC;border-radius:8px"><tr><td style="padding:12px;text-align:center;color:#64748B;font-size:12px">'.orderEmailEscape($key).'</td></tr><tr><td style="padding:0 12px 12px;text-align:center;color:#15243A;font-size:24px;font-weight:bold">'.$counts[$key].'</td></tr></table></td>';
    $link = '';
    $url = orderEmailViewUrl($appUrl, $allowInternalUrl);
    if ($url !== null) $link = '<p style="margin:18px 0"><a href="'.orderEmailEscape($url).'" style="background:#315DA8;color:#ffffff;text-decoration:none;padding:11px 18px;border-radius:6px;font-weight:bold">View Orders</a></p>';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#F5F7FB;font-family:Inter,Noto Sans,Nirmala UI,Arial,Helvetica,sans-serif;color:#15243A"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F5F7FB"><tr><td align="center" style="padding:24px 10px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:900px;background:#ffffff;border-radius:10px;overflow:hidden"><tr><td style="background:#15243A;padding:24px 28px;color:#ffffff"><div style="font-size:22px;font-weight:bold">Inventory Manager</div><div style="margin-top:7px;color:#DBEAFE;font-size:14px">Order status report</div></td></tr><tr><td style="padding:24px 28px"><p style="margin:0 0 6px;font-size:14px">Hello,</p><h1 style="margin:0 0 6px;font-size:22px">Order status summary</h1><p style="margin:0 0 18px;color:#64748B;font-size:13px">Report date: '.orderEmailEscape($date).'</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'.$cards.'</tr></table><p style="margin:14px 0 8px;font-size:14px"><strong>'.$counts['Total'].'</strong> orders require tracking. Pending orders are highlighted for review.</p><div style="overflow-x:auto"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:12px"><thead><tr style="background:#15243A;color:#ffffff;text-align:left"><th style="padding:10px 8px">Order ID</th><th style="padding:10px 8px">Product</th><th style="padding:10px 8px">Quantity</th><th style="padding:10px 8px">Vendor</th><th style="padding:10px 8px">Order Date</th><th style="padding:10px 8px">Status</th><th style="padding:10px 8px">Total Price</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'.$link.'</td></tr><tr><td style="background:#F8FAFC;padding:17px 28px;color:#64748B;font-size:12px">This report reflects order information currently recorded in Inventory Manager.<br>© Inventory Manager</td></tr></table></td></tr></table></body></html>';
}

function orderReportText(array $orders, string $appUrl, ?DateTimeImmutable $now = null, bool $allowInternalUrl = false): string
{
    $counts = orderReportCounts($orders);
    $date = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y, h:i A') . ' IST';
    $text = "Inventory Manager — Order status report\nReport date: {$date}\n\n";
    foreach (['Pending','Confirmed','Rejected','NULL Status'] as $key) $text .= "{$key}: {$counts[$key]}\n";
    $text .= "Total: {$counts['Total']}\n\n";
    foreach ($orders as $order) $text .= sprintf("Order #%s | %s | Quantity: %s | Vendor: %s | Order date: %s | Status: %s | Total price: %s\n", $order['id'] ?? '', $order['product_name'] ?? '', $order['quantity'] ?? '', (($order['vendor'] ?? '') !== '' ? $order['vendor'] : '—'), $order['transaction_at'] ?? '', $order['status'] ?? 'NULL Status', formatInr($order['total_price'] ?? null));
    $url = orderEmailViewUrl($appUrl, $allowInternalUrl);
    if ($url !== null) $text .= "\nView Orders: {$url}\n";
    return $text;
}

function orderReportSubject(?DateTimeImmutable $now = null): string
{
    return 'Inventory Manager order status report — ' . ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y');
}

function deliverOrderReport(array $recipients, callable $send): array
{
    $sent = 0; $failed = 0; $failures = [];
    foreach ($recipients as $recipient) {
        try { $send((string)$recipient); $sent++; }
        catch (Throwable $e) { $failed++; $failures[(string)$recipient] = $e->getMessage(); }
    }
    return ['sent'=>$sent,'failed'=>$failed,'failures'=>$failures];
}

function orderEmailRecipients(string $configured): array
{
    $emails = array_map('trim', explode(',', $configured));
    $recipients = [];
    $seen = [];
    foreach ($emails as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) continue;
        $key = strtolower($email);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $recipients[] = $email;
    }
    return $recipients;
}

/** Execute either report command. The preview path never invokes the delivery callback. */
function executeOrderReportCommand(string $command, array $orders, string $appUrl, bool $allowInternalUrl, array $recipients, callable $send, ?string $previewPath = null): array
{
    if ($command === '--preview') {
        $html = orderReportHtml($orders, $appUrl, null, $allowInternalUrl);
        if ($previewPath === null || $previewPath === '') return ['mode'=>'preview','html'=>$html,'path'=>null];
        if (file_put_contents($previewPath, $html, LOCK_EX) === false) throw new RuntimeException('Could not write preview file.');
        return ['mode'=>'preview','html'=>$html,'path'=>$previewPath];
    }
    if ($command !== '--send-report') throw new InvalidArgumentException('Unknown order report command.');
    if (!$recipients) throw new RuntimeException('Report not sent: no valid ADMIN_EMAILS recipients configured.');
    $html = orderReportHtml($orders, $appUrl, null, $allowInternalUrl);
    $text = orderReportText($orders, $appUrl, null, $allowInternalUrl);
    $result = deliverOrderReport($recipients, static function (string $recipient) use ($send, $html, $text): void {
        $send($recipient, orderReportSubject(), $html, $text);
    });
    return ['mode'=>'send','orders'=>count($orders)] + $result;
}

function weeklyReminderHtml(array $orders, string $appUrl, bool $allowInternalUrl = false, ?DateTimeImmutable $now = null): string
{
    $html = orderReportHtml($orders, $appUrl, $now, $allowInternalUrl);
    return str_replace(['Order status report</div>', 'Order status summary', 'orders require tracking. Pending orders are highlighted for review.'], ['Weekly pending reminder</div>', 'Pending orders need review', 'orders have been Pending for at least seven days.'], $html);
}

function weeklyReminderText(array $orders, string $appUrl, bool $allowInternalUrl = false, ?DateTimeImmutable $now = null): string
{
    $date = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y, h:i A') . ' IST';
    $text = "Inventory Manager — Weekly pending reminder\nReport date: {$date}\n\n" . count($orders) . " orders have been Pending for at least seven days.\n\n";
    foreach ($orders as $order) $text .= sprintf("Order #%s | %s | Quantity: %s | Vendor: %s | Order date: %s | Days pending: %s\n", $order['id'] ?? '', $order['product_name'] ?? '', $order['quantity'] ?? '', (($order['vendor'] ?? '') !== '' ? $order['vendor'] : '—'), $order['transaction_at'] ?? '', $order['days_pending'] ?? '7+');
    $url = orderEmailViewUrl($appUrl, $allowInternalUrl);
    if ($url !== null) $text .= "\nView Orders: {$url}\n";
    return $text;
}

function orderReminderDue(DateTimeImmutable $orderedAt, DateTimeImmutable $now): bool
{
    return $orderedAt <= $now->modify('-7 days');
}

function orderReminderEligible(?DateTimeImmutable $lastSuccessfulDelivery, DateTimeImmutable $now): bool
{
    return $lastSuccessfulDelivery === null || $lastSuccessfulDelivery <= $now->modify('-7 days');
}
