<?php
/**
 * 缘分故事屋 - 单文件 PHP 后端（零依赖，JSON 文件存储，无需 MySQL）
 *
 * 接口与 server.js（本地 Node 开发服务器）完全对齐。
 * 前端统一请求：api.php?route=xxx
 *
 * 部署：宝塔新建 PHP 站点（PHP 7.4+），上传 index.html、api.php、data/ 目录，
 *       并保证 data/ 目录可写（www 用户写权限）。
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
// 站点为同源应用（所有页面都以相对路径请求 api.php），不需要任何跨域授权。
// ⚠️ 这里曾放开 `Access-Control-Allow-Origin: *`，会让任意第三方站点在浏览器里
//    跨域调用 admin_* 管理接口暴力猜密码，已移除；确需跨域时请显式列出自己的域名。
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

define('DATA_DIR', __DIR__ . '/data');
define('DATA_FILE', DATA_DIR . '/stories.json');
define('SEED_FILE', DATA_DIR . '/seed.json');
define('FEEDBACK_FILE', DATA_DIR . '/feedback.json');
define('VIEWS_LOG', DATA_DIR . '/views_log.json');
define('ADMIN_KEY_FILE', DATA_DIR . '/admin_key.txt');
define('ADMIN_GUARD_FILE', DATA_DIR . '/admin_guard.json');
define('RATE_GUARD_FILE', DATA_DIR . '/rate_guard.json');
define('DELETIONS_FILE', DATA_DIR . '/deletions.json');   // 访客删除动态（管理页提示用）
define('DELETIONS_MAX', 200);                             // 最多保留条数，防止文件无限增长
define('VIEW_WINDOW', 43200);   // 浏览量去重窗口（秒）= 12 小时
define('VIEW_IP_MAX', 20);      // 同 IP 对同一故事在窗口内的计数上限（防清 localStorage 刷量）

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

/* ================= 工具函数 ================= */

function respond($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void
{
    respond(['ok' => false, 'error' => $msg], $code);
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '', true);
    return is_array($j) ? $j : [];
}

/**
 * 原子写文件：先写同目录临时文件，再 rename 覆盖。
 *
 * rename 在同一文件系统内是原子操作，读者要么看到旧内容、要么看到新内容；
 * 而直接 `ftruncate + fwrite` 中间会有一段「文件已被清空但还没写完」的窗口，
 * 此时进程被杀 / 断电就会留下残缺的 JSON —— 数据文件一旦这样坏掉就是全站读不出来。
 */
function atomic_write(string $file, string $content): bool
{
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) { return false; }
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

/**
 * 取得某个文件的排他锁。
 *
 * 为什么用**独立的 `.lock` 文件**而不是锁数据文件本身：
 * 原子写靠 rename 替换文件，而 flock 锁的是 inode —— rename 之后原来的锁
 * 就跟着旧 inode 一起作废了，后续请求会锁到新 inode 上，互斥直接失效。
 * 所以锁必须落在一个不会被替换的文件上。
 *
 * @return resource|null 成功返回句柄（调用方负责 unlock_guard()），失败返回 null
 */
function lock_guard(string $file)
{
    $fp = @fopen($file . '.lock', 'c');
    if (!$fp) { return null; }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return null; }
    return $fp;
}

function unlock_guard($fp): void
{
    if (!$fp) { return; }
    flock($fp, LOCK_UN);
    fclose($fp);
}

/* ================= 访客删除动态（管理页「删除动态」提示用） ================= */

/**
 * 记录一次访客自发删除（故事 / 评论），管理页据此提示「有内容被别人删除」。
 * 站长自己在管理页删除的不记（自己删的自己知道）。
 * 记录失败只影响提示，不影响删除本身 —— 调用方无需检查返回值。
 */
function record_deletion(array $evt): void
{
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
    $lock = lock_guard(DELETIONS_FILE);
    // 拿不到锁就直接放弃：宁可不记，也不能在无锁状态下读-改-写
    // （可能与另一个并发记录互相覆盖，甚至把半截内容写进文件）
    if (!$lock) { return; }
    try {
        $db = json_decode((string)@file_get_contents(DELETIONS_FILE) ?: '', true);
        if (!is_array($db)) { $db = []; }
        if (!isset($db['nextId']) || !is_numeric($db['nextId'])) { $db['nextId'] = 1; }
        if (!isset($db['readUpTo']) || !is_numeric($db['readUpTo'])) { $db['readUpTo'] = 0; }
        if (!isset($db['items']) || !is_array($db['items'])) { $db['items'] = []; }

        $db['items'][] = [
            'id'        => (int)$db['nextId']++,
            'type'      => (($evt['type'] ?? '') === 'comment') ? 'comment' : 'story',
            'storyId'   => (int)($evt['storyId'] ?? 0),
            'title'     => mb_substr((string)($evt['title'] ?? ''), 0, 60),
            'author'    => mb_substr((string)($evt['author'] ?? ''), 0, 20),
            'excerpt'   => mb_substr((string)($evt['excerpt'] ?? ''), 0, 120),
            'extra'     => (string)($evt['extra'] ?? ''),
            'likes'     => (int)($evt['likes'] ?? 0),
            'views'     => (int)($evt['views'] ?? 0),
            'comments'  => (int)($evt['comments'] ?? 0),
            'createdAt' => time(),
        ];
        if (count($db['items']) > DELETIONS_MAX) { $db['items'] = array_slice($db['items'], -DELETIONS_MAX); }
        atomic_write(DELETIONS_FILE, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } finally {
        unlock_guard($lock);
    }
}

/**
 * 把 createdAtOffset（相对时间戳）归一化成 createdAt，递归处理 comments / replies。
 *
 * ⚠️ 这里必须**先把数组取到变量再遍历**：
 *      `foreach (($node['comments'] ?? []) as &$child)` 这种「对表达式取引用」的写法，
 *      PHP 改的是临时副本，原数组不会被更新——而且**不报错、静默失效**。
 *      曾经因此让线上所有种子评论的 createdAt 缺失，前端时间显示成 1970-01-01。
 *
 * @return bool 是否发生了修改（调用方据此决定要不要写盘）
 */
function normalize_created_at(array &$node, int $now): bool
{
    $dirty = false;

    if (isset($node['createdAtOffset'])) {
        if (empty($node['createdAt'])) { $node['createdAt'] = $now - (int)$node['createdAtOffset']; }
        unset($node['createdAtOffset']);
        $dirty = true;
    }

    foreach (['comments', 'replies'] as $field) {
        if (!isset($node[$field]) || !is_array($node[$field])) { continue; }
        $list = $node[$field];              // ← 关键：先取到变量，不能对表达式取引用
        foreach ($list as &$child) {
            if (normalize_created_at($child, $now)) { $dirty = true; }
        }
        unset($child);
        $node[$field] = $list;
    }

    return $dirty;
}

/**
 * 把数据库里的旧格式（createdAtOffset）归一化成 createdAt（递归覆盖 comments/replies），
 * 并补齐缺失的 nextId。就地修改 $db。
 *
 * @return bool 是否发生了改动（调用方据此决定要不要写盘）
 */
function db_normalize(array &$db, int $now): bool
{
    if (!isset($db['stories']) || !is_array($db['stories'])) { $db['stories'] = []; }
    $dirty = false;
    foreach ($db['stories'] as &$s) {
        if (normalize_created_at($s, $now)) { $dirty = true; }
    }
    unset($s);
    if (!isset($db['nextId'])) { $db['nextId'] = count($db['stories']) + 1; $dirty = true; }
    return $dirty;
}

/** 用种子数据构造一份全新的数据库（首次运行 / 数据文件为空时用） */
function db_from_seed(int $now): array
{
    $seed = [];
    if (is_file(SEED_FILE)) {
        $seed = json_decode((string)file_get_contents(SEED_FILE), true) ?: [];
    }
    $stories = $seed['stories'] ?? [];
    foreach ($stories as &$s) { normalize_created_at($s, $now); }
    unset($s);
    return ['nextId' => (int)($seed['nextId'] ?? (count($stories) + 1)), 'stories' => $stories];
}

/** 读取数据库（只读接口用；首次运行自动用种子数据初始化，时间戳按当前时间偏移生成） */
function db_load(): array
{
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
    $now = time();

    if (!file_exists(DATA_FILE)) {
        $db = db_from_seed($now);
        db_save($db);
        return $db;
    }

    $db = json_decode((string)file_get_contents(DATA_FILE), true);
    if (!is_array($db)) { fail('数据文件损坏，请联系管理员', 500); }
    // 归一化旧版/手动导入数据里的 createdAtOffset（转成 createdAt 并清理，仅在有修复时写回）
    if (db_normalize($db, $now)) { db_save($db); }
    return $db;
}

/**
 * 写数据库（独立锁文件互斥 + 原子替换）。
 *
 * ⚠️ 它只保证「这一次写入」安全，**并不保护「读-改-写」整个事务**。
 *    凡是「先读出来、改完再写回」的接口，一律用 db_transaction()，
 *    否则并发下会丢更新（见下）。
 */
function db_save(array $db): void
{
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
    $payload = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $lock = lock_guard(DATA_FILE);
    if ($payload === false || !atomic_write(DATA_FILE, $payload)) {
        unlock_guard($lock);
        fail('无法写入数据文件', 500);
    }
    unlock_guard($lock);
}

/**
 * 「读-改-写」事务：**全程持有数据文件的排他锁**，避免并发写丢更新。
 *
 * 为什么需要它：原先写接口都是 `db_load() → 改 → db_save()` 三段式，
 * flock 只覆盖 db_save 内部那一次写。两个请求同时读到同一份快照、各自改完再写回，
 * 后写的会把先写的整份覆盖掉（经典 lost update）——表现为「评论/点赞偶尔凭空消失」。
 * PHP 每个请求是独立进程，必须靠文件锁把整个事务圈起来。
 *
 * 用法：
 *   $result = db_transaction(function (array &$db) {
 *       // 在这里修改 $db；返回值即 db_transaction 的返回值
 *       return $whatever;
 *   });
 *
 * 约定：
 * - 回调里**不要再调用 db_save()**（同进程重复加锁会死锁）；
 * - 回调里调用 fail() 会直接结束请求且**不写盘**，适合「校验不通过就中止」；
 * - 只有 $db 真的变化了才写盘，读多写少的接口不会产生空写。
 *
 * Node 侧（server.js）不需要这个：单线程 + 全同步文件 IO，
 * 一次请求处理中途不会被其他请求打断，天然不存在该竞态。
 */
function db_transaction(callable $fn)
{
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
    $now = time();

    $lock = lock_guard(DATA_FILE);
    if (!$lock) { fail('数据文件繁忙，请稍后重试', 503); }

    $db = json_decode((string)@file_get_contents(DATA_FILE) ?: '', true);
    if (!is_array($db)) { $db = db_from_seed($now); }   // 文件为空/损坏 → 按首次运行初始化
    db_normalize($db, $now);

    $before = json_encode($db, JSON_UNESCAPED_UNICODE);
    $result = $fn($db);                                  // ← 回调在持锁状态下修改 $db
    $after  = json_encode($db, JSON_UNESCAPED_UNICODE);

    if ($after !== $before) {
        $payload = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($payload !== false) { atomic_write(DATA_FILE, $payload); }
    }
    unlock_guard($lock);

    return $result;
}

/**
 * 把 <link rel="stylesheet"> 转成 <style>@import url("...")</style>。
 *
 * 净化流程会删掉 link 标签（防 preconnect / icon / preload 之类的外链探测），
 * 但样式表本身是排版的一部分：AI 生成的 HTML 几乎都用
 *   <link href="https://fonts.googleapis.com/css2?family=..." rel="stylesheet">
 * 引入字体。整条丢弃后，字体只剩 font-family 声明、没有字体文件，
 * 浏览器必然回退成系统默认宋体 —— 用户看到的就是「字体不显示」。
 *
 * 转成 @import 之后：link 元素依然进不了 DOM，样式表照常加载。
 * 只放行 http(s) 与协议相对地址，data/file/javascript 一律丢弃。
 */
function link_to_import(string $html): string
{
    return preg_replace_callback('#<link\b[^>]*>#i', static function (array $m): string {
        $tag = $m[0];
        // 只处理样式表；preconnect / icon / preload 直接丢弃
        if (!preg_match('#\brel\s*=\s*("|\')?[^"\'>]*\bstylesheet\b#i', $tag)) {
            return '';
        }
        if (!preg_match('#\bhref\s*=\s*("|\')([^"\']+)\1#i', $tag, $h)) {
            return '';
        }
        $url = trim($h[2]);
        // URL 里不允许出现引号（正则已排除），避免逃出 @import 的字符串
        if (!preg_match('#^(?:https?:)?//#i', $url)) {
            return '';
        }
        return '<style>@import url("' . $url . '");</style>';
    }, $html) ?? '';
}

/* ================= 站内字体代理（Google Fonts） =================
 *
 * 作者 HTML 常用 Google Fonts 的中文创意字体（站酷小薇、志莽行书…），
 * 但 fonts.googleapis.com 在国内基本超时，加载不到就回退成系统宋体。
 * 服务器本身能访问 Google（实测 ~100ms），因此由服务端代理并落盘缓存：
 * 浏览器只跟本站通信，字体就能真正显示出来。
 *
 * 安全约束（都很关键，别放松）：
 *   - 只放行 https + fonts.googleapis.com / fonts.gstatic.com
 *   - 不跟随重定向（否则可 302 跳到内网地址，等于 SSRF）
 *   - 单资源上限 3MB，超时 8s
 *   - 任何失败都返回 404，让浏览器回落到 local() / 系统字体
 */

const FONT_MAX_BYTES = 3145728;    // 单资源上限 3MB
const FONT_CACHE_TTL = 2592000;    // 缓存 30 天
// 代理地址的版本号：改动「CSS 改写逻辑」时把它 +1，
// 因为浏览器会把代理 CSS 缓存 30 天，不加版本号用户会一直用旧改写结果。
// 注意它只影响浏览器缓存 key，服务端缓存仍按原始 URL 的 sha1 命中。
const FONT_PROXY_VER = 2;

/** 站内可代理的字体：小写字体名 => Google Fonts family 参数 */
const FONT_GOOGLE_MAP = [
    'zcool xiaowei'         => 'ZCOOL+XiaoWei',
    'zcool kuaile'          => 'ZCOOL+KuaiLe',
    'zcool qingke huangyou' => 'ZCOOL+QingKe+HuangYou',
    'ma shan zheng'         => 'Ma+Shan+Zheng',
    'zhi mang xing'         => 'Zhi+Mang+Xing',
    'long cang'             => 'Long+Cang',
    'liu jian mao cao'      => 'Liu+Jian+Mao+Cao',
    'noto serif sc'         => 'Noto+Serif+SC',
    'noto sans sc'          => 'Noto+Sans+SC',
    'lxgw wenkai tc'        => 'LXGW+WenKai+TC',
    'inter'                 => 'Inter',
    'roboto'                => 'Roboto',
    'playfair display'      => 'Playfair+Display',
    'merriweather'          => 'Merriweather',
    'lora'                  => 'Lora',
    'open sans'             => 'Open+Sans',
    'lato'                  => 'Lato',
    'montserrat'            => 'Montserrat',
    'poppins'               => 'Poppins',
    'noto serif'            => 'Noto+Serif',
    'noto sans'             => 'Noto+Sans',
    'eb garamond'           => 'EB+Garamond',
    'cormorant garamond'    => 'Cormorant+Garamond',
];

function font_cache_dir(): string
{
    return DATA_DIR . '/fontcache';
}

function b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) { $s .= str_repeat('=', 4 - $pad); }
    $out = base64_decode($s, true);
    return $out === false ? '' : $out;
}

/** 外部字体地址 → 站内代理地址（带版本号，见 FONT_PROXY_VER） */
function proxy_font_url(string $url): string
{
    return 'api.php?route=font&u=' . b64url_encode($url) . '&v=' . FONT_PROXY_VER;
}

/** 正文里出现的、站内可代理的字体名（保持首次出现顺序） */
function used_google_fonts(string $html): array
{
    if (!preg_match_all('/font-family\s*:\s*([^;}\n]+)/i', $html, $m)) { return []; }
    $found = [];
    foreach ($m[1] as $decl) {
        foreach (preg_split('/,/', $decl) ?: [] as $name) {
            $name = strtolower(trim(trim($name), "\"'"));
            if ($name !== '' && isset(FONT_GOOGLE_MAP[$name]) && !isset($found[$name])) {
                $found[$name] = FONT_GOOGLE_MAP[$name];
            }
        }
    }
    return $found;
}

/** 为正文用到的字体生成 <link>（走站内代理，国内可达） */
function font_link_tags(string $html): string
{
    $out = '';
    foreach (used_google_fonts($html) as $family) {
        $css = 'https://fonts.googleapis.com/css2?family=' . $family . '&display=swap';
        $out .= '<link rel="stylesheet" href="' . proxy_font_url($css) . '">' . "\n";
    }
    return $out;
}

/** 把正文/样式表里的 Google 字体地址全部换成站内代理地址 */
function rewrite_google_font_urls(string $html): string
{
    return preg_replace_callback(
        '#https://fonts\.(?:googleapis|gstatic)\.com/[^\s"\'()]+#i',
        static function (array $m): string { return proxy_font_url($m[0]); },
        $html
    ) ?? $html;
}

/** 抓取远端资源（curl 优先，未装扩展时退回 file_get_contents） */
function font_fetch(string $url): ?string
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,   // 不跟随重定向：防 SSRF 跳到内网
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => $ua,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $code !== 200 || strlen($body) > FONT_MAX_BYTES) { return null; }
        return $body;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => $ua]]);
    $body = @file_get_contents($url, false, $ctx, 0, FONT_MAX_BYTES);
    return is_string($body) && $body !== '' ? $body : null;
}

/** 字体代理路由：api.php?route=font&u=<base64url(原始URL)> */
function serve_font_proxy(): void
{
    $url = b64url_decode((string)($_GET['u'] ?? ''));
    $p = $url === '' ? false : parse_url($url);
    $host = $p ? strtolower((string)($p['host'] ?? '')) : '';
    if (!$p || ($p['scheme'] ?? '') !== 'https'
        || !in_array($host, ['fonts.googleapis.com', 'fonts.gstatic.com'], true)) {
        http_response_code(404);
        exit;
    }

    $isCss = $host === 'fonts.googleapis.com';
    $dir  = font_cache_dir();
    $path = $dir . '/' . sha1($url) . ($isCss ? '.css' : '.woff2');

    $body = null;
    if (is_file($path) && (time() - (int)filemtime($path)) < FONT_CACHE_TTL) {
        $cached = @file_get_contents($path);
        if ($cached !== false) { $body = $cached; }
    }
    if ($body === null) {
        $body = font_fetch($url);
        if ($body !== null) {
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $tmp = $path . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $body) !== false && !@rename($tmp, $path)) {
                @unlink($tmp);
            }
        }
    }
    if ($body === null || $body === '') {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit;
    }

    if ($isCss) {
        // woff2 分片地址同样改写成代理；再给每个 @font-face 的 src 加 local() 前缀，
        // 本机装了同名字体时直接用本机字体，省掉整片字体文件的下载
        $body = rewrite_google_font_urls($body);
        $body = preg_replace_callback('#@font-face\s*\{[^}]*\}#i', static function (array $m): string {
            $block = $m[0];
            if (!preg_match('#font-family:\s*([\'"])([^\'"]+)\1#i', $block, $f)) { return $block; }
            $esc = str_replace(['\\', "'"], ['\\\\', "\\'"], $f[2]);
            return preg_replace('#\bsrc:\s*#i', "src: local('" . $esc . "'), ", $block, 1) ?? $block;
        }, $body) ?? $body;
        header('Content-Type: text/css; charset=utf-8');
    } else {
        header('Content-Type: font/woff2');
        // sandbox iframe 是独立的不透明源，字体属 CORS 请求（Origin: null），必须显式放行
        header('Access-Control-Allow-Origin: *');
    }
    header('Cache-Control: public, max-age=' . FONT_CACHE_TTL);
    echo $body;
    exit;
}

/**
 * 字体兼容垫片：随 raw 页注入到 <head> 最前面（优先级最低，作者样式可覆盖）。
 *
 * 作者 HTML 常写 font-family:'Noto Serif SC' 却不带字体文件；字体 CDN 在
 * 国内基本不可达，一旦加载失败就回退成系统宋体，观感和原设计差很远。
 * 这里用 @font-face + local() 把高频字体名映射到本机已有的等价字体：
 * 零网络请求、必然命中、不会闪字（local 是同步的）。
 * 若本机真装了同名字体，local('同名') 排第一位，仍然优先用真字体。
 */
const FONT_SHIM_CSS = <<<'CSS'
/* 中文衬线 / 宋体系 */
@font-face{font-family:'Noto Serif SC';src:local('Noto Serif SC'),local('Source Han Serif SC'),local('Source Han Serif CN'),local('Noto Serif CJK SC'),local('Songti SC'),local('STSong'),local('华文宋体'),local('SimSun'),local('宋体');font-display:swap}
@font-face{font-family:'Source Han Serif SC';src:local('Source Han Serif SC'),local('Noto Serif SC'),local('Noto Serif CJK SC'),local('Songti SC'),local('STSong'),local('华文宋体'),local('SimSun')}
@font-face{font-family:'ZCOOL XiaoWei';src:local('ZCOOL XiaoWei'),local('Source Han Serif SC'),local('Noto Serif SC'),local('Noto Serif CJK SC'),local('Songti SC'),local('STSong'),local('华文宋体'),local('SimSun')}
@font-face{font-family:'Songti SC';src:local('Songti SC'),local('STSong'),local('华文宋体'),local('SimSun')}
/* 中文无衬线 / 黑体系 */
@font-face{font-family:'Noto Sans SC';src:local('Noto Sans SC'),local('Source Han Sans SC'),local('Source Han Sans CN'),local('Noto Sans CJK SC'),local('PingFang SC'),local('Microsoft YaHei'),local('微软雅黑'),local('MiSans'),local('HarmonyOS Sans SC'),local('SimHei'),local('黑体');font-display:swap}
@font-face{font-family:'Source Han Sans SC';src:local('Source Han Sans SC'),local('Noto Sans SC'),local('Noto Sans CJK SC'),local('PingFang SC'),local('Microsoft YaHei'),local('微软雅黑'),local('SimHei')}
@font-face{font-family:'PingFang SC';src:local('PingFang SC'),local('PingFang Medium'),local('Microsoft YaHei'),local('微软雅黑'),local('Source Han Sans SC'),local('Noto Sans SC')}
@font-face{font-family:'ZCOOL KuaiLe';src:local('ZCOOL KuaiLe'),local('YouYuan'),local('幼圆'),local('Microsoft YaHei'),local('微软雅黑')}
@font-face{font-family:'ZCOOL QingKe HuangYou';src:local('ZCOOL QingKe HuangYou'),local('Microsoft YaHei'),local('微软雅黑'),local('SimHei'),local('黑体')}
/* 手写 / 书法 */
@font-face{font-family:'LXGW WenKai';src:local('LXGW WenKai'),local('霞鹜文楷'),local('KaiTi'),local('STKaiti'),local('华文楷体'),local('楷体')}
@font-face{font-family:'Ma Shan Zheng';src:local('Ma Shan Zheng'),local('STXingkai'),local('华文行楷'),local('KaiTi'),local('STKaiti'),local('楷体')}
@font-face{font-family:'Zhi Mang Xing';src:local('Zhi Mang Xing'),local('STXingkai'),local('华文行楷'),local('KaiTi'),local('STKaiti')}
@font-face{font-family:'Long Cang';src:local('Long Cang'),local('STXingkai'),local('华文行楷'),local('KaiTi'),local('STKaiti')}
@font-face{font-family:'Liu Jian Mao Cao';src:local('Liu Jian Mao Cao'),local('STXingkai'),local('华文行楷'),local('KaiTi'),local('STKaiti')}
@font-face{font-family:'Kaiti SC';src:local('Kaiti SC'),local('STKaiti'),local('华文楷体'),local('KaiTi'),local('楷体')}
/* 西文 */
@font-face{font-family:'Inter';src:local('Inter'),local('Segoe UI'),local('Helvetica Neue'),local('Arial')}
@font-face{font-family:'Roboto';src:local('Roboto'),local('Segoe UI'),local('Arial')}
@font-face{font-family:'Playfair Display';src:local('Playfair Display'),local('Georgia'),local('Times New Roman')}
@font-face{font-family:'Merriweather';src:local('Merriweather'),local('Georgia')}
@font-face{font-family:'Lora';src:local('Lora'),local('Georgia')}
CSS;

/**
 * HTML 轻净化：去 script/危险属性（保留 style/结构/动画）。
 * mode=html 的内容阅读时通过沙箱 iframe（route=raw）渲染，与站点完全隔离，
 * 因此无需再做 CSS 作用域隔离，样式 100% 原样。
 *
 * 两个例外要保住，否则排版会失真：
 *   1. <link rel="stylesheet"> → 转成 @import（见 link_to_import）
 *   2. <meta name="viewport">  → 移动端布局必需，且无攻击面
 */
function sanitize_html(string $html): string
{
    $html = link_to_import($html);
    // viewport 先用占位符保护起来，躲过下面的 meta 黑名单，净化完再还原
    $html = preg_replace_callback('#<meta\b[^>]*>#i', static function (array $m): string {
        return preg_match('#\bname\s*=\s*("|\')?viewport\b#i', $m[0]) ? "\x02VIEWPORT\x02" : $m[0];
    }, $html) ?? '';

    $html = preg_replace('#<\s*script\b[^>]*>[\s\S]*?<\s*/\s*script\s*>#is', '', $html) ?? '';
    $html = preg_replace('#<\s*/?\s*script\b[^>]*>#is', '', $html) ?? '';
    $html = preg_replace('#<\s*(iframe|object|embed|form|link|meta)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
    $html = preg_replace('#<\s*/?\s*(iframe|object|embed|form|link|meta|input|button|textarea|select)\b[^>]*>#is', '', $html) ?? '';
    $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
    $html = preg_replace('#(href|src)\s*=\s*("|\')\s*(javascript|vbscript):[^"\']*#i', '$1=$2#', $html) ?? '';
    $html = preg_replace('#(href|src)\s*=\s*(?!["\'])(javascript|vbscript):[^\s>]*#i', '', $html) ?? '';
    $html = preg_replace('#(?<![-\w])behavior\s*:#i', '', $html) ?? '';
    $html = preg_replace('#expression\s*\(#i', '', $html) ?? '';

    return trim(str_replace("\x02VIEWPORT\x02", '<meta name="viewport" content="width=device-width, initial-scale=1">', $html));
}

/** 详情页「滚动显现动画」驱动器（站点注入的安全脚本，随 raw 输出附带）
 *
 * 目的：作者页面常见写法是「初始 opacity:0 + 滚动 IntersectionObserver 揭示」。
 * 一旦揭示脚本失效（净化版、或脚本被 CSP 拦下），正文会整片看不见，所以要兜底。
 *
 * ⚠️ 早先的实现是给这些元素批量 addClass(['visible','active','show','in','on',...,
 * 'display']) —— 靠「猜」作者的揭示类名。这个方案有个致命副作用：这些词在作者页面里
 * 太常见，撞上就是灾难。《那年·白衣》（2026-10-30）作者用 .display 做 72px 主标题，
 * 注入脚本把 display 加到了每个待揭示元素上，于是页头小字、正文段落全被套上
 * font-size:72px / line-height:1.05，手机上正文被挤成每行两个字。
 *
 * 现在改成直接写内联样式（带 !important）强制可见：完全不碰 class，与作者命名零冲突；
 * 过渡动画仍由作者 CSS 的 transition 负责，观感不变。
 */
const REVEAL_SCRIPT = <<<'HTML'
<script>
(function () {
  function reveal(el) {
    el.style.setProperty('opacity', '1', 'important');
    el.style.setProperty('transform', 'none', 'important');
  }
  function drive() {
    var targets = [];
    document.querySelectorAll('*').forEach(function (el) {
      if (getComputedStyle(el).opacity !== '0') return;
      targets.push(el);
    });
    if (!targets.length) return;
    if (!('IntersectionObserver' in window)) {
      targets.forEach(reveal);
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        reveal(entry.target);
        io.unobserve(entry.target);
      });
    }, { threshold: 0.06, rootMargin: '60px' });
    targets.forEach(function (el) { io.observe(el); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', drive);
  else drive();
})();
</script>
HTML;

/* ================= 原始 HTML 源文件（原样保存、原样阅读） =================
 *
 * 上传的 .html 源文件单独落盘到 data/uploads/{id}.html，raw 路由优先读它。
 * 作者文件里的 <script>、<style>、注释、渲染结构都保持原样 ——
 * 净化版（stories.json 的 content）只用于列表摘要与搜索，不再承担「显示全文」。
 *
 * 安全边界靠双重沙箱 + CSP 收紧，而不是靠删标签：
 *   - iframe sandbox="allow-scripts" + 响应头 CSP: sandbox allow-scripts
 *     → 文档是独立的不透明源，读不到站点 cookie / localStorage / 同源接口
 *   - connect-src 'none'  → fetch / XHR / WebSocket 全禁（挡挖矿与外传）
 *   - form-action 'none'  → 挡住钓鱼表单提交
 *   - base-uri 'none'     → 防止用 <base> 劫持相对地址
 */

const UPLOAD_MAX_BYTES = 524288;   // 单文件上限 512KB（与前端上传限制一致）

function upload_dir(): string
{
    return DATA_DIR . '/uploads';
}

function upload_path(int $id): string
{
    return upload_dir() . '/' . $id . '.html';
}

/** 写入原始 HTML；返回是否成功（失败不影响发布，只是退回净化版显示） */
function upload_source_write(int $id, string $html): bool
{
    $dir = upload_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return false; }
    $path = upload_path($id);
    $tmp  = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $html) === false) { return false; }
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

function upload_source_read(int $id): ?string
{
    $path = upload_path($id);
    if (!is_file($path)) { return null; }
    $body = @file_get_contents($path);
    return $body === false ? null : $body;
}

function upload_source_delete(int $id): void
{
    $path = upload_path($id);
    if (is_file($path)) { @unlink($path); }
}

/**
 * 结构容错：CSS 里写了 `.hero h1 { font-family: ... }`，但 HTML 里没有 .hero 容器时，
 * 把这条字体规则补到 h1 上。
 *
 * 成因：AI 生成的单文件 HTML 常见「CSS 选择器与 DOM 结构对不上」——
 * 样式按 .hero 后代写，结构里却只有 .wrap，于是字体规则永远匹配不到元素，
 * 标题默默退回系统默认字体。只补 font-family，不动 padding/text-align 等布局属性，
 * 避免二次改变版式。仅在没有 .hero 元素时触发，正常文件不受影响。
 */
function shim_orphan_font_rules(string $html): string
{
    // 没有任何 .hero 选择器 → 与本次容错无关
    if (!preg_match('#\.hero\b#', $html)) { return $html; }
    // 已经存在 .hero 元素 → 规则本来就能匹配，不插手。
    // 注意不能用 \bhero\b 判断：class="hero-rule" 也会命中。必须按 class token 精确比对
    if (preg_match_all('#class\s*=\s*["\']([^"\']*)["\']#i', $html, $cm)) {
        foreach ($cm[1] as $cls) {
            if (in_array('hero', preg_split('/\s+/', trim($cls)), true)) { return $html; }
        }
    }

    $extra = '';
    $pairs = [
        // h1/h2 用 :first-of-type 限定：只影响页头那一个，
        // 不会把正文里十几个章节标题一起改掉
        ['#\.hero\s+h1\s*\{([^}]*)\}#i',      'h1:first-of-type'],
        ['#\.hero\s+h2\s*\{([^}]*)\}#i',      'h2:first-of-type'],
        ['#\.hero\s+\.hand\s*\{([^}]*)\}#i',  '.hand'],
        ['#\.hero\s+\.kicker\s*\{([^}]*)\}#i', '.kicker'],
    ];
    foreach ($pairs as $pair) {
        if (!preg_match($pair[0], $html, $m)) { continue; }
        if (!preg_match('#font-family\s*:\s*([^;}]+)#i', $m[1], $f)) { continue; }
        $extra .= $pair[1] . '{font-family:' . trim($f[1]) . '}';
    }
    if ($extra === '') { return $html; }

    // 追加到最后一个 </style> 之前：同权重靠后者胜出，确保能盖住前面的规则
    $pos = strripos($html, '</style>');
    if ($pos === false) { return $html; }
    return substr($html, 0, $pos) . $extra . "\n" . substr($html, $pos);
}

/** 输出故事的原始 HTML（沙箱隔离，供 iframe/新窗口阅读）
 *  $rawSource 不为 null 时用原始文件（未净化），否则回落到净化后的 content */
function serve_raw_story(array $story, ?string $rawSource = null): void
{
    $title = preg_replace('/[<>&"]/', '', (string)($story['title'] ?? '故事')) ?: '故事';
    $body = $rawSource !== null ? $rawSource : (string)($story['content'] ?? '');
    // 存量内容里可能残留 Google 字体地址（新发布会转成 @import），统一改写成站内代理
    $body = rewrite_google_font_urls($body);
    // CSS 选择器与 DOM 结构对不上时（AI 生成的 HTML 常见），把标题字体规则补到位
    $body = shim_orphan_font_rules($body);
    // 垫片在前（本机字体兜底），Google CSS 在后（真字体覆盖同名定义，src 已带 local 前缀）
    $shim = "<style>\n" . FONT_SHIM_CSS . "\n</style>\n" . font_link_tags($body);

    // 作者上传的可能是完整网页（<!DOCTYPE html> / <html>）。这种情况原样输出，
    // 不再套一层壳 —— 否则 <head> 里的 viewport、样式表顺序、<base> 全被打乱，
    // 移动端会按 980px 布局宽度渲染，排版直接变形。
    if (preg_match('#<(?:!doctype\s+html|html)\b#i', $body)) {
        $html = $body;
        if (preg_match('#<head\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            // 垫片插在 <head> 最前面：优先级最低，作者自己的样式表随后覆盖
            $pos  = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $pos) . "\n" . $shim . substr($html, $pos);
        } elseif (preg_match('#<html\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos  = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $pos)
                . "\n<head>\n<meta charset=\"UTF-8\">\n"
                . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
                . "<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title>\n"
                . $shim . "</head>\n"
                . substr($html, $pos);
        } else {
            $html = $shim . $html;
        }
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('#</body>#i', REVEAL_SCRIPT . "\n</body>", $html, 1) ?? $html;
        } else {
            $html .= "\n" . REVEAL_SCRIPT;
        }
    } else {
        $html = "<!DOCTYPE html>\n<html lang=\"zh-CN\">\n<head>\n<meta charset=\"UTF-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
            . "<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title>\n"
            . "<style>html,body{margin:0;padding:0;}</style>\n"
            . $shim
            . "</head>\n<body>\n" . $body . "\n" . REVEAL_SCRIPT . "\n</body>\n</html>";
    }

    header('Content-Type: text/html; charset=utf-8');
    // 沙箱化：文档为独立源，内部脚本无法访问站点数据/存储，仅可运行自身动画。
    // 原始源文件模式会带上作者的 <script>，因此再收紧一层：禁网络外联与表单提交
    header("Content-Security-Policy: sandbox allow-scripts; connect-src 'none'; form-action 'none'; base-uri 'none'");
    header('Cache-Control: no-cache');
    echo $html;
    exit;
}

/** 生成摘要（列表页用，取前 90 字；先剥掉 style/script 块防止 CSS 文本泄漏） */
function make_excerpt(string $content, string $mode): string
{
    $text = $content;
    if ($mode === 'html') {
        $text = preg_replace('#<(style|script)\b[^>]*>[\s\S]*?</\1>#i', ' ', $text) ?? '';
        $text = strip_tags($text);
    }
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    return mb_strlen($text) > 90 ? mb_substr($text, 0, 90) . '…' : $text;
}

/** 随机匿名昵称 */
function random_nickname(): string
{
    $adjs = ['温柔', '爱笑', '好奇', '慢热', '迷糊', '认真', '元气', '安静的'];
    $nouns = ['小鹿', '小猫', '月亮', '晚风', '橘子', '汽水', '云朵', '星星', '草莓', '栗子'];
    $emos = ['🐟', '🧸', '🌙', '🍓', '☕', '🌷', '⭐', '🍑'];
    return $adjs[array_rand($adjs)] . $nouns[array_rand($nouns)] . $emos[array_rand($emos)];
}

/* ==================== 浏览量防刷（IP + 时间窗口去重） ==================== */

/**
 * 是否信任 X-Forwarded-For 头（默认**关闭**）。
 * 本站是「容器 Apache 直连公网」，前面没有反向代理，XFF 完全由客户端伪造——
 * 信任它等于把限流和浏览量去重拱手让人：加一个请求头就能无限刷。
 * 将来若在前面挂了 nginx/CDN，再改成 true（并确保代理是**覆盖**而不是追加该头）。
 */
const TRUST_FORWARDED_FOR = false;

/** 获取客户端真实 IP */
function client_ip(): string
{
    if (TRUST_FORWARDED_FOR && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * 浏览量去重。
 *
 * 主键是「浏览器 visitorId | 故事id」——同一 WiFi / 公司 / 小区出口 IP 下的不同人
 * （不同浏览器）能各记一次，不再像纯 IP 那样被合并成一个人。
 * 兜底是「IP | 故事id」的窗口内计数上限：visitorId 存在 localStorage 里、清掉就重置，
 * 所以必须留一道 IP 维度的闸，否则换个 id 就能无限刷。
 * 没带 visitorId（老浏览器 / 直接 curl）时退化为纯 IP 去重。
 */
function should_count_view(string $ip, int $id, string $vid = ''): bool
{
    $now = time();

    $lock = lock_guard(VIEWS_LOG);
    if (!$lock) { return true; } // 日志不可用时退化为始终计数

    $raw = json_decode((string)@file_get_contents(VIEWS_LOG) ?: '', true);
    $v = [];  // visitorId|id => 时间戳
    $i = [];  // ip|id => [时间戳, ...]
    if (is_array($raw)) {
        if (isset($raw['v']) || isset($raw['i'])) {
            $v = is_array($raw['v'] ?? null) ? $raw['v'] : [];
            $i = is_array($raw['i'] ?? null) ? $raw['i'] : [];
        } else {
            // 旧格式（扁平 { "ip|id": 时间戳 }）整体迁移成 IP 维度记录
            foreach ($raw as $k => $t) {
                if (is_numeric($t)) { $i[(string)$k] = [(int)$t]; }
            }
        }
    }

    $ipKey = $ip . '|' . $id;
    $vKey  = $vid !== '' ? $vid . '|' . $id : '';

    // 清理过期记录，防止文件无限增长
    foreach ($v as $k => $t) {
        if (($now - (int)$t) > VIEW_WINDOW) { unset($v[$k]); }
    }
    foreach ($i as $k => $arr) {
        $arr = array_values(array_filter((array)$arr, static fn($t): bool => ($now - (int)$t) <= VIEW_WINDOW));
        if ($arr) { $i[$k] = $arr; } else { unset($i[$k]); }
    }

    $ipHits = count($i[$ipKey] ?? []);
    if ($ipHits >= VIEW_IP_MAX) {
        $counted = false;                      // IP 兜底：同 IP 对该故事已达上限
    } elseif ($vKey !== '') {
        $counted = !isset($v[$vKey]);          // 有 visitorId：按 visitor 去重
    } else {
        $counted = ($ipHits === 0);            // 无 visitorId：退化为纯 IP 去重
    }

    if ($counted) {
        if ($vKey !== '') { $v[$vKey] = $now; }
        $i[$ipKey][] = $now;
    }
    atomic_write(VIEWS_LOG, json_encode(['v' => $v, 'i' => $i], JSON_UNESCAPED_UNICODE));
    unlock_guard($lock);
    return $counted;
}

/** 评论输出映射（两级楼中楼：一级评论 + 扁平回复列表；字段级兜底，残缺数据不再触发 Warning） */
function comment_out(array $c): array
{
    return [
        'id'              => (string)($c['id'] ?? ''),
        'nickname'        => (string)($c['nickname'] ?? '匿名'),
        'content'         => (string)($c['content'] ?? ''),
        'createdAt'       => (int)($c['createdAt'] ?? 0),
        'replies'         => array_values(array_map(static fn(array $r): array => [
            'id'              => (string)($r['id'] ?? ''),
            'nickname'        => (string)($r['nickname'] ?? '匿名'),
            'content'         => (string)($r['content'] ?? ''),
            'createdAt'       => (int)($r['createdAt'] ?? 0),
            'replyToNickname' => (string)($r['replyToNickname'] ?? ''),
        ], array_values($c['replies'] ?? []))),
    ];
}

/** 两级楼中楼：在评论里查找目标（一级或其回复），新回复统一挂到所属一级评论的 replies 末尾（$entry 引用传递，回填 replyToNickname） */
function comment_reply_attach(array &$comments, string $replyTo, array &$entry): bool
{
    foreach ($comments as $i => $c) {
        $found = null;
        if ((string)($c['id'] ?? '') === $replyTo) {
            $found = $c;
        } else {
            foreach (($c['replies'] ?? []) as $r) {
                if ((string)($r['id'] ?? '') === $replyTo) { $found = $r; break; }
            }
        }
        if ($found !== null) {
            $entry['replyToNickname'] = (string)($found['nickname'] ?? '匿名');
            $comments[$i]['replies'] = array_values($c['replies'] ?? []);
            $comments[$i]['replies'][] = $entry;
            return true;
        }
    }
    return false;
}

/** 评论数 = 一级评论数 + 各自回复数（不再计入种子模拟基数，显示真实互动量） */
function comments_count(array $story): int
{
    $real = 0;
    foreach (($story['comments'] ?? []) as $c) {
        $real += 1 + count($c['replies'] ?? []);
    }
    return $real;
}

/** 热度分（热门排序用） */
function hot_score(array $s): float
{
    return ((int)$s['likes']) * 3 + comments_count($s) * 5 + ((int)$s['views']) * 0.5;
}

/** 列表项字段（不含全文，减小流量） */
function story_card(array $s): array
{
    return [
        'id'            => (int)$s['id'],
        'title'         => (string)$s['title'],
        'excerpt'       => make_excerpt((string)$s['content'], (string)($s['mode'] ?? 'text')),
        'mode'          => (string)($s['mode'] ?? 'text'),
        'tags'          => array_values((array)($s['tags'] ?? [])),
        'author'        => $s['author'] ?? ['nickname' => '匿名', 'info' => '匿名分享'],
        'likes'         => (int)$s['likes'],
        'views'         => (int)$s['views'],
        'commentsCount' => comments_count($s),
        'pinned'        => !empty($s['pinned']),
        'realCount'     => (int)($s['realCount'] ?? 0),
        'createdAt'     => (int)($s['createdAt'] ?? time()),
    ];
}

/** 详情字段（含全文与评论） */
function story_full(array $s): array
{
    return array_merge(story_card($s), [
        'content'  => (string)$s['content'],
        'comments' => array_map('comment_out', array_values($s['comments'] ?? [])),
    ]);
}

/* ================= 管理密码与登录限流 ================= */

/** 管理登录限流参数：同一 IP 连续失败达阈值即锁定一段时间 */
const ADMIN_MAX_FAILS = 5;        // 允许的连续失败次数
const ADMIN_LOCK_SECONDS = 900;   // 触发后锁定时长（15 分钟）

/**
 * 管理页密码。
 * 优先读 data/admin_key.txt；文件不存在时**自动生成 12 位随机密码并落盘**。
 * 注意：这里刻意不再提供公开仓库里可见的兜底默认值——否则任何忘记配置的部署都等于没有密码。
 * 生成失败（data/ 不可写）时返回空串，由 admin_auth() 给出可诊断的报错。
 */
function admin_key(): string
{
    static $key = null;
    if ($key !== null) { return $key; }
    $key = trim((string)@file_get_contents(ADMIN_KEY_FILE));
    if ($key !== '') { return $key; }

    // 首次运行：生成随机密码。加锁并复查一次，避免两个并发请求各生成一个、
    // 后写的把先写的顶掉（那样先拿到密码的那个请求等于拿到一个立刻失效的密码）。
    $lock = lock_guard(ADMIN_KEY_FILE);
    $key = trim((string)@file_get_contents(ADMIN_KEY_FILE));
    if ($key === '') {
        $key = bin2hex(random_bytes(6)); // 12 位随机密码
        if (atomic_write(ADMIN_KEY_FILE, $key)) {
            @chmod(ADMIN_KEY_FILE, 0600);
            error_log('[缘分故事屋] 首次运行已自动生成管理密码，见 data/admin_key.txt');
        }
    }
    unlock_guard($lock);
    return $key;
}

/** 读取限流记录（data/admin_guard.json，PHP 每次请求独立进程，无法用内存保存） */
function admin_guard_load(): array
{
    $data = json_decode((string)@file_get_contents(ADMIN_GUARD_FILE) ?: '', true);
    return is_array($data) ? $data : [];
}

function admin_guard_save(array $data): void
{
    atomic_write(ADMIN_GUARD_FILE, json_encode($data, JSON_UNESCAPED_UNICODE));
}

/**
 * 清理 admin_guard.json 里的陈旧记录。
 *
 * 原实现只在「登录失败」时顺手清理，于是「失败过一两次、之后再也不来」的 IP 会一直堆在文件里
 * （文件只增不减）。现在每次调用 admin_auth 都跑一遍：锁定期已过、且最后一次尝试距今
 * 超过一个锁定时长的记录，视为「不会再来」，直接清掉。
 *
 * @return bool 是否有记录被清理（调用方据此决定要不要写盘）
 */
function admin_guard_gc(array &$guard, int $now): bool
{
    $changed = false;
    foreach ($guard as $ip => $rec) {
        if (!is_array($rec)) { unset($guard[$ip]); $changed = true; continue; }
        $until = (int)($rec['until'] ?? 0);
        $last  = (int)($rec['last'] ?? 0);
        // 仍在锁定期内 → 保留；刚失败过（还没锁定）→ 保留，等它自然过期
        if ($until <= $now && ($now - $last) > ADMIN_LOCK_SECONDS) {
            unset($guard[$ip]);
            $changed = true;
        }
    }
    return $changed;
}

/** 校验管理密码；未通过或处于锁定期直接终止响应 */
function admin_auth(array $in): void
{
    $key = admin_key();
    // 文件既读不到也写不进去：给出明确指引，避免"静默锁死管理页"
    if ($key === '' || !is_file(ADMIN_KEY_FILE)) {
        fail('管理密码未配置，且 data/ 目录不可写。请在服务器手动创建 data/admin_key.txt 并写入你的密码', 500);
    }

    $ip = client_ip();
    $now = time();
    $guard = admin_guard_load();
    $cleaned = admin_guard_gc($guard, $now);   // 每次进来都清理陈旧记录，不再只靠失败分支
    $rec = $guard[$ip] ?? null;

    // 锁定期内直接拒绝，不再比对密码（防止在锁定窗口里继续试）
    if (is_array($rec) && (int)($rec['until'] ?? 0) > $now) {
        if ($cleaned) { admin_guard_save($guard); }
        $left = (int)ceil(((int)$rec['until'] - $now) / 60);
        fail('密码错误次数过多，请 ' . $left . ' 分钟后再试', 429);
    }

    if (!hash_equals($key, (string)($in['key'] ?? ''))) {
        // 累计失败次数，达阈值开始锁定（陈旧记录已在上面的 gc 里清掉）
        $n = (int)(is_array($rec) ? ($rec['n'] ?? 0) : 0) + 1;
        $guard[$ip] = [
            'n'     => $n,
            'last'  => $now,
            'until' => $n >= ADMIN_MAX_FAILS ? $now + ADMIN_LOCK_SECONDS : 0,
        ];
        admin_guard_save($guard);
        $left = ADMIN_MAX_FAILS - $n;
        fail($left > 0 ? '管理密码错误（还可尝试 ' . $left . ' 次）' : '密码错误次数过多，请 15 分钟后再试', $left > 0 ? 403 : 429);
    }

    // 登录成功：清空该 IP 的失败记录；若刚清理过其他陈旧记录，也一并落盘
    if (isset($guard[$ip])) { unset($guard[$ip]); admin_guard_save($guard); }
    elseif ($cleaned) { admin_guard_save($guard); }
}

/* ================= 内容写入限流（防脚本刷屏） ================= */

/**
 * 匿名站没有登录门槛，写接口必须限流，否则可被脚本刷爆。
 * 动作 => [窗口内允许次数, 窗口秒数, 提示文案用的动词]
 * 阈值刻意留宽松：正常用户几乎碰不到，脚本刷屏会立刻撞墙。
 */
const RATE_LIMITS = [
    'stories'  => [5,  600,  '发布'],
    'comments' => [15, 300,  '评论'],
    'feedback' => [3,  1800, '提交反馈'],
    'delete_comment' => [20, 600, '删除评论'],
    'real'           => [30, 600, '标记真实故事'],
    'delete_story'   => [10, 600, '删除故事'],
];

/**
 * 滑动窗口计数。放行返回 null；超限返回还需等待的分钟数。
 * 与 server.js 的 rateCheck() 共用 data/rate_guard.json（同格式），两边行为一致。
 */
function rate_check(string $action, string $ip): ?int
{
    $conf = RATE_LIMITS[$action] ?? null;
    if ($conf === null) { return null; }
    [$max, $window] = $conf;

    $now = time();
    $key = $ip . '|' . $action;

    $lock = lock_guard(RATE_GUARD_FILE);
    // 记录文件不可写时直接放行：限流是附加保护，不该因为写不了日志就把正常用户挡在门外
    if (!$lock) { return null; }

    $data = json_decode((string)@file_get_contents(RATE_GUARD_FILE) ?: '', true);
    if (!is_array($data)) { $data = []; }

    // 只保留窗口内的命中记录
    $hits = array_values(array_filter(
        (array)($data[$key] ?? []),
        static fn($t): bool => ($now - (int)$t) < $window
    ));

    $over = count($hits) >= $max;
    if (!$over) { $hits[] = $now; }
    $data[$key] = $hits;

    // 清理过期/为空的键，防止文件无限增长。
    // ⚠️ 每个键必须按**它自己那个动作**的窗口判过期：各动作窗口不同（发布 600s / 评论 300s / 反馈 1800s / 删评·删故事 600s），
    //    拿当前请求动作的窗口去过滤别的动作，会把仍在有效期内的记录误删 ——
    //    表现就是「发满 5 篇后随便发一条评论，发布额度就被重置」。
    foreach ($data as $k => $v) {
        $pos = strpos($k, '|');
        $act = $pos === false ? '' : substr($k, $pos + 1);
        $w = RATE_LIMITS[$act][1] ?? $window;
        $alive = array_values(array_filter((array)$v, static fn($t): bool => ($now - (int)$t) < $w));
        if (!$alive) { unset($data[$k]); } else { $data[$k] = $alive; }
    }

    atomic_write(RATE_GUARD_FILE, json_encode($data, JSON_UNESCAPED_UNICODE));
    unlock_guard($lock);

    if (!$over) { return null; }
    // 最早那次命中滑出窗口时即可再次操作
    return max(1, (int)ceil((min($hits) + $window - $now) / 60));
}

/** 超限直接终止响应 */
function rate_guard(string $action): void
{
    $wait = rate_check($action, client_ip());
    if ($wait !== null) {
        $label = RATE_LIMITS[$action][2] ?? '操作';
        fail($label . '太频繁啦，请 ' . $wait . ' 分钟后再试 💕', 429);
    }
}

/* ================= 路由处理 ================= */

$route = $_GET['route'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

switch ($route) {

    /* ---- 故事列表 ---- */
    case 'stories': {
        if ($method === 'POST') {
            $in = body_json();
            $title = trim((string)($in['title'] ?? ''));
            $content = trim((string)($in['content'] ?? ''));
            $source = (string)($in['source'] ?? '');   // 上传的原始 .html 全文（可选）
            // 统一换行：前端已规范化，这里兜底（直接调接口时可能是 CRLF）
            $source = str_replace(["\r\n", "\r"], "\n", $source);
            $mode = ($in['mode'] ?? 'text') === 'html' ? 'html' : 'text';
            $nickname = trim((string)($in['nickname'] ?? ''));
            $tagsIn = (array)($in['tags'] ?? []);

            if ($title === '') { fail('故事标题不能为空'); }
            if (mb_strlen($title) > 60) { fail('标题最多 60 个字'); }

            // 原始源文件模式：完整文件另存到 data/uploads/{id}.html，阅读时原样输出；
            // content 仍存净化版（截断到 5 万字）供列表摘要与搜索，避免 stories.json 膨胀
            $keepSource = false;
            if ($mode === 'html' && $source !== '') {
                if (strlen($source) > UPLOAD_MAX_BYTES) {
                    fail('源文件太大啦，最多 ' . (int)(UPLOAD_MAX_BYTES / 1024) . 'KB');
                }
                if (!preg_match('#<[a-z!/][^>]*>#i', $source)) { fail('源文件内容不像 HTML'); }
                $content = mb_substr(sanitize_html($source), 0, 50000);
                $keepSource = true;
            }

            if ($content === '') { fail('故事内容不能为空'); }
            if (!$keepSource && mb_strlen($content) > ($mode === 'html' ? 50000 : 20000)) {
                fail('内容太长啦，最多 ' . ($mode === 'html' ? 50000 : 20000) . ' 个字符');
            }
            if (mb_strlen($nickname) > 20) { fail('昵称最多 20 个字符'); }
            if ($nickname === '') { $nickname = random_nickname(); }

            $tags = array_values(array_unique(array_intersect($tagsIn, VALID_TAGS)));
            if (count($tags) > 4) { $tags = array_slice($tags, 0, 4); }
            // 一个标签都没选时，默认归为「日常记录」，保证每篇故事都有归属分类
            if (empty($tags)) { $tags = ['daily']; }

            // 校验通过后才计数：正常用户填错重试不会被罚，只有真正落库的提交才消耗额度
            rate_guard('stories');

            // 事务内完成「分配 id → 插入 → 写盘」：并发发布不会拿到重复 id，也不会互相覆盖
            $created = db_transaction(static function (array &$db) use ($title, $content, $mode, $nickname, $tags): array {
                $story = [
                    'id'           => (int)$db['nextId']++,
                    'title'        => $title,
                    'content'      => $mode === 'html' ? sanitize_html($content) : $content,
                    'mode'         => $mode,
                    'tags'         => $tags,
                    'author'       => ['nickname' => $nickname, 'info' => '缘分旅人 · 匿名分享'],
                    'likes'        => 0,
                    'views'        => 0,
                    'comments'     => [],
                    'createdAt'    => time(),
                ];
                // 生成发布者删除凭证（仅本次响应返回，存储在发布者浏览器里）
                $story['editKey'] = substr(bin2hex(random_bytes(5)), 0, 8);
                array_unshift($db['stories'], $story);
                return $story;
            });
            // 事务外落盘原始文件：大块 IO 不占数据锁，写失败也不影响发布（退回净化版显示）
            $sourceSaved = false;
            if ($keepSource) {
                $sourceSaved = upload_source_write((int)$created['id'], $source);
            }
            $full = story_full($created);
            $full['editKey'] = $created['editKey'];
            $full['sourceSaved'] = $sourceSaved;
            respond(['ok' => true, 'story' => $full]);
        }

        // GET 列表
        $filter = (string)($_GET['filter'] ?? 'hot');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(20, max(1, (int)($_GET['limit'] ?? 6)));

        $db = db_load();
        $arr = $db['stories'];
        if ($filter === 'hot') {
            usort($arr, fn($a, $b) => hot_score($b) <=> hot_score($a));
        } elseif ($filter === 'new') {
            usort($arr, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
        } elseif (in_array($filter, VALID_TAGS, true)) {
            $arr = array_values(array_filter($arr, fn($s) => in_array($filter, (array)($s['tags'] ?? []), true)));
            usort($arr, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
        } else {
            fail('未知的筛选类型');
        }
        // 置顶优先：先按原规则排好，再把置顶的稳定提到最前（置顶内部保持原排序）
        if (count($arr) > 1) {
            $pinnedArr = array_values(array_filter($arr, fn($s) => !empty($s['pinned'])));
            $rest = array_values(array_filter($arr, fn($s) => empty($s['pinned'])));
            $arr = array_merge($pinnedArr, $rest);
        }

        $total = count($arr);
        $slice = array_slice($arr, ($page - 1) * $limit, $limit);
        respond([
            'ok'      => true,
            'total'   => $total,
            'page'    => $page,
            'hasMore' => ($page * $limit) < $total,
            'stories' => array_map('story_card', $slice),
        ]);
    }

    /* ---- 字体代理（Google Fonts 站内中转，见 serve_font_proxy） ---- */
    case 'font': {
        serve_font_proxy();
        exit;
    }

    /* ---- 故事原文（沙箱隔离渲染，供 iframe/新窗口阅读） ---- */
    case 'raw': {
        $db = db_load();
        foreach ($db['stories'] as $s) {
            if ((int)$s['id'] === $id) {
                if (($s['mode'] ?? 'text') === 'html') {
                    // 优先读原始源文件（未净化，作者写什么就显示什么）；没有才回落到净化版
                    serve_raw_story($s, upload_source_read((int)$s['id']));
                    exit;
                }
                // 纯文本故事：简单排版输出
                $title = preg_replace('/[<>&"]/', '', (string)($s['title'] ?? '故事')) ?: '故事';
                $text = htmlspecialchars((string)($s['content'] ?? ''), ENT_QUOTES, 'UTF-8');
                $text = nl2br($text);
                header('Content-Type: text/html; charset=utf-8');
                header("Content-Security-Policy: sandbox");
                header('Cache-Control: no-cache');
                echo "<!DOCTYPE html>\n<html lang=\"zh-CN\">\n<head>\n<meta charset=\"UTF-8\">\n"
                    . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
                    . "<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title>\n"
                    . "<style>body{font-family:'Noto Serif SC','STSong',serif;background:#faf6ef;color:#2c2416;line-height:2;max-width:720px;margin:0 auto;padding:48px 24px;font-size:16px;}h1{font-size:1.6rem;margin:0 0 1.5rem;}</style>\n"
                    . "</head>\n<body>\n<h1>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h1>\n" . $text . "\n</body>\n</html>";
                exit;
            }
        }
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Not Found';
        exit;
    }

    /* ---- 故事详情（浏览量按 visitorId 去重，IP 计数上限兜底） ---- */
    case 'story': {
        // 前端会话内重复打开(count=0)或去重命中，都不重复计数
        $vid = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['vid'] ?? '')), 0, 32);
        $count = ($_GET['count'] ?? '1') !== '0' && should_count_view(client_ip(), $id, $vid);

        $found = db_transaction(static function (array &$db) use ($id, $count): ?array {
            foreach ($db['stories'] as $i => $s) {
                if ((int)$s['id'] === $id) {
                    if ($count) { $db['stories'][$i]['views'] = (int)$s['views'] + 1; }
                    return $db['stories'][$i];
                }
            }
            return null;
        });
        if ($found === null) { fail('故事不存在或已被删除', 404); }
        respond(['ok' => true, 'story' => story_full($found)]);
    }

    /* ---- 点赞（like / unlike） ---- */
    case 'like': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        $undo = !empty($in['undo']);
        $likes = db_transaction(static function (array &$db) use ($in, $undo): ?int {
            foreach ($db['stories'] as $i => $s) {
                if ((int)$s['id'] === (int)($in['id'] ?? 0)) {
                    $db['stories'][$i]['likes'] = max(0, (int)$s['likes'] + ($undo ? -1 : 1));
                    return (int)$db['stories'][$i]['likes'];
                }
            }
            return null;
        });
        if ($likes === null) { fail('故事不存在或已被删除', 404); }
        respond(['ok' => true, 'likes' => $likes]);
    }

    /* ---- 真实故事标记（与点赞同同机制：可撤销 + 写接口限流） ---- */
    case 'real': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        $undo = !empty($in['undo']);
        $rid = (int)($in['id'] ?? 0);
        $exists = false;
        foreach (db_load()['stories'] as $s) {
            if ((int)$s['id'] === $rid) { $exists = true; break; }
        }
        if (!$exists) { fail('故事不存在或已被删除', 404); }
        rate_guard('real');
        $real = db_transaction(static function (array &$db) use ($rid, $undo): ?int {
            foreach ($db['stories'] as $i => $s) {
                if ((int)$s['id'] === $rid) {
                    $db['stories'][$i]['realCount'] = max(0, (int)($s['realCount'] ?? 0) + ($undo ? -1 : 1));
                    return (int)$db['stories'][$i]['realCount'];
                }
            }
            return null;
        });
        if ($real === null) { fail('故事不存在或已被删除', 404); }
        respond(['ok' => true, 'realCount' => $real]);
    }

    /* ---- 评论 ---- */
    case 'comments': {
        if ($method === 'POST') {
            $in = body_json();
            $content = trim((string)($in['content'] ?? ''));
            $nickname = trim((string)($in['nickname'] ?? ''));
            $replyTo = trim((string)($in['replyTo'] ?? ''));
            if ($content === '') { fail('评论内容不能为空'); }
            if (mb_strlen($content) > 500) { fail('评论最多 500 个字'); }
            if (mb_strlen($nickname) > 20) { fail('昵称最多 20 个字符'); }
            if ($nickname === '') { $nickname = random_nickname(); }
            rate_guard('comments');
            $entry = [
                'id'        => 'c' . time() . mt_rand(1000, 9999),
                'nickname'  => $nickname,
                'content'   => $content,
                'createdAt' => time(),
                // 评论者删除凭证（仅本次响应返回、存储在评论者浏览器里；GET 输出走 comment_out 自动剥离）
                'delKey'    => substr(bin2hex(random_bytes(5)), 0, 8),
            ];
            // 事务内「定位故事 → 挂评论 → 写盘」：并发评论不会互相覆盖
            $result = db_transaction(static function (array &$db) use ($id, $entry, $replyTo): array {
                foreach ($db['stories'] as $i => $s) {
                    if ((int)$s['id'] !== $id) { continue; }
                    if ($replyTo !== '') {
                        /* 两级楼中楼：replyTo 为目标评论或回复的 id，
                           回复统一挂在其所属一级评论的 replies 下，并记录被回复人昵称 */
                        $comments = array_values($db['stories'][$i]['comments'] ?? []);
                        if (!comment_reply_attach($comments, $replyTo, $entry)) {
                            return ['error' => '要回复的评论不存在或已被删除', 'code' => 404];
                        }
                        $db['stories'][$i]['comments'] = $comments;
                    } else {
                        $db['stories'][$i]['comments'][] = $entry;
                    }
                    // $entry 已被 comment_reply_attach 回填 replyToNickname，随结果带回
                    return ['count' => comments_count($db['stories'][$i]), 'entry' => $entry];
                }
                return ['error' => '故事不存在或已被删除', 'code' => 404];
            });
            if (isset($result['error'])) { fail($result['error'], (int)$result['code']); }
            respond(['ok' => true, 'commentsCount' => $result['count'], 'comment' => $result['entry']]);
        }

        // GET：只读
        $db = db_load();
        foreach ($db['stories'] as $s) {
            if ((int)$s['id'] === $id) {
                respond(['ok' => true, 'comments' => array_map('comment_out', array_values($s['comments'] ?? []))]);
            }
        }
        fail('故事不存在或已被删除', 404);
    }

    /* ---- 评论者删除自己的评论/回复（凭评论时下发的凭证） ---- */
    case 'delete_comment': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        $commentId = trim((string)($in['commentId'] ?? ''));
        $delKey = trim((string)($in['delKey'] ?? ''));
        if ($commentId === '' || $delKey === '') { fail('参数不完整'); }
        rate_guard('delete_comment');
        // 🔔 捕获被删内容，供「删除动态」提示用（事务内只收集，事务外才写文件）
        $removed = null;
        // 事务内校验凭证并删除：一级评论（连带其回复）或任一回复均可删
        $status = db_transaction(static function (array &$db) use ($id, $commentId, $delKey, &$removed): string {
            foreach ($db['stories'] as $i => $s) {
                if ((int)$s['id'] !== $id) { continue; }
                $comments = array_values($s['comments'] ?? []);
                foreach ($comments as $ci => $c) {
                    if ((string)($c['id'] ?? '') === $commentId) {
                        if (empty($c['delKey']) || !hash_equals((string)$c['delKey'], $delKey)) {
                            return 'bad_key';
                        }
                        $removed = [
                            'nickname' => (string)($c['nickname'] ?? ''),
                            'content'  => (string)($c['content'] ?? ''),
                            'replies'  => count($c['replies'] ?? []),
                            'isReply'  => false,
                        ];
                        array_splice($comments, $ci, 1);   // 一级评论删除，其回复随之移除
                        $db['stories'][$i]['comments'] = $comments;
                        return 'deleted';
                    }
                    foreach (($c['replies'] ?? []) as $ri => $r) {
                        if ((string)($r['id'] ?? '') === $commentId) {
                            if (empty($r['delKey']) || !hash_equals((string)$r['delKey'], $delKey)) {
                                return 'bad_key';
                            }
                            $removed = [
                                'nickname' => (string)($r['nickname'] ?? ''),
                                'content'  => (string)($r['content'] ?? ''),
                                'replies'  => 0,
                                'isReply'  => true,
                            ];
                            $reps = $c['replies'];
                            array_splice($reps, $ri, 1);
                            $comments[$ci]['replies'] = array_values($reps);
                            $db['stories'][$i]['comments'] = $comments;
                            return 'deleted';
                        }
                    }
                }
                return 'not_found';
            }
            return 'not_found';
        });
        if ($status === 'bad_key') { fail('删除凭证不正确，无法删除这条评论'); }
        if ($status === 'not_found') { fail('评论不存在或已被删除', 404); }
        // 返回删除后的最新评论数
        $count = 0;
        $owner = null;
        foreach (db_load()['stories'] as $s) {
            if ((int)$s['id'] === $id) { $count = comments_count($s); $owner = $s; break; }
        }
        // 🔔 记录访客自发删除（管理页「删除动态」提示用）；记录失败不影响删除本身
        if ($removed !== null && is_array($owner)) {
            record_deletion([
                'type'     => 'comment',
                'storyId'  => (int)$owner['id'],
                'title'    => (string)($owner['title'] ?? ''),
                'author'   => $removed['nickname'],
                'excerpt'  => $removed['content'],
                'extra'    => $removed['isReply']
                    ? '一条回复'
                    : '一级评论' . ($removed['replies'] ? "（连带 {$removed['replies']} 条回复）" : ''),
                'likes'    => (int)($owner['likes'] ?? 0),
                'views'    => (int)($owner['views'] ?? 0),
                'comments' => $count,
            ]);
        }
        respond(['ok' => true, 'commentsCount' => $count, 'message' => '评论已删除']);
    }

    /* ---- 标签云计数 ---- */
    case 'tags': {
        $db = db_load();
        $counts = [];
        foreach ($db['stories'] as $s) {
            foreach ((array)($s['tags'] ?? []) as $t) {
                if (in_array($t, VALID_TAGS, true)) {
                    $counts[$t] = ($counts[$t] ?? 0) + 1;
                }
            }
        }
        respond(['ok' => true, 'tags' => array_map(static fn(string $t): array => [
            'tag' => $t, 'count' => $counts[$t] ?? 0,
        ], VALID_TAGS)]);
    }

    /* ---- 反馈建议 ---- */
    case 'feedback': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        $content = trim((string)($in['content'] ?? ''));
        $contact = trim((string)($in['contact'] ?? ''));
        $nickname = trim((string)($in['nickname'] ?? ''));
        if ($content === '') { fail('反馈内容不能为空'); }
        if (mb_strlen($content) > 1000) { fail('反馈内容最多 1000 字'); }
        if (mb_strlen($contact) > 100) { fail('联系方式最多 100 个字符'); }
        if (mb_strlen($nickname) > 20) { fail('昵称最多 20 个字符'); }
        if ($nickname === '') { $nickname = random_nickname(); }
        rate_guard('feedback');
        if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }

        // 加锁保护「读-改-写」：访客提交与管理端删除可能同时发生
        $lock = lock_guard(FEEDBACK_FILE);
        $db = ['nextId' => 1, 'items' => []];
        $loaded = json_decode((string)@file_get_contents(FEEDBACK_FILE) ?: '', true);
        if (is_array($loaded)) { $db = $loaded; }
        if (!isset($db['items']) || !is_array($db['items'])) { $db['items'] = []; }
        if (!isset($db['nextId'])) { $db['nextId'] = count($db['items']) + 1; }

        $item = [
            'id'        => (int)$db['nextId']++,
            'nickname'  => $nickname,
            'contact'   => $contact,
            'content'   => $content,
            'createdAt' => time(),
        ];
        $db['items'][] = $item;
        atomic_write(FEEDBACK_FILE, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        unlock_guard($lock);
        respond(['ok' => true, 'message' => '反馈已收到，感谢你的每一句建议 💕']);
    }

    /* ---- 发布者删除自己的故事（凭发布时下发的凭证） ---- */
    case 'delete_story': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        $id = (int)($in['id'] ?? 0);
        $editKey = trim((string)($in['editKey'] ?? ''));
        // 限流：先确认故事存在（不存在直接 404，不消耗额度），
        // 错误凭证的爆破尝试才计数 —— 与 server.js 语义一致
        $exists = false;
        foreach (db_load()['stories'] as $s) {
            if ((int)$s['id'] === $id) { $exists = true; break; }
        }
        if (!$exists) { fail('故事不存在或已被删除', 404); }
        rate_guard('delete_story');
        // 🔔 捕获被删故事快照，供「删除动态」提示用
        $gone = null;
        // 事务内校验凭证并删除：并发下不会误删别的故事
        $status = db_transaction(static function (array &$db) use ($id, $editKey, &$gone): string {
            foreach ($db['stories'] as $i => $s) {
                if ((int)$s['id'] !== $id) { continue; }
                if (empty($s['editKey']) || !hash_equals((string)$s['editKey'], $editKey)) {
                    return 'bad_key';
                }
                $gone = $s;
                array_splice($db['stories'], $i, 1);
                return 'deleted';
            }
            return 'not_found';
        });
        if ($status === 'bad_key') { fail('删除凭证不正确，无法删除这篇故事'); }
        if ($status === 'not_found') { fail('故事不存在或已被删除', 404); }
        // 🔔 记录访客自发删除（管理页「删除动态」提示用）；记录失败不影响删除本身
        if (is_array($gone)) {
            record_deletion([
                'type'     => 'story',
                'storyId'  => (int)$gone['id'],
                'title'    => (string)($gone['title'] ?? ''),
                'author'   => (string)($gone['author']['nickname'] ?? ''),
                'excerpt'  => make_excerpt((string)($gone['content'] ?? ''), (string)($gone['mode'] ?? 'text')),
                'extra'    => '发布者自行删除',
                'likes'    => (int)($gone['likes'] ?? 0),
                'views'    => (int)($gone['views'] ?? 0),
                'comments' => comments_count($gone),
            ]);
        }
        // 原始 HTML 文件随故事一起清理，避免 data/uploads 里留下孤儿文件
        upload_source_delete($id);
        respond(['ok' => true, 'message' => '故事已删除']);
    }

    /* ---- 管理页：故事管理（查看/编辑/删除，支持标签筛选、关键词搜索、排序、分页） ---- */
    case 'admin_stories': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        admin_auth($in);
        $op = (string)($in['op'] ?? 'list');

        if ($op === 'delete') {
            // 支持单个 id，也支持 ids 数组（管理页多选批量删除）
            if (isset($in['ids']) && is_array($in['ids'])) {
                $ids = array_values(array_unique(array_map('intval', $in['ids'])));
            } else {
                $ids = [(int)($in['id'] ?? 0)];
            }
            $ids = array_values(array_filter($ids, static fn(int $v): bool => $v > 0));
            if (!$ids) { fail('没有指定要删除的故事'); }

            $removed = db_transaction(static function (array &$db) use ($ids): int {
                $before = count($db['stories']);
                $db['stories'] = array_values(array_filter(
                    $db['stories'],
                    static fn(array $s): bool => !in_array((int)$s['id'], $ids, true)
                ));
                return $before - count($db['stories']);
            });
            if ($removed === 0) { fail('未找到这些故事，可能已被删除'); }

            // 原始 HTML 文件随故事一起清理
            foreach ($ids as $one) { upload_source_delete((int)$one); }

            // 批量删除直接返回，不用再拉列表
            if (count($ids) > 1) {
                respond(['ok' => true, 'message' => '已删除 ' . $removed . ' 篇故事', 'removed' => $removed]);
            }
        }

        // 编辑：只改标题 / 正文 / 标签；点赞、评论、浏览、删除凭证一律原样保留
        if ($op === 'update') {
            $id      = (int)($in['id'] ?? 0);
            $title   = trim((string)($in['title'] ?? ''));
            $content = trim((string)($in['content'] ?? ''));
            $tagsIn  = $in['tags'] ?? null;
            // 点赞数 / 浏览数 / 真实鉴定数：可选，仅在传入时更新（只改标题不影响计数）
            $newCounts = [];
            foreach (['likes' => '点赞数', 'views' => '浏览数', 'realCount' => '真实鉴定数'] as $k => $label) {
                if (!isset($in[$k]) || $in[$k] === '' || $in[$k] === null) { continue; }
                $n = filter_var($in[$k], FILTER_VALIDATE_INT);
                if ($n === false || $n < 0 || $n > 99999999) {
                    fail($label . '必须是 0~99999999 的整数');
                }
                $newCounts[$k] = $n;
            }

            if ($title === '') { fail('标题不能为空'); }
            if (mb_strlen($title) > 60) { fail('标题最多 60 个字'); }
            if ($content === '') { fail('正文不能为空'); }

            $contentChanged = false;
            $status = db_transaction(static function (array &$db) use ($id, $title, $content, $tagsIn, $newCounts, &$contentChanged): string {
                foreach ($db['stories'] as $i => $s) {
                    if ((int)$s['id'] !== $id) { continue; }
                    $mode = ($s['mode'] ?? 'text') === 'html' ? 'html' : 'text';
                    $max  = $mode === 'html' ? 50000 : 20000;
                    if (mb_strlen($content) > $max) { return 'too_long:' . $max; }

                    $newBody = $mode === 'html' ? sanitize_html($content) : $content;
                    if ($newBody !== (string)($s['content'] ?? '')) { $contentChanged = true; }
                    $db['stories'][$i]['title']   = $title;
                    $db['stories'][$i]['content'] = $newBody;
                    if (is_array($tagsIn)) {
                        $tags = array_values(array_unique(array_intersect($tagsIn, VALID_TAGS)));
                        if (count($tags) > 4) { $tags = array_slice($tags, 0, 4); }
                        if (empty($tags)) { $tags = ['daily']; }
                        $db['stories'][$i]['tags'] = $tags;
                    }
                    foreach ($newCounts as $k => $v) { $db['stories'][$i][$k] = $v; }
                    return 'ok';
                }
                return 'not_found';
            });

            if ($status === 'not_found') { fail('故事不存在或已被删除', 404); }
            if (strpos($status, 'too_long:') === 0) {
                fail('内容太长啦，最多 ' . substr($status, 9) . ' 个字符');
            }
            // 正文被真正改写过 → 原始文件作废（否则读者看到的仍是旧原文，编辑像没生效）
            $hadSource = is_file(upload_path($id));
            if ($contentChanged) { upload_source_delete($id); }
            respond([
                'ok'      => true,
                'message' => ($contentChanged && $hadSource) ? '已保存（原始文件已删除）' : '已保存',
            ]);
        }

        // 置顶/取消置顶（独立开关，不必打开编辑弹窗）
        if ($op === 'pin') {
            $id = (int)($in['id'] ?? 0);
            $pinned = !empty($in['pinned']);
            $status = db_transaction(static function (array &$db) use ($id, $pinned): string {
                foreach ($db['stories'] as $i => $s) {
                    if ((int)$s['id'] !== $id) { continue; }
                    if ($pinned) { $db['stories'][$i]['pinned'] = true; }
                    else { unset($db['stories'][$i]['pinned']); }
                    return 'ok';
                }
                return 'not_found';
            });
            if ($status === 'not_found') { fail('故事不存在或已被删除', 404); }
            respond(['ok' => true, 'message' => $pinned ? '已置顶' : '已取消置顶']);
        }

        // 列表
        $db   = db_load();
        $tag  = (string)($in['tag'] ?? 'all');
        $q    = trim((string)($in['q'] ?? ''));
        $sort = (string)($in['sort'] ?? 'new');

        $items = array_values(array_filter($db['stories'], static function (array $s) use ($tag, $q): bool {
            if ($tag !== 'all' && (!isset($s['tags']) || !in_array($tag, (array)$s['tags'], true))) { return false; }
            if ($q !== '') {
                $hay = (string)($s['title'] ?? '') . ' '
                     . (string)($s['content'] ?? '') . ' '
                     . (string)($s['author']['nickname'] ?? '');
                if (mb_stripos($hay, $q) === false) { return false; }
            }
            return true;
        }));

        switch ($sort) {
            case 'likes':
                usort($items, static fn(array $a, array $b): int => ((int)$b['likes']) <=> ((int)$a['likes']));
                break;
            case 'views':
                usort($items, static fn(array $a, array $b): int => ((int)$b['views']) <=> ((int)$a['views']));
                break;
            case 'comments':
                usort($items, static fn(array $a, array $b): int => comments_count($b) <=> comments_count($a));
                break;
            case 'hot':
                usort($items, static fn(array $a, array $b): int => hot_score($b) <=> hot_score($a));
                break;
            default:
                usort($items, static fn(array $a, array $b): int => ((int)($b['createdAt'] ?? 0)) <=> ((int)($a['createdAt'] ?? 0)));
        }

        // 分页：默认每页 20 条、最多 100
        $total    = count($items);
        $page     = max(1, (int)($in['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($in['pageSize'] ?? 20)));
        $items = array_map(static fn(array $s): array => [
            'id'            => (int)$s['id'],
            'title'         => (string)$s['title'],
            'author'        => (string)($s['author']['nickname'] ?? '匿名'),
            'tags'          => array_values((array)($s['tags'] ?? [])),
            'likes'         => (int)$s['likes'],
            'views'         => (int)$s['views'],
            'commentsCount' => comments_count($s),
            'createdAt'     => (int)($s['createdAt'] ?? 0),
            'mode'          => (string)($s['mode'] ?? 'text'),
            'excerpt'       => make_excerpt((string)($s['content'] ?? ''), (string)($s['mode'] ?? 'text')),
            'pinned'        => !empty($s['pinned']),
            'realCount'     => (int)($s['realCount'] ?? 0),
            // 是否有原始 HTML 文件存档：管理页据此提示「编辑会替换原文」
            'hasSource'     => is_file(upload_path((int)$s['id'])),
        ], array_slice($items, ($page - 1) * $pageSize, $pageSize));

        respond([
            'ok'       => true,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'hasMore'  => ($page * $pageSize) < $total,
            'items'    => $items,
        ]);
    }

    /* ---- 管理页：评论管理（全站评论时间线 / 删除一级评论或单条回复） ---- */
    case 'admin_comments': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        admin_auth($in);

        if (($in['op'] ?? 'list') === 'delete') {
            $storyId   = (int)($in['storyId'] ?? 0);
            $commentId = trim((string)($in['commentId'] ?? ''));
            if ($commentId === '') { fail('缺少 commentId'); }

            $status = db_transaction(static function (array &$db) use ($storyId, $commentId): string {
                foreach ($db['stories'] as $i => $s) {
                    if ((int)$s['id'] !== $storyId) { continue; }
                    $comments = array_values($s['comments'] ?? []);

                    // ① 命中一级评论 → 连同它的所有回复一起删
                    foreach ($comments as $ci => $c) {
                        if ((string)($c['id'] ?? '') === $commentId) {
                            array_splice($comments, $ci, 1);
                            $db['stories'][$i]['comments'] = $comments;
                            return 'deleted';
                        }
                    }
                    // ② 命中某条回复 → 只删这一条
                    foreach ($comments as $ci => $c) {
                        $reps = array_values($c['replies'] ?? []);
                        foreach ($reps as $ri => $r) {
                            if ((string)($r['id'] ?? '') === $commentId) {
                                array_splice($reps, $ri, 1);
                                $comments[$ci]['replies'] = $reps;
                                $db['stories'][$i]['comments'] = $comments;
                                return 'deleted';
                            }
                        }
                    }
                    return 'not_found';
                }
                return 'no_story';
            });

            if ($status === 'no_story')  { fail('故事不存在或已被删除', 404); }
            if ($status === 'not_found') { fail('评论不存在或已被删除', 404); }
            respond(['ok' => true, 'message' => '评论已删除']);
        }

        // 列表：把所有故事的一级评论与回复摊平成一条按时间倒序的时间线。
        // storyId 传了且 > 0 时只返回那一篇的评论（管理页故事卡片的「内联评论面板」用）。
        $db     = db_load();
        $q      = trim((string)($in['q'] ?? ''));
        $onlyId = isset($in['storyId']) && (int)$in['storyId'] > 0 ? (int)$in['storyId'] : 0;
        $rows   = [];

        foreach ($db['stories'] as $s) {
            $sid    = (int)($s['id'] ?? 0);
            if ($onlyId && $sid !== $onlyId) { continue; }
            $stitle = (string)($s['title'] ?? '');
            foreach (array_values($s['comments'] ?? []) as $c) {
                $rows[] = [
                    'storyId'    => $sid,
                    'storyTitle' => $stitle,
                    'id'         => (string)($c['id'] ?? ''),
                    'nickname'   => (string)($c['nickname'] ?? '匿名'),
                    'content'    => (string)($c['content'] ?? ''),
                    'createdAt'  => (int)($c['createdAt'] ?? 0),
                    'isReply'    => false,
                    'replyTo'    => '',
                    'replyCount' => count($c['replies'] ?? []),
                ];
                foreach (array_values($c['replies'] ?? []) as $r) {
                    $rows[] = [
                        'storyId'    => $sid,
                        'storyTitle' => $stitle,
                        'id'         => (string)($r['id'] ?? ''),
                        'nickname'   => (string)($r['nickname'] ?? '匿名'),
                        'content'    => (string)($r['content'] ?? ''),
                        'createdAt'  => (int)($r['createdAt'] ?? 0),
                        'isReply'    => true,
                        'replyTo'    => (string)($r['replyToNickname'] ?? ''),
                        'replyCount' => 0,
                    ];
                }
            }
        }

        if ($q !== '') {
            $rows = array_values(array_filter($rows, static function (array $r) use ($q): bool {
                return mb_stripos($r['content'], $q) !== false
                    || mb_stripos($r['nickname'], $q) !== false
                    || mb_stripos($r['storyTitle'], $q) !== false;
            }));
        }
        usort($rows, static fn(array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);

        $total    = count($rows);
        $page     = max(1, (int)($in['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($in['pageSize'] ?? 20)));
        respond([
            'ok'       => true,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'hasMore'  => ($page * $pageSize) < $total,
            'items'    => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
        ]);
    }

    /* ---- 管理页：导出数据（直接下载 JSON 文件） ---- */
    case 'admin_export': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        admin_auth($in);

        $what = (string)($in['what'] ?? 'stories');
        if ($what === 'feedback') {
            $path = FEEDBACK_FILE;
            $name = 'feedback-' . date('Ymd-His') . '.json';
        } else {
            $path = DATA_FILE;
            $name = 'stories-' . date('Ymd-His') . '.json';
        }
        if (!is_file($path)) { fail('该数据文件还不存在', 404); }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    /* ---- 管理页：修改管理密码 ---- */
    case 'admin_change_key': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        admin_auth($in);   // 先验旧密码

        $newKey = trim((string)($in['newKey'] ?? ''));
        if (mb_strlen($newKey) < 8)  { fail('新密码至少 8 位'); }
        if (mb_strlen($newKey) > 64) { fail('新密码最多 64 位'); }
        if (!atomic_write(ADMIN_KEY_FILE, $newKey)) {
            fail('密码写入失败，请检查 data/ 目录权限', 500);
        }
        @chmod(ADMIN_KEY_FILE, 0600);
        respond(['ok' => true, 'message' => '管理密码已更新']);
    }

    /* ---- 管理页：访客删除动态（谁删掉了自己的故事/评论） ---- */
    case 'admin_deletions': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        admin_auth($in);

        $db = ['nextId' => 1, 'readUpTo' => 0, 'items' => []];
        $loaded = json_decode((string)@file_get_contents(DELETIONS_FILE) ?: '', true);
        if (is_array($loaded)) { $db = $loaded; }
        if (!isset($db['items']) || !is_array($db['items'])) { $db['items'] = []; }
        if (!isset($db['readUpTo']) || !is_numeric($db['readUpTo'])) { $db['readUpTo'] = 0; }

        // 标记已读：把 readUpTo 推进到当前最大 id（打开过「删除动态」就算看过了）
        if (($in['op'] ?? 'list') === 'read') {
            $lock = lock_guard(DELETIONS_FILE);
            $fresh = json_decode((string)@file_get_contents(DELETIONS_FILE) ?: '', true);
            if (is_array($fresh)) { $db = $fresh; }
            if (!isset($db['items']) || !is_array($db['items'])) { $db['items'] = []; }
            if (!isset($db['readUpTo']) || !is_numeric($db['readUpTo'])) { $db['readUpTo'] = 0; }

            $maxId = 0;
            foreach ($db['items'] as $it) { $maxId = max($maxId, (int)($it['id'] ?? 0)); }
            if (isset($in['upTo'])) { $maxId = max($maxId, (int)$in['upTo']); }
            if ($maxId > $db['readUpTo']) {
                $db['readUpTo'] = $maxId;
                atomic_write(DELETIONS_FILE, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            unlock_guard($lock);
            respond(['ok' => true, 'readUpTo' => $db['readUpTo']]);
        }

        $items = array_reverse($db['items']);   // 最新在前
        $unread = 0; $unreadStory = 0; $unreadComment = 0;
        foreach ($items as $it) {
            if ((int)($it['id'] ?? 0) > $db['readUpTo']) {
                $unread++;
                if (($it['type'] ?? '') === 'comment') { $unreadComment++; } else { $unreadStory++; }
            }
        }
        respond([
            'ok'             => true,
            'total'          => count($db['items']),
            'unread'         => $unread,
            'unreadStory'    => $unreadStory,
            'unreadComment'  => $unreadComment,
            'readUpTo'       => (int)$db['readUpTo'],
            'items'          => array_slice($items, 0, 100),
        ]);
    }

    /* ---- 管理页：查看/删除反馈 ---- */
    case 'admin_feedback': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        admin_auth($in);

        $db = ['nextId' => 1, 'items' => []];
        $loaded = json_decode((string)@file_get_contents(FEEDBACK_FILE) ?: '', true);
        if (is_array($loaded)) { $db = $loaded; }
        if (!isset($db['items']) || !is_array($db['items'])) { $db['items'] = []; }

        // 标记已读：传 ids 只标这些，不传则全部标为已读
        if (($in['op'] ?? 'list') === 'read') {
            $ids = [];
            if (isset($in['ids']) && is_array($in['ids'])) {
                $ids = array_values(array_unique(array_map('intval', $in['ids'])));
            }
            $lock = lock_guard(FEEDBACK_FILE);
            $fresh = json_decode((string)@file_get_contents(FEEDBACK_FILE) ?: '', true);
            if (is_array($fresh)) { $db = $fresh; }
            if (!isset($db['items']) || !is_array($db['items'])) { $db['items'] = []; }

            $marked = 0;
            foreach ($db['items'] as $i => $it) {
                if ($ids && !in_array((int)($it['id'] ?? 0), $ids, true)) { continue; }
                if (empty($it['read'])) { $db['items'][$i]['read'] = true; $marked++; }
            }
            atomic_write(FEEDBACK_FILE, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            unlock_guard($lock);
            respond(['ok' => true, 'marked' => $marked]);
        }

        if (($in['op'] ?? 'list') === 'delete') {
            $id = (int)($in['id'] ?? 0);
            $lock = lock_guard(FEEDBACK_FILE);
            // 拿到锁后重新读一次，避免用过期快照做删除
            $fresh = json_decode((string)@file_get_contents(FEEDBACK_FILE) ?: '', true);
            if (is_array($fresh)) { $db = $fresh; }
            if (!isset($db['items']) || !is_array($db['items'])) { $db['items'] = []; }

            $before = count($db['items']);
            $db['items'] = array_values(array_filter($db['items'], static fn(array $it): bool => (int)$it['id'] !== $id));
            if (count($db['items']) === $before) {
                unlock_guard($lock);
                fail('未找到该条反馈，可能已被删除');
            }
            atomic_write(FEEDBACK_FILE, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            unlock_guard($lock);
        }

        // 分页：默认每页 20 条、最多 100（反馈按时间倒序，原先一次性返回全部）
        $items    = array_reverse($db['items']);
        $total    = count($items);
        $page     = max(1, (int)($in['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($in['pageSize'] ?? 20)));
        $items    = array_slice($items, ($page - 1) * $pageSize, $pageSize);
        respond([
            'ok'       => true,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'hasMore'  => ($page * $pageSize) < $total,
            'items'    => $items,
        ]);
    }

    /* ---- 管理页：数据概览（故事/赞/浏览/评论总数 + 今日新增 + 未读反馈） ---- */
    case 'admin_stats': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        admin_auth($in);

        $db         = db_load();
        $todayStart = strtotime('today');   // 今天 00:00（服务器时区）

        $likes = 0; $views = 0; $comments = 0; $todayStories = 0; $todayComments = 0;
        foreach ($db['stories'] as $s) {
            $likes += (int)($s['likes'] ?? 0);
            $views += (int)($s['views'] ?? 0);
            if ((int)($s['createdAt'] ?? 0) >= $todayStart) { $todayStories++; }
            foreach (($s['comments'] ?? []) as $c) {
                $comments++;
                if ((int)($c['createdAt'] ?? 0) >= $todayStart) { $todayComments++; }
                foreach (($c['replies'] ?? []) as $r) {
                    $comments++;
                    if ((int)($r['createdAt'] ?? 0) >= $todayStart) { $todayComments++; }
                }
            }
        }

        $fb = ['items' => []];
        $loaded = json_decode((string)@file_get_contents(FEEDBACK_FILE) ?: '', true);
        if (is_array($loaded) && isset($loaded['items'])) { $fb = $loaded; }
        $fbTotal = 0; $fbUnread = 0; $todayFb = 0;
        foreach ($fb['items'] as $it) {
            $fbTotal++;
            if (empty($it['read'])) { $fbUnread++; }
            if ((int)($it['createdAt'] ?? 0) >= $todayStart) { $todayFb++; }
        }

        respond(['ok' => true, 'stats' => [
            'stories'        => count($db['stories']),
            'likes'          => $likes,
            'views'          => $views,
            'comments'       => $comments,
            'todayStories'   => $todayStories,
            'todayComments'  => $todayComments,
            'feedback'       => $fbTotal,
            'feedbackUnread' => $fbUnread,
            'todayFeedback'  => $todayFb,
            'today'          => date('Y-m-d'),
        ]]);
    }

    /* ---- 站点统计 ---- */
    case 'stats': {
        $db = db_load();
        $likes = 0; $views = 0; $sweet = 0;
        foreach ($db['stories'] as $s) {
            $likes += (int)$s['likes'];
            $views += (int)$s['views'];
            if (in_array('sweet', (array)($s['tags'] ?? []), true)) { $sweet++; }
        }
        respond(['ok' => true, 'stats' => [
            'stories' => count($db['stories']),
            'likes'   => $likes,
            'views'   => $views,
            'sweet'   => $sweet,
        ]]);
    }

    /* ---- 热门精选 TOP N（按全时段热度排序，没有时间窗口） ---- */
    case 'hot': {
        $limit = min(10, max(1, (int)($_GET['limit'] ?? 5)));
        $db = db_load();
        $arr = $db['stories'];
        usort($arr, fn($a, $b) => hot_score($b) <=> hot_score($a));
        $arr = array_slice($arr, 0, $limit);
        respond(['ok' => true, 'stories' => array_map(fn($s) => [
            'id'            => (int)$s['id'],
            'title'         => (string)$s['title'],
            'views'         => (int)$s['views'],
            'likes'         => (int)($s['likes'] ?? 0),
            'commentsCount' => comments_count($s),
        ], $arr)]);
    }

    default:
        fail('未知接口 route=' . htmlspecialchars($route, ENT_QUOTES), 404);
}
