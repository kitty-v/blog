<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

$pdo = db();
$errors = [];
$edit   = null;

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([(int)$_GET['delete']]);
    header('Location: categories.php?msg=deleted'); exit;
}
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM categories WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id         = (int)($_POST['id'] ?? 0);
    $name       = trim($_POST['name'] ?? '');
    $slug       = trim($_POST['slug'] ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    if (!$name) $errors[] = '名称为必填项。';
    if (!$slug) $errors[] = 'Slug为必填项。';
    if ($slug && !preg_match('/^[a-z0-9\-]+$/', $slug)) $errors[] = 'Slug只能包含小写字母、数字和连字符。';
    if (empty($errors)) {
        try {
            if ($id) {
                $pdo->prepare('UPDATE categories SET name=?,slug=?,sort_order=? WHERE id=?')
                    ->execute([$name,$slug,$sort_order,$id]);
            } else {
                $pdo->prepare('INSERT INTO categories (name,slug,sort_order) VALUES (?,?,?)')
                    ->execute([$name,$slug,$sort_order]);
            }
            header('Location: categories.php?msg=saved'); exit;;
        } catch (PDOException $e) {
            $errors[] = 'Slug已存在，请换一个。';
        }
    }
    $edit = compact('id','name','slug','sort_order');
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC')->fetchAll();
$page_title = 'Categories';
require '_layout.php';
function hcat(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert-success"><?= $_GET['msg'] === 'deleted' ? '分类已删除。' : '保存成功。' ?></div>
<?php endif; ?>
<?php if ($errors): ?>
<div class="alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<div class="flex flex-col lg:flex-row gap-6">

  <!-- Form -->
  <div class="lg:w-80 flex-shrink-0">
    <div class="bg-white border border-zinc-200 p-6">
      <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-5"><?= $edit ? '编辑分类' : '新建分类' ?></p>
      <form method="POST" class="space-y-4">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div>
          <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-1.5">名称 *</label>
          <input type="text" name="name" value="<?= hcat($edit['name'] ?? '') ?>" required class="field">
        </div>
        <div>
          <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-1.5">
            Slug <span class="normal-case text-zinc-300">(URL标识符)</span>
          </label>
          <input type="text" name="slug" id="slug_input"
                 value="<?= hcat($edit['slug'] ?? '') ?>" required
                 pattern="[a-z0-9\-]+" class="field font-mono text-xs"
                 placeholder="例如：design-thinking">
          <p class="text-[10px] text-zinc-400 mt-1">仅支持小写字母、数字和连字符。</p>
        </div>
        <div>
          <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-1.5">排列顺序</label>
          <input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>" class="field">
        </div>
        <div class="flex gap-2 pt-1">
          <button type="submit" class="btn-primary flex-1 justify-center"><?= $edit ? '保存' : '创建' ?></button>
          <?php if ($edit): ?>
          <a href="categories.php" class="btn-outline">取消</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- List -->
  <div class="flex-1 min-w-0">
    <!-- Mobile -->
    <div class="bg-white border border-zinc-200 divide-y divide-zinc-100 lg:hidden">
      <?php if (empty($categories)): ?>
      <p class="px-5 py-10 text-sm text-zinc-300 text-center">暂无分类。</p>
      <?php endif; ?>
      <?php foreach ($categories as $c):
        $cnt = (int)$pdo->prepare('SELECT COUNT(*) FROM posts WHERE category_id=?')->execute([$c['id']]) ? $pdo->query('SELECT COUNT(*) FROM posts WHERE category_id=' . $c['id'])->fetchColumn() : 0;
      ?>
      <div class="px-4 py-4">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-sm font-light"><?= hcat($c['name']) ?></p>
            <p class="text-[11px] text-zinc-400 font-mono mt-0.5"><?= hcat($c['slug']) ?></p>
          </div>
          <span class="text-[11px] text-zinc-400"><?= $cnt ?> posts</span>
        </div>
        <div class="flex gap-4 mt-2">
          <a href="categories.php?edit=<?= $c['id'] ?>" class="text-[11px] uppercase tracking-wider text-zinc-400 hover:text-zinc-900">编辑</a>
          <a href="categories.php?delete=<?= $c['id'] ?>" onclick="return confirm('确定删除该分类？文章将变为未分类。')"
             class="text-[11px] uppercase tracking-wider text-red-400 hover:text-red-600">删除</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Desktop -->
    <div class="hidden lg:block bg-white border border-zinc-200 overflow-hidden">
      <table class="data-table">
        <thead><tr><th>名称</th><th>Slug</th><th>文章数</th><th>排序</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($categories)): ?>
          <tr><td colspan="5" class="text-center text-zinc-300 py-10">暂无分类。</td></tr>
          <?php endif; ?>
          <?php foreach ($categories as $c):
            $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE category_id=?');
            $stmt2->execute([$c['id']]);
            $cnt = (int)$stmt2->fetchColumn();
          ?>
          <tr>
            <td class="font-light"><?= hcat($c['name']) ?></td>
            <td class="font-mono text-xs text-zinc-400"><?= hcat($c['slug']) ?></td>
            <td class="text-zinc-400"><?= $cnt ?></td>
            <td class="text-zinc-400"><?= $c['sort_order'] ?></td>
            <td class="text-right whitespace-nowrap">
              <div class="flex items-center justify-end gap-4">
                <a href="categories.php?edit=<?= $c['id'] ?>" class="text-xs text-zinc-400 hover:text-zinc-900 uppercase tracking-wider">编辑</a>
                <a href="categories.php?delete=<?= $c['id'] ?>" onclick="return confirm('确定删除该分类？文章将变为未分类。')"
                   class="text-xs text-red-400 hover:text-red-600 uppercase tracking-wider">删除</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
// Auto-generate slug from name (only when creating new)
<?php if (!$edit): ?>
document.querySelector('[name="name"]').addEventListener('input', function() {
  const slug = this.value.toLowerCase()
    .replace(/[\u4e00-\u9fa5]/g, '')
    .replace(/[^a-z0-9\s-]/g, '')
    .trim().replace(/\s+/g, '-');
  document.getElementById('slug_input').value = slug;
});
<?php endif; ?>
</script>

<?php require '_layout_end.php'; ?>
