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
const crypto = require('crypto');

const ROOT = __dirname;
const DATA_DIR = path.join(ROOT, 'data');
const DATA_FILE = path.join(DATA_DIR, 'stories.json');
const SEED_FILE = path.join(DATA_DIR, 'seed.json');
const FEEDBACK_FILE = path.join(DATA_DIR, 'feedback.json');
const ADMIN_KEY_FILE = path.join(DATA_DIR, 'admin_key.txt');
const ADMIN_GUARD_FILE = path.join(DATA_DIR, 'admin_guard.json');

/**
 * 管理页密码（用于 admin.html 查看网友反馈）
 * 优先读 data/admin_key.txt；文件不存在时自动生成随机密码并落盘。
 * 与 api.php 行为一致——不再提供公开仓库里可见的兜底默认值。
 */
function adminKey() {
  let key = '';
  try { key = String(fs.readFileSync(ADMIN_KEY_FILE, 'utf8')).trim(); } catch (e) { key = ''; }
  if (key) return key;
  key = crypto.randomBytes(6).toString('hex'); // 12 位随机密码
  try {
    if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(ADMIN_KEY_FILE, key, 'utf8');
    console.log('  [首次运行] 已自动生成管理密码，见 data/admin_key.txt');
  } catch (e) { /* 写不进去时由 adminAuth 给出明确报错 */ }
  return key;
}

/** 管理登录限流：同一 IP 连续失败达阈值即锁定（与 api.php 同规则、同记录文件） */
const ADMIN_MAX_FAILS = 5;        // 允许的连续失败次数
const ADMIN_LOCK_SECONDS = 900;   // 触发后锁定时长（15 分钟）

/** 恒定时间字符串比较（对应 PHP 的 hash_equals） */
function safeEqual(a, b) {
  const ba = Buffer.from(String(a), 'utf8');
  const bb = Buffer.from(String(b), 'utf8');
  if (ba.length !== bb.length) return false;
  return crypto.timingSafeEqual(ba, bb);
}

/** 读取限流记录：与 api.php 共用 data/admin_guard.json，删掉该文件即可立即解锁 */
function adminGuardLoad() {
  try {
    const d = JSON.parse(fs.readFileSync(ADMIN_GUARD_FILE, 'utf8'));
    return d && typeof d === 'object' && !Array.isArray(d) ? d : {};
  } catch (e) { return {}; }
}

function adminGuardSave(data) {
  try {
    if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(ADMIN_GUARD_FILE, JSON.stringify(data), 'utf8');
  } catch (e) { /* 记录不可写时不影响主流程 */ }
}

/**
 * 清理 admin_guard.json 里的陈旧记录（与 api.php 的 admin_guard_gc() 行为一致）。
 *
 * 原实现只在「登录失败」时顺手清理，于是「失败过一两次、之后再也不来」的 IP 会一直堆在文件里
 * （文件只增不减）。现在每次 adminAuth 都跑一遍：锁定期已过、且最后一次尝试距今超过一个
 * 锁定时长的记录，视为「不会再来」，直接清掉。
 *
 * @returns {boolean} 是否有记录被清理（调用方据此决定要不要写盘）
 */
function adminGuardGc(guard, now) {
  let changed = false;
  for (const ip of Object.keys(guard)) {
    const rec = guard[ip];
    if (!rec || typeof rec !== 'object' || Array.isArray(rec)) { delete guard[ip]; changed = true; continue; }
    const until = Number(rec.until || 0);
    const last = Number(rec.last || 0);
    // 仍在锁定期内 → 保留；刚失败过（还没锁定）→ 保留，等它自然过期
    if (until <= now && (now - last) > ADMIN_LOCK_SECONDS) { delete guard[ip]; changed = true; }
  }
  return changed;
}

/** 校验管理密码；未通过或处于锁定期直接响应并返回 false */
function adminAuth(req, res, body) {
  const key = adminKey();
  if (!key || !fs.existsSync(ADMIN_KEY_FILE)) {
    fail(res, '管理密码未配置，且 data/ 目录不可写。请手动创建 data/admin_key.txt 并写入你的密码', 500);
    return false;
  }

  const ip = clientIp(req);
  const now = Math.floor(Date.now() / 1000);
  const guard = adminGuardLoad();
  const cleaned = adminGuardGc(guard, now);   // 每次进来都清理陈旧记录，不再只靠失败分支
  const rec = guard[ip];

  // 锁定期内直接拒绝，不再比对密码（防止在锁定窗口里继续试）
  if (rec && Number(rec.until || 0) > now) {
    if (cleaned) adminGuardSave(guard);
    fail(res, '密码错误次数过多，请 ' + Math.ceil((Number(rec.until) - now) / 60) + ' 分钟后再试', 429);
    return false;
  }

  if (!safeEqual(key, body.key || '')) {
    // 累计失败次数，达阈值开始锁定（陈旧记录已在上面的 gc 里清掉）
    const n = Number((rec && rec.n) || 0) + 1;
    guard[ip] = { n, last: now, until: n >= ADMIN_MAX_FAILS ? now + ADMIN_LOCK_SECONDS : 0 };
    adminGuardSave(guard);
    const left = ADMIN_MAX_FAILS - n;
    fail(res, left > 0 ? '管理密码错误（还可尝试 ' + left + ' 次）' : '密码错误次数过多，请 15 分钟后再试', left > 0 ? 403 : 429);
    return false;
  }

  // 登录成功：清空该 IP 的失败记录；若刚清理过其他陈旧记录，也一并落盘
  if (guard[ip]) { delete guard[ip]; adminGuardSave(guard); }
  else if (cleaned) adminGuardSave(guard);
  return true;
}

/* ================= 内容写入限流（防脚本刷屏） ================= */

/**
 * 匿名站没有登录门槛，写接口必须限流，否则可被脚本刷爆。
 * 与 api.php 的 RATE_LIMITS 同规则、共用 data/rate_guard.json（同格式）。
 */
const RATE_GUARD_FILE = path.join(DATA_DIR, 'rate_guard.json');
const RATE_LIMITS = {
  stories:  { max: 5,  window: 600,  label: '发布' },
  comments: { max: 15, window: 300,  label: '评论' },
  feedback: { max: 3,  window: 1800, label: '提交反馈' },
};

/** 滑动窗口计数。放行返回 null；超限返回还需等待的分钟数 */
function rateCheck(action, ip) {
  const conf = RATE_LIMITS[action];
  if (!conf) return null;

  const now = Math.floor(Date.now() / 1000);
  const key = ip + '|' + action;

  let data = {};
  try {
    const d = JSON.parse(fs.readFileSync(RATE_GUARD_FILE, 'utf8'));
    if (d && typeof d === 'object' && !Array.isArray(d)) data = d;
  } catch (e) { data = {}; }   // 文件不存在/损坏：按空记录处理

  let hits = (Array.isArray(data[key]) ? data[key] : [])
    .map(Number).filter(t => now - t < conf.window);

  const over = hits.length >= conf.max;
  if (!over) hits.push(now);
  data[key] = hits;

  // 清理过期/为空的键，防止文件无限增长。
  // ⚠️ 每个键必须按**它自己那个动作**的窗口判过期：三个动作窗口不同（发布 600s / 评论 300s / 反馈 1800s），
  //    拿当前请求动作的窗口去过滤别的动作，会把仍在有效期内的记录误删 ——
  //    表现就是「发满 5 篇后随便发一条评论，发布额度就被重置」。
  for (const k of Object.keys(data)) {
    const bar = k.indexOf('|');
    const w = (RATE_LIMITS[bar === -1 ? '' : k.slice(bar + 1)] || {}).window || conf.window;
    const alive = (Array.isArray(data[k]) ? data[k] : []).map(Number).filter(t => now - t < w);
    if (!alive.length) delete data[k]; else data[k] = alive;
  }

  try {
    if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
    const tmp = RATE_GUARD_FILE + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(data), 'utf8');
    fs.renameSync(tmp, RATE_GUARD_FILE);
  } catch (e) { /* 记录不可写时不影响主流程（限流是附加保护，不挡正常用户） */ }

  if (!over) return null;
  // 最早那次命中滑出窗口时即可再次操作
  return Math.max(1, Math.ceil((Math.min(...hits) + conf.window - now) / 60));
}

/** 超限直接响应并返回 false */
function rateGuard(req, res, action) {
  const wait = rateCheck(action, clientIp(req));
  if (wait !== null) {
    const label = (RATE_LIMITS[action] || {}).label || '操作';
    fail(res, label + '太频繁啦，请 ' + wait + ' 分钟后再试 💕', 429);
    return false;
  }
  return true;
}

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

/**
 * 把 createdAtOffset（相对时间戳）归一化成 createdAt，递归处理 comments / replies。
 * 与 api.php 的 normalize_created_at() 行为保持一致。
 *
 * 背景：PHP 那边踩过坑——`foreach (($node['comments'] ?? []) as &$child)` 这种
 * 「对表达式取引用」的写法会静默失效，导致线上种子评论时间显示成 1970-01-01。
 * JS 没有这个陷阱，但两边必须都覆盖到 comments 和 replies。
 *
 * @returns {boolean} 是否发生了修改（调用方据此决定要不要写盘）
 */
function normalizeCreatedAt(node, now) {
  let dirty = false;

  if (node.createdAtOffset !== undefined) {
    if (!node.createdAt) node.createdAt = now - Number(node.createdAtOffset || 0);
    delete node.createdAtOffset;
    dirty = true;
  }

  for (const field of ['comments', 'replies']) {
    if (!Array.isArray(node[field])) continue;
    for (const child of node[field]) {
      if (normalizeCreatedAt(child, now)) dirty = true;
    }
  }

  return dirty;
}

/** 读取数据库（首次运行自动用种子初始化，时间戳按当前时间偏移生成） */
function dbLoad() {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  const now = Math.floor(Date.now() / 1000);

  if (!fs.existsSync(DATA_FILE)) {
    let seed = {};
    try { seed = JSON.parse(fs.readFileSync(SEED_FILE, 'utf8')); } catch (e) { seed = {}; }
    const stories = seed.stories || [];
    for (const s of stories) normalizeCreatedAt(s, now);
    const db = { nextId: seed.nextId || stories.length + 1, stories };
    dbSave(db);
    return db;
  }

  let db;
  try { db = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8')); } catch (e) { db = null; }
  if (!db) return { nextId: 1, stories: [] };
  if (!Array.isArray(db.stories)) db.stories = [];
  if (!db.nextId) db.nextId = db.stories.length + 1;

  // 归一化旧版/手动导入的 createdAtOffset 形态（一次写入后不再有开销）
  let dirty = false;
  for (const s of db.stories) {
    if (normalizeCreatedAt(s, now)) dirty = true;
  }
  if (dirty) dbSave(db);
  return db;
}

/**
 * 写数据库（同步 + 临时文件原子替换）。
 *
 * 说明：Node 侧**不需要** PHP 那种「读-改-写」文件事务——单线程 + 全同步文件 IO，
 * 一次请求从 dbLoad() 到 dbSave() 之间不会被其他请求打断，天然不存在 lost update。
 * （api.php 每个请求是独立进程，所以那边必须用 db_transaction() 显式加锁。）
 */
function dbSave(db) {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  const tmp = DATA_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(db, null, 2), 'utf8');
  fs.renameSync(tmp, DATA_FILE);
}

/** 原子写 JSON：先写临时文件再 rename（与 api.php 的 atomic_write() 对应） */
function writeJsonAtomic(file, obj) {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  const tmp = file + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(obj, null, 2), 'utf8');
  fs.renameSync(tmp, file);
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
// 与 api.php 保持一致：同样落盘 data/views_log.json（同一份文件、同一格式），
// 因此本地与线上行为完全等价，重启服务也不会让去重记录失效。
const VIEWS_LOG = path.join(DATA_DIR, 'views_log.json');
const VIEW_WINDOW = 24 * 60 * 60;        // 24 小时（秒，与 PHP 同单位）

/**
 * 是否信任 X-Forwarded-For 头（默认**关闭**）。
 * 站点前面没有反向代理时，XFF 完全由客户端伪造——信任它等于把限流和浏览量去重拱手让人。
 * 将来若在前面挂了 nginx/CDN，再改成 true（并确保代理是**覆盖**而不是追加该头）。
 */
const TRUST_FORWARDED_FOR = false;

function clientIp(req) {
  if (TRUST_FORWARDED_FOR) {
    const xf = req.headers['x-forwarded-for'];
    if (xf) return String(xf).split(',')[0].trim();   // 经反代时取真实 IP
  }
  return req.socket.remoteAddress || 'unknown';
}

function shouldCountView(req, storyId) {
  const key = clientIp(req) + '|' + storyId;
  const now = Math.floor(Date.now() / 1000);
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });

  let log = {};
  try {
    const parsed = JSON.parse(fs.readFileSync(VIEWS_LOG, 'utf8'));
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) log = parsed;
  } catch (e) { log = {}; }   // 文件不存在/损坏：按空日志处理（同 PHP）

  const last = Number(log[key] || 0);
  const counted = !(last && now - last < VIEW_WINDOW);
  if (counted) log[key] = now;

  // 顺手清理过期记录，防止文件无限增长
  for (const k of Object.keys(log)) {
    if (now - Number(log[k]) > VIEW_WINDOW) delete log[k];
  }

  try {
    const tmp = VIEWS_LOG + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(log), 'utf8');
    fs.renameSync(tmp, VIEWS_LOG);   // 原子替换，避免写一半被读到
  } catch (e) { /* 日志不可写时退化为仅内存判断：不影响浏览本身 */ }

  return counted;
}

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

        // 校验通过后才计数：正常用户填错重试不会被罚，只有真正落库的提交才消耗额度
        if (!rateGuard(req, res, 'stories')) return;

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
        if (!rateGuard(req, res, 'comments')) return;
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
      if (!rateGuard(req, res, 'feedback')) return;
      if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
      let db = { nextId: 1, items: [] };
      if (fs.existsSync(FEEDBACK_FILE)) {
        try { db = JSON.parse(fs.readFileSync(FEEDBACK_FILE, 'utf8')); } catch (e) { db = { nextId: 1, items: [] }; }
      }
      if (!Array.isArray(db.items)) db.items = [];
      if (!db.nextId) db.nextId = db.items.length + 1;
      db.items.push({ id: db.nextId++, nickname, contact, content, createdAt: Math.floor(Date.now() / 1000) });
      writeJsonAtomic(FEEDBACK_FILE, db);
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
      if (!adminAuth(req, res, body)) return;

      const db = dbLoad();
      if ((body.op || 'list') === 'delete') {
        const id = body.id | 0;
        const before = db.stories.length;
        db.stories = db.stories.filter(s => s.id !== id);
        if (db.stories.length === before) return fail(res, '未找到该故事，可能已被删除');
        dbSave(db);
      }

      const tag = String(body.tag || 'all');
      const all = db.stories
        .filter(s => tag === 'all' || (Array.isArray(s.tags) && s.tags.includes(tag)))
        .sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0));   // 最新在前

      // 分页：默认每页 20 条、最多 100（与 api.php 一致）
      const total = all.length;
      const page = Math.max(1, parseInt(body.page, 10) || 1);
      const pageSize = Math.min(100, Math.max(1, parseInt(body.pageSize, 10) || 20));
      const items = all.slice((page - 1) * pageSize, page * pageSize).map(s => ({
        id: s.id,
        title: s.title,
        author: (s.author && s.author.nickname) || '匿名',
        tags: s.tags || [],
        likes: s.likes,
        views: s.views,
        commentsCount: commentsCount(s),
        createdAt: s.createdAt,
      }));
      return json(res, { ok: true, total, page, pageSize, hasMore: page * pageSize < total, items });
    }

    /* ---- 管理页：查看/删除反馈 ---- */
    case 'admin_feedback': {
      if (method !== 'POST') return fail(res, '请使用 POST', 405);
      if (!adminAuth(req, res, body)) return;

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
        writeJsonAtomic(FEEDBACK_FILE, db);
      }

      // 分页：默认每页 20 条、最多 100（反馈按时间倒序，与 api.php 一致）
      const all = db.items.slice().reverse();
      const total = all.length;
      const page = Math.max(1, parseInt(body.page, 10) || 1);
      const pageSize = Math.min(100, Math.max(1, parseInt(body.pageSize, 10) || 20));
      const items = all.slice((page - 1) * pageSize, page * pageSize);
      return json(res, { ok: true, total, page, pageSize, hasMore: page * pageSize < total, items });
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

    /* ---- 热门精选（按全时段热度排序，没有时间窗口） ---- */
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

  let decoded;
  try {
    decoded = decodeURIComponent(pathname);
  } catch (e) {
    // 畸形百分号转义（如 /%）会让 decodeURIComponent 抛异常，不接住会直接崩掉进程
    res.writeHead(400, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('400 Bad Request');
    return;
  }

  const file = path.normalize(path.join(ROOT, decoded));
  // 目录穿越防护：必须严格位于 ROOT 之内。
  // 用 ROOT + 分隔符比较，否则 `念爱故事屋-evil` 这类同前缀的兄弟目录会被 startsWith 误判为站内。
  if (file !== ROOT && !file.startsWith(ROOT + path.sep)) {
    res.writeHead(403, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('403 Forbidden');
    return;
  }

  // 🔒 data/ 目录（管理密码 / 全站数据库 / 删除凭证 / 网友反馈）不允许通过 HTTP 读取，
  //    与线上 Apache 的 deny 规则保持一致——应用只经 api.php 走文件系统读取。
  if (file === DATA_DIR || file.startsWith(DATA_DIR + path.sep)) {
    res.writeHead(403, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('403 Forbidden');
    return;
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
