  </div><!-- /#spa-content -->
  </main>
</div><!-- /main wrapper -->

<!-- 图片选择器 Modal 骨架 — JS 逻辑由各页面自行注册到 window -->
<div id="pickerModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);overflow-y:auto;-webkit-overflow-scrolling:touch;" onclick="if(event.target===this)closePicturePicker()">
  <div style="background:#fff;max-width:820px;margin:4vh auto;border-radius:2px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #e4e4e7;">
      <div>
        <p style="font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:#a1a1aa;margin:0 0 2px;">uploads/picture</p>
        <h2 style="font-size:15px;font-weight:600;color:#18181b;margin:0;">选择图片</h2>
      </div>
      <button onclick="closePicturePicker()" style="background:none;border:none;cursor:pointer;padding:6px;color:#71717a;line-height:0;">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div id="pickerGrid" style="padding:14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;max-height:70vh;overflow-y:auto;"></div>
    <div id="pickerFooter" style="padding:10px 18px;border-top:1px solid #e4e4e7;font-size:11px;color:#a1a1aa;"></div>
  </div>
</div>

<script>
// =============================================
// Sidebar open/close (mobile)
// window.* 노출 → inline onclick 에서 SPA 경유 후에도 항상 참조 가능
// =============================================
// openSidebar / closeSidebar / closePicturePicker 는
// _layout.php <body> 상단 <script> 에서 window.* 로 정의됨 (중복 정의 불필요)

// =============================================
// SPA Router — 无感刷新侧边栏导航
// =============================================
const spaContent = document.getElementById('spa-content');
const spaTitle   = document.getElementById('spa-title');
const spaLoader  = document.getElementById('spa-loader');

// 需要完整页面跳转的页面（含文件上传表单、登出）
const FULL_RELOAD_PAGES = ['logout.php', 'login.php'];

let spaLoaderTimer = null;

function showLoader() {
  clearTimeout(spaLoaderTimer);
  spaLoader.style.width = '0%';
  spaLoader.style.opacity = '1';
  spaLoader.classList.add('active');
  // animate to 80% quickly
  requestAnimationFrame(() => {
    spaLoader.style.transition = 'width .4s ease, opacity .3s ease';
    spaLoader.style.width = '80%';
  });
}

function hideLoader() {
  spaLoader.style.width = '100%';
  spaLoaderTimer = setTimeout(() => {
    spaLoader.style.opacity = '0';
    setTimeout(() => { spaLoader.style.width = '0%'; }, 300);
  }, 200);
}

function updateNavActive(href) {
  const base = href.split('?')[0].split('/').pop();
  document.querySelectorAll('.spa-nav-link').forEach(link => {
    const matches = link.dataset.match ? link.dataset.match.split(',') : [];
    const isActive = matches.includes(base);
    link.className = link.className.replace(/bg-white\/10 text-white|text-white\/40 hover:text-white\/80 hover:bg-white\/5/g, '').trim();
    link.classList.add(isActive ? 'bg-white/10' : 'text-white/40', isActive ? 'text-white' : 'hover:text-white/80');
    if (!isActive) link.classList.add('hover:bg-white/5');
  });
}

async function spaNavigate(href, pushState = true) {
  // 需要完整跳转的页面
  const base = href.split('?')[0].split('/').pop();
  if (FULL_RELOAD_PAGES.includes(base)) {
    location.href = href;
    return;
  }

  showLoader();
  spaContent.classList.add('loading');

  try {
    const res = await fetch(href, {
      headers: { 'X-SPA-Request': '1' }
    });

    if (!res.ok) throw new Error('HTTP ' + res.status);

    const html = await res.text();

    // 解析返回的 HTML，提取 #spa-content 内容和标题
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    // 提取 <main> 内 #spa-content 的内部内容
    const newContent = doc.getElementById('spa-content');
    const newTitle   = doc.getElementById('spa-title');

    if (newContent) {
      spaContent.innerHTML = newContent.innerHTML;
    } else {
      // 降级：提取 <main> 里的内容
      const newMain = doc.querySelector('main > div#spa-content') || doc.querySelector('main');
      spaContent.innerHTML = newMain ? newMain.innerHTML : html;
    }

    // 更新标题
    if (newTitle) spaTitle.textContent = newTitle.textContent.trim();
    const docTitle = doc.querySelector('title');
    if (docTitle) document.title = docTitle.textContent;

    // 更新 URL & 历史
    if (pushState) history.pushState({ href }, '', href);

    // 更新侧边栏高亮
    updateNavActive(href);

    // 移动端自动关闭侧边栏
    if (window.innerWidth < 1024) closeSidebar();

    // 重新执行页面内的 <script> 标签（跳过数据块，如 type="application/json"）
    // 先用 Array.from 取快照，避免 replaceChild 期间 NodeList 受 DOM 变化影响
    const scripts = Array.from(spaContent.querySelectorAll('script'));
    scripts.forEach(oldScript => {
      const t = (oldScript.type || '').toLowerCase();
      if (t && t !== 'text/javascript' && t !== 'module') return; // 跳过数据块
      // 保护性检查：前一个脚本执行期间可能已将此节点移出 DOM
      if (!oldScript.parentNode) return;
      const s = document.createElement('script');
      if (oldScript.src) {
        s.src = oldScript.src;
      } else {
        s.textContent = oldScript.textContent;
      }
      oldScript.parentNode.replaceChild(s, oldScript);
    });

    // 滚动到顶部
    window.scrollTo(0, 0);

  } catch (err) {
    console.warn('[SPA] fetch failed, falling back:', err);
    location.href = href;
  } finally {
    spaContent.classList.remove('loading');
    hideLoader();
  }
}

// 拦截侧边栏链接
document.addEventListener('click', e => {
  const link = e.target.closest('a[data-spa]');
  if (!link) return;
  e.preventDefault();
  const href = link.getAttribute('href');
  if (href && !href.startsWith('http') && !href.startsWith('//')) {
    spaNavigate(href);
  } else {
    location.href = href;
  }
});

// 浏览器前进/后退
window.addEventListener('popstate', e => {
  const href = (e.state && e.state.href) || location.pathname + location.search;
  spaNavigate(href, false);
});

// 记录初始状态
history.replaceState({ href: location.pathname + location.search }, '', location.pathname + location.search);
</script>
</body>
</html>
