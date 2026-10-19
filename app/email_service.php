<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function emailSafeError(string $message): string
{
    foreach (['SMTP_PASSWORD', 'SMTP_USER'] as $key) {
        $secret = envv($key);
        if ($secret !== '') $message = str_replace($secret, '[redacted]', $message);
    }
    return substr($message, 0, 1000);
}

function mailer(): PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/vendor/autoload.php';
    $host = envv('SMTP_HOST');
    $user = envv('SMTP_USER');
    $password = envv('SMTP_PASSWORD');
    $from = envv('SMTP_FROM');
    $port = (int)envv('SMTP_PORT', '587');
    if ($host === '' || $user === '' || $password === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || $port < 1 || $port > 65535) {
        throw new RuntimeException('SMTP configuration is incomplete or invalid.');
    }
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = true;
    $mail->Username = $user;
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
    $mail->Timeout = 15;
    $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mail->setFrom($from, 'Inventory Manager');
    return $mail;
}

function sendMail(string $to, string $subject, string $body, ?string $plainText = null): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('The email recipient is invalid.');
    $mail = mailer();
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body = $body;
    $mail->AltBody = $plainText ?? trim(strip_tags(str_replace(['</tr>', '</td>', '<br>'], "\n", $body)));
    $mail->send();
}
