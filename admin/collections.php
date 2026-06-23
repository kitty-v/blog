<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

$pdo = db();
$errors = [];
$edit   = null;

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $pdo->prepare('DELETE FROM collections WHERE id=?')->execute([(int)$_GET['delete']]);
    header('Location: collections.php?msg=deleted'); exit;
}
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM collections WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int)($_POST['id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $cover_url   = trim($_POST['cover_url'] ?? '');
    $sort_order  = (int)($_POST['sort_order'] ?? 0);
    if (!$title) $errors[] = '标题为必填项。';
    if (empty($errors)) {
        if ($id) {
            $pdo->prepare('UPDATE collections SET title=?,description=?,cover_url=?,sort_order=? WHERE id=?')
                ->execute([$title,$description,$cover_url,$sort_order,$id]);
        } else {
            $pdo->prepare('INSERT INTO collections (title,description,cover_url,sort_order) VALUES (?,?,?,?)')
                ->execute([$title,$description,$cover_url,$sort_order]);
        }
        header('Location: collections.php?msg=saved'); exit;
    }
    $edit = compact('id','title','description','cover_url','sort_order');
}

$collections = $pdo->query('SELECT * FROM collections ORDER BY sort_order ASC')->fetchAll();
$page_title  = 'Collections';

// 扫描 uploads/picture/ 供图片选择器使用
$_pic_dir  = realpath(__DIR__ . '/../uploads/picture');
$_pic_exts = ['jpg','jpeg','png','gif','webp','avif','bmp'];
$_pic_list = [];
if ($_pic_dir && is_dir($_pic_dir)) {
    $doc_root     = rtrim(realpath($_SERVER['DOCUMENT_ROOT']), '/\\');
    $project_root = rtrim(realpath(__DIR__ . '/..'), '/\\');
    $base_url = (strpos($project_root, $doc_root) === 0)
        ? str_replace('\\', '/', substr($project_root, strlen($doc_root)))
        : '';
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
function hc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert-success"><?= $_GET['msg'] === 'deleted' ? '专题已删除。' : '保存成功。' ?></div>
<?php endif; ?>
<?php if ($errors): ?>
<div class="alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<div class="flex flex-col lg:flex-row gap-6">

  <!-- Form -->
  <div class="lg:w-80 flex-shrink-0">
    <div class="bg-white border border-zinc-200 p-6">
      <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-5"><?= $edit ? '编辑专题' : '新建专题' ?></p>
      <form method="POST" class="space-y-4" id="collectionForm">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div>
          <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-1.5">标题 *</label>
          <input type="text" name="title" value="<?= hc($edit['title'] ?? '') ?>" required class="field">
        </div>
        <div>
          <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-1.5">简介</label>
          <textarea name="description" rows="3" class="field resize-none"><?= hc($edit['description'] ?? '') ?></textarea>
        </div>

        <!-- 封面图片（上传 + 裁剪，与文章编辑器一致） -->
        <div>
          <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-3">封面图片</label>

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
            <input type="text" id="cover_url_manual"
                   placeholder="https://…"
                   class="field text-xs"
                   value="<?= hc($edit['cover_url'] ?? '') ?>">
            <button type="button" onclick="applyManualUrl()" class="btn-outline w-full justify-center mt-2 text-xs">套用</button>
          </div>

          <!-- 服务器图片选择区 -->
          <div id="panelPicker" class="hidden">
            <div id="pickerGridInline" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:7px;max-height:300px;overflow-y:auto;padding:2px;"></div>
            <p id="pickerInlineFooter" style="font-size:10px;color:#a1a1aa;margin-top:6px;text-align:center;"></p>
          </div>

          <!-- 预览 + 真实 hidden 字段 -->
          <input type="hidden" name="cover_url" id="cover_url" value="<?= hc($edit['cover_url'] ?? '') ?>">
          <div id="cover_preview" class="mt-4 <?= empty($edit['cover_url']) ? 'hidden' : '' ?>">
            <div class="relative group">
              <?php if (!empty($edit['cover_url'])): ?>
              <img id="cover_img" src="<?= hc($edit['cover_url']) ?>" class="w-full aspect-video object-cover" alt=""
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
              <div class="w-full aspect-video bg-zinc-100 items-center justify-center text-zinc-300 text-xs" style="display:none">图片加载失败</div>
              <?php else: ?>
              <img id="cover_img" src="" class="w-full aspect-video object-cover" alt="" style="display:none"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
              <div class="w-full aspect-video bg-zinc-100 items-center justify-center text-zinc-300 text-xs" style="display:none">暂无封面</div>
              <?php endif; ?>
              <button type="button" onclick="clearCover()"
                      class="absolute top-2 right-2 bg-black/60 text-white text-[10px] px-2 py-1 opacity-0 group-hover:opacity-100 transition">✕ 移除</button>
            </div>
            <p id="cover_url_display" class="text-[10px] text-zinc-400 mt-1 truncate"><?= hc($edit['cover_url'] ?? '') ?></p>
          </div>
        </div>
        <!-- /封面图片 -->

        <div>
          <label class="block text-[10px] tracking-wider uppercase text-zinc-400 mb-1.5">排列顺序</label>
          <input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>" class="field">
        </div>
        <div class="flex gap-2 pt-1">
          <button type="submit" class="btn-primary flex-1 justify-center"><?= $edit ? '保存' : '创建' ?></button>
          <?php if ($edit): ?>
          <a href="collections.php" class="btn-outline">取消</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- List -->
  <div class="flex-1 min-w-0">
    <!-- Mobile -->
    <div class="bg-white border border-zinc-200 divide-y divide-zinc-100 lg:hidden">
      <?php if (empty($collections)): ?>
      <p class="px-5 py-10 text-sm text-zinc-300 text-center">暂无专题。</p>
      <?php endif; ?>
      <?php foreach ($collections as $c): ?>
      <div class="p-4 flex gap-3">
        <?php if ($c['cover_url']): ?>
        <img src="<?= hc($c['cover_url']) ?>" class="w-14 h-14 object-cover flex-shrink-0 rounded-sm">
        <?php endif; ?>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-light truncate"><?= hc($c['title']) ?></p>
          <p class="text-xs text-zinc-400 mt-0.5 line-clamp-1"><?= hc($c['description'] ?? '') ?></p>
          <div class="flex gap-4 mt-2">
            <a href="collections.php?edit=<?= $c['id'] ?>" class="text-[11px] uppercase tracking-wider text-zinc-400 hover:text-zinc-900">编辑</a>
            <a href="collections.php?delete=<?= $c['id'] ?>" onclick="return confirm('Delete this collection?')"
               class="text-[11px] uppercase tracking-wider text-red-400 hover:text-red-600">删除</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Desktop -->
    <div class="hidden lg:block bg-white border border-zinc-200 overflow-hidden">
      <table class="data-table">
        <thead><tr><th>专题</th><th>简介</th><th>排序</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($collections)): ?>
          <tr><td colspan="4" class="text-center text-zinc-300 py-10">暂无专题。</td></tr>
          <?php endif; ?>
          <?php foreach ($collections as $c): ?>
          <tr>
            <td>
              <div class="flex items-center gap-3">
                <?php if ($c['cover_url']): ?>
                <img src="<?= hc($c['cover_url']) ?>" class="w-10 h-10 object-cover rounded-sm flex-shrink-0">
                <?php endif; ?>
                <span class="font-light"><?= hc($c['title']) ?></span>
              </div>
            </td>
            <td class="text-zinc-400 text-xs max-w-xs"><span class="truncate block"><?= hc($c['description'] ?? '') ?></span></td>
            <td class="text-zinc-400"><?= $c['sort_order'] ?></td>
            <td class="text-right whitespace-nowrap">
              <div class="flex items-center justify-end gap-4">
                <a href="collections.php?edit=<?= $c['id'] ?>" class="text-xs text-zinc-400 hover:text-zinc-900 uppercase tracking-wider">编辑</a>
                <a href="collections.php?delete=<?= $c['id'] ?>" onclick="return confirm('Delete this collection?')"
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

<!-- pickerData 已内联到 JS 变量，无需此标签 -->

<script>
(function() { // IIFE — 防止 SPA 二次加载时 const 重复声明报错
// =============================================
// 封面上传 + 裁剪（与文章编辑器逻辑完全一致）
// =============================================
let cropState = { x:0.05, y:0.05, w:0.90, h:0.90 };
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

// Tab 切换
// 内联图片数据（PHP 直接输出为 JS 变量，无 DOM 依赖）
const PICKER_PICS = <?= json_encode($_pic_list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
let _pickerRendered = false;

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
      requestAnimationFrame(() => renderCropBox());
    };
  };
  reader.readAsDataURL(file);
  coverFileInput._pendingFile = file;
}

function renderCropBox() {
  const iw = cropImg.offsetWidth, ih = cropImg.offsetHeight;
  cropBox.style.left   = (cropState.x * iw) + 'px';
  cropBox.style.top    = (cropState.y * ih) + 'px';
  cropBox.style.width  = (cropState.w * iw) + 'px';
  cropBox.style.height = (cropState.h * ih) + 'px';
}

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
    bx = Math.max(0, bx); by = Math.max(0, by);
    bw = Math.min(bw, iw - bx); bh = Math.min(bh, ih - by);
    cropState.x = bx/iw; cropState.y = by/ih;
    cropState.w = bw/iw; cropState.h = bh/ih;
  }
  renderCropBox();
});

document.addEventListener('mouseup', () => { isDragging = false; isResizing = false; });

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
  e.preventDefault();
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

// 执行上传（调用相同的 upload.php，保存到相同的 uploads/picture/ 目录）
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
        const preview = xhr.responseText.substring(0, 300);
        reject(new Error('响应解析失败，服务器返回：\n' + preview));
      }
    });
    xhr.addEventListener('error', () => reject(new Error('网络错误，请检查服务器是否支持大文件上传')));
    xhr.addEventListener('timeout', () => reject(new Error('上传超时')));
    xhr.timeout = 120000;
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
