<?php
// =========================================
// email/mailer.php — PHPMailer 邮件发送工具
// SMTP 邮件发送工具（配置从数据库 site_settings 读取）
// =========================================

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * 记录邮件错误（尽量输出到“控制台/日志”）
 */
function mailer_log(string $msg): void {
    // 生产环境通常会进入 PHP-FPM / Web 服务器 error log
    error_log($msg);
    // 某些环境会把 stderr 打到容器/控制台，便于你用 docker logs 查看
    @file_put_contents('php://stderr', $msg . PHP_EOL);
}

/**
 * 从数据库读取 SMTP 配置（site_settings 表）
 * - 为了安全：不再把邮箱/密钥写死在文件里
 * - 如果未配置或关闭，则直接跳过发送（仅写日志，不影响页面）
 */
function mailer_get_smtp_config(): array {
    $cfg = [
        'enabled'    => false,
        'provider'   => 'custom',
        'host'       => '',
        'port'       => 0,
        'secure'     => '', // ssl / tls / none
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => '',
    ];

    try {
        require_once __DIR__ . '/../includes/db.php';
        $pdo = db();

        try {
            $rows = $pdo->query("SELECT `key`,`value` FROM site_settings")->fetchAll();
        } catch (Exception $e) {
            return $cfg; // 表不存在时静默降级
        }

        $map = [];
        foreach ($rows as $r) $map[$r['key']] = $r['value'];

        $cfg['enabled']    = (($map['smtp_enabled'] ?? '0') === '1');
        $cfg['provider']   = $map['smtp_provider']   ?? 'custom';
        $cfg['host']       = trim((string)($map['smtp_host'] ?? ''));
        $cfg['port']       = (int)($map['smtp_port'] ?? 0);
        $cfg['secure']     = trim((string)($map['smtp_secure'] ?? ''));
        $cfg['username']   = trim((string)($map['smtp_username'] ?? ''));
        $cfg['password']   = (string)($map['smtp_password'] ?? '');
        $cfg['from_email'] = trim((string)($map['smtp_from_email'] ?? ''));
        $cfg['from_name']  = trim((string)($map['smtp_from_name'] ?? ''));
    } catch (Throwable $t) {
        mailer_log('[Mailer] load smtp config failed: ' . $t->getMessage());
    }

    return $cfg;
}

/**
 * 内部：同步发送（会阻塞当前流程）
 */
function _send_mail_now(string $toEmail, string $toName, string $subject, string $body, bool $isHtml = false): bool {
    $cfg = mailer_get_smtp_config();
    if (!$cfg['enabled']) {
        mailer_log('[Mailer] smtp disabled (skip send) subject=' . $subject);
        return false;
    }
    if (!$cfg['host'] || !$cfg['port'] || !$cfg['username'] || !$cfg['password']) {
        mailer_log('[Mailer] smtp not configured (skip send) host/port/user/pass missing');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        // 服务器配置
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->Port       = (int)$cfg['port'];
        $mail->CharSet    = 'UTF-8';

        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];

        $secure = strtolower((string)$cfg['secure']);
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            // none
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        // 发件人
        $fromEmail = $cfg['from_email'] ?: $cfg['username'];
        $fromName  = $cfg['from_name']  ?: '博客管理员';
        $mail->setFrom($fromEmail, $fromName);

        // 收件人
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        // 内容
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        if ($isHtml) {
            // 纯文本备用（去除 HTML 标签）
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        } else {
            $mail->AltBody = $body;
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        // 记录错误但不中断主流程（按你的要求：只打印到日志/控制台，不影响页面）
        mailer_log('[Mailer] send failed to=' . $toEmail . ' err=' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * 轻量异步队列：把邮件发送延后到 shutdown 阶段（并在 PHP-FPM 下尽量提前结束响应）
 */
class MailerQueue {
    private static bool $registered = false;
    private static array $jobs = [];

    public static function push(array $job): void {
        self::$jobs[] = $job;
        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    public static function flush(): void {
        if (empty(self::$jobs)) return;

        // 尽量先把响应发给用户，避免页面卡住（仅 PHP-FPM 有效）
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            // 其他 SAPI 下尽量 flush（不保证一定有效）
            @ob_flush();
            @flush();
        }

        foreach (self::$jobs as $job) {
            try {
                _send_mail_now(
                    $job['toEmail'],
                    $job['toName'] ?? '',
                    $job['subject'],
                    $job['body'],
                    (bool)($job['isHtml'] ?? false)
                );
            } catch (Throwable $t) {
                mailer_log('[Mailer] async send exception: ' . $t->getMessage());
            }
        }
        self::$jobs = [];
    }
}

/**
 * 异步发送（不阻塞页面）
 */
function send_mail_async(string $toEmail, string $toName, string $subject, string $body, bool $isHtml = false): void {
    $job = [
        'toEmail' => $toEmail,
        'toName'  => $toName,
        'subject' => $subject,
        'body'    => $body,
        'isHtml'  => $isHtml,
    ];

    // 优先：真正后台（fork 出 CLI 子进程）——不会占用当前 PHP-FPM worker，避免站点被“拖慢/404”
    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
    $can_popen = function_exists('popen') && !in_array('popen', $disabled, true) && !in_array('pclose', $disabled, true);
    $can_exec  = function_exists('exec')  && !in_array('exec',  $disabled, true);
    $can_shell = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);

    $script = __DIR__ . '/send_async.php';
    if (is_file($script) && (php_sapi_name() !== 'cli') && ($can_popen || $can_exec || $can_shell)) {
        $payload = base64_encode(json_encode($job, JSON_UNESCAPED_UNICODE));
        // PHP-FPM 环境下 PHP_BINARY 可能是 php-fpm，不一定可执行 CLI；优先用 PHP_BINDIR/php，其次 fallback 到 "php"
        $phpCli = 'php';
        $phpBin = defined('PHP_BINDIR') ? (PHP_BINDIR . '/php') : '';
        if ($phpBin && is_file($phpBin) && is_executable($phpBin)) $phpCli = $phpBin;
        if (defined('PHP_BINARY') && basename(PHP_BINARY) === 'php' && is_file(PHP_BINARY) && is_executable(PHP_BINARY)) $phpCli = PHP_BINARY;

        $cmd = escapeshellarg($phpCli) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($payload) . ' > /dev/null 2>&1 &';
        try {
            if ($can_popen) {
                $h = @popen($cmd, 'r');
                if (is_resource($h)) { @pclose($h); return; }
            } elseif ($can_exec) {
                @exec($cmd);
                return;
            } elseif ($can_shell) {
                @shell_exec($cmd);
                return;
            }
        } catch (Throwable $t) {
            // 失败则降级为 shutdown 队列
            mailer_log('[Mailer] background spawn failed: ' . $t->getMessage());
        }
    }

    // 降级：shutdown 阶段发送（体验不阻塞，但仍占用 worker）
    MailerQueue::push($job);
}

/**
 * 发送「申请已提交」确认邮件给用户（极简纯文本）
 *
 * @param string $toEmail   申请人邮箱
 * @param string $siteName  申请的网站名称
 * @param string $siteUrl   申请的网站链接
 */
function send_application_received_mail(string $toEmail, string $siteName, string $siteUrl): void {
    $subject = '友情链接申请已收到';
    $text = "你好：\n\n我们已收到你的友情链接申请，将尽快审核。\n\n【申请信息】\n- 网站名称：{$siteName}\n- 网站链接：{$siteUrl}\n\n审核结果会发送至此邮箱，请留意查收。\n\n— 博客管理员（系统邮件，请勿直接回复）\n";
    $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'PingFang SC','Microsoft YaHei',Arial,sans-serif;color:#111827;line-height:1.8;">
  <div style="max-width:560px;margin:24px auto;padding:22px 22px 18px;border:1px solid #e5e7eb;border-radius:10px;">
    <div style="font-size:12px;color:#6b7280;letter-spacing:.12em;text-transform:uppercase;">友情链接申请</div>
    <div style="font-size:20px;font-weight:600;margin:8px 0 14px;">申请已收到</div>
    <div style="font-size:14px;color:#374151;margin:0 0 14px;">我们已收到你的友情链接申请，将尽快审核。</div>
    <div style="font-size:13px;color:#111827;border-top:1px solid #f3f4f6;padding-top:12px;margin-top:12px;">
      <div style="margin:0 0 6px;color:#6b7280;font-size:12px;">申请信息</div>
      <div style="margin:0;"><strong>网站名称：</strong>{$siteName}</div>
      <div style="margin:0;"><strong>网站链接：</strong><a href="{$siteUrl}" style="color:#111827;text-decoration:underline;">{$siteUrl}</a></div>
    </div>
    <div style="margin-top:14px;font-size:12px;color:#6b7280;">审核结果会发送至此邮箱。此邮件为系统发送，请勿直接回复。</div>
  </div>
</body>
</html>
HTML;
    send_mail_async($toEmail, '', $subject, $html, true);
}

/**
 * 发送「申请已通过」通知邮件给用户（极简纯文本）
 *
 * @param string $toEmail   申请人邮箱
 * @param string $siteName  申请的网站名称
 * @param string $siteUrl   申请的网站链接
 */
function send_application_approved_mail(string $toEmail, string $siteName, string $siteUrl): void {
    $subject = '友情链接申请已通过';
    $text = "你好：\n\n你的友情链接申请已审核通过，我们已将你的网站加入友情链接列表。\n\n【网站信息】\n- 网站名称：{$siteName}\n- 网站链接：{$siteUrl}\n\n— 博客管理员（系统邮件，请勿直接回复）\n";
    $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'PingFang SC','Microsoft YaHei',Arial,sans-serif;color:#111827;line-height:1.8;">
  <div style="max-width:560px;margin:24px auto;padding:22px 22px 18px;border:1px solid #e5e7eb;border-radius:10px;">
    <div style="font-size:12px;color:#6b7280;letter-spacing:.12em;text-transform:uppercase;">友情链接申请</div>
    <div style="font-size:20px;font-weight:600;margin:8px 0 14px;">申请已通过</div>
    <div style="font-size:14px;color:#374151;margin:0 0 14px;">我们已将你的网站加入友情链接列表，欢迎互访。</div>
    <div style="font-size:13px;color:#111827;border-top:1px solid #f3f4f6;padding-top:12px;margin-top:12px;">
      <div style="margin:0 0 6px;color:#6b7280;font-size:12px;">网站信息</div>
      <div style="margin:0;"><strong>网站名称：</strong>{$siteName}</div>
      <div style="margin:0;"><strong>网站链接：</strong><a href="{$siteUrl}" style="color:#111827;text-decoration:underline;">{$siteUrl}</a></div>
    </div>
    <div style="margin-top:14px;font-size:12px;color:#6b7280;">此邮件为系统发送，请勿直接回复。</div>
  </div>
</body>
</html>
HTML;
    send_mail_async($toEmail, '', $subject, $html, true);
}

/**
 * 发送「申请已拒绝」通知邮件给用户（极简纯文本）
 *
 * @param string $toEmail   申请人邮箱
 * @param string $siteName  申请的网站名称
 */
function send_application_rejected_mail(string $toEmail, string $siteName, string $adminMessage = ''): void {
    $subject = '友情链接申请结果通知';
    $msgBlock = $adminMessage ? ("\n\n【管理员寄语】\n" . $adminMessage . "\n") : '';
    $text = "你好：\n\n感谢你申请与我们交换友情链接。\n\n很遗憾，{$siteName} 的申请本次未能通过审核。{$msgBlock}\n你可以在调整后再次提交申请。\n\n— 博客管理员（系统邮件，请勿直接回复）\n";

    $adminHtml = '';
    if (trim($adminMessage) !== '') {
        $safe = htmlspecialchars($adminMessage, ENT_QUOTES, 'UTF-8');
        $adminHtml = '<div style="margin-top:14px;border-left:2px solid #e5e7eb;padding-left:12px;color:#374151;"><div style="font-size:12px;color:#6b7280;margin-bottom:4px;">管理员寄语</div><div style="white-space:pre-wrap;">' . $safe . '</div></div>';
    }

    $siteNameSafe = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'PingFang SC','Microsoft YaHei',Arial,sans-serif;color:#111827;line-height:1.8;">
  <div style="max-width:560px;margin:24px auto;padding:22px 22px 18px;border:1px solid #e5e7eb;border-radius:10px;">
    <div style="font-size:12px;color:#6b7280;letter-spacing:.12em;text-transform:uppercase;">友情链接申请</div>
    <div style="font-size:20px;font-weight:600;margin:8px 0 14px;">申请未通过</div>
    <div style="font-size:14px;color:#374151;margin:0;">很遗憾，<strong>{$siteNameSafe}</strong> 的申请本次未能通过审核。</div>
    {$adminHtml}
    <div style="margin-top:14px;font-size:14px;color:#374151;">你可以在调整后再次提交申请。</div>
    <div style="margin-top:14px;font-size:12px;color:#6b7280;">此邮件为系统发送，请勿直接回复。</div>
  </div>
</body>
</html>
HTML;

    send_mail_async($toEmail, '', $subject, $html, true);
}

/**
 * 发送「新友链申请」通知邮件给管理员
 *
 * @param string $siteName    申请人网站名称
 * @param string $siteUrl     申请人网站链接
 * @param string $description 申请人网站描述
 * @param string $email       申请人联系邮箱
 */
function send_admin_new_application_mail(string $siteName, string $siteUrl, string $description, string $email): void {
    // 从数据库读取管理员通知邮箱
    try {
        require_once __DIR__ . '/../includes/db.php';
        $pdo = db();
        $row = $pdo->query("SELECT `value` FROM site_settings WHERE `key`='admin_notify_email' LIMIT 1")->fetch();
        $adminEmail = trim((string)($row['value'] ?? ''));
    } catch (Throwable $t) {
        mailer_log('[Mailer] admin_notify_email fetch failed: ' . $t->getMessage());
        return;
    }

    if (!$adminEmail || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        return; // 未配置或无效，静默跳过
    }

    $subject = '收到新的友链申请：' . $siteName;
    $timeStr  = date('Y-m-d H:i');

    $descBlock = '';
    if (trim($description) !== '') {
        $descSafe  = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $descBlock = '<div style="margin:0;"><strong>网站描述：</strong>' . $descSafe . '</div>';
    }

    $siteNameSafe = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $siteUrlSafe  = htmlspecialchars($siteUrl,  ENT_QUOTES, 'UTF-8');
    $emailSafe    = htmlspecialchars($email,     ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'PingFang SC','Microsoft YaHei',Arial,sans-serif;color:#111827;line-height:1.8;">
  <div style="max-width:560px;margin:24px auto;padding:22px 22px 18px;border:1px solid #e5e7eb;border-radius:10px;">
    <div style="font-size:12px;color:#6b7280;letter-spacing:.12em;text-transform:uppercase;">友情链接</div>
    <div style="font-size:20px;font-weight:600;margin:8px 0 6px;">收到新的申请</div>
    <div style="font-size:12px;color:#9ca3af;margin-bottom:14px;">{$timeStr}</div>
    <div style="font-size:13px;color:#111827;border-top:1px solid #f3f4f6;padding-top:12px;margin-top:4px;">
      <div style="margin:0 0 6px;color:#6b7280;font-size:12px;">申请信息</div>
      <div style="margin:0;"><strong>网站名称：</strong>{$siteNameSafe}</div>
      <div style="margin:0;"><strong>网站链接：</strong><a href="{$siteUrlSafe}" style="color:#111827;text-decoration:underline;">{$siteUrlSafe}</a></div>
      {$descBlock}
      <div style="margin:0;"><strong>联系邮箱：</strong><a href="mailto:{$emailSafe}" style="color:#111827;text-decoration:underline;">{$emailSafe}</a></div>
    </div>
    <div style="margin-top:16px;font-size:12px;color:#6b7280;">请登录后台审核此申请。此邮件为系统自动发送。</div>
  </div>
</body>
</html>
HTML;

    send_mail_async($adminEmail, '', $subject, $html, true);
}
