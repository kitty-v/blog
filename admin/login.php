<?php
require_once __DIR__ . '/../includes/auth.php';

auth_start();
if (!empty($_SESSION['admin_id'])) { header('Location: index.php'); exit; }

// 读取凭据（支持后台修改密码）
$_cf = __DIR__ . '/../includes/admin_credentials.php';
$current_user = 'admin';
$current_pass = 'password';
if (file_exists($_cf)) { include $_cf; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u === $current_user && $p === $current_pass) {
        auth_login(1, $u);
        header('Location: index.php'); exit;
    }
    $error = '用户名或密码错误，请重试。';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login | center</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Inter', sans-serif; }
  .playfair { font-family: 'Playfair Display', serif; }
  .input-field {
    width: 100%; background: transparent;
    border: none; border-bottom: 1px solid #3f3f46;
    color: #fff; font-size: 15px; padding: 12px 0;
    outline: none; transition: border-color .3s;
    font-family: 'Inter', sans-serif;
  }
  .input-field::placeholder { color: #52525b; }
  .input-field:focus { border-bottom-color: #fff; }
  .btn-signin {
    position: relative; overflow: hidden;
    background: #fff; color: #09090b;
    font-size: 11px; font-weight: 500;
    letter-spacing: .2em; text-transform: uppercase;
    padding: 16px 40px; border: none; cursor: pointer;
    transition: background .3s, color .3s;
    width: 100%;
  }
  .btn-signin:hover { background: #e4e4e7; }
  .noise {
    position: fixed; inset: 0; pointer-events: none; z-index: 0; opacity: .03;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
  }
  .grid-line {
    position: absolute; background: rgba(255,255,255,.04);
  }
</style>
</head>
<body class="min-h-screen bg-[#0a0a0a] flex overflow-hidden">

  <div class="noise"></div>

  <!-- Left panel — branding -->
  <div class="hidden lg:flex lg:w-1/2 relative flex-col justify-between p-16 border-r border-white/5">
    <!-- Grid decoration -->
    <div class="grid-line" style="top:0;bottom:0;left:33%;width:1px;"></div>
    <div class="grid-line" style="top:0;bottom:0;left:66%;width:1px;"></div>
    <div class="grid-line" style="left:0;right:0;top:40%;height:1px;"></div>

    <div class="relative z-10">
      <span class="text-white/20 text-[10px] tracking-[.4em] uppercase">昨夜书 / 后台</span>
    </div>

    <div class="relative z-10">
      <p class="playfair text-white/10 text-[80px] leading-none font-bold select-none -ml-2">拾<br>光<br>者</p>
    </div>

    <div class="relative z-10 space-y-3">
      <div class="w-8 h-[1px] bg-white/20"></div>
      <p class="playfair italic text-white/30 text-sm leading-relaxed max-w-xs">
        "The present moment always will have been."
      </p>
      <p class="text-white/15 text-[10px] tracking-widest uppercase">Since 2026</p>
    </div>
  </div>

  <!-- Right panel — form -->
  <div class="w-full lg:w-1/2 flex items-center justify-center px-6 py-16 relative z-10">
    <div class="w-full max-w-sm">

      <!-- Mobile logo -->
      <div class="lg:hidden mb-12 text-center">
        <p class="playfair text-white text-2xl italic">昨夜书</p>
        <p class="text-white/30 text-[10px] tracking-[.4em] uppercase mt-1">后台管理</p>
      </div>

      <!-- Desktop heading -->
      <div class="hidden lg:block mb-12">
        <h1 class="playfair text-white text-4xl mb-2">Welcome</h1>
        <p class="text-zinc-500 text-sm">登录以管理你的日志。</p>
      </div>

      <?php if ($error): ?>
      <div class="mb-8 flex items-start space-x-3 border border-red-900/60 bg-red-950/30 px-4 py-3">
        <span class="text-red-400 mt-0.5 flex-shrink-0">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </span>
        <p class="text-red-400 text-sm"><?= htmlspecialchars($error) ?></p>
      </div>
      <?php endif; ?>

      <form id="loginForm" method="POST" class="space-y-8">
        <div>
          <label class="block text-[10px] text-zinc-500 tracking-[.3em] uppercase mb-1">用户名</label>
          <input id="username" name="username" type="text" autocomplete="username"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                 class="input-field" placeholder="admin">
        </div>
        <div>
          <label class="block text-[10px] text-zinc-500 tracking-[.3em] uppercase mb-1">密码</label>
          <input id="password" name="password" type="password" autocomplete="current-password"
                 class="input-field" placeholder="••••••••••">
        </div>

        <div id="jsError" class="hidden mb-0 flex items-start space-x-3 border border-red-900/60 bg-red-950/30 px-4 py-3">
          <span class="text-red-400 mt-0.5 flex-shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </span>
          <p id="jsErrorMsg" class="text-red-400 text-sm"></p>
        </div>

        <div class="pt-2">
          <button type="submit" id="submitBtn" class="btn-signin">登录</button>
        </div>
      </form>

      <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
          e.preventDefault();

          const btn = document.getElementById('submitBtn');
          const jsError = document.getElementById('jsError');
          const jsErrorMsg = document.getElementById('jsErrorMsg');

          btn.disabled = true;
          btn.textContent = '登录中…';
          jsError.classList.add('hidden');

          const formData = new FormData(this);

          fetch('', {
            method: 'POST',
            body: formData
          })
          .then(res => res.text())
          .then(html => {
            // 如果服务器重定向到 index.php，fetch 会跟随并返回 index.php 的内容
            // 通过检查返回 HTML 是否包含登录表单来判断是否成功
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const hasLoginForm = doc.getElementById('loginForm');

            if (!hasLoginForm) {
              // 登录成功，跳转
              window.location.href = 'index.php';
            } else {
              // 登录失败，提取错误信息
              const errEl = doc.querySelector('.text-red-400.text-sm');
              const msg = errEl ? errEl.textContent.trim() : '用户名或密码错误，请重试。';
              jsErrorMsg.textContent = msg;
              jsError.classList.remove('hidden');
              btn.disabled = false;
              btn.textContent = '登录';
            }
          })
          .catch(() => {
            jsErrorMsg.textContent = '网络错误，请重试。';
            jsError.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = '登录';
          });
        });
      </script>

      <div class="mt-12 pt-8 border-t border-white/5">
        <a href="../index.php" class="text-zinc-600 text-[10px] tracking-[.3em] uppercase hover:text-zinc-300 transition-colors">
           <返回主站>
        </a>
      </div>
    </div>
  </div>

</body>
</html>
