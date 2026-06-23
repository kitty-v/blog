<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

$config_file  = __DIR__ . '/../includes/admin_credentials.php';
$current_user = 'admin';
$current_pass = 'password';
if (file_exists($config_file)) { include $config_file; }

$msg = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old  = $_POST['old_password']     ?? '';
    $new1 = $_POST['new_password']     ?? '';
    $new2 = $_POST['confirm_password'] ?? '';
    if ($old !== $current_pass)    $error = '当前密码不正确。';
    elseif (strlen($new1) < 6)    $error = '新密码至少需要6位字符。';
    elseif ($new1 !== $new2)      $error = '两次密码输入不一致。';
    else {
        $code = '<?php' . "\n"
              . '$current_user = ' . var_export($current_user, true) . ";\n"
              . '$current_pass = ' . var_export($new1, true) . ";\n";
        // 确保目录存在且可写，给出明确错误而非静默失败
        $dir = dirname($config_file);
        if (!is_dir($dir)) {
            $error = '配置目录不存在，请联系管理员。';
        } elseif (!is_writable($config_file) && !is_writable($dir)) {
            $error = '配置文件不可写，请检查 includes/ 目录权限（chmod 755 或 chown www-data），密码未修改。';
        } elseif (file_put_contents($config_file, $code) === false) {
            $error = '写入配置文件失败，密码未修改。';
        } else {
            $current_pass = $new1;
            $msg = '密码修改成功，下次登录请使用新密码。';
        }
    }
}

$page_title = 'Password';
require '_layout.php';
?>

<div class="max-w-md">

  <?php if ($msg): ?>
  <div class="alert-success flex items-center gap-2">
    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    <?= htmlspecialchars($msg) ?>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-6">修改密码</p>
    <form id="pwdForm" method="POST" class="space-y-5">
      <div>
        <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-1.5">当前密码</label>
        <input type="password" name="old_password" class="field" placeholder="••••••••••">
      </div>
      <div>
        <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-1.5">新密码 <span class="normal-case text-zinc-300">(至少6位)</span></label>
        <input type="password" name="new_password" class="field" placeholder="••••••••••">
      </div>
      <div>
        <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-1.5">确认新密码</label>
        <input type="password" name="confirm_password" class="field" placeholder="••••••••••">
      </div>
      <button type="submit" id="pwdBtn" class="btn-primary w-full justify-center mt-2">更新密码</button>
    </form>
    <script>
      document.getElementById('pwdForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('pwdBtn');
        btn.disabled = true;
        btn.textContent = '保存中…';
        fetch('', { method: 'POST', body: new FormData(this) })
          .then(res => res.text())
          .then(html => { document.open(); document.write(html); document.close(); })
          .catch(() => { btn.disabled = false; btn.textContent = '更新密码'; });
      });
    </script>
  </div>

  <p class="text-[11px] text-zinc-400 mt-4 leading-relaxed">
    修改立即生效。下次登录时请使用新密码。
  </p>
</div>

<?php require '_layout_end.php'; ?>
