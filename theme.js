/* ============================================================
   缘分故事屋 · 全站主题切换器
   ------------------------------------------------------------
   用法（放 <head>，在 theme.css 之后）：
       <link rel="stylesheet" href="theme.css">
       <script src="theme.js"></script>

   它负责四件事：
   1) 首屏绘制前把 localStorage 里的主题挂到 <html data-theme>（避免刷新闪一下粉色）
   2) 页面加载后往 .nav-inner 末尾注入右上角的主题菜单（各页无需写任何 HTML）
   3) 摸鱼主题下同步伪装标签页标题与 favicon，恢复默认粉时还原
   4) Alt+1~4 快速切换（Alt+2 = 老板键，一秒变表格）

   主题名：pink（默认）/ excel（表格）/ code（代码）/ word（文档）
   ============================================================ */
(function () {
    'use strict';

    var STORAGE_KEY = 'yst-theme';

    var THEMES = {
        pink:  { label: '主题', dot: '#e8556d', title: null },
        excel: { label: '表格', dot: '#217346', title: '销售数据汇总-2026.xlsx - Excel' },
        code:  { label: '代码', dot: '#89b4fa', title: 'main.js - Visual Studio Code' },
        word:  { label: '文档', dot: '#2b579a', title: '季度工作总结.docx - Word' }
    };

    var ORDER = ['pink', 'excel', 'code', 'word'];

    var FAVICON = {
        excel: "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='14' fill='%23217346'/><text x='50' y='72' font-size='64' font-family='Arial' font-weight='bold' text-anchor='middle' fill='white'>X</text></svg>",
        code:  "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='14' fill='%231e1e2e'/><text x='50' y='67' font-size='38' font-family='Consolas,monospace' font-weight='bold' text-anchor='middle' fill='%2389b4fa'>&lt;/&gt;</text></svg>",
        word:  "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='14' fill='%232b579a'/><text x='50' y='72' font-size='62' font-family='Arial' font-weight='bold' text-anchor='middle' fill='white'>W</text></svg>"
    };

    var SWATCH = {
        pink: 'linear-gradient(135deg, #ff9a76, #e8556d)',
        excel: '#217346',
        code: '#1e1e2e',
        word: '#2b579a'
    };

    var LABEL = {
        pink: '默认 · 粉',
        excel: '摸鱼 · 表格',
        code: '摸鱼 · 代码',
        word: '摸鱼 · 文档'
    };

    var iconLink = null;
    var originalFavicon = '';
    var originalTitle = '';
    var menuEl = null;
    var btnEl = null;

    function readStored() {
        try {
            var t = localStorage.getItem(STORAGE_KEY);
            return THEMES[t] ? t : 'pink';
        } catch (e) {
            return 'pink';   /* 隐私模式下 localStorage 会抛错 */
        }
    }

    /* ---------- 1) 尽早在 <html> 上落主题 ---------- */
    var current = readStored();
    document.documentElement.setAttribute('data-theme', current);

    /* ---------- 2) 应用主题（标题 / 图标 / 菜单状态） ---------- */
    function applyTheme(name) {
        var key = THEMES[name] ? name : 'pink';
        current = key;
        document.documentElement.setAttribute('data-theme', key);
        try { localStorage.setItem(STORAGE_KEY, key); } catch (e) { /* 忽略 */ }

        /* 摸鱼主题伪装标签页标题；默认粉还原页面自己的标题 */
        var meta = THEMES[key];
        document.title = meta.title || originalTitle;

        if (iconLink) {
            iconLink.setAttribute('href', key === 'pink' ? originalFavicon : FAVICON[key]);
        }

        if (btnEl) {
            var dot = btnEl.querySelector('.tb-dot');
            var label = btnEl.querySelector('.tb-label');
            if (dot) dot.style.background = meta.dot;
            if (label) label.textContent = meta.label;
        }
        if (menuEl) {
            var items = menuEl.querySelectorAll('button[data-theme]');
            for (var i = 0; i < items.length; i++) {
                items[i].classList.toggle('active', items[i].getAttribute('data-theme') === key);
            }
        }
    }

    /* ---------- 3) 注入切换器 ---------- */
    function buildSwitch() {
        var wrap = document.createElement('div');
        wrap.className = 'theme-switch';
        wrap.id = 'theme-switch';

        var html = '<button class="theme-btn" id="theme-btn" type="button"' +
            ' aria-haspopup="menu" aria-expanded="false" title="切换界面主题（Alt + 1~4）">' +
            '<span class="tb-dot"></span><span class="tb-label">主题</span></button>' +
            '<div class="theme-menu" id="theme-menu" role="menu">';

        for (var i = 0; i < ORDER.length; i++) {
            var k = ORDER[i];
            html += '<button type="button" role="menuitem" data-theme="' + k + '">' +
                '<span class="tm-swatch" style="background:' + SWATCH[k] + ';"></span>' +
                LABEL[k] + '</button>';
        }
        html += '<div class="tm-hint">Alt + 1~4 快速切换<br>选中记忆在本机浏览器</div></div>';
        wrap.innerHTML = html;

        var host = document.querySelector('.nav-inner');
        if (host) {
            host.appendChild(wrap);
        } else {
            /* 没有导航栏的页面：浮在右上角，别丢功能 */
            wrap.style.position = 'fixed';
            wrap.style.top = '16px';
            wrap.style.right = '16px';
            wrap.style.zIndex = '1300';
            document.body.appendChild(wrap);
        }

        btnEl = wrap.querySelector('#theme-btn');
        menuEl = wrap.querySelector('#theme-menu');

        btnEl.addEventListener('click', function (e) {
            e.stopPropagation();   /* 别让 document 上的关闭逻辑立刻把它关掉 */
            var open = menuEl.classList.toggle('open');
            btnEl.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        for (var j = 0; j < ORDER.length; j++) {
            (function (themeName) {
                var item = menuEl.querySelector('button[data-theme="' + themeName + '"]');
                if (!item) return;
                item.addEventListener('click', function () {
                    applyTheme(themeName);
                    closeMenu();
                });
            })(ORDER[j]);
        }
    }

    function closeMenu() {
        if (!menuEl || !btnEl) return;
        menuEl.classList.remove('open');
        btnEl.setAttribute('aria-expanded', 'false');
    }

    function init() {
        originalTitle = document.title;
        iconLink = document.querySelector('link[rel="icon"]');
        originalFavicon = iconLink ? iconLink.getAttribute('href') : '';

        buildSwitch();
        applyTheme(current);   /* 补按钮 / 标题 / 菜单勾选状态 */

        document.addEventListener('click', closeMenu);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMenu();
            /* Alt + 1~4 直接切：老板过来了按 Alt+2，一秒变表格 */
            if (e.altKey && !e.ctrlKey && !e.metaKey) {
                var byKey = { '1': 'pink', '2': 'excel', '3': 'code', '4': 'word' };
                var target = byKey[e.key];
                if (target) {
                    e.preventDefault();
                    applyTheme(target);
                    closeMenu();
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* 便于其它脚本读取 / 切换（例如发布页跳转前判断） */
    window.SiteTheme = {
        apply: function (name) { applyTheme(name); },
        current: function () { return current; }
    };
})();
