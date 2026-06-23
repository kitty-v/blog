<?php
// email/send_async.php
// 用 CLI 子进程发送单封邮件（真正不占用 PHP-FPM worker）
// 用法：php send_async.php <base64(json)>

require_once __DIR__ . '/mailer.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$payload = $argv[1] ?? '';
$json = base64_decode($payload, true);
if ($json === false) {
    mailer_log('[Mailer] send_async invalid base64 payload');
    exit(1);
}

$job = json_decode($json, true);
if (!is_array($job)) {
    mailer_log('[Mailer] send_async invalid json payload');
    exit(1);
}

foreach (['toEmail','subject','body'] as $k) {
    if (empty($job[$k]) || !is_string($job[$k])) {
        mailer_log('[Mailer] send_async missing field: ' . $k);
        exit(1);
    }
}

_send_mail_now(
    $job['toEmail'],
    (string)($job['toName'] ?? ''),
    (string)$job['subject'],
    (string)$job['body'],
    (bool)($job['isHtml'] ?? false)
);

