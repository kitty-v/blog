<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

$pdo = db();

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $pdo->prepare('DELETE FROM posts WHERE id=?')->execute([(int)$_GET['delete']]);
    header('Location: posts.php?msg=deleted'); exit;
}
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $pdo->prepare('UPDATE posts SET is_published = 1 - is_published WHERE id=?')->execute([(int)$_GET['toggle']]);
    header('Location: posts.php?msg=toggled'); exit;
}

$per_page = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$draft    = isset($_GET['draft']);
$where    = $draft ? 'WHERE p.is_published=0' : '';

$total       = (int)$pdo->query("SELECT COUNT(*) FROM posts p $where")->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$stmt = $pdo->prepare("SELECT p.id,p.title,p.published_at,p.is_published,c.name AS cat FROM posts p LEFT JOIN categories c ON c.id=p.category_id $where ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$per_page, $offset]);
$posts = $stmt->fetchAll();

$page_title = $draft ? '草稿箱' : '全部文章';
require '_layout.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert-success"><?= $_GET['msg'] === 'deleted' ? '文章已删除。' : '状态已更新。' ?></div>
<?php endif; ?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <div class="flex gap-2">
    <a href="posts.php"         class="btn-<?= !$draft ? 'primary' : 'outline' ?>">全部 <span class="opacity-50">(<?= $draft ? $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn() : $total ?>)</span></a>
    <a href="posts.php?draft=1" class="btn-<?= $draft  ? 'primary' : 'outline' ?>">草稿</a>
  </div>
  <a href="post_edit.php" class="btn-primary">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    写新文章
  </a>
</div>

<!-- Mobile cards -->
<div class="bg-white border border-zinc-200 divide-y divide-zinc-100 lg:hidden">
  <?php if (empty($posts)): ?>
  <p class="px-5 py-12 text-sm text-zinc-300 text-center">暂无文章。</p>
  <?php endif; ?>
  <?php foreach ($posts as $p): ?>
  <div class="px-4 py-4">
    <div class="flex items-start gap-3 mb-2">
      <p class="flex-1 text-sm font-light leading-snug"><?= htmlspecialchars($p['title']) ?></p>
      <a href="posts.php?toggle=<?= $p['id'] ?>" title="Toggle status">
        <span class="badge <?= $p['is_published'] ? 'badge-green' : 'badge-gray' ?> cursor-pointer">
          <?= $p['is_published'] ? '已发布' : '草稿' ?>
        </span>
      </a>
    </div>
    <div class="flex items-center gap-3 text-[11px] text-zinc-400">
      <span><?= htmlspecialchars($p['cat'] ?? '—') ?></span>
      <span class="text-zinc-200">·</span>
      <span><?= $p['published_at'] ?? '—' ?></span>
      <div class="ml-auto flex gap-4">
        <a href="post_edit.php?id=<?= $p['id'] ?>" class="uppercase tracking-wider hover:text-zinc-900 transition-colors">编辑</a>
        <a href="posts.php?delete=<?= $p['id'] ?>" onclick="return confirm('确定删除这篇文章？此操作不可撤销。')"
           class="uppercase tracking-wider text-red-400 hover:text-red-600 transition-colors">删除</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Desktop table -->
<div class="hidden lg:block bg-white border border-zinc-200 overflow-hidden">
  <table class="data-table">
    <thead>
      <tr>
        <th>标题</th>
        <th>分类</th>
        <th>发布日期</th>
        <th>状态</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($posts)): ?>
      <tr><td colspan="5" class="text-center text-zinc-300 py-12">暂无文章。</td></tr>
      <?php endif; ?>
      <?php foreach ($posts as $p): ?>
      <tr>
        <td class="font-light"><span class="block max-w-sm truncate"><?= htmlspecialchars($p['title']) ?></span></td>
        <td class="text-zinc-400 text-xs"><?= htmlspecialchars($p['cat'] ?? '—') ?></td>
        <td class="text-zinc-400 text-xs"><?= $p['published_at'] ?? '—' ?></td>
        <td>
          <a href="posts.php?toggle=<?= $p['id'] ?>" title="Click to toggle">
            <span class="badge <?= $p['is_published'] ? 'badge-green' : 'badge-gray' ?> cursor-pointer">
              <?= $p['is_published'] ? '已发布' : '草稿' ?>
            </span>
          </a>
        </td>
        <td class="text-right whitespace-nowrap">
          <div class="flex items-center justify-end gap-4">
            <a href="post_edit.php?id=<?= $p['id'] ?>" class="text-xs text-zinc-400 hover:text-zinc-900 uppercase tracking-wider transition-colors">编辑</a>
            <a href="posts.php?delete=<?= $p['id'] ?>" onclick="return confirm('确定删除这篇文章？此操作不可撤销。')"
               class="text-xs text-red-400 hover:text-red-600 uppercase tracking-wider transition-colors">删除</a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="flex items-center gap-1 mt-5">
  <?php for ($i = 1; $i <= $total_pages; $i++): ?>
  <a href="?page=<?= $i ?><?= $draft ? '&draft=1' : '' ?>"
     class="w-8 h-8 flex items-center justify-center text-xs border transition-colors
            <?= $i === $page ? 'bg-zinc-900 text-white border-zinc-900' : 'border-zinc-200 text-zinc-500 hover:border-zinc-400' ?>">
    <?= $i ?>
  </a>
  <?php endfor; ?>
  <span class="ml-3 text-[11px] text-zinc-400"><?= $total ?> 篇文章</span>
</div>
<?php endif; ?>

<?php require '_layout_end.php'; ?>
