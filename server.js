/**
 * 缘分故事屋 - 零依赖 Node 本地开发服务器
 *
 * 接口行为与 api.php 完全对齐（同一份 data/stories.json 数据文件），
 * 本机未装 PHP 时用这个做本地测试：node server.js
 * 生产部署（宝塔 PHP / Docker）时使用 api.php，前端代码无需任何改动。
 *
 * 用法：node server.js   （默认端口 8000，可 PORT=xx node server.js 指定）
 */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = __dirname;
const DATA_DIR = path.join(ROOT, 'data');
const DATA_FILE = path.join(DATA_DIR, 'stories.json');
const SEED_FILE = path.join(DATA_DIR, 'seed.json');
const FEEDBACK_FILE = path.join(DATA_DIR, 'feedback.json');

/**
 * 管理页密码（用于 admin.html 查看网友反馈）
 * ⚠️ 部署上线前请务必修改成你自己的密码！
 */
let ADMIN_KEY = 'yuanfen2025';
try { const fk = fs.readFileSync('./data/admin_key.txt', 'utf8'); if (fk.trim()) ADMIN_KEY = fk.trim(); } catch (e) {}
const VALID_TAGS = [
  'sweet',     // 甜蜜脱单
  'funny',     // 搞笑经历
  'touching',  // 感人故事
  'complain',  // 心酸吐槽
  'daily',     // 日常记录
  'yidi',      // 异地相亲
  'chujian',   // 初次见面
  'liaotian',  // 聊天记录
  'jiaolv',    // 年龄焦虑
  'cuihun',    // 父母催婚
  'jianjia',   // 见家长
  'yilian',    // 异地恋
  'shanhun',   // 闪婚
];

/* ================= 工具 ================= */

function json(res, obj, code = 200) {
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(obj));
}

const fail = (res, msg, code = 400) => json(res, { ok: false, error: msg }, code);

/** 读取数据库（首次运行自动用种子初始化，时间戳按当前时间偏移生成） */
function dbLoad() {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  if (!fs.existsSync(DATA_FILE)) {
    let seed = {};
    try { seed = JSON.parse(fs.readFileSync(SEED_FILE, 'utf8')); } catch (e) { seed = {}; }
    const now = Math.floor(Date.now() / 1000);
    const stories = (seed.stories || []).map(s => {
      const copy = { ...s, createdAt: now - (s.createdAtOffset || 0) };
      delete copy.createdAtOffset;
      copy.comments = (s.comments || []).map(c => {
        const cc = { ...c, createdAt: now - (c.createdAtOffset || 0) };
        delete cc.createdAtOffset;
        return cc;
      });
      return copy;
    });
    const db = { nextId: seed.nextId || stories.length + 1, stories };
    dbSave(db);
    return db;
  }
  let db;
  try { db = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8')); } catch (e) { db = null; }
  if (!db) return { nextId: 1, stories: [] };
  if (!Array.isArray(db.stories)) db.stories = [];
  if (!db.nextId) db.nextId = db.stories.length + 1;
  return db;
}

/** 写数据库（同步 + 临时文件原子替换） */
function dbSave(db) {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  const tmp = DATA_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(db, null, 2), 'utf8');
  fs.renameSync(tmp, DATA_FILE);
}

/** HTML 轻净化：去 script/危险属性（保留 style/结构/动画）。
    mode=html 的内容阅读时通过沙箱 iframe（route=raw）渲染，与站点完全隔离，
    因此无需再做 CSS 作用域隔离，样式 100% 原样。 */
function sanitizeHtml(html) {
  html = html.replace(/<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/gi, '');
  html = html.replace(/<\s*\/?\s*script\b[^>]*>/gi, '');
  html = html.replace(/<\s*(iframe|object|embed|form|link|meta)\b[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi, '');
  html = html.replace(/<\s*\/?\s*(iframe|object|embed|form|link|meta|input|button|textarea|select)\b[^>]*>/gi, '');
  html = html.replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
  html = html.replace(/(href|src)\s*=\s*("|')\s*(javascript|vbscript):[^"']*/gi, '$1=$2#');
  html = html.replace(/(href|src)\s*=\s*(?!["'])(javascript|vbscript):[^\s>]*/gi, '');
  html = html.replace(/(?<![-\w])behavior\s*:/gi, '');  // 老 IE 行为（不误伤 scroll-behavior）
  html = html.replace(/expression\s*\(/gi, '');          // 老 IE 的 CSS 表达式
  return html.trim();
}

/** 详情页「滚动显现动画」驱动器（站点注入的安全脚本，随 raw 输出附带） */
const REVEAL_SCRIPT = `
<script>
(function () {
  var CLASSES = ['visible','active','show','in','on','entered','revealed','appear','appeared','fade-in','shown','display'];
  function drive() {
    var targets = [];
    document.querySelectorAll('*').forEach(function (el) {
      if (getComputedStyle(el).opacity !== '0') return;
      targets.push(el);
    });
    if (!targets.length) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        CLASSES.forEach(function (c) { entry.target.classList.add(c); });
        io.unobserve(entry.target);
      });
    }, { threshold: 0.06, rootMargin: '60px' });
    targets.forEach(function (el) { io.observe(el); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', drive);
  else drive();
})();
</script>`;

/** 输出故事的原始 HTML（沙箱隔离，供 iframe/新窗口阅读） */
function serveRawStory(res, story) {
  const body = String(story.content || '');
  const html = '<!DOCTYPE html>\n<html lang="zh-CN">\n<head>\n<meta charset="UTF-8">\n' +
    '<meta name="viewport" content="width=device-width, initial-scale=1.0">\n' +
    '<title>' + String(story.title || '故事').replace(/[<>&"]/g, '') + '</title>\n' +
    '<style>html,body{margin:0;padding:0;}</style>\n' +
    '</head>\n<body>\n' + body + '\n' + REVEAL_SCRIPT + '\n</body>\n</html>';
  res.writeHead(200, {
    'Content-Type': 'text/html; charset=utf-8',
    // 沙箱化：文档为独立源，内部脚本无法访问站点数据/存储，仅可运行自身动画
    'Content-Security-Policy': 'sandbox allow-scripts',
    'Cache-Control': 'no-cache'
  });
  res.end(html);
}

/** 摘要：取前 90 字（先剥掉 style/script 块，防止 CSS 文本泄漏到摘要） */
function makeExcerpt(content, mode) {
  let text = mode === 'html'
    ? content.replace(/<(style|script)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ').replace(/<[^>]*>/g, ' ')
    : content;
  text = text
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'");
  text = text.replace(/\s+/g, ' ').trim();
  const chars = Array.from(text);
  return chars.length > 90 ? chars.slice(0, 90).join('') + '…' : text;
}

function randomNickname() {
  const adjs = ['温柔', '爱笑', '好奇', '慢热', '迷糊', '认真', '元气', '安静的'];
  const nouns = ['小鹿', '小猫', '月亮', '晚风', '橘子', '汽水', '云朵', '星星', '草莓', '栗子'];
  const emos = ['🐟', '🧸', '🌙', '🍓', '☕', '🌷', '⭐', '🍑'];
  const pick = a => a[Math.floor(Math.random() * a.length)];
  return pick(adjs) + pick(nouns) + pick(emos);
}

/** 评论输出映射（两级楼中楼：一级评论 + 扁平回复列表；字段级兜底，残缺数据不报错） */
const commentOut = c => ({
  id: String(c.id ?? ''),
  nickname: String(c.nickname ?? '匿名'),
  content: String(c.content ?? ''),
  createdAt: Number(c.createdAt ?? 0),
  replies: (c.replies || []).map(r => ({
    id: String(r.id ?? ''),
    nickname: String(r.nickname ?? '匿名'),
    content: String(r.content ?? ''),
    createdAt: Number(r.createdAt ?? 0),
    replyToNickname: String(r.replyToNickname || ''),
  })),
});

/** 两级楼中楼：在评论里查找目标（一级或其回复），新回复统一挂到所属一级评论的 replies 末尾 */
const commentReplyAttach = (comments, replyTo, entry) => {
  for (let i = 0; i < comments.length; i++) {
    const c = comments[i];
    let found = null;
    if (String(c.id ?? '') === replyTo) {
      found = c;
    } else {
      for (const r of (c.replies || [])) {
        if (String(r.id ?? '') === replyTo) { found = r; break; }
      }
    }
    if (found) {
      entry.replyToNickname = String(found.nickname ?? '匿名');
      c.replies = c.replies || [];
      c.replies.push(entry);
      return true;
    }
  }
  return false;
};

/** 评论数 = 一级评论数 + 各自回复数（不再计入种子模拟基数，显示真实互动量） */
const commentsCount = s => (s.comments || []).reduce((n, c) => n + 1 + (c.replies || []).length, 0);
const hotScore = s => s.likes * 3 + commentsCount(s) * 5 + s.views * 0.5;

/* ==================== 浏览量防刷（IP + 时间窗口去重） ==================== */
// 同一 IP 对同一故事 24 小时内只计 1 次浏览。
// 记录存内存即可：窗口过期自动失效，服务重启重置也无碍。
const viewLog = new Map();               // key: `${ip}|${storyId}` → value: 上次计数时间戳(ms)
const VIEW_WINDOW = 24 * 60 * 60 * 1000; // 24 小时

function clientIp(req) {
  const xf = req.headers['x-forwarded-for'];
  if (xf) return String(xf).split(',')[0].trim();   // 经 nginx/Docker 反代时取真实 IP
  return req.socket.remoteAddress || 'unknown';
}

function shouldCountView(req, storyId) {
  const key = clientIp(req) + '|' + storyId;
  const now = Date.now();
  const last = viewLog.get(key);
  if (last && now - last < VIEW_WINDOW) return false;
  viewLog.set(key, now);
  // 顺手清理过期记录，防止内存无限增长
  if (viewLog.size > 5000) {
    for (const [k, t] of viewLog) {
      if (now - t > VIEW_WINDOW) viewLog.delete(k);
    }
  }
  return true;
}

// 每小时兜底清理一次过期记录
setInterval(() => {
  const now = Date.now();
  for (const [k, t] of viewLog) {
    if (now - t > VIEW_WINDOW) viewLog.delete(k);
  }
}, 60 * 60 * 1000).unref();

/** 列表项字段（不含全文） */
function storyCard(s) {
  return {
    id: s.id,
    title: s.title,
    excerpt: makeExcerpt(s.content, s.mode || 'text'),
    mode: s.mode || 'text',
    tags: s.tags || [],
    author: s.author || { nickname: '匿名', info: '匿名分享' },
    likes: s.likes,
    views: s.views,
    commentsCount: commentsCount(s),
    createdAt: s.createdAt,
  };
}

/** 详情字段（含全文与评论） */
function storyFull(s) {
  return {
    ...storyCard(s),
    content: s.content,
    comments: (s.comments || []).map(commentOut),
  };
}

/* ================= API 路由（与 api.php 对齐） ================= */

function handleApi(req, res, url, body) {
  const route = url.searchParams.get('route') || '';
  const method = req.method || 'GET';
  const id = parseInt(url.searchParams.get('id'), 10) || 0;

  switch (route) {

    /* ---- 故事列表 / 发布 ---- */
    case 'stories': {
      if (method === 'POST') {
        const title = String(body.title || '').trim();
        const content = String(body.content || '').trim();
        const mode = body.mode === 'html' ? 'html' : 'text';
        let nickname = String(body.nickname || '').trim();
        const tagsIn = Array.isArray(body.tags) ? body.tags : [];

        if (!title) return fail(res, '故事标题不能为空');
        if (Array.from(title).length > 60) return fail(res, '标题最多 60 个字');
        if (!content) return fail(res, '故事内容不能为空');
        const maxLen = mode === 'html' ? 50000 : 20000;
        if (Array.from(content).length > maxLen) return fail(res, `内容太长啦，最多 ${maxLen} 个字符`);
        if (Array.from(nickname).length > 20) return fail(res, '昵称最多 20 个字符');
        if (!nickname) nickname = randomNickname();

        const tags = [...new Set(tagsIn.filter(t => VALID_TAGS.includes(t)))].slice(0, 4);
        // 一个标签都没选时，默认归为「日常记录」，保证每篇故事都有归属分类
        if (!tags.length) tags.push('daily');

        const db = dbLoad();
        const story = {
          id: db.nextId++,
          title,
          content: mode === 'html' ? sanitizeHtml(content) : content,
          mode, tags,
          author: { nickname, info: '缘分旅人 · 匿名分享' },
          likes: 0, views: 0, comments: [],
          createdAt: Math.floor(Date.now() / 1000),
        };
        // 生成发布者删除凭证（仅本次响应返回，存储在发布者浏览器里）
        const editKey = Math.random().toString(36).slice(2, 6) + Math.random().toString(36).slice(2, 6);
        story.editKey = editKey;
        db.stories.unshift(story);
        dbSave(db);
        const full = storyFull(story);
        full.editKey = editKey;
        return json(res, { ok: true, story: full });
      }

      // GET 列表
      const filter = url.searchParams.get('filter') || 'hot';
      const page = Math.max(1, parseInt(url.searchParams.get('page'), 10) || 1);
      const limit = Math.min(20, Math.max(1, parseInt(url.searchParams.get('limit'), 10) || 6));

      const db = dbLoad();
      let arr = db.stories.slice();
      if (filter === 'hot') {
        arr.sort((a, b) => hotScore(b) - hotScore(a));
      } else if (filter === 'new') {
        arr.sort((a, b) => b.createdAt - a.createdAt);
      } else if (VALID_TAGS.includes(filter)) {
        arr = arr.filter(s => (s.tags || []).includes(filter));
        arr.sort((a, b) => b.createdAt - a.createdAt);
      } else {
        return fail(res, '未知的筛选类型');
      }

      const total = arr.length;
      const stories = arr.slice((page - 1) * limit, page * limit).map(storyCard);
      return json(res, { ok: true, total, page, hasMore: page * limit < total, stories });
    }

    /* ---- 故事原文（沙箱隔离渲染，供 iframe/新窗口阅读） ---- */
    case 'raw': {
      const db = dbLoad();
      const s = db.stories.find(x => x.id === id);
      if (!s) { res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' }); res.end('404 Not Found'); return; }
      if (s.mode === 'html') { serveRawStory(res, s); return; }
      // 纯文本故事：简单排版输出
      const escText = String(s.content || '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\n/g, '<br>');
      const escTitle = String(s.title || '故事').replace(/[<>&"]/g, '');
      const html = '<!DOCTYPE html>\n<html lang="zh-CN">\n<head>\n<meta charset="UTF-8">\n' +
        '<meta name="viewport" content="width=device-width, initial-scale=1.0">\n' +
        '<title>' + escTitle + '</title>\n' +
        '<style>body{font-family:"Noto Serif SC","STSong",serif;background:#faf6ef;color:#2c2416;line-height:2;max-width:720px;margin:0 auto;padding:48px 24px;font-size:16px;}h1{font-size:1.6rem;margin:0 0 1.5rem;}</style>\n' +
        '</head>\n<body>\n<h1>' + escTitle + '</h1>\n' + escText + '\n</body>\n</html>';
      res.writeHead(200, {
        'Content-Type': 'text/html; charset=utf-8',
        'Content-Security-Policy': 'sandbox',
        'Cache-Control': 'no-cache'
      });
      res.end(html);
      return;
    }

    /* ---- 故事详情（浏览量按 IP 24h 去重计数） ---- */
    case 'story': {
      const db = dbLoad();
      const s = db.stories.find(x => x.id === id);
      if (!s) return fail(res, '故事不存在或已被删除', 404);
      // 前端会话内重复打开(count=0)或同 IP 24h 内重复访问，都不重复计数
      if (url.searchParams.get('count') !== '0' && shouldCountView(req, id)) {
        s.views += 1;
        dbSave(db);
      }
      return json(res, { ok: true, story: storyFull(s) });
    }

    /* ---- 点赞 ---- */
    case 'like': {
      if (method !== 'POST') return fail(res, '请使用 POST', 405);
      const db = dbLoad();
      const s = db.stories.find(x => x.id === (body.id | 0));
      if (!s) return fail(res, '故事不存在或已被删除', 404);
      s.likes = Math.max(0, s.likes + (body.undo ? -1 : 1));
      dbSave(db);
      return json(res, { ok: true, likes: s.likes });
    }

    /* ---- 评论 ---- */
    case 'comments': {
      const db = dbLoad();
      const s = db.stories.find(x => x.id === id);
      if (!s) return fail(res, '故事不存在或已被删除', 404);
      s.comments = s.comments || [];

      if (method === 'POST') {
        const content = String(body.content || '').trim();
        let nickname = String(body.nickname || '').trim();
        const replyTo = String(body.replyTo || '').trim();
        if (!content) return fail(res, '评论内容不能为空');
        if (Array.from(content).length > 500) return fail(res, '评论最多 500 个字');
        if (Array.from(nickname).length > 20) return fail(res, '昵称最多 20 个字符');
        if (!nickname) nickname = randomNickname();
        const entry = {
          id: 'c' + Date.now() + Math.floor(Math.random() * 9000 + 1000),
          nickname, content,
          createdAt: Math.floor(Date.now() / 1000),
        };
        if (replyTo) {
          /* 两级楼中楼：replyTo 为目标评论或回复的 id，
             回复统一挂在其所属一级评论的 replies 下，并记录被回复人昵称 */
          const comments = (s.comments || []).map(commentOut);
          if (!commentReplyAttach(comments, replyTo, entry)) {
            return fail(res, '要回复的评论不存在或已被删除', 404);
          }
          s.comments = comments;
        } else {
          s.comments = s.comments || [];
          s.comments.push(entry);
        }
        dbSave(db);
        return json(res, { ok: true, commentsCount: commentsCount(s), comment: entry });
      }

      return json(res, { ok: true, comments: (s.comments || []).map(commentOut) });
    }

    /* ---- 标签云计数 ---- */
    case 'tags': {
      const db = dbLoad();
      const counts = {};
      for (const s of db.stories) {
        for (const t of (s.tags || [])) {
          if (VALID_TAGS.includes(t)) counts[t] = (counts[t] || 0) + 1;
        }
      }
      return json(res, { ok: true, tags: VALID_TAGS.map(t => ({ tag: t, count: counts[t] || 0 })) });
    }

    /* ---- 反馈建议 ---- */
    case 'feedback': {
      if (method !== 'POST') return fail(res, '请使用 POST', 405);
      const content = String(body.content || '').trim();
      const contact = String(body.contact || '').trim();
      let nickname = String(body.nickname || '').trim();
      if (!content) return fail(res, '反馈内容不能为空');
      if (Array.from(content).length > 1000) return fail(res, '反馈内容最多 1000 字');
      if (contact.length > 100) return fail(res, '联系方式最多 100 个字符');
      if (Array.from(nickname).length > 20) return fail(res, '昵称最多 20 个字符');
      if (!nickname) nickname = randomNickname();
      if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
      let db = { nextId: 1, items: [] };
      if (fs.existsSync(FEEDBACK_FILE)) {
        try { db = JSON.parse(fs.readFileSync(FEEDBACK_FILE, 'utf8')); } catch (e) { db = { nextId: 1, items: [] }; }
      }
      if (!Array.isArray(db.items)) db.items = [];
      if (!db.nextId) db.nextId = db.items.length + 1;
      db.items.push({ id: db.nextId++, nickname, contact, content, createdAt: Math.floor(Date.now() / 1000) });
      fs.writeFileSync(FEEDBACK_FILE, JSON.stringify(db, null, 2), 'utf8');
      return json(res, { ok: true, message: '反馈已收到，感谢你的每一句建议 💕' });
    }

    /* ---- 发布者删除自己的故事（凭发布时下发的凭证） ---- */
    case 'delete_story': {
      if (method !== 'POST') return fail(res, '请使用 POST', 405);
      const db = dbLoad();
      const idx = db.stories.findIndex(x => x.id === (body.id | 0));
      if (idx === -1) return fail(res, '故事不存在或已被删除', 404);
      const s = db.stories[idx];
      if (!s.editKey || String(body.editKey || '').trim() !== String(s.editKey)) {
        return fail(res, '删除凭证不正确，无法删除这篇故事');
      }
      db.stories.splice(idx, 1);
      dbSave(db);
      return json(res, { ok: true, message: '故事已删除' });
    }

    /* ---- 管理页：故事管理（查看/删除任意故事，支持标签筛选、最新在前） ---- */
    case 'admin_stories': {
      if (method !== 'POST') return fail(res, '请使用 POST', 405);
      if (String(body.key || '') !== ADMIN_KEY) return fail(res, '管理密码错误', 403);

      const db = dbLoad();
      if ((body.op || 'list') === 'delete') {
        const id = body.id | 0;
        const before = db.stories.length;
        db.stories = db.stories.filter(s => s.id !== id);
        if (db.stories.length === before) return fail(res, '未找到该故事，可能已被删除');
        dbSave(db);
      }

      const tag = String(body.tag || 'all');
      const items = db.stories
        .filter(s => tag === 'all' || (Array.isArray(s.tags) && s.tags.includes(tag)))
        .sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0))   // 最新在前
        .map(s => ({
          id: s.id,
          title: s.title,
          author: (s.author && s.author.nickname) || '匿名',
          tags: s.tags || [],
          likes: s.likes,
          views: s.views,
          commentsCount: commentsCount(s),
          createdAt: s.createdAt,
        }));
      return json(res, { ok: true, total: items.length, items });
    }

    /* ---- 管理页：查看/删除反馈 ---- */
    case 'admin_feedback': {
      if (method !== 'POST') return fail(res, '请使用 POST', 405);
      if (String(body.key || '') !== ADMIN_KEY) return fail(res, '管理密码错误', 403);

      let db = { nextId: 1, items: [] };
      if (fs.existsSync(FEEDBACK_FILE)) {
        try { db = JSON.parse(fs.readFileSync(FEEDBACK_FILE, 'utf8')); } catch (e) { db = { nextId: 1, items: [] }; }
      }
      if (!Array.isArray(db.items)) db.items = [];

      if ((body.op || 'list') === 'delete') {
        const id = body.id | 0;
        const before = db.items.length;
        db.items = db.items.filter(it => it.id !== id);
        if (db.items.length === before) return fail(res, '未找到该条反馈，可能已被删除');
        fs.writeFileSync(FEEDBACK_FILE, JSON.stringify(db, null, 2), 'utf8');
      }

      return json(res, { ok: true, total: db.items.length, items: db.items.slice().reverse() });
    }

    /* ---- 站点统计 ---- */
    case 'stats': {
      const db = dbLoad();
      let likes = 0, views = 0, sweet = 0;
      for (const s of db.stories) {
        likes += s.likes;
        views += s.views;
        if ((s.tags || []).includes('sweet')) sweet++;
      }
      return json(res, { ok: true, stats: { stories: db.stories.length, likes, views, sweet } });
    }

    /* ---- 本周热门 ---- */
    case 'hot': {
      const limit = Math.min(10, Math.max(1, parseInt(url.searchParams.get('limit'), 10) || 5));
      const db = dbLoad();
      const stories = db.stories
        .slice()
        .sort((a, b) => hotScore(b) - hotScore(a))
        .slice(0, limit)
        .map(s => ({ id: s.id, title: s.title, views: s.views, commentsCount: commentsCount(s) }));
      return json(res, { ok: true, stories });
    }

    default:
      return fail(res, `未知接口 route=${route}`, 404);
  }
}

/* ================= 静态文件服务 ================= */

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.txt': 'text/plain; charset=utf-8',
};

function serveStatic(req, res, pathname) {
  if (pathname === '/') pathname = '/index.html';
  const file = path.normalize(path.join(ROOT, decodeURIComponent(pathname)));
  if (!file.startsWith(ROOT)) {
    res.writeHead(403); res.end('Forbidden'); return;
  }
  fs.readFile(file, (err, data) => {
    if (err) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('404 Not Found');
      return;
    }
    const ext = path.extname(file).toLowerCase();
    // HTML/JS 禁用缓存，保证页面更新后浏览器总能拿到最新版本
    const noCache = ext === '.html' || ext === '.js' || ext === '.json';
    const headers = { 'Content-Type': MIME[ext] || 'application/octet-stream' };
    if (noCache) headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
    res.writeHead(200, headers);
    res.end(data);
  });
}

/* ================= HTTP 服务器 ================= */

function createServer() {
  return http.createServer((req, res) => {
    let url;
    try {
      url = new URL(req.url, 'http://localhost');
    } catch (e) {
      res.writeHead(400); res.end('Bad Request'); return;
    }

    // API：统一走 /api.php?route=xxx（与 PHP 部署形态一致）
    if (url.pathname === '/api.php') {
      let raw = '';
      let overflow = false;
      req.on('data', chunk => {
        raw += chunk;
        if (raw.length > 1024 * 1024) { overflow = true; req.destroy(); }
      });
      req.on('end', () => {
        if (overflow) return;
        let body = {};
        if (raw) {
          try { body = JSON.parse(raw); } catch (e) { body = {}; }
        }
        try {
          handleApi(req, res, url, body);
        } catch (e) {
          fail(res, '服务器内部错误: ' + e.message, 500);
        }
      });
      return;
    }

    serveStatic(req, res, url.pathname);
  });
}

function listen(port, attemptsLeft) {
  const server = createServer();
  server.on('error', err => {
    if (err.code === 'EADDRINUSE' && attemptsLeft > 0) {
      console.log(`端口 ${port} 被占用，尝试 ${port + 1} ...`);
      listen(port + 1, attemptsLeft - 1);
    } else {
      console.error('启动失败:', err.message);
      process.exit(1);
    }
  });
  server.listen(port, () => {
    console.log('');
    console.log('  💕 缘分故事屋 本地服务已启动');
    console.log(`  👉 http://localhost:${port}`);
    console.log('  按 Ctrl+C 停止');
    console.log('');
  });
}

const startPort = parseInt(process.env.PORT, 10) || 8000;
listen(startPort, 10);
