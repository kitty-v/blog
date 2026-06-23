<?php
// =========================================
// post.php  —  文章详情页
// =========================================
require_once __DIR__ . '/includes/db.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$pdo = db();

// 读取站点设置
function get_post_site_settings(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT `key`,`value` FROM site_settings")->fetchAll();
        $map  = [];
        foreach ($rows as $r) $map[$r['key']] = $r['value'];
        return $map;
    } catch (Exception $e) { return []; }
}
$_psite = get_post_site_settings($pdo);
$_pss   = function(string $k, string $default = '') use ($_psite) { return $_psite[$k] ?? $default; };
$stmt = $pdo->prepare(
    'SELECT p.*, c.name AS category_name, col.title AS collection_title
     FROM posts p
     LEFT JOIN categories  c   ON c.id  = p.category_id
     LEFT JOIN collections col ON col.id = p.collection_id
     WHERE p.id = ? AND p.is_published = 1'
);
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) { http_response_code(404); echo '文章不存在'; exit; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_date(string $d): string { return date('Y.m.d', strtotime($d)); }
?>
<!DOCTYPE html>
<html lang="zh-CN" class="overflow-x-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= h($post['title']) ?> | <?= htmlspecialchars($_pss('site_theme_name', '昨夜书'), ENT_QUOTES, 'UTF-8') ?></title>
    <?php if (!empty($_psite['site_favicon_url'])): ?>
    <link rel="icon" href="<?= htmlspecialchars($_psite['site_favicon_url'], ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@200;400;700&family=Noto+Serif+SC:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans SC', sans-serif; background-color: #fff; color: #1a1a1a; transition: background-color 0.4s ease, color 0.4s ease; }
        html.dark body { background-color: #0f0f0f; color: #e5e5e5; }
        html.dark { background-color: #0f0f0f; }
        .serif-cn { font-family: 'Noto Serif SC', serif; }
        .prose { line-height: 2; }
        .prose p { margin-bottom: 1.5rem; color: #444; font-weight: 300; }
        html.dark .prose p { color: #aaa; }
        .prose h2 { font-family: 'Noto Serif SC', serif; font-size: 1.5rem; margin: 2.5rem 0 1rem; }
        html.dark .prose h2 { color: #e5e5e5; }
        .prose blockquote { border-left: 2px solid #e4e4e7; padding-left: 1.5rem; color: #71717a; margin: 2rem 0; }
        html.dark .prose blockquote { border-color: #333; color: #888; }
        .prose code { font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.82em; background: #f4f4f5; color: #e11d48; padding: 2px 6px; border-radius: 3px; }
        html.dark .prose code { background: #1e1e1e; color: #f472b6; }
        .prose pre { background: #18181b; color: #e4e4e7; padding: 1.5rem; border-radius: 4px; overflow-x: auto; margin: 2rem 0; line-height: 1.7; }
        .prose pre code { background: none; color: inherit; padding: 0; font-size: 0.85em; }
        html.dark .prose pre { background: #0a0a0a; border: 1px solid #2a2a2a; }
        .prose ul { list-style: none; padding: 0; margin: 1.5rem 0; }
        .prose ul li { padding-left: 1.2rem; position: relative; margin-bottom: 0.5rem; color: #444; font-weight: 300; }
        .prose ul li::before { content: '—'; position: absolute; left: 0; color: #d4d4d8; }
        html.dark .prose ul li { color: #aaa; }
        html.dark .prose ul li::before { color: #3f3f46; }
        .prose ol { padding-left: 1.5rem; margin: 1.5rem 0; }
        .prose ol li { margin-bottom: 0.5rem; color: #444; font-weight: 300; }
        html.dark .prose ol li { color: #aaa; }
        .prose hr { border: none; border-top: 1px solid #e4e4e7; margin: 3rem 0; }
        html.dark .prose hr { border-color: #2a2a2a; }
        .prose h3 { font-family: 'Noto Serif SC', serif; font-size: 1.15rem; margin: 2rem 0 0.75rem; }
        html.dark .prose h3 { color: #e5e5e5; }
        /* Nav dark */
        html.dark .post-nav { background-color: #0f0f0f; border-color: #1a1a1a; }
        html.dark .post-nav a { color: #e5e5e5; }
        html.dark .post-nav .back-link { color: #888; }
        html.dark .post-nav .back-link:hover { color: #e5e5e5; }
        /* Summary dark */
        html.dark .summary-block { border-color: #2a2a2a; color: #888; }
        /* Cover dark */
        html.dark .cover-bg { background-color: #1a1a1a; }
        /* Footer dark override */
        html.dark .post-footer { background-color: #050505; }
        /* Theme toggle */
        .theme-btn { transition: opacity 0.2s; }
        .theme-btn:hover { opacity: 0.5; }
    </style>
    <script>
        // 在页面渲染前立即应用主题，避免闪白
        (function() {
            var saved = localStorage.getItem('theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (saved === 'dark' || (!saved && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>
<body class="selection:bg-zinc-100">

    <!-- 导航 -->
    <nav class="post-nav fixed top-0 w-full flex justify-between items-center px-6 md:px-16 py-5 md:py-8 z-50 bg-white border-b border-zinc-50 transition-colors duration-400">
        <a href="index.php" class="text-2xl md:text-3xl font-bold tracking-tighter serif-cn italic text-zinc-900"><?= htmlspecialchars($_pss('site_theme_name', '昨夜书'), ENT_QUOTES, 'UTF-8') ?></a>
        <div class="flex items-center space-x-6">
            <!-- 深色模式切换 -->
            <button id="themeToggle" class="theme-btn p-2 text-zinc-400" aria-label="切换主题">
                <svg id="iconSun" class="w-5 h-5 md:w-6 md:h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z"/></svg>
                <svg id="iconMoon" class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z"/></svg>
            </button>
            <a href="index.php" class="back-link text-[10px] tracking-widest uppercase text-zinc-400 hover:text-zinc-900 transition">返回首页</a>
        </div>
    </nav>

    <!-- 文章主体 -->
    <article class="pt-32 pb-32 px-6 md:px-16">
        <div class="max-w-2xl mx-auto">

            <!-- 元信息 -->
            <div class="flex items-center space-x-3 text-[10px] tracking-widest text-zinc-400 uppercase mb-8">
                <span><?= h(fmt_date($post['published_at'])) ?></span>
                <?php if ($post['category_name']): ?>
                <span class="w-4 h-[1px] bg-zinc-200"></span>
                <span><?= h($post['category_name']) ?></span>
                <?php endif; ?>
                <?php if ($post['collection_title']): ?>
                <span class="w-4 h-[1px] bg-zinc-200"></span>
                <span><?= h($post['collection_title']) ?></span>
                <?php endif; ?>
            </div>

            <!-- 标题 -->
            <h1 class="serif-cn text-4xl md:text-5xl leading-tight mb-10">
                <?= h($post['title']) ?>
            </h1>

            <!-- 摘要 -->
            <?php if ($post['summary']): ?>
            <p class="text-zinc-500 text-base font-light leading-relaxed border-l-2 border-zinc-100 pl-6 mb-12">
                <?= h($post['summary']) ?>
            </p>
            <?php endif; ?>

            <!-- 封面 -->
            <?php if ($post['cover_url']): ?>
            <div class="mb-12 aspect-[16/9] overflow-hidden bg-zinc-100">
                <img src="<?= h($post['cover_url']) ?>" alt="<?= h($post['title']) ?>"
                     class="w-full h-full object-cover">
            </div>
            <?php endif; ?>

            <!-- 正文 -->
            <div class="prose text-sm md:text-base">
                <?php if ($post['content']): ?>
                    <?= $post['content'] /* 若正文存储为HTML则直接输出；若为Markdown需安装parsedown */ ?>
                <?php else: ?>
                    <p class="text-zinc-400 italic">（文章正文暂未录入）</p>
                <?php endif; ?>
            </div>

        </div>
    </article>

    <!-- 底部
    <footer class="post-footer bg-zinc-950 text-white px-6 md:px-16 py-16 text-center transition-colors duration-400">
        <a href="index.php" class="serif-cn text-2xl italic text-zinc-400 hover:text-white transition">返回首页</a>
    </footer> -->

    <script>
        const html      = document.documentElement;
        const themeBtn  = document.getElementById('themeToggle');
        const iconSun   = document.getElementById('iconSun');
        const iconMoon  = document.getElementById('iconMoon');
        const postNav   = document.querySelector('.post-nav');

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

        const savedTheme  = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(savedTheme === 'dark' || (!savedTheme && prefersDark));

        themeBtn.addEventListener('click', () => {
            const isDark = html.classList.contains('dark');
            applyTheme(!isDark);
            localStorage.setItem('theme', !isDark ? 'dark' : 'light');
        });

    </script>
</body>
</html>
