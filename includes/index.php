<?php
require_once __DIR__ . '/includes/db.php';

$pdo = db();

// ---- 站点设置 ----
function get_site_settings(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT `key`,`value` FROM site_settings")->fetchAll();
        $map  = [];
        foreach ($rows as $r) $map[$r['key']] = $r['value'];
        return $map;
    } catch (Exception $e) {
        return []; // 表不存在时静默降级
    }
}
$site = get_site_settings($pdo);
$ss   = function(string $k, string $default = '') use ($site) { return $site[$k] ?? $default; };

// ---- 筛选参数 ----
$filter_category   = trim($_GET['category']   ?? '');  // slug
$filter_collection = (int)($_GET['collection'] ?? 0);  // id

// ---- 构建 WHERE 条件 ----
$where_parts = ['p.is_published = 1'];
$bind_params = [];

$current_category   = null;
$current_collection = null;

if ($filter_category !== '') {
    $cat_stmt = $pdo->prepare('SELECT id, name, slug FROM categories WHERE slug = ?');
    $cat_stmt->execute([$filter_category]);
    $current_category = $cat_stmt->fetch();
    if ($current_category) {
        $where_parts[] = 'p.category_id = ?';
        $bind_params[]  = $current_category['id'];
    }
}

if ($filter_collection > 0) {
    $col_stmt = $pdo->prepare('SELECT id, title FROM collections WHERE id = ?');
    $col_stmt->execute([$filter_collection]);
    $current_collection = $col_stmt->fetch();
    if ($current_collection) {
        $where_parts[] = 'p.collection_id = ?';
        $bind_params[]  = $current_collection['id'];
    }
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

// ---- 分页参数 ----
$per_page = 5;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// ---- 文章总数 ----
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM posts p $where_sql");
$count_stmt->execute($bind_params);
$total       = (int)$count_stmt->fetchColumn();
$total_pages = (int)ceil($total / $per_page);
$page        = min($page, max(1, $total_pages)); // clamp
$offset      = ($page - 1) * $per_page;

// ---- 当前页文章 ----
$stmt = $pdo->prepare(
    "SELECT p.id, p.title, p.summary, p.cover_url, p.published_at,
            c.name AS category_name, c.slug AS category_slug,
            col.title AS collection_title, col.id AS collection_id
     FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN collections col ON col.id = p.collection_id
     $where_sql
     ORDER BY p.published_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([...$bind_params, $per_page, $offset]);
$posts = $stmt->fetchAll();

// ---- 所有专题（侧边栏 + 首页展示用） ----
$collections = $pdo
    ->query('SELECT id, title, description, cover_url FROM collections ORDER BY sort_order ASC')
    ->fetchAll();
// 首页最多展示3个
$collections_home = array_slice($collections, 0, 3);

// ---- 所有分类（带文章计数） ----
$categories = $pdo
    ->query('SELECT c.id, c.name, c.slug,
             COUNT(p.id) AS post_count
             FROM categories c
             LEFT JOIN posts p ON p.category_id = c.id AND p.is_published = 1
             GROUP BY c.id
             ORDER BY c.sort_order ASC')
    ->fetchAll();

function fmt_date(string $date): string { return date('Y.m.d', strtotime($date)); }
function h(string $str): string { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

// 分页链接生成（保留筛选参数）
function page_url(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

// 判断当前筛选状态
$is_filtered = ($current_category || $current_collection);
$filter_title = '';
if ($current_category)   $filter_title = '# ' . $current_category['name'];
if ($current_collection) $filter_title = '专题：' . $current_collection['title'];
?>
<!DOCTYPE html>
<html lang="zh-CN" class="overflow-x-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Watcher</title>
    <?php if (!empty($site['site_favicon_url'])): ?>
    <link rel="icon" href="<?= htmlspecialchars($site['site_favicon_url'], ENT_QUOTES, 'UTF-8') ?>">
    <?php else: ?>
    <link rel="icon" type="image/x-icon" href="./uploads/picture/20260513_154140_1a04de12.jpg">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <script>
        // 在渲染前立即应用主题，避免白闪
        (function() {
            var saved = localStorage.getItem('theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (saved === 'dark' || (!saved && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@200;400;700&family=Noto+Serif+SC:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans SC', sans-serif; background-color: #fff; color: #1a1a1a; transition: background-color 0.25s ease, color 0.25s ease; }
        .dark body { background-color: #0f0f0f; color: #e5e5e5; }
        html.dark { background-color: #0f0f0f; }
        .serif-cn { font-family: 'Noto Serif SC', serif; }
        .reveal { opacity: 0; transform: translateY(14px); transition: opacity 0.55s cubic-bezier(0.22, 1, 0.36, 1), transform 0.55s cubic-bezier(0.22, 1, 0.36, 1); }
        .reveal.active { opacity: 1; transform: translateY(0); }
        #searchBox { transition: all 0.38s cubic-bezier(0.85, 0, 0.15, 1); transform: translateY(-100%); opacity: 0; pointer-events: none; }
        #searchBox.active { transform: translateY(0); opacity: 1; pointer-events: auto; }
        .dark #searchBox { background-color: #0f0f0f; color: #e5e5e5; }
        #overlay { opacity: 0; pointer-events: none; z-index: 100; transition: all 0.38s ease; backdrop-filter: blur(4px); }
        #overlay.show { opacity: 1; pointer-events: auto; }
        .no-scroll { overflow: hidden; height: 100vh; }
        .bg-text { position: absolute; font-size: 18vw; opacity: 0.02; font-weight: 700; z-index: -1; pointer-events: none; white-space: nowrap; font-family: 'Noto Serif SC', serif; }
        /* Dark mode overrides */
        .dark .bg-\[\#fff\] { background-color: #0f0f0f !important; }
        .dark .bg-\[\#fafafa\] { background-color: #161616 !important; }
        .dark .bg-zinc-100 { background-color: #1a1a1a !important; }
        .dark .bg-zinc-200 { background-color: #2a2a2a !important; }
        .dark .border-zinc-100 { border-color: #2a2a2a !important; }
        .dark .border-zinc-200 { border-color: #333 !important; }
        .dark .text-zinc-400 { color: #888 !important; }
        .dark .text-zinc-500 { color: #777 !important; }
        .dark .text-zinc-300 { color: #666 !important; }
        .dark .text-\[\#1a1a1a\] { color: #e5e5e5 !important; }
        .dark .selection\:bg-zinc-100::selection { background-color: #2a2a2a; }
        /* Search box dark */
        .dark #searchInput { background: transparent; color: #e5e5e5; border-color: #333; }
        .dark #searchInput::placeholder { color: #333; }
        .dark #searchInput:focus { border-color: #e5e5e5; }
        .dark #searchResults a:hover { background-color: #1a1a1a; }
        /* Article hover dark */
        .dark .group:hover .group-hover\:text-zinc-500 { color: #aaa !important; }
        /* Pagination dark */
        .dark .border-zinc-200.hover\:border-zinc-900 { border-color: #333; color: #888; }
        .dark .bg-zinc-900 { background-color: #e5e5e5 !important; color: #0f0f0f !important; }
        /* Article cover image */
        .article-cover { transition: transform 0.55s cubic-bezier(0.22, 1, 0.36, 1); }
        .group:hover .article-cover { transform: scale(1.04); }
        /* Read more hover underline */
        .read-more { position:relative; display:inline-block; border-bottom: 1px solid #d4d4d8; padding-bottom: 2px; transition: border-color 0.2s ease; }
        .read-more:hover { border-color: #18181b; }
        .dark .read-more { border-color: #3f3f46; }
        .dark .read-more:hover { border-color: #e5e5e5; }
    </style>
</head>
<body class="selection:bg-zinc-100">

    <div id="overlay" class="fixed inset-0 bg-black/20"></div>

    <!-- 搜索浮层 -->
    <div id="searchBox" class="fixed inset-0 bg-white z-[150] flex flex-col items-center justify-center px-6">
        <button id="closeSearch" class="absolute top-10 right-10 text-sm tracking-widest uppercase p-4 hover:rotate-90 transition-transform">关闭 ✕</button>
        <div class="w-full max-w-4xl">
            <input id="searchInput" type="text" placeholder="输入关键词，按回车探索..."
                   class="serif-cn text-3xl md:text-6xl border-b border-zinc-100 outline-none w-full py-6 text-center placeholder:text-zinc-100 focus:border-zinc-900 transition-colors">
            <div id="searchResults" class="mt-10 space-y-4 max-h-[50vh] overflow-y-auto text-left hidden"></div>
            <div id="searchHints" class="flex justify-center space-x-6 mt-8 text-[10px] tracking-widest text-zinc-400 uppercase flex-wrap gap-y-2">
                <span>热门搜索:</span>
                <?php foreach ($categories as $cat): ?>
                <a href="?category=<?= h($cat['slug']) ?>" class="hover:text-black"># <?= h($cat['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 侧边菜单 -->
    <div id="sidebar" class="fixed inset-y-0 right-0 w-full md:w-[500px] bg-zinc-950 text-white z-[110] translate-x-full flex flex-col p-8 md:p-16 overflow-y-auto transition-transform duration-500">
        <div class="flex justify-between items-center mb-12">
            <span class="text-[10px] tracking-[0.4em] opacity-40 uppercase">Navigation</span>
            <button id="closeMenu" class="text-sm p-2 hover:rotate-90 transition-transform">✕</button>
        </div>
        <nav class="space-y-2 mb-12">

            <!-- 文章归档 (可展开分类) -->
            <div class="nav-group">
                <button class="nav-toggle serif-cn text-4xl w-full text-left flex items-center justify-between group py-2 hover:text-zinc-400 transition"
                        data-target="nav-categories">
                    <span>文章归档</span>
                    <svg class="nav-arrow w-5 h-5 opacity-30 flex-shrink-0 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div id="nav-categories" class="nav-sub overflow-hidden max-h-0 transition-all duration-500 ease-in-out">
                    <div class="pt-3 pb-4 pl-1 space-y-1">
                        <a href="index.php"
                           class="flex items-center justify-between text-sm font-light text-zinc-400 hover:text-white py-2 border-b border-white/5 transition <?= !$filter_category && !$filter_collection ? 'text-white' : '' ?>">
                            <span>全部文章</span>
                            <span class="text-[10px] text-zinc-600"><?= $total ?> 篇</span>
                        </a>
                        <?php foreach ($categories as $cat): ?>
                        <a href="?category=<?= h($cat['slug']) ?>"
                           class="flex items-center justify-between text-sm font-light text-zinc-400 hover:text-white py-2 border-b border-white/5 transition <?= ($filter_category === $cat['slug']) ? 'text-white' : '' ?>">
                            <span># <?= h($cat['name']) ?></span>
                            <span class="text-[10px] text-zinc-600"><?= (int)$cat['post_count'] ?> 篇</span>
                        </a>
                        <?php endforeach; ?>
                        <?php if (empty($categories)): ?>
                        <p class="text-xs text-zinc-600 py-2">暂无分类</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 专题系列 (可展开专题) -->
            <div class="nav-group">
                <button class="nav-toggle serif-cn text-4xl w-full text-left flex items-center justify-between group py-2 hover:text-zinc-400 transition"
                        data-target="nav-collections">
                    <span>专题系列</span>
                    <svg class="nav-arrow w-5 h-5 opacity-30 flex-shrink-0 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div id="nav-collections" class="nav-sub overflow-hidden max-h-0 transition-all duration-500 ease-in-out">
                    <div class="pt-3 pb-4 pl-1 space-y-1">
                        <?php foreach ($collections as $col): ?>
                        <a href="?collection=<?= (int)$col['id'] ?>"
                           class="flex items-center justify-between text-sm font-light text-zinc-400 hover:text-white py-2 border-b border-white/5 transition <?= ($filter_collection === (int)$col['id']) ? 'text-white' : '' ?>">
                            <span><?= h($col['title']) ?></span>
                            <svg class="w-3 h-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>
                        <?php endforeach; ?>
                        <?php if (empty($collections)): ?>
                        <p class="text-xs text-zinc-600 py-2">暂无专题</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 关于我 -->
            <div class="py-2">
                <a href="#about" onclick="document.getElementById('closeMenu').click()"
                   class="serif-cn text-4xl block hover:text-zinc-400 transition">关于我</a>
            </div>

        </nav>
        <div class="mt-auto grid grid-cols-2 gap-8 border-t border-zinc-800 pt-10">
            <div>
                <p class="text-[10px] text-zinc-500 uppercase mb-4 tracking-widest">微信公众号</p>
                <?php if ($ss('wechat_qr_url')): ?>
                <img src="<?= htmlspecialchars($ss('wechat_qr_url')) ?>" alt="微信公众号二维码"
                     class="w-24 h-24 object-cover rounded border border-white/10">
                <?php else: ?>
                <div class="w-24 h-24 bg-white/5 flex items-center justify-center rounded text-[10px] text-zinc-500 border border-white/5 italic">[扫码关注]</div>
                <?php endif; ?>
            </div>
            <div>
                <p class="text-[10px] text-zinc-500 uppercase mb-4 tracking-widest">数字足迹</p>
                <div class="flex flex-col space-y-2 text-sm font-light text-zinc-400">
                    <?php
                    $socials_cfg = [
                        'social_github'      => ['label'=>'Github',     'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>'],
                        'social_youtube'     => ['label'=>'Youtube',    'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>'],
                        'social_bilibili'    => ['label'=>'Bilibili',   'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M17.813 4.653h.854c1.51.054 2.769.578 3.773 1.574 1.004.995 1.524 2.249 1.56 3.76v7.36c-.036 1.51-.556 2.769-1.56 3.773s-2.262 1.524-3.773 1.56H5.333c-1.51-.036-2.769-.556-3.773-1.56S.036 18.858 0 17.347v-7.36c.036-1.511.556-2.765 1.56-3.76 1.004-.996 2.262-1.52 3.773-1.574h.774l-1.174-1.12a1.234 1.234 0 0 1-.373-.906c0-.356.124-.658.373-.907l.027-.027c.267-.249.573-.373.92-.373.347 0 .653.124.92.373L9.653 4.44c.071.071.134.142.187.213h4.267a.836.836 0 0 1 .16-.213l2.853-2.747c.267-.249.573-.373.92-.373.347 0 .662.151.929.4.267.249.391.551.391.907 0 .355-.124.657-.373.906zM5.333 7.24c-.746.018-1.373.276-1.88.773-.506.498-.769 1.13-.786 1.894v7.52c.017.764.28 1.395.786 1.893.507.498 1.134.756 1.88.773h13.334c.746-.017 1.373-.275 1.88-.773.506-.498.769-1.129.786-1.893v-7.52c-.017-.765-.28-1.396-.786-1.894-.507-.497-1.134-.755-1.88-.773zM8 11.107c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c0-.373.129-.689.386-.947.258-.257.574-.386.947-.386zm8 0c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c.017-.391.15-.711.4-.96.249-.249.56-.373.933-.373z"/></svg>'],
                        'social_twitter'     => ['label'=>'Twitter / X','icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'],
                        'social_instagram'   => ['label'=>'Instagram',  'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>'],
                        'social_linkedin'    => ['label'=>'LinkedIn',   'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>'],
                        'social_telegram'    => ['label'=>'Telegram',   'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>'],
                        'social_facebook'    => ['label'=>'Facebook',   'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'],
                        'social_threads'     => ['label'=>'Threads',    'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.868 1.205 8.62.024 12.203 0h.014c2.858.023 5.18.786 6.907 2.27 1.848 1.582 2.87 3.947 3.033 7.029l.004.082h-2.586l-.004-.069c-.155-2.449-.895-4.336-2.201-5.612-1.233-1.204-3.015-1.831-5.312-1.86-2.867.031-5.034.956-6.44 2.748-1.319 1.686-1.99 4.143-1.997 7.299.006 3.155.678 5.611 1.997 7.298 1.407 1.792 3.574 2.717 6.44 2.748 1.973-.027 3.392-.521 4.333-1.507.886-.928 1.344-2.34 1.362-4.198v-.072h-5.87v-2.33h8.442l.004.096c.024.651.028 1.319-.01 1.967-.18 2.932-1.12 5.194-2.798 6.72-1.61 1.465-3.822 2.22-6.574 2.244z"/></svg>'],
                        'social_tiktok'      => ['label'=>'TikTok',     'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>'],
                        'social_weibo'       => ['label'=>'微博',        'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M10.098 20.323c-3.977.391-7.414-1.406-7.672-4.02-.259-2.609 2.759-5.047 6.74-5.441 3.979-.394 7.413 1.404 7.671 4.018.259 2.6-2.759 5.049-6.739 5.443zM9.05 17.219c-.384.616-1.208.884-1.829.602-.612-.279-.793-.991-.406-1.593.379-.595 1.176-.861 1.793-.601.622.263.826.968.442 1.592zm2.73-1.511c-.141.237-.449.353-.689.253-.236-.09-.313-.361-.177-.586.138-.227.436-.346.672-.24.239.1.323.352.194.573zm2.218-5.209c-1.088-.299-2.316-.22-3.2.426L9.75 11.84c-.562.463-1.188 1.2-.896 2.194.285.882 1.284 1.538 2.375 1.498 1.37-.046 2.503-1.107 2.616-2.479.063-.74-.193-1.5-.847-1.553zM19.38 7.9c-.51-.23-1.07-.39-1.62-.45.28-.3.49-.65.59-1.04.37-1.48-.64-2.98-2.26-3.36-1.62-.38-3.26.49-3.63 1.97-.07.28-.07.56-.03.83H12.4c-.06 0-.11.01-.17.01a5.41 5.41 0 0 0-2.03.38l-.22.1C8.29 7.06 7.22 8.66 7.44 10.38c-.02-.01-.03-.01-.05-.02C5.42 9.5 3.46 9.73 2.46 11.04c-1 1.3-.6 3.16.91 4.2a7.88 7.88 0 0 0-.06.8c0 3.51 3.56 6.35 7.95 6.35 4.39 0 7.95-2.84 7.95-6.35 0-.66-.13-1.29-.38-1.89.85-.5 1.46-1.29 1.46-2.25-.01-1.44-1.28-2.65-2.9-2.65 0 0 .01 0 0 0z"/></svg>'],
                        'social_xiaohongshu' => ['label'=>'小红书',      'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.5 10.5h-3v3a1.5 1.5 0 01-3 0v-3h-3a1.5 1.5 0 010-3h3v-3a1.5 1.5 0 013 0v3h3a1.5 1.5 0 010 3z"/></svg>'],
                        'social_zhihu'       => ['label'=>'知乎',        'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M5.721 0C2.251 0 0 2.25 0 5.719V18.28C0 21.751 2.252 24 5.721 24H18.28C21.751 24 24 21.75 24 18.281V5.72C24 2.249 21.75 0 18.281 0zm6.964 6.575l-.917.008v6.31l.917-.001c.35 0 .52.193.52.526v.35c0 .335-.17.527-.52.527H9.243c-.35 0-.52-.192-.52-.527v-.35c0-.333.17-.526.52-.526l.915.001V7.478l-.915-.008c-.35 0-.52-.185-.52-.517v-.362c0-.333.17-.517.52-.517h2.942c.35 0 .52.184.52.517v.362c0 .332-.17.622-.52.622zm5.09 10.9c-.282.376-.71.563-1.138.563h-3.61v-1.348h3.19c.196 0 .313-.074.383-.17l1.546-2.117-1.546-2.118c-.07-.096-.187-.17-.383-.17h-3.19V10.77h3.61c.428 0 .856.186 1.138.562l1.97 2.7c.282.374.282.866 0 1.24l-1.97 2.203z"/></svg>'],
                        'social_douyin'      => ['label'=>'抖音',        'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.32 6.32 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V9.15a8.16 8.16 0 004.77 1.52V7.23a4.85 4.85 0 01-1-.54z"/></svg>'],
                        'social_douban'      => ['label'=>'豆瓣',        'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M1.5 5.25h21v1.5h-21zM6 18.75l1.5-7.5h9l1.5 7.5H6zm3.75 2.25h4.5v1.5h-4.5zM10.5 2.25h3v3h-3z"/></svg>'],
                    ];
                    foreach ($socials_cfg as $k => $cfg):
                        $url = $ss($k);
                        if (!$url) continue;
                    ?>
                    <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener"
                       class="flex items-center gap-2.5 hover:text-white transition-colors">
                        <?= $cfg['icon'] ?>
                        <span><?= $cfg['label'] ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 导航栏 -->
    <nav id="topNav" class="fixed top-0 w-full flex justify-between items-center px-6 md:px-16 py-5 md:py-8 z-50 <?= $hp_bg_url ? 'mix-blend-difference text-white' : 'text-[#1a1a1a] dark:text-white' ?>">
        <div class="text-2xl md:text-3xl font-bold tracking-tighter serif-cn italic cursor-pointer">
            <a href="index.php"><?= htmlspecialchars($ss('site_theme_name', '昨夜书'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
        <div class="flex items-center space-x-6 md:space-x-10">
            <!-- 深色模式切换 -->
            <button id="themeToggle" class="p-2 hover:opacity-50 transition" aria-label="切换主题">
                <svg id="iconSun" class="w-5 h-5 md:w-6 md:h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z"/></svg>
                <svg id="iconMoon" class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z"/></svg>
            </button>
            <button id="openSearch" class="p-2 hover:opacity-50 transition">
                <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" stroke-width="1.5"/></svg>
            </button>
            <button id="openMenu" class="group flex items-center space-x-3">
                <span class="text-[10px] md:text-xs uppercase tracking-[0.2em] font-light">目录</span>
                <div class="w-6 h-4 flex flex-col justify-between items-end">
                    <div class="w-6 h-[1px] bg-current"></div>
                    <div class="w-4 h-[1px] bg-current group-hover:w-6 transition-all"></div>
                </div>
            </button>
        </div>
    </nav>

    <!-- Hero (只在第一页且未筛选时显示) -->
    <?php
    // 首页个人卡片数据（从 site_settings 读取，有默认值）
    $hp_avatar  = $ss('homepage_avatar', 'https://i.pravatar.cc/200?img=68');
    $hp_name    = $ss('homepage_name', 'Jeremy Bentham');
    $hp_bio     = $ss('homepage_bio', '保持理想，步履不停。');
    $hp_bg_url  = $ss('homepage_bg_url', '');
    $hp_bg_opacity = intval($ss('homepage_bg_opacity', '30'));
    $hp_bg_blur = intval($ss('homepage_bg_blur', '0'));
    $hp_bg_position = $ss('homepage_bg_position', 'center');
    $hp_bg_mobile_enabled = $ss('homepage_bg_mobile_enabled', '0') === '1';
    $hp_avatar_no_frame = $ss('homepage_avatar_no_frame', '0') === '1';

    // 社交链接配置（用于首页图标展示）
    $hp_socials = [
        'social_github'      => ['label'=>'GitHub',     'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>'],
        'social_bilibili'    => ['label'=>'Bilibili',   'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.813 4.653h.854c1.51.054 2.769.578 3.773 1.574 1.004.995 1.524 2.249 1.56 3.76v7.36c-.036 1.51-.556 2.769-1.56 3.773s-2.262 1.524-3.773 1.56H5.333c-1.51-.036-2.769-.556-3.773-1.56S.036 18.858 0 17.347v-7.36c.036-1.511.556-2.765 1.56-3.76 1.004-.996 2.262-1.52 3.773-1.574h.774l-1.174-1.12a1.234 1.234 0 0 1-.373-.906c0-.356.124-.658.373-.907l.027-.027c.267-.249.573-.373.92-.373.347 0 .653.124.92.373L9.653 4.44c.071.071.134.142.187.213h4.267a.836.836 0 0 1 .16-.213l2.853-2.747c.267-.249.573-.373.92-.373.347 0 .662.151.929.4.267.249.391.551.391.907 0 .355-.124.657-.373.906zM5.333 7.24c-.746.018-1.373.276-1.88.773-.506.498-.769 1.13-.786 1.894v7.52c.017.764.28 1.395.786 1.893.507.498 1.134.756 1.88.773h13.334c.746-.017 1.373-.275 1.88-.773.506-.498.769-1.129.786-1.893v-7.52c-.017-.765-.28-1.396-.786-1.894-.507-.497-1.134-.755-1.88-.773zM8 11.107c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c0-.373.129-.689.386-.947.258-.257.574-.386.947-.386zm8 0c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c.017-.391.15-.711.4-.96.249-.249.56-.373.933-.373z"/></svg>'],
        'social_instagram'   => ['label'=>'Instagram',  'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>'],
        'social_twitter'     => ['label'=>'Twitter',    'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'],
        'social_youtube'     => ['label'=>'YouTube',    'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M21.593 7.203a2.506 2.506 0 00-1.762-1.766C18.265 5.007 12 5 12 5s-6.264-.007-7.831.404a2.56 2.56 0 00-1.766 1.778c-.413 1.566-.417 4.814-.417 4.814s-.004 3.264.406 4.814c.23.857.905 1.534 1.763 1.765 1.582.43 7.83.437 7.83.437s6.265.007 7.831-.403a2.515 2.515 0 001.767-1.763c.414-1.565.417-4.812.417-4.812s.02-3.265-.407-4.831zM9.996 15.005l.005-6 5.207 3.005-5.212 2.995z"/></svg>'],
        'social_linkedin'    => ['label'=>'LinkedIn',   'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>'],
        'social_telegram'    => ['label'=>'Telegram',   'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>'],
        'social_facebook'    => ['label'=>'Facebook',   'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'],
        'social_threads'     => ['label'=>'Threads',    'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.868 1.205 8.62.024 12.203 0h.014c2.858.023 5.18.786 6.907 2.27 1.848 1.582 2.87 3.947 3.033 7.029l.004.082h-2.586l-.004-.069c-.155-2.449-.895-4.336-2.201-5.612-1.233-1.204-3.015-1.831-5.312-1.86-2.867.031-5.034.956-6.44 2.748-1.319 1.686-1.99 4.143-1.997 7.299.006 3.155.678 5.611 1.997 7.298 1.407 1.792 3.574 2.717 6.44 2.748 1.973-.027 3.392-.521 4.333-1.507.886-.928 1.344-2.34 1.362-4.198v-.072h-5.87v-2.33h8.442l.004.096c.024.651.028 1.319-.01 1.967-.18 2.932-1.12 5.194-2.798 6.72-1.61 1.465-3.822 2.22-6.574 2.244z"/></svg>'],
        'social_tiktok'      => ['label'=>'TikTok',     'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>'],
        'social_weibo'       => ['label'=>'微博',        'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M10.098 20.323c-3.977.391-7.414-1.406-7.672-4.02-.259-2.609 2.759-5.047 6.74-5.441 3.979-.394 7.413 1.404 7.671 4.018.259 2.6-2.759 5.049-6.739 5.443zM9.05 17.219c-.384.616-1.208.884-1.829.602-.612-.279-.793-.991-.406-1.593.379-.595 1.176-.861 1.793-.601.622.263.826.968.442 1.592zm2.73-1.511c-.141.237-.449.353-.689.253-.236-.09-.313-.361-.177-.586.138-.227.436-.346.672-.24.239.1.323.352.194.573zm2.218-5.209c-1.088-.299-2.316-.22-3.2.426L9.75 11.84c-.562.463-1.188 1.2-.896 2.194.285.882 1.284 1.538 2.375 1.498 1.37-.046 2.503-1.107 2.616-2.479.063-.74-.193-1.5-.847-1.553zM19.38 7.9c-.51-.23-1.07-.39-1.62-.45.28-.3.49-.65.59-1.04.37-1.48-.64-2.98-2.26-3.36-1.62-.38-3.26.49-3.63 1.97-.07.28-.07.56-.03.83H12.4c-.06 0-.11.01-.17.01a5.41 5.41 0 0 0-2.03.38l-.22.1C8.29 7.06 7.22 8.66 7.44 10.38c-.02-.01-.03-.01-.05-.02C5.42 9.5 3.46 9.73 2.46 11.04c-1 1.3-.6 3.16.91 4.2a7.88 7.88 0 0 0-.06.8c0 3.51 3.56 6.35 7.95 6.35 4.39 0 7.95-2.84 7.95-6.35 0-.66-.13-1.29-.38-1.89.85-.5 1.46-1.29 1.46-2.25-.01-1.44-1.28-2.65-2.9-2.65 0 0 .01 0 0 0z"/></svg>'],
        'social_xiaohongshu' => ['label'=>'小红书',      'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.5 10.5h-3v3a1.5 1.5 0 01-3 0v-3h-3a1.5 1.5 0 010-3h3v-3a1.5 1.5 0 013 0v3h3a1.5 1.5 0 010 3z"/></svg>'],
        'social_zhihu'       => ['label'=>'知乎',        'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M5.721 0C2.251 0 0 2.25 0 5.719V18.28C0 21.751 2.252 24 5.721 24H18.28C21.751 24 24 21.75 24 18.281V5.72C24 2.249 21.75 0 18.281 0zm6.964 6.575l-.917.008v6.31l.917-.001c.35 0 .52.193.52.526v.35c0 .335-.17.527-.52.527H9.243c-.35 0-.52-.192-.52-.527v-.35c0-.333.17-.526.52-.526l.915.001V7.478l-.915-.008c-.35 0-.52-.185-.52-.517v-.362c0-.333.17-.517.52-.517h2.942c.35 0 .52.184.52.517v.362c0 .332-.17.622-.52.622zm5.09 10.9c-.282.376-.71.563-1.138.563h-3.61v-1.348h3.19c.196 0 .313-.074.383-.17l1.546-2.117-1.546-2.118c-.07-.096-.187-.17-.383-.17h-3.19V10.77h3.61c.428 0 .856.186 1.138.562l1.97 2.7c.282.374.282.866 0 1.24l-1.97 2.203z"/></svg>'],
        'social_douyin'      => ['label'=>'抖音',        'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.32 6.32 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V9.15a8.16 8.16 0 004.77 1.52V7.23a4.85 4.85 0 01-1-.54z"/></svg>'],
        'social_douban'      => ['label'=>'豆瓣',        'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M1.5 5.25h21v1.5h-21zM6 18.75l1.5-7.5h9l1.5 7.5H6zm3.75 2.25h4.5v1.5h-4.5zM10.5 2.25h3v3h-3z"/></svg>'],
    ]
    ?>
    <?php
    // 背景图始终放在独立 div 层，不用 header inline style，方便移动端通过 CSS 隐藏
    $header_extra_classes = 'bg-[#fff] dark:bg-zinc-950';
    // 有背景图时 header 本身不设背景色（背景由子 div 层承载）
    if ($hp_bg_url) {
        $header_extra_classes = '';
    }
    ?>

    <?php if ($hp_bg_url && !$hp_bg_mobile_enabled): ?>
    <style>
    /* 移动端：隐藏背景图层和遮罩，恢复 header 默认背景色，文字改为深色 */
    @media (max-width: 767px) {
        #site-header { background-color: #fff; }
        html.dark #site-header { background-color: #030712; }
        .hp-bg-layer { display: none !important; }
        .hp-bg-overlay { display: none !important; }
        .hp-bg-text-white { color: #1a1a1a !important; }
        .hp-bg-text-white-70 { color: rgb(113 113 122) !important; }
        .hp-bg-text-white-50 { color: rgb(161 161 170) !important; }
        .hp-bg-text-white-40 { color: rgb(212 212 216) !important; }
        .hp-bg-text-white-25 { color: rgb(228 228 231) !important; }
        .hp-ring-bg { --tw-ring-color: transparent !important; }
    }
    </style>
    <?php endif; ?>

    <header id="site-header" class="<?= ($page > 1 || $is_filtered) ? 'hidden' : 'min-h-screen flex items-center justify-center' ?> px-6 relative overflow-hidden <?= $header_extra_classes ?>">
        <?php if ($hp_bg_url): ?>
        <!-- 背景图层（独立 div，移动端可通过 CSS 隐藏） -->
        <div class="hp-bg-layer absolute inset-0" style="background-image:url(<?= h($hp_bg_url) ?>);background-size:cover;background-position:<?= h($hp_bg_position) ?><?= $hp_bg_blur > 0 ? ';filter:blur(' . $hp_bg_blur . 'px);transform:scale(1.05)' : '' ?>"></div>
        <!-- 遮罩层 -->
        <div class="hp-bg-overlay absolute inset-0 bg-black" style="opacity:<?= round($hp_bg_opacity / 100, 2) ?>"></div>
        <?php endif; ?>
        <div class="flex flex-col items-center text-center reveal relative z-10">
            <!-- 头像 -->
            <?php if (!$hp_avatar_hidden): ?>
            <div class="mb-6">
                <img src="<?= h($hp_avatar) ?>" alt="<?= h($hp_name) ?>"
                     class="w-24 h-24 object-cover rounded-full <?= ($hp_bg_url && !$hp_avatar_no_frame) ? 'ring-2 ring-white/30 hp-ring-bg' : 'ring-2 ring-transparent' ?>"
                     onerror="this.src='https://i.pravatar.cc/200?img=68'">
            </div>
            <?php endif; ?>
            <!-- 姓名 -->
            <h1 class="serif-cn text-2xl md:text-3xl font-medium mb-4 <?= $hp_bg_url ? 'text-white hp-bg-text-white' : 'text-[#1a1a1a]' ?>"><?= h($hp_name) ?></h1>
            <!-- 简介 -->
            <p class="text-sm font-light max-w-sm leading-relaxed mb-6 <?= $hp_bg_url ? 'text-white/70 hp-bg-text-white-70' : 'text-zinc-500' ?>">
                <?= nl2br(h($hp_bio)) ?>
            </p>
            <!-- 社交图标 -->
            <div class="flex items-center gap-5 <?= $hp_bg_url ? 'text-white hp-bg-text-white' : 'text-[#1a1a1a]' ?>">
                <?php foreach ($hp_socials as $key => $cfg):
                    $url = $ss($key);
                    if (!$url) continue; ?>
                <a href="<?= h($url) ?>" target="_blank" rel="noopener"
                   title="<?= h($cfg['label']) ?>"
                   class="hover:opacity-40 transition-opacity duration-200">
                    <?= $cfg['icon'] ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- 下滑提示 -->
        <div id="scrollHint" class="absolute bottom-10 left-1/2 -translate-x-1/2 flex flex-col items-center space-y-2 transition-opacity duration-700 z-10">
            <span class="text-[10px] tracking-[0.3em] <?= $hp_bg_url ? 'text-white/50 hp-bg-text-white-50' : 'text-zinc-400' ?> uppercase">向下探索</span>
            <div class="flex flex-col items-center space-y-1">
                <svg class="w-4 h-4 <?= $hp_bg_url ? 'text-white/40 hp-bg-text-white-40' : 'text-zinc-300' ?> animate-bounce" style="animation-delay:0s" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                </svg>
                <svg class="w-4 h-4 <?= $hp_bg_url ? 'text-white/25 hp-bg-text-white-25' : 'text-zinc-200' ?> animate-bounce" style="animation-delay:0.15s" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </div>
    </header>

    <!-- 精选专题（只在第一页且未筛选时显示） -->
    <?php if ($page === 1 && !$is_filtered): ?>
    <section class="px-6 md:px-16 py-24 bg-[#fafafa]">
        <div class="max-w-7xl mx-auto">
            <div class="flex items-baseline justify-between mb-16 border-b border-zinc-200 pb-4">
                <h2 class="serif-cn text-2xl">精选专题</h2>
                <span class="text-[10px] text-zinc-400 tracking-widest uppercase">Collections</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12">
                <?php foreach ($collections_home as $i => $col): ?>
                <a href="?collection=<?= (int)$col['id'] ?>" class="reveal group cursor-pointer block" <?= $i > 0 ? 'style="transition-delay:' . ($i * 0.1) . 's;"' : '' ?>>
                    <div class="aspect-[16/10] overflow-hidden bg-zinc-200 mb-6">
                        <?php if ($col['cover_url']): ?>
                        <img src="<?= h($col['cover_url']) ?>" alt="<?= h($col['title']) ?>"
                             class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <h3 class="serif-cn text-xl mb-3 group-hover:text-zinc-500 transition"># <?= h($col['title']) ?></h3>
                    <p class="text-xs text-zinc-400 font-light leading-relaxed"><?= h($col['description']) ?></p>
                </a>
                <?php endforeach; ?>
                <?php if (empty($collections_home)): ?>
                <p class="col-span-3 text-zinc-400 text-sm">暂无专题。</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- 文章列表 -->
    <main class="px-6 md:px-16 py-32">
        <div class="max-w-5xl mx-auto">

            <!-- 筛选状态提示条 -->
            <?php if ($is_filtered): ?>
            <div class="flex items-center justify-between mb-16 pb-6 border-b border-zinc-100">
                <div>
                    <p class="text-[10px] tracking-widest text-zinc-400 uppercase mb-1">当前筛选</p>
                    <h2 class="serif-cn text-2xl"><?= h($filter_title) ?></h2>
                    <p class="text-xs text-zinc-400 mt-1"><?= $total ?> 篇文章</p>
                </div>
                <a href="index.php" class="text-[10px] tracking-[0.2em] uppercase border-b border-zinc-200 pb-1 hover:border-zinc-900 transition">
                    清除筛选 ✕
                </a>
            </div>
            <?php endif; ?>

            <!-- 文章 -->
            <div class="space-y-32">
                <?php if (empty($posts)): ?>
                <p class="text-zinc-400 text-sm">该筛选条件下暂无文章。</p>
                <?php endif; ?>

                <?php foreach ($posts as $post): ?>
                <?php $has_cover = !empty($post['cover_url']); ?>
                <article class="reveal group <?= $has_cover ? 'grid grid-cols-1 md:grid-cols-12 gap-8 items-center' : '' ?>">
                    <?php if ($has_cover): ?>
                    <div class="md:col-span-5 aspect-[4/3] overflow-hidden">
                        <img src="<?= h($post['cover_url']) ?>" alt="<?= h($post['title']) ?>"
                             class="w-full h-full object-cover article-cover">
                    </div>
                    <div class="md:col-span-7 space-y-4">
                    <?php else: ?>
                    <div class="space-y-4">
                    <?php endif; ?>
                        <div class="flex items-center flex-wrap gap-x-3 gap-y-1 text-[10px] tracking-widest text-zinc-400 uppercase">
                            <span><?= h(fmt_date($post['published_at'])) ?></span>
                            <?php if ($post['category_name']): ?>
                            <span class="w-4 h-[1px] bg-zinc-100"></span>
                            <a href="?category=<?= h($post['category_slug']) ?>" class="hover:text-zinc-700 transition">
                                <?= h($post['category_name']) ?>
                            </a>
                            <?php endif; ?>
                            <?php if ($post['collection_title']): ?>
                            <span class="w-4 h-[1px] bg-zinc-100"></span>
                            <a href="?collection=<?= (int)$post['collection_id'] ?>" class="hover:text-zinc-700 transition italic normal-case">
                                # <?= h($post['collection_title']) ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        <h3 class="serif-cn text-3xl group-hover:text-zinc-500 transition cursor-pointer">
                            <?= h($post['title']) ?>
                        </h3>
                        <p class="text-zinc-500 text-sm font-light leading-relaxed"><?= h($post['summary']) ?></p>
                        <a href="post.php?id=<?= (int)$post['id'] ?>"
                           class="read-more inline-block text-[10px] tracking-[0.2em] font-bold">
                            <阅读全文>
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <!-- ======= 分页 ======= -->
            <?php if ($total_pages > 1): ?>
            <nav class="mt-32 flex items-center justify-center space-x-2" aria-label="分页">

                <?php if ($page > 1): ?>
                <a href="<?= h(page_url($page - 1)) ?>"
                   class="w-10 h-10 flex items-center justify-center border border-zinc-200 text-zinc-400 hover:border-zinc-900 hover:text-zinc-900 transition text-sm">
                    ←
                </a>
                <?php else: ?>
                <span class="w-10 h-10 flex items-center justify-center border border-zinc-100 text-zinc-200 text-sm cursor-default">←</span>
                <?php endif; ?>

                <?php
                // 显示页码窗口：始终显示首、末页，及当前页前后各1页
                $shown = [];
                for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i === 1 || $i === $total_pages || abs($i - $page) <= 1) {
                        $shown[] = $i;
                    }
                }
                $prev_shown = null;
                foreach ($shown as $p_num):
                    if ($prev_shown !== null && $p_num - $prev_shown > 1): ?>
                        <span class="text-zinc-300 text-xs px-1">···</span>
                    <?php endif;
                    if ($p_num === $page): ?>
                        <span class="w-10 h-10 flex items-center justify-center bg-zinc-900 text-white text-sm"><?= $p_num ?></span>
                    <?php else: ?>
                        <a href="<?= h(page_url($p_num)) ?>"
                           class="w-10 h-10 flex items-center justify-center border border-zinc-200 text-zinc-400 hover:border-zinc-900 hover:text-zinc-900 transition text-sm">
                            <?= $p_num ?>
                        </a>
                    <?php endif;
                    $prev_shown = $p_num;
                endforeach; ?>

                <?php if ($page < $total_pages): ?>
                <a href="<?= h(page_url($page + 1)) ?>"
                   class="w-10 h-10 flex items-center justify-center border border-zinc-200 text-zinc-400 hover:border-zinc-900 hover:text-zinc-900 transition text-sm">
                    →
                </a>
                <?php else: ?>
                <span class="w-10 h-10 flex items-center justify-center border border-zinc-100 text-zinc-200 text-sm cursor-default">→</span>
                <?php endif; ?>

            </nav>
            <p class="text-center text-[10px] text-zinc-300 tracking-widest mt-6 uppercase">
                第 <?= $page ?> 页 / 共 <?= $total_pages ?> 页 · <?= $total ?> 篇文章
            </p>
            <?php endif; ?>

        </div>
    </main>

    <!-- 底部 / 关于我 -->
    <footer id="about" class="bg-zinc-950 text-white px-6 md:px-16 py-24">
        <div class="max-w-7xl mx-auto">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-16 mb-20">
                <div>
                    <p class="text-[10px] tracking-[0.4em] text-white/20 uppercase mb-4">About</p>
                    <h2 class="serif-cn text-4xl mb-8 italic"><?= htmlspecialchars($ss('about_quote', '保持理想，步履不停。')) ?></h2>
                    <p class="text-zinc-500 text-sm font-light leading-relaxed max-w-sm">对设计、摄影或科技有独特见解？欢迎通过邮件交流，或在社交媒体上订阅动态。</p>
                </div>
                <div class="flex md:justify-end items-end">
                    <div class="text-right">
                        <?php if ($ss('about_email')): ?>
                        <p class="text-[10px] tracking-widest opacity-30 mb-2 uppercase">Contact</p>
                        <p class="text-sm font-light"><a href="mailto:<?= htmlspecialchars($ss('about_email')) ?>"><?= htmlspecialchars($ss('about_email')) ?></a></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="border-t border-zinc-900 pt-10 flex flex-col md:flex-row justify-between items-center text-[10px] tracking-[0.2em] opacity-20">
                <p>© 2026 <?= htmlspecialchars($ss('site_theme_name', '昨夜书'), ENT_QUOTES, 'UTF-8') ?>. 粤ICP备12345678号</p>
                <div class="mt-4 md:mt-0 flex space-x-6 uppercase">
                    <a href="#">隐私政策</a>
                    <a href="#">使用协议</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        const sidebar    = document.getElementById('sidebar');
        const overlay    = document.getElementById('overlay');
        const openBtn    = document.getElementById('openMenu');
        const closeBtn   = document.getElementById('closeMenu');
        const searchBox  = document.getElementById('searchBox');
        const openSearch = document.getElementById('openSearch');
        const closeSearch= document.getElementById('closeSearch');
        const searchInput= document.getElementById('searchInput');
        const searchResults = document.getElementById('searchResults');
        const searchHints   = document.getElementById('searchHints');

        function toggleLock(v) { document.body.classList.toggle('no-scroll', v); }

        openBtn.onclick  = () => { sidebar.classList.remove('translate-x-full'); overlay.classList.add('show'); toggleLock(true); };
        closeBtn.onclick = () => { sidebar.classList.add('translate-x-full'); overlay.classList.remove('show'); toggleLock(false); };
        overlay.onclick  = () => { sidebar.classList.add('translate-x-full'); overlay.classList.remove('show'); toggleLock(false); };

        openSearch.onclick  = () => { searchBox.classList.add('active'); toggleLock(true); searchInput.focus(); };
        closeSearch.onclick = () => { searchBox.classList.remove('active'); toggleLock(false); clearSearch(); };

        // ---- 手风琴侧边导航 ----
        document.querySelectorAll('.nav-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.dataset.target;
                const sub = document.getElementById(targetId);
                const arrow = btn.querySelector('.nav-arrow');
                const isOpen = sub.style.maxHeight && sub.style.maxHeight !== '0px';

                // 关闭所有
                document.querySelectorAll('.nav-sub').forEach(el => {
                    el.style.maxHeight = '0px';
                });
                document.querySelectorAll('.nav-arrow').forEach(el => {
                    el.style.transform = 'rotate(0deg)';
                });

                // 切换当前
                if (!isOpen) {
                    sub.style.maxHeight = sub.scrollHeight + 'px';
                    arrow.style.transform = 'rotate(180deg)';
                }
            });
        });

        // 页面加载时自动展开有激活项的分组
        <?php if ($current_category): ?>
        (function() {
            const el = document.getElementById('nav-categories');
            const arrow = document.querySelector('[data-target="nav-categories"] .nav-arrow');
            if (el) { el.style.maxHeight = el.scrollHeight + 'px'; arrow.style.transform = 'rotate(180deg)'; }
        })();
        <?php elseif ($current_collection): ?>
        (function() {
            const el = document.getElementById('nav-collections');
            const arrow = document.querySelector('[data-target="nav-collections"] .nav-arrow');
            if (el) { el.style.maxHeight = el.scrollHeight + 'px'; arrow.style.transform = 'rotate(180deg)'; }
        })();
        <?php endif; ?>

        let searchTimer = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            const q = searchInput.value.trim();
            if (!q) { clearSearch(); return; }
            searchTimer = setTimeout(() => doSearch(q), 300);
        });
        searchInput.addEventListener('keydown', e => { if (e.key === 'Enter' && searchInput.value.trim()) doSearch(searchInput.value.trim()); });

        function highlight(text, q) {
            if (!text || !q) return text || '';
            const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            return text.replace(new RegExp(escaped, 'gi'), m => `<mark class="bg-zinc-200 dark:bg-zinc-700 text-inherit rounded px-0.5">${m}</mark>`);
        }

        async function doSearch(q) {
            searchResults.innerHTML = '<p class="text-zinc-400 text-sm text-center py-8 tracking-widest">搜索中...</p>';
            searchResults.classList.remove('hidden');
            searchHints.classList.add('hidden');
            try {
                const resp = await fetch(`api/posts.php?search=${encodeURIComponent(q)}`);
                const data = await resp.json();
                if (!data.length) {
                    searchResults.innerHTML = `<p class="text-zinc-400 text-sm text-center py-8 tracking-widest">未找到与「${q}」相关的文章</p>`;
                    return;
                }
                searchResults.innerHTML = data.map(p => {
                    const summary = (p.summary || '').substring(0, 80);
                    const tags = [p.category_name, p.collection_title].filter(Boolean);
                    const tagHtml = tags.map(t =>
                        `<span class="inline-block text-[9px] tracking-widest uppercase border border-zinc-200 dark:border-zinc-700 px-2 py-0.5 text-zinc-400">${highlight(t, q)}</span>`
                    ).join('');
                    return `
                    <a href="post.php?id=${p.id}" class="flex items-start gap-4 py-5 border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-900 px-3 transition group">
                        ${p.cover_url
                            ? `<img src="${p.cover_url}" class="w-20 h-14 object-cover flex-shrink-0 transition-transform duration-300 group-hover:scale-105">`
                            : `<div class="w-20 h-14 bg-zinc-100 dark:bg-zinc-800 flex-shrink-0"></div>`
                        }
                        <div class="min-w-0">
                            <div class="flex flex-wrap gap-1.5 mb-2">${tagHtml}</div>
                            <p class="serif-cn text-base leading-snug mb-1">${highlight(p.title, q)}</p>
                            <p class="text-[11px] text-zinc-400 font-light leading-relaxed line-clamp-2">${highlight(summary, q)}${summary.length >= 80 ? '…' : ''}</p>
                            <p class="text-[10px] text-zinc-300 dark:text-zinc-600 mt-1.5 tracking-widest">${p.published_at ? p.published_at.substring(0,10).replace(/-/g,'.') : ''}</p>
                        </div>
                    </a>`;
                }).join('');
                // 结果头部
                searchResults.insertAdjacentHTML('afterbegin',
                    `<p class="text-[10px] tracking-widest text-zinc-300 dark:text-zinc-600 uppercase px-3 pb-3">找到 ${data.length} 篇相关文章，按匹配度排列</p>`
                );
            } catch(e) {
                searchResults.innerHTML = '<p class="text-zinc-400 text-sm text-center py-8">搜索出错，请稍后重试</p>';
            }
        }

        function clearSearch() {
            searchResults.innerHTML = '';
            searchResults.classList.add('hidden');
            searchHints.classList.remove('hidden');
            searchInput.value = '';
        }

        const observer = new IntersectionObserver(entries => {
            entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('active'); });
        }, { threshold: 0.1 });
        document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

        // ---- 深色 / 浅色模式 ----
        const html       = document.documentElement;
        const themeBtn   = document.getElementById('themeToggle');
        const iconSun    = document.getElementById('iconSun');
        const iconMoon   = document.getElementById('iconMoon');

        function applyTheme(dark) {
            if (dark) {
                html.classList.add('dark');
                document.body.style.backgroundColor = '#0f0f0f';
                document.body.style.color = '#e5e5e5';
                iconSun.classList.remove('hidden');
                iconMoon.classList.add('hidden');
            } else {
                html.classList.remove('dark');
                document.body.style.backgroundColor = '#fff';
                document.body.style.color = '#1a1a1a';
                iconSun.classList.add('hidden');
                iconMoon.classList.remove('hidden');
            }
        }

        // 读取本地存储或系统偏好
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(savedTheme === 'dark' || (!savedTheme && prefersDark));

        themeBtn.addEventListener('click', () => {
            const isDark = html.classList.contains('dark');
            applyTheme(!isDark);
            localStorage.setItem('theme', !isDark ? 'dark' : 'light');
        });

        // ---- 下滑提示：滚动后淡出隐藏 ----
        const scrollHint = document.getElementById('scrollHint');
        if (scrollHint) {
            let hintHidden = false;
            window.addEventListener('scroll', () => {
                if (!hintHidden && window.scrollY > 60) {
                    hintHidden = true;
                    scrollHint.style.opacity = '0';
                    scrollHint.style.pointerEvents = 'none';
                }
            }, { passive: true });
        }
    </script>
</body>
</html>
