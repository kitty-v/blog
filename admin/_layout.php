<?php
// admin/_layout.php — 后台公共布局（SPA路由模式 + 移动端适配）
$page_title = $page_title ?? 'Admin';
$current    = basename($_SERVER['PHP_SELF']);

// 读取站点设置（用于动态主题名称和图标）
if (!isset($pdo)) { require_once __DIR__ . '/../includes/db.php'; $pdo = db(); }
function _admin_get_site_settings(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT `key`,`value` FROM site_settings")->fetchAll();
        $map  = [];
        foreach ($rows as $r) $map[$r['key']] = $r['value'];
        return $map;
    } catch (Exception $e) { return []; }
}
$_admin_site      = _admin_get_site_settings($pdo);
$_admin_theme     = htmlspecialchars($_admin_site['site_theme_name'] ?? '', ENT_QUOTES, 'UTF-8');
$_admin_favicon   = htmlspecialchars($_admin_site['site_favicon_url'] ?? '', ENT_QUOTES, 'UTF-8');

$nav = [
    ['href' => 'dashboard.php',  'label' => '控制面板',  'match' => ['dashboard.php','index.php'],
     'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
    ['href' => 'posts.php',      'label' => '全部文章', 'match' => ['posts.php'],
     'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    ['href' => 'post_edit.php',  'label' => '写新文章', 'match' => ['post_edit.php'],
     'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
    ['href' => 'collections.php','label' => '专题管理', 'match' => ['collections.php'],
     'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
    ['href' => 'categories.php', 'label' => '分类管理', 'match' => ['categories.php'],
     'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
    ['href' => 'files.php',      'label' => '文件管理', 'match' => ['files.php'],
     'icon' => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z'],
    ['href' => 'password.php',   'label' => '修改密码', 'match' => ['password.php'],
     'icon' => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z'],
    ['href' => 'settings.php',   'label' => '站点设置', 'match' => ['settings.php'],
     'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($page_title) ?> — <?= $_admin_theme ?></title>
<?php if ($_admin_favicon): ?><link rel="icon" href="<?= $_admin_favicon ?>"><?php endif; ?>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Inter', 'Noto Sans SC', sans-serif; }
  .playfair { font-family: 'Playfair Display', serif; }

  /* Sidebar transition */
  #sidebar { transition: transform .35s cubic-bezier(.4,0,.2,1); }
  #sidebar-overlay { transition: opacity .35s ease; }

  /* SPA page transitions */
  #spa-content { transition: opacity .15s ease; }
  #spa-content.loading { opacity: 0.4; pointer-events: none; }

  /* Top loader bar */
  #spa-loader {
    position: fixed; top: 0; left: 0; height: 2px; width: 0%;
    background: #fff; z-index: 9999;
    transition: width .3s ease, opacity .3s ease;
    opacity: 0;
  }
  #spa-loader.active { opacity: 1; }

  /* Form inputs */
  .field {
    width: 100%; border: 1px solid #e4e4e7;
    padding: 10px 14px; font-size: 14px;
    outline: none; transition: border-color .2s; background: #fff;
    font-family: 'Inter', sans-serif;
  }
  .field:focus { border-color: #18181b; }
  select.field { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; background-size: 16px; }

  /* Table */
  .data-table { width: 100%; border-collapse: collapse; }
  .data-table th { text-align: left; padding: 10px 16px; font-size: 10px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase; color: #a1a1aa; border-bottom: 1px solid #f4f4f5; background: #fafafa; }
  .data-table td { padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #f4f4f5; }
  .data-table tr:last-child td { border-bottom: none; }
  .data-table tbody tr:hover td { background: #fafafa; }

  /* Badge */
  .badge { display: inline-flex; align-items: center; padding: 2px 8px; font-size: 10px; letter-spacing: .05em; font-weight: 500; }
  .badge-green { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
  .badge-gray  { background: #f4f4f5; color: #71717a; border: 1px solid #e4e4e7; }

  /* Btn */
  .btn-primary { display:inline-flex; align-items:center; gap:6px; background:#18181b; color:#fff; font-size:12px; font-weight:500; letter-spacing:.1em; text-transform:uppercase; padding:10px 20px; cursor:pointer; border:none; transition:background .2s; text-decoration:none; }
  .btn-primary:hover { background:#3f3f46; }
  .btn-outline { display:inline-flex; align-items:center; gap:6px; background:transparent; color:#52525b; font-size:12px; font-weight:500; letter-spacing:.1em; text-transform:uppercase; padding:9px 20px; border:1px solid #e4e4e7; cursor:pointer; transition:all .2s; text-decoration:none; }
  .btn-outline:hover { border-color:#18181b; color:#18181b; }

  /* Alert */
  .alert-success { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; padding:12px 16px; font-size:13px; margin-bottom:24px; }
  .alert-error   { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; padding:12px 16px; font-size:13px; margin-bottom:24px; }
</style>
</head>
<body class="bg-[#f8f8f8] text-zinc-800 min-h-screen">
<script>
// 이 스크립트는 #spa-content 밖, <body> 최상단에 위치 →
// 직접 로드·SPA 경유 어느 경우에도 항상 가장 먼저 실행됨
window.openSidebar = function() {
  var sb = document.getElementById('sidebar');
  var ov = document.getElementById('sidebar-overlay');
  if (sb) sb.classList.remove('-translate-x-full');
  if (ov) { ov.classList.remove('opacity-0','pointer-events-none'); ov.classList.add('opacity-100'); }
};
window.closeSidebar = function() {
  var sb = document.getElementById('sidebar');
  var ov = document.getElementById('sidebar-overlay');
  if (sb) sb.classList.add('-translate-x-full');
  if (ov) { ov.classList.add('opacity-0','pointer-events-none'); ov.classList.remove('opacity-100'); }
};
window.closePicturePicker = window.closePicturePicker || function() {
  var m = document.getElementById('pickerModal');
  if (m) m.style.display = 'none';
};
window.addEventListener('resize', function() {
  if (window.innerWidth >= 1024) window.closeSidebar();
});
</script>

<!-- SPA top loader bar -->
<div id="spa-loader"></div>

<!-- Mobile overlay -->
<div id="sidebar-overlay" class="fixed inset-0 bg-black/40 z-30 opacity-0 pointer-events-none lg:hidden" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-[#0f0f0f] text-white z-40 flex flex-col -translate-x-full lg:translate-x-0">

  <div class="px-6 py-7 border-b border-white/5 flex justify-center">
    <a href="../index.php" target="_blank" class="block text-center">
      <p class="playfair text-lg italic text-white tracking-tight"><?= $_admin_theme ?></p>
      <p class="text-[10px] text-white/25 tracking-[.3em] uppercase mt-0.5">后台管理</p>
    </a>
  </div>

  <nav class="flex-1 px-3 py-5 space-y-0.5 overflow-y-auto">
    <?php foreach ($nav as $item):
      $active = in_array($current, $item['match']);
    ?>
    <a href="<?= $item['href'] ?>"
       data-spa
       class="spa-nav-link flex items-center gap-3 px-3 py-2.5 rounded-sm text-[13px] transition-colors <?= $active ? 'bg-white/10 text-white' : 'text-white/40 hover:text-white/80 hover:bg-white/5' ?>"
       data-match="<?= implode(',', $item['match']) ?>">
      <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= $item['icon'] ?>"/>
      </svg>
      <span><?= $item['label'] ?></span>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="px-3 py-4 border-t border-white/5">
    <a href="logout.php" class="flex items-center gap-3 px-3 py-2.5 text-white/30 hover:text-white/60 text-[13px] transition-colors rounded-sm">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
      </svg>
      <span>退出登录</span>
    </a>
  </div>
</aside>

<!-- Main -->
<div class="lg:pl-64 min-h-screen flex flex-col">

  <!-- Top bar -->
  <header class="sticky top-0 z-20 bg-white border-b border-zinc-200 px-4 lg:px-8 h-14 flex items-center justify-between">
    <!-- Hamburger (mobile) -->
    <button onclick="openSidebar()" class="lg:hidden p-2 -ml-2 text-zinc-500 hover:text-zinc-900 transition-colors">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/>
      </svg>
    </button>

    <span id="spa-title" class="text-sm font-medium text-zinc-800 lg:font-normal lg:text-zinc-500">
      <?= htmlspecialchars($page_title) ?>
    </span>

    <div class="flex items-center gap-4">
      <a href="../index.php" target="_blank"
         class="hidden sm:flex items-center gap-1.5 text-[11px] text-zinc-400 hover:text-zinc-700 tracking-widest uppercase transition-colors">
        查看网站
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
      </a>
      <?php
        $__avatar_url = '';
        try {
          $__pdo_av = db();
          $__av_row = $__pdo_av->query("SELECT `value` FROM site_settings WHERE `key`='homepage_avatar' LIMIT 1")->fetch();
          $__avatar_url = $__av_row ? trim($__av_row['value']) : '';
        } catch (\Throwable $__e) { $__avatar_url = ''; }
        $__fallback_letter = strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1));
      ?>
      <?php if ($__avatar_url): ?>
        <img src="<?= htmlspecialchars($__avatar_url, ENT_QUOTES, 'UTF-8') ?>"
             alt="avatar"
             class="w-7 h-7 rounded-full object-cover ring-1 ring-white/10"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="w-7 h-7 bg-zinc-900 text-white text-[11px] font-medium items-center justify-center rounded-full hidden">
          <?= $__fallback_letter ?>
        </div>
      <?php else: ?>
        <div class="w-7 h-7 bg-zinc-900 text-white text-[11px] font-medium flex items-center justify-center rounded-full">
          <?= $__fallback_letter ?>
        </div>
      <?php endif; ?>
    </div>
  </header>

  <!-- Page content (SPA container) -->
  <main class="flex-1 p-4 lg:p-8 max-w-6xl w-full mx-auto">
  <div id="spa-content">
