<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

$pdo    = db();
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$post   = null;
$errors = [];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM posts WHERE id=?');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) { header('Location: posts.php'); exit; }
}

$categories  = $pdo->query('SELECT id, name FROM categories ORDER BY sort_order')->fetchAll();
$collections = $pdo->query('SELECT id, title FROM collections ORDER BY sort_order')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'title'         => trim($_POST['title'] ?? ''),
        'summary'       => trim($_POST['summary'] ?? ''),
        'content'       => $_POST['content'] ?? '',
        'cover_url'     => trim($_POST['cover_url'] ?? ''),
        'category_id'   => ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null,
        'collection_id' => ($_POST['collection_id'] ?? '') !== '' ? (int)$_POST['collection_id'] : null,
        'published_at'  => $_POST['published_at'] ?: date('Y-m-d'),
        'is_published'  => isset($_POST['is_published']) ? 1 : 0,
    ];
    if (!$data['title']) $errors[] = '标题为必填项。';
    if (empty($errors)) {
        if ($id) {
            $params = array_values($data);
            $params[] = $id;
            $pdo->prepare('UPDATE posts SET title=?,summary=?,content=?,cover_url=?,category_id=?,collection_id=?,published_at=?,is_published=?,updated_at=NOW() WHERE id=?')
                ->execute($params);
        } else {
            $pdo->prepare('INSERT INTO posts (title,summary,content,cover_url,category_id,collection_id,published_at,is_published) VALUES (?,?,?,?,?,?,?,?)')
                ->execute(array_values($data));
            $id = (int)$pdo->lastInsertId();
        }
        header('Location: post_edit.php?id=' . $id . '&msg=saved'); exit;
    }
    $post = array_merge($post ?? [], $data);
}

$page_title = $id ? '编辑文章' : '写新文章';
$v = function(string $k) use ($post): string {
    return htmlspecialchars($post[$k] ?? '', ENT_QUOTES, 'UTF-8');
};

// 扫描 uploads/picture/ 供图片选择器使用
$_pic_dir  = realpath(__DIR__ . '/../uploads/picture');
$_pic_exts = ['jpg','jpeg','png','gif','webp','avif','bmp'];
$_pic_list = [];
if ($_pic_dir && is_dir($_pic_dir)) {
    // SCRIPT_NAME 推算项目根 URL，比 DOCUMENT_ROOT 字符串减法更可靠
    $_script_url = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base_url    = rtrim(dirname(dirname($_script_url)), '/');
    if ($base_url === '/') $base_url = ''; // 部署在域名根时归零
    foreach (scandir($_pic_dir) as $_pf) {
        if ($_pf === '.' || $_pf === '..') continue;
        $_full = $_pic_dir . DIRECTORY_SEPARATOR . $_pf;
        if (!is_file($_full)) continue;
        $_ext = strtolower(pathinfo($_pf, PATHINFO_EXTENSION));
        if (!in_array($_ext, $_pic_exts)) continue;
        $_pic_list[] = ['name' => $_pf, 'url' => $base_url . '/uploads/picture/' . rawurlencode($_pf), 'mtime' => filemtime($_full)];
    }
    usort($_pic_list, fn($a,$b) => $b['mtime'] - $a['mtime']);
}
$_pic_json = json_encode($_pic_list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

require '_layout.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert-success flex items-center gap-2">
  <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
  文章保存成功。
  <?php if ($id): ?>
  <a href="../post.php?id=<?= $id ?>" target="_blank" class="ml-auto text-[11px] underline underline-offset-2 opacity-70">查看文章 ↗</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<form method="POST" action="post_edit.php<?= $id ? '?id=' . $id : '' ?>">

  <!-- Two-column layout on desktop -->
  <div class="flex flex-col lg:flex-row gap-6">

    <!-- Left: main content -->
    <div class="flex-1 space-y-5 min-w-0">

      <!-- Title -->
      <div>
        <label class="block text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-1.5">标题 *</label>
        <input type="text" name="title" value="<?= $v('title') ?>" required
               class="field text-base" placeholder="输入文章标题…">
      </div>

      <!-- Summary -->
      <div>
        <label class="block text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-1.5">摘要 <span class="normal-case text-zinc-300">(显示在首页)</span></label>
        <textarea name="summary" rows="3" class="field resize-none"
                  placeholder="文章简短描述…"><?= $v('summary') ?></textarea>
      </div>

      <!-- Content -->
      <div>
        <div class="flex items-center justify-between mb-1.5">
          <label class="block text-[10px] tracking-[.25em] uppercase text-zinc-400">正文内容 <span class="normal-case text-zinc-300">(HTML格式)</span></label>
          <div class="flex gap-1">
            <?php
            $btns = [
              ['B', '<strong>','</strong>','font-bold'],
              ['I', '<em>','</em>','italic'],
              ['H2','<h2>','</h2>',''],
              ['P', '<p>','</p>',''],
              ['"', '<blockquote>','</blockquote>',''],
            ];
            foreach ($btns as [$lbl,$op,$cl,$cls]): ?>
            <button type="button" onclick="wrapTag('<?= addslashes($op) ?>','<?= addslashes($cl) ?>')"
                    class="px-2 py-1 text-[11px] border border-zinc-200 bg-white hover:bg-zinc-50 text-zinc-500 transition-colors <?= $cls ?>"><?= $lbl ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <textarea id="content" name="content" rows="20"
                  class="field font-mono text-xs resize-y"
                  placeholder="用HTML编写文章正文…"><?= $v('content') ?></textarea>
        <p class="mt-1 text-[10px] text-zinc-400">支持HTML。选中文字后点击工具栏按钮可快速包裹标签。</p>
      </div>

    </div>

    <!-- Right: sidebar meta -->
    <div class="lg:w-72 space-y-5 flex-shrink-0">

      <!-- Publish box -->
      <div class="bg-white border border-zinc-200 p-5">
        <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-4">发布设置</p>

        <label class="flex items-center gap-3 cursor-pointer mb-5">
          <div class="relative">
            <input type="checkbox" name="is_published" value="1" id="pub_toggle"
                   <?= ($post['is_published'] ?? 1) ? 'checked' : '' ?> class="sr-only peer">
            <div class="w-9 h-5 bg-zinc-200 peer-checked:bg-zinc-900 rounded-full transition-colors"></div>
            <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
          </div>
          <span class="text-sm text-zinc-600" id="pub_label"><?= ($post['is_published'] ?? 1) ? '已发布' : '草稿' ?></span>
        </label>

        <div class="flex gap-2">
          <a href="posts.php" class="btn-outline flex-1 justify-center">取消</a>
          <button type="submit" class="btn-primary flex-1 justify-center">
            <?= $id ? '保存' : '发布' ?>
          </button>
        </div>
      </div>

      <!-- Cover image -->
      <div class="bg-white border border-zinc-200 p-5">
        <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-3">封面图片</p>

        <!-- 标签切换：上传 / URL / 预览 -->
        <div class="flex border border-zinc-200 mb-4 text-[11px] tracking-widest uppercase">
          <button type="button" id="tabUpload"
                  onclick="switchTab('upload')"
                  class="flex-1 py-2 transition-colors bg-zinc-900 text-white">本地上传</button>
          <button type="button" id="tabUrl"
                  onclick="switchTab('url')"
                  class="flex-1 py-2 transition-colors text-zinc-400 hover:text-zinc-700">URL</button>
          <button type="button" id="tabPicker"
                  onclick="switchTab('picker')"
                  class="flex-1 py-2 transition-colors text-zinc-400 hover:text-zinc-700">预览</button>
        </div>

        <!-- 上传区 -->
        <div id="panelUpload">
          <div id="dropzone"
               class="border-2 border-dashed border-zinc-200 rounded text-center py-8 cursor-pointer hover:border-zinc-400 transition-colors relative"
               onclick="document.getElementById('coverFileInput').click()">
            <svg class="w-8 h-8 mx-auto text-zinc-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            <p class="text-xs text-zinc-400">点击或拖拽图片到此处</p>
            <p class="text-[10px] text-zinc-300 mt-1">JPG / PNG / WEBP · 最大 10MB</p>
            <input type="file" id="coverFileInput" accept="image/*" class="hidden">
          </div>

          <!-- 裁剪区（选图后显示） -->
          <div id="cropArea" class="hidden mt-4">
            <div id="cropContainer" class="relative overflow-hidden bg-zinc-100 w-full" style="max-height:260px;touch-action:none">
              <img id="cropImg" src="" alt="" class="block" style="max-height:260px;width:100%;object-fit:contain;cursor:crosshair;user-select:none;-webkit-user-select:none">
              <!-- 裁剪遮罩 -->
              <div id="cropBox"
                   class="absolute border-2 border-white shadow-lg"
                   style="left:5%;top:5%;width:90%;height:90%;box-shadow:0 0 0 9999px rgba(0,0,0,.45);cursor:move">
                <!-- 角手柄 -->
                <div class="crop-handle absolute -top-1.5 -left-1.5  w-3 h-3 bg-white border border-zinc-300 cursor-nwse-resize" data-dir="nw"></div>
                <div class="crop-handle absolute -top-1.5 -right-1.5 w-3 h-3 bg-white border border-zinc-300 cursor-nesw-resize" data-dir="ne"></div>
                <div class="crop-handle absolute -bottom-1.5 -left-1.5  w-3 h-3 bg-white border border-zinc-300 cursor-nesw-resize" data-dir="sw"></div>
                <div class="crop-handle absolute -bottom-1.5 -right-1.5 w-3 h-3 bg-white border border-zinc-300 cursor-nwse-resize" data-dir="se"></div>
              </div>
            </div>

            <!-- 缩放滑块 -->
            <div class="mt-3 flex items-center gap-3">
              <span class="text-[10px] text-zinc-400 uppercase tracking-widest w-8">缩放</span>
              <input type="range" id="scaleSlider" min="10" max="100" value="100" class="flex-1 accent-zinc-900">
              <span id="scaleVal" class="text-[10px] text-zinc-400 w-8 text-right">100%</span>
            </div>

            <!-- 上传按钮 -->
            <button type="button" id="doCropUpload"
                    class="btn-primary w-full justify-center mt-3 text-xs">
              裁剪并上传
            </button>
          </div>

          <!-- 上传进度 -->
          <div id="uploadingMsg" class="hidden mt-3 space-y-2">
            <div class="flex justify-between text-[11px] text-zinc-400">
              <span class="tracking-widest">上传中…</span>
              <span id="coverUploadPct">0%</span>
            </div>
            <div class="w-full bg-zinc-100 h-1.5 overflow-hidden">
              <div id="coverUploadBar" class="h-full bg-zinc-900 transition-all duration-100" style="width:0%"></div>
            </div>
          </div>
        </div>

        <!-- URL 区 -->
        <div id="panelUrl" class="hidden">
          <input type="url" id="cover_url_manual"
                 placeholder="https://…"
                 class="field text-xs"
                 value="<?= $v('cover_url') ?>">
          <button type="button" onclick="applyManualUrl()" class="btn-outline w-full justify-center mt-2 text-xs">套用</button>
        </div>

        <!-- 服务器图片选择区 -->
        <div id="panelPicker" class="hidden">
          <div id="pickerGridInline" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:7px;max-height:300px;overflow-y:auto;padding:2px;"></div>
          <p id="pickerInlineFooter" style="font-size:10px;color:#a1a1aa;margin-top:6px;text-align:center;"></p>
        </div>
        <input type="hidden" name="cover_url" id="cover_url" value="<?= $v('cover_url') ?>">
        <div id="cover_preview" class="mt-4 <?= empty($post['cover_url']) ? 'hidden' : '' ?>">
          <div class="relative group">
            <?php if (!empty($post['cover_url'])): ?>
            <img id="cover_img" src="<?= $v('cover_url') ?>" class="w-full aspect-video object-cover" alt="" onerror="this.style.display='none';this.nextElementSibling.style.removeProperty('display');this.nextElementSibling.style.display='flex'">
            <div class="w-full aspect-video bg-zinc-100 items-center justify-center text-zinc-300 text-xs" style="display:none">图片加载失败</div>
            <?php else: ?>
            <img id="cover_img" src="" class="w-full aspect-video object-cover" alt="" style="display:none" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="w-full aspect-video bg-zinc-100 items-center justify-center text-zinc-300 text-xs" style="display:none">暂无封面</div>
            <?php endif; ?>
            <button type="button" onclick="clearCover()"
                    class="absolute top-2 right-2 bg-black/60 text-white text-[10px] px-2 py-1 opacity-0 group-hover:opacity-100 transition">✕ 移除</button>
          </div>
          <p id="cover_url_display" class="text-[10px] text-zinc-400 mt-1 truncate"><?= $v('cover_url') ?></p>
        </div>
      </div>

      <!-- Category & Collection -->
      <div class="bg-white border border-zinc-200 p-5 space-y-4">
        <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400">分类归属</p>
        <div>
          <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">分类</label>
          <select name="category_id" class="field">
            <option value="">— 无 —</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($post['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">专题</label>
          <select name="collection_id" class="field">
            <option value="">— 无 —</option>
            <?php foreach ($collections as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($post['collection_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['title']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Date -->
      <div class="bg-white border border-zinc-200 p-5">
        <label class="block text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-3">发布日期</label>
        <input type="date" name="published_at"
               value="<?= htmlspecialchars($post['published_at'] ?? date('Y-m-d')) ?>"
               class="field">
      </div>

    </div>
  </div>

</form>

<!-- pickerData 已内联到 JS 变量，无需此标签 -->

<script>
(function() { // IIFE — 防止 SPA 二次加载时 const 重复声明报错

// =============================================
// 修复表单提交 — 绕过SPA，用fetch直接POST
// =============================================
(function fixFormSubmit() {
  const form = document.querySelector('form[method="POST"]');
  if (!form) return;

  const submitBtn = form.querySelector('button[type="submit"]');
  if (!submitBtn) return;

  submitBtn.addEventListener('click', async function(e) {
    e.preventDefault();
    e.stopPropagation();

    const title = (form.querySelector('[name="title"]') || {}).value || '';
    if (!title.trim()) {
      alert('标题为必填项');
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = '保存中…';

    try {
      const fd = new FormData(form);
      const res = await fetch(form.action, {
        method: 'POST',
        body: fd,
        redirect: 'manual'
      });

      // opaqueredirect = 服务器执行了 header('Location:...')，即保存成功
      if (res.type === 'opaqueredirect' || res.status === 0) {
        location.reload();
        return;
      }

      const text = await res.text();
      if (res.status === 200 && (text.includes('文章保存成功') || text.includes('msg=saved'))) {
        location.reload();
      } else {
        alert('保存失败，请检查服务器日志。HTTP ' + res.status);
      }
    } catch(err) {
      alert('提交出错：' + err.message);
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = '保存';
    }
  });
})();

// =============================================
// 粘贴时自动将换行转换为 <br>（仅对纯文本生效，HTML内容直接粘贴）
// =============================================
(function setupPaste() {
  const ta = document.getElementById('content');
  if (!ta) return;
  ta.addEventListener('paste', function(e) {
    // 优先取纯文本
    const plain = e.clipboardData && e.clipboardData.getData('text/plain');
    if (!plain) return;

    // 如果文本本身已含HTML标签，直接放行（让浏览器默认处理）
    if (/<[a-z][\s\S]*>/i.test(plain)) return;

    // 纯文本：将换行转为 <br>，多个连续空行合并为段落间距
    e.preventDefault();
    const converted = plain
      .replace(/\r\n/g, '\n')       // 统一换行符
      .replace(/\r/g, '\n')
      .replace(/\n\n+/g, '</p><p>') // 空行 → 段落
      .replace(/\n/g, '<br>');      // 单换行 → <br>
    const html = '<p>' + converted + '</p>';

    // 插入到光标位置
    const start = ta.selectionStart;
    const end   = ta.selectionEnd;
    const before = ta.value.substring(0, start);
    const after  = ta.value.substring(end);
    ta.value = before + html + after;
    // 将光标移到插入内容末尾
    const pos = start + html.length;
    ta.selectionStart = ta.selectionEnd = pos;
    ta.dispatchEvent(new Event('input'));
  });
})();

// wrapTag 需要挂到全局供 onclick 属性调用
window.wrapTag = function wrapTag(open, close) {
  const ta = document.getElementById('content');
  const s = ta.selectionStart, e = ta.selectionEnd;
  const sel = ta.value.substring(s, e);
  ta.value = ta.value.substring(0, s) + open + sel + close + ta.value.substring(e);
  ta.focus();
  ta.selectionStart = s + open.length;
  ta.selectionEnd   = s + open.length + sel.length;
};

// Toggle label
const pubToggleEl = document.getElementById('pub_toggle');
if (pubToggleEl) {
  pubToggleEl.addEventListener('change', function() {
    document.getElementById('pub_label').textContent = this.checked ? '已发布' : '草稿';
  });
}

// =============================================
// 封面上传 + 裁剪
// =============================================
let cropState = { x:0.05, y:0.05, w:0.90, h:0.90, scale:1 };
let isDragging = false, isResizing = false, resizeDir = '';
let dragStart = {mx:0, my:0, bx:0, by:0, bw:0, bh:0};
let naturalW = 0, naturalH = 0;

const coverFileInput  = document.getElementById('coverFileInput');
const cropArea        = document.getElementById('cropArea');
const cropImg         = document.getElementById('cropImg');
const cropBox         = document.getElementById('cropBox');
const scaleSlider     = document.getElementById('scaleSlider');
const scaleVal        = document.getElementById('scaleVal');
const doCropUpload    = document.getElementById('doCropUpload');
const uploadingMsg    = document.getElementById('uploadingMsg');
const coverPreview    = document.getElementById('cover_preview');
const coverImg        = document.getElementById('cover_img');
const coverUrlField   = document.getElementById('cover_url');
const coverUrlDisplay = document.getElementById('cover_url_display');
const dropzone        = document.getElementById('dropzone');

// 内联图片数据（PHP 直接输出为 JS 变量，无 DOM 依赖）
const PICKER_PICS = <?= json_encode($_pic_list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
let _pickerRendered = false;

// Tab 切换
function switchTab(tab) {
  const isUpload = tab === 'upload';
  const isUrl    = tab === 'url';
  const isPicker = tab === 'picker';
  document.getElementById('panelUpload').classList.toggle('hidden', !isUpload);
  document.getElementById('panelUrl').classList.toggle('hidden',    !isUrl);
  document.getElementById('panelPicker').classList.toggle('hidden', !isPicker);
  const on  = 'flex-1 py-2 transition-colors bg-zinc-900 text-white';
  const off = 'flex-1 py-2 transition-colors text-zinc-400 hover:text-zinc-700';
  document.getElementById('tabUpload').className = isUpload ? on : off;
  document.getElementById('tabUrl').className    = isUrl    ? on : off;
  document.getElementById('tabPicker').className = isPicker ? on : off;
  if (isPicker && !_pickerRendered) { _pickerRendered = true; renderInlinePicker(); }
}

function renderInlinePicker() {
  const grid   = document.getElementById('pickerGridInline');
  const footer = document.getElementById('pickerInlineFooter');
  if (!PICKER_PICS.length) {
    grid.innerHTML = '<p style="grid-column:1/-1;color:#a1a1aa;font-size:12px;text-align:center;padding:30px 0;">uploads/picture/ 暂无图片<br><small>请先通过上传按钮上传图片</small></p>';
    return;
  }
  PICKER_PICS.forEach(function(f) {
    const btn = document.createElement('button');
    btn.type  = 'button';
    btn.title = f.name;
    btn.style.cssText = 'border:2px solid transparent;background:#f4f4f5;border-radius:3px;padding:0;cursor:pointer;overflow:hidden;transition:border-color .15s,transform .15s;aspect-ratio:1/1;display:flex;flex-direction:column;align-items:stretch;width:100%;';
    btn.innerHTML =
      '<img src="'+f.url+'" alt="'+f.name+'" style="width:100%;flex:1;object-fit:cover;min-height:0;display:block;" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">' +
      '<div style="display:none;flex:1;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#d4d4d8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>' +
      '<div style="padding:3px 4px;background:#fff;border-top:1px solid #e4e4e7;font-size:8px;color:#71717a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+f.name+'</div>';
    btn.addEventListener('mouseenter', function(){ this.style.borderColor='#18181b'; this.style.transform='scale(1.04)'; });
    btn.addEventListener('mouseleave', function(){ this.style.borderColor='transparent'; this.style.transform=''; });
    btn.addEventListener('click', function(){ setCover(f.url); switchTab('upload'); });
    grid.appendChild(btn);
  });
  footer.textContent = '共 ' + PICKER_PICS.length + ' 张 · 点击即可选用';
}

// 手动 URL 套用
function applyManualUrl() {
  const url = document.getElementById('cover_url_manual').value.trim();
  if (!url) return;
  setCover(url);
}

function clearCover() {
  coverUrlField.value = '';
  coverImg.src = '';
  coverPreview.classList.add('hidden');
  if (coverUrlDisplay) coverUrlDisplay.textContent = '';
}

function setCover(url) {
  coverUrlField.value = url;
  // 重置"暂无封面"占位 div（onerror 可能已将其设为 flex）
  const placeholder = coverImg.nextElementSibling;
  if (placeholder) placeholder.style.display = 'none';
  coverImg.style.display = '';
  coverImg.src = url;
  coverPreview.classList.remove('hidden');
  if (coverUrlDisplay) coverUrlDisplay.textContent = url;
}

// 文件选择 / 拖放
coverFileInput.addEventListener('change', e => { if (e.target.files[0]) loadCropImage(e.target.files[0]); });
dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('border-zinc-500'); });
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('border-zinc-500'));
dropzone.addEventListener('drop', e => {
  e.preventDefault();
  dropzone.classList.remove('border-zinc-500');
  const file = e.dataTransfer.files[0];
  if (file && file.type.startsWith('image/')) loadCropImage(file);
});

function loadCropImage(file) {
  const reader = new FileReader();
  reader.onload = ev => {
    cropImg.src = ev.target.result;
    cropImg.onload = () => {
      naturalW = cropImg.naturalWidth;
      naturalH = cropImg.naturalHeight;
      cropState = { x:0.05, y:0.05, w:0.90, h:0.90 };
      scaleSlider.value = 100;
      scaleVal.textContent = '100%';
      cropArea.classList.remove('hidden');
      dropzone.classList.add('hidden');
      // 等待布局稳定后再渲染裁剪框
      requestAnimationFrame(() => renderCropBox());
    };
  };
  reader.readAsDataURL(file);
  // 保存文件引用供后续上传
  coverFileInput._pendingFile = file;
}

function renderCropBox() {
  const iw = cropImg.offsetWidth, ih = cropImg.offsetHeight;
  cropBox.style.left   = (cropState.x * iw) + 'px';
  cropBox.style.top    = (cropState.y * ih) + 'px';
  cropBox.style.width  = (cropState.w * iw) + 'px';
  cropBox.style.height = (cropState.h * ih) + 'px';
}

// 缩放滑块（缩小显示区域模拟"缩放"，实际调整 max_w 参数）
scaleSlider.addEventListener('input', function() {
  scaleVal.textContent = this.value + '%';
});

// 裁剪框拖动
cropBox.addEventListener('mousedown', e => {
  if (e.target.classList.contains('crop-handle')) return;
  isDragging = true;
  const iw = cropImg.offsetWidth, ih = cropImg.offsetHeight;
  dragStart = {
    mx: e.clientX, my: e.clientY,
    bx: cropState.x * iw, by: cropState.y * ih,
    bw: cropState.w * iw, bh: cropState.h * ih
  };
  e.preventDefault();
});

// 角手柄
document.querySelectorAll('.crop-handle').forEach(h => {
  h.addEventListener('mousedown', e => {
    isResizing = true;
    resizeDir  = h.dataset.dir;
    const iw = cropImg.offsetWidth, ih = cropImg.offsetHeight;
    dragStart = {
      mx: e.clientX, my: e.clientY,
      bx: cropState.x * iw, by: cropState.y * ih,
      bw: cropState.w * iw, bh: cropState.h * ih
    };
    e.preventDefault();
    e.stopPropagation();
  });
});

document.addEventListener('mousemove', e => {
  if (!isDragging && !isResizing) return;
  const iw = cropImg.offsetWidth, ih = cropImg.offsetHeight;
  const dx = e.clientX - dragStart.mx, dy = e.clientY - dragStart.my;

  if (isDragging) {
    let nx = (dragStart.bx + dx) / iw;
    let ny = (dragStart.by + dy) / ih;
    nx = Math.max(0, Math.min(1 - cropState.w, nx));
    ny = Math.max(0, Math.min(1 - cropState.h, ny));
    cropState.x = nx; cropState.y = ny;
  } else if (isResizing) {
    let {bx, by, bw, bh} = dragStart;
    if (resizeDir.includes('e')) bw = Math.max(0.05 * iw, bw + dx);
    if (resizeDir.includes('s')) bh = Math.max(0.05 * ih, bh + dy);
    if (resizeDir.includes('w')) { const nb = Math.min(bx + bw - 0.05 * iw, bx + dx); bw = bx + bw - nb; bx = nb; }
    if (resizeDir.includes('n')) { const nb = Math.min(by + bh - 0.05 * ih, by + dy); bh = by + bh - nb; by = nb; }
    // 边界钳制
    bx = Math.max(0, bx); by = Math.max(0, by);
    bw = Math.min(bw, iw - bx); bh = Math.min(bh, ih - by);
    cropState.x = bx/iw; cropState.y = by/ih;
    cropState.w = bw/iw; cropState.h = bh/ih;
  }
  renderCropBox();
});

document.addEventListener('mouseup', () => { isDragging = false; isResizing = false; });

// 窗口缩放时重新渲染裁剪框（防止比例错乱）
window.addEventListener('resize', () => {
  if (!cropArea.classList.contains('hidden')) renderCropBox();
});

// 触摸支持
cropBox.addEventListener('touchstart', e => {
  if (e.target.classList.contains('crop-handle')) return;
  isDragging = true;
  const iw = cropImg.offsetWidth, ih = cropImg.offsetHeight;
  dragStart = { mx: e.touches[0].clientX, my: e.touches[0].clientY,
    bx: cropState.x * iw, by: cropState.y * ih,
    bw: cropState.w * iw, bh: cropState.h * ih };
  e.preventDefault();
}, {passive:false});
document.addEventListener('touchmove', e => {
  if (!isDragging && !isResizing) return;
  e.preventDefault(); // 阻止页面滚动，必须 passive:false
  const iw = cropImg.offsetWidth, ih = cropImg.offsetHeight;
  const dx = e.touches[0].clientX - dragStart.mx, dy = e.touches[0].clientY - dragStart.my;
  if (isDragging) {
    let nx = (dragStart.bx + dx) / iw;
    let ny = (dragStart.by + dy) / ih;
    nx = Math.max(0, Math.min(1 - cropState.w, nx));
    ny = Math.max(0, Math.min(1 - cropState.h, ny));
    cropState.x = nx; cropState.y = ny;
  } else if (isResizing) {
    let {bx, by, bw, bh} = dragStart;
    if (resizeDir.includes('e')) bw = Math.max(0.05 * iw, bw + dx);
    if (resizeDir.includes('s')) bh = Math.max(0.05 * ih, bh + dy);
    if (resizeDir.includes('w')) { const nb = Math.min(bx + bw - 0.05 * iw, bx + dx); bw = bx + bw - nb; bx = nb; }
    if (resizeDir.includes('n')) { const nb = Math.min(by + bh - 0.05 * ih, by + dy); bh = by + bh - nb; by = nb; }
    bx = Math.max(0, bx); by = Math.max(0, by);
    bw = Math.min(bw, iw - bx); bh = Math.min(bh, ih - by);
    cropState.x = bx/iw; cropState.y = by/ih;
    cropState.w = bw/iw; cropState.h = bh/ih;
  }
  renderCropBox();
}, {passive:false});
document.addEventListener('touchend', () => { isDragging = false; isResizing = false; });

// 触摸角手柄缩放
document.querySelectorAll('.crop-handle').forEach(h => {
  h.addEventListener('touchstart', e => {
    isResizing = true;
    resizeDir  = h.dataset.dir;
    const iw = cropImg.offsetWidth, ih = cropImg.offsetHeight;
    dragStart = {
      mx: e.touches[0].clientX, my: e.touches[0].clientY,
      bx: cropState.x * iw, by: cropState.y * ih,
      bw: cropState.w * iw, bh: cropState.h * ih
    };
    e.preventDefault();
    e.stopPropagation();
  }, {passive:false});
});

// 执行上传
doCropUpload.addEventListener('click', async () => {
  const file = coverFileInput._pendingFile || coverFileInput.files[0];
  if (!file) return;

  const scale = parseInt(scaleSlider.value) / 100;
  const maxW  = Math.round(1600 * scale);
  const maxH  = Math.round(1200 * scale);

  const fd = new FormData();
  fd.append('file',   file);
  fd.append('crop_x', cropState.x.toFixed(6));
  fd.append('crop_y', cropState.y.toFixed(6));
  fd.append('crop_w', cropState.w.toFixed(6));
  fd.append('crop_h', cropState.h.toFixed(6));
  fd.append('max_w',  maxW);
  fd.append('max_h',  maxH);

  doCropUpload.classList.add('hidden');
  uploadingMsg.classList.remove('hidden');
  const pctEl = document.getElementById('coverUploadPct');
  const barEl = document.getElementById('coverUploadBar');
  pctEl.textContent = '0%';
  barEl.style.width = '0%';

  try {
    const data = await xhrUpload('upload.php', fd, (pct) => {
      pctEl.textContent = pct + '%';
      barEl.style.width  = pct + '%';
    });
    if (data.error) { alert('上传失败：' + data.error); }
    else {
      barEl.style.width = '100%';
      pctEl.textContent = '完成';
      await new Promise(r => setTimeout(r, 400));
      setCover(data.url);
      cropArea.classList.add('hidden');
      dropzone.classList.remove('hidden');
      cropImg.src = '';
      coverFileInput.value = '';
      coverFileInput._pendingFile = null;
    }
  } catch(e) {
    alert('上传出错，请重试\n' + (e.message || ''));
  } finally {
    doCropUpload.classList.remove('hidden');
    uploadingMsg.classList.add('hidden');
  }
});

function xhrUpload(url, formData, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable) onProgress(Math.min(99, Math.round(e.loaded / e.total * 100)));
    });
    xhr.addEventListener('load', () => {
      try {
        resolve(JSON.parse(xhr.responseText));
      } catch(e) {
        // 把服务器原始返回内容附在错误里，方便排查
        const preview = xhr.responseText.substring(0, 300);
        reject(new Error('响应解析失败，服务器返回：\n' + preview));
      }
    });
    xhr.addEventListener('error', () => reject(new Error('网络错误，请检查服务器是否支持大文件上传')));
    xhr.addEventListener('timeout', () => reject(new Error('上传超时')));
    xhr.timeout = 120000; // 2分钟超时
    xhr.send(formData);
  });
}

// 将需要被 HTML onclick 属性调用的函数挂到全局
window.switchTab      = switchTab;
window.applyManualUrl = applyManualUrl;
window.clearCover     = clearCover;

})(); // end IIFE
</script>

<?php require '_layout_end.php'; ?>
