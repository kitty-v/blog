<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

$pdo = db();
$total_posts       = (int)$pdo->query('SELECT COUNT(*) FROM posts WHERE is_published=1')->fetchColumn();
$total_drafts      = (int)$pdo->query('SELECT COUNT(*) FROM posts WHERE is_published=0')->fetchColumn();
$total_collections = (int)$pdo->query('SELECT COUNT(*) FROM collections')->fetchColumn();
$total_categories  = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();

$recent = $pdo->query(
    'SELECT p.id, p.title, p.published_at, p.is_published, c.name AS cat
     FROM posts p LEFT JOIN categories c ON c.id=p.category_id
     ORDER BY p.created_at DESC LIMIT 8'
)->fetchAll();

$page_title = 'Dashboard';
require '_layout.php';
?>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-8">
<?php
$stats = [
    ['label'=>'已发布', 'value'=>$total_posts,       'href'=>'posts.php',        'dark'=>true],
    ['label'=>'草稿箱', 'value'=>$total_drafts,      'href'=>'posts.php?draft=1','dark'=>false],
    ['label'=>'专题数', 'value'=>$total_collections, 'href'=>'collections.php',  'dark'=>false],
    ['label'=>'分类数', 'value'=>$total_categories,  'href'=>'categories.php',   'dark'=>false],
];
foreach ($stats as $s): ?>
<a href="<?= $s['href'] ?>"
   class="<?= $s['dark'] ? 'bg-zinc-900 text-white' : 'bg-white text-zinc-800 border border-zinc-200' ?> p-5 block hover:opacity-75 transition-opacity">
  <p class="text-3xl font-light mb-1 <?= $s['dark'] ? '' : 'text-zinc-900' ?>"><?= $s['value'] ?></p>
  <p class="text-[10px] tracking-[.2em] uppercase <?= $s['dark'] ? 'text-white/40' : 'text-zinc-400' ?>"><?= $s['label'] ?></p>
</a>
<?php endforeach; ?>
</div>

<!-- Quick actions -->
<div class="flex flex-wrap gap-3 mb-8">
  <a href="post_edit.php" class="btn-primary">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    写新文章
  </a>
  <a href="collections.php" class="btn-outline">新建专题</a>
</div>

<!-- Recent posts -->
<div class="bg-white border border-zinc-200">
  <div class="px-5 py-4 border-b border-zinc-100 flex items-center justify-between">
    <p class="text-[11px] font-medium tracking-[.15em] uppercase text-zinc-500">最近文章</p>
    <a href="posts.php" class="text-[11px] text-zinc-400 hover:text-zinc-900 tracking-widest uppercase transition-colors">查看全部 →</a>
  </div>

  <!-- Mobile cards -->
  <div class="divide-y divide-zinc-100 lg:hidden">
    <?php if (empty($recent)): ?>
    <p class="px-5 py-10 text-sm text-zinc-300 text-center">暂无文章。</p>
    <?php endif; ?>
    <?php foreach ($recent as $p): ?>
    <div class="px-5 py-4">
      <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-light text-zinc-800 leading-snug flex-1"><?= htmlspecialchars($p['title']) ?></p>
        <span class="badge <?= $p['is_published'] ? 'badge-green' : 'badge-gray' ?> flex-shrink-0">
          <?= $p['is_published'] ? '已发布' : '草稿' ?>
        </span>
      </div>
      <div class="flex items-center gap-3 mt-2">
        <span class="text-[11px] text-zinc-400"><?= $p['cat'] ?? '—' ?></span>
        <span class="text-zinc-200">·</span>
        <span class="text-[11px] text-zinc-400"><?= $p['published_at'] ?? '—' ?></span>
        <a href="post_edit.php?id=<?= $p['id'] ?>" class="ml-auto text-[11px] text-zinc-400 hover:text-zinc-900 uppercase tracking-widest">编辑</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Desktop table -->
  <div class="hidden lg:block overflow-x-auto">
    <table class="data-table">
      <thead>
        <tr>
          <th>标题</th>
          <th>分类</th>
          <th>日期</th>
          <th>状态</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recent)): ?>
        <tr><td colspan="5" class="text-center text-zinc-300 py-10">暂无文章。</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $p): ?>
        <tr>
          <td class="font-light max-w-xs"><span class="truncate block"><?= htmlspecialchars($p['title']) ?></span></td>
          <td class="text-zinc-400 text-xs"><?= htmlspecialchars($p['cat'] ?? '—') ?></td>
          <td class="text-zinc-400 text-xs"><?= $p['published_at'] ?? '—' ?></td>
          <td><span class="badge <?= $p['is_published'] ? 'badge-green' : 'badge-gray' ?>"><?= $p['is_published'] ? '已发布' : '草稿' ?></span></td>
          <td class="text-right"><a href="post_edit.php?id=<?= $p['id'] ?>" class="text-xs text-zinc-400 hover:text-zinc-900 uppercase tracking-wider transition-colors">编辑</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require '_layout_end.php'; ?>
