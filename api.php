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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

define('DATA_DIR', __DIR__ . '/data');
define('DATA_FILE', DATA_DIR . '/stories.json');
define('SEED_FILE', DATA_DIR . '/seed.json');
define('FEEDBACK_FILE', DATA_DIR . '/feedback.json');
define('VIEWS_LOG', DATA_DIR . '/views_log.json');

/**
 * 管理页密码（用于 admin.html 查看网友反馈）
 * ⚠️ 部署上线前请务必修改成你自己的密码！
 */
const ADMIN_KEY = 'yuanfen2025';

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

/** 读取数据库（首次运行自动用种子数据初始化，时间戳按当前时间偏移生成） */
function db_load(): array
{
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
    if (!file_exists(DATA_FILE)) {
        $seed = [];
        if (file_exists(SEED_FILE)) {
            $seed = json_decode((string)file_get_contents(SEED_FILE), true) ?: [];
        }
        $now = time();
        $stories = $seed['stories'] ?? [];
        foreach ($stories as &$s) {
            $s['createdAt'] = $now - (int)($s['createdAtOffset'] ?? 0);
            unset($s['createdAtOffset']);
            foreach (($s['comments'] ?? []) as &$c) {
                $c['createdAt'] = $now - (int)($c['createdAtOffset'] ?? 0);
                unset($c['createdAtOffset']);
            }
            unset($c);
        }
        unset($s);
        $db = ['nextId' => (int)($seed['nextId'] ?? (count($stories) + 1)), 'stories' => $stories];
        db_save($db);
        return $db;
    }
    $db = json_decode((string)file_get_contents(DATA_FILE), true);
    if (!is_array($db)) { fail('数据文件损坏，请联系管理员', 500); }
    if (!isset($db['stories']) || !is_array($db['stories'])) { $db['stories'] = []; }
    if (!isset($db['nextId'])) { $db['nextId'] = count($db['stories']) + 1; }
    return $db;
}

/** 写数据库（带文件锁，写临时文件后原子替换） */
function db_save(array $db): void
{
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
    $fp = fopen(DATA_FILE, 'c+');
    if (!$fp) { fail('无法写入数据文件', 500); }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * HTML 轻净化：去 script/危险属性（保留 style/结构/动画）。
 * mode=html 的内容阅读时通过沙箱 iframe（route=raw）渲染，与站点完全隔离，
 * 因此无需再做 CSS 作用域隔离，样式 100% 原样。
 */
function sanitize_html(string $html): string
{
    $html = preg_replace('#<\s*script\b[^>]*>[\s\S]*?<\s*/\s*script\s*>#is', '', $html) ?? '';
    $html = preg_replace('#<\s*/?\s*script\b[^>]*>#is', '', $html) ?? '';
    $html = preg_replace('#<\s*(iframe|object|embed|form|link|meta)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
    $html = preg_replace('#<\s*/?\s*(iframe|object|embed|form|link|meta|input|button|textarea|select)\b[^>]*>#is', '', $html) ?? '';
    $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
    $html = preg_replace('#(href|src)\s*=\s*("|\')\s*(javascript|vbscript):[^"\']*#i', '$1=$2#', $html) ?? '';
    $html = preg_replace('#(href|src)\s*=\s*(?!["\'])(javascript|vbscript):[^\s>]*#i', '', $html) ?? '';
    $html = preg_replace('#(?<![-\w])behavior\s*:#i', '', $html) ?? '';
    $html = preg_replace('#expression\s*\(#i', '', $html) ?? '';
    return trim($html);
}

/** 详情页「滚动显现动画」驱动器（站点注入的安全脚本，随 raw 输出附带） */
const REVEAL_SCRIPT = <<<'HTML'
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
</script>
HTML;

/** 输出故事的原始 HTML（沙箱隔离，供 iframe/新窗口阅读） */
function serve_raw_story(array $story): void
{
    $title = preg_replace('/[<>&"]/', '', (string)($story['title'] ?? '故事')) ?: '故事';
    $body = (string)($story['content'] ?? '');
    $html = "<!DOCTYPE html>\n<html lang=\"zh-CN\">\n<head>\n<meta charset=\"UTF-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
        . "<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title>\n"
        . "<style>html,body{margin:0;padding:0;}</style>\n"
        . "</head>\n<body>\n" . $body . "\n" . REVEAL_SCRIPT . "\n</body>\n</html>";
    header('Content-Type: text/html; charset=utf-8');
    // 沙箱化：文档为独立源，内部脚本无法访问站点数据/存储，仅可运行自身动画
    header("Content-Security-Policy: sandbox allow-scripts");
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

/** 获取客户端真实 IP（兼容 nginx/Docker 反代） */
function client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** 浏览量去重：同一 IP 对同一故事 24 小时内只计 1 次 */
function should_count_view(string $ip, int $id): bool
{
    $window = 86400; // 24 小时
    $now = time();
    $fp = fopen(VIEWS_LOG, 'c+');
    if (!$fp) { return true; } // 日志不可用时退化为始终计数
    flock($fp, LOCK_EX);
    rewind($fp);
    $raw = stream_get_contents($fp);
    $log = json_decode($raw ?: '', true);
    if (!is_array($log)) { $log = []; }
    $key = $ip . '|' . $id;
    $counted = false;
    if (isset($log[$key]) && ($now - (int)$log[$key]) < $window) {
        // 窗口内已计过：不计数
    } else {
        $log[$key] = $now;
        $counted = true;
    }
    // 清理过期记录，防止文件无限增长
    foreach ($log as $k => $t) {
        if (($now - (int)$t) > $window) { unset($log[$k]); }
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($log, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $counted;
}

/** 评论输出映射（两级楼中楼：一级评论 + 扁平回复列表） */
function comment_out(array $c): array
{
    return [
        'id'              => (string)$c['id'],
        'nickname'        => (string)$c['nickname'],
        'content'         => (string)$c['content'],
        'createdAt'       => (int)$c['createdAt'],
        'replies'         => array_values(array_map(static fn(array $r): array => [
            'id'              => (string)$r['id'],
            'nickname'        => (string)$r['nickname'],
            'content'         => (string)$r['content'],
            'createdAt'       => (int)$r['createdAt'],
            'replyToNickname' => (string)($r['replyToNickname'] ?? ''),
        ], array_values($c['replies'] ?? []))),
    ];
}

/** 两级楼中楼：在评论里查找目标（一级或其回复），新回复统一挂到所属一级评论的 replies 末尾 */
function comment_reply_attach(array &$comments, string $replyTo, array $entry): bool
{
    foreach ($comments as $i => $c) {
        $found = null;
        if ((string)$c['id'] === $replyTo) {
            $found = $c;
        } else {
            foreach (($c['replies'] ?? []) as $r) {
                if ((string)$r['id'] === $replyTo) { $found = $r; break; }
            }
        }
        if ($found !== null) {
            $entry['replyToNickname'] = (string)$found['nickname'];
            $comments[$i]['replies'] = array_values($c['replies'] ?? []);
            $comments[$i]['replies'][] = $entry;
            return true;
        }
    }
    return false;
}

/** 评论数 = 种子基数 + 一级评论数 + 各自回复数（两级） */
function comments_count(array $story): int
{
    $real = 0;
    foreach (($story['comments'] ?? []) as $c) {
        $real += 1 + count($c['replies'] ?? []);
    }
    return (int)($story['commentsBase'] ?? 0) + $real;
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
            $mode = ($in['mode'] ?? 'text') === 'html' ? 'html' : 'text';
            $nickname = trim((string)($in['nickname'] ?? ''));
            $tagsIn = (array)($in['tags'] ?? []);

            if ($title === '') { fail('故事标题不能为空'); }
            if (mb_strlen($title) > 60) { fail('标题最多 60 个字'); }
            if ($content === '') { fail('故事内容不能为空'); }
            if (mb_strlen($content) > ($mode === 'html' ? 50000 : 20000)) {
                fail('内容太长啦，最多 ' . ($mode === 'html' ? 50000 : 20000) . ' 个字符');
            }
            if (mb_strlen($nickname) > 20) { fail('昵称最多 20 个字符'); }
            if ($nickname === '') { $nickname = random_nickname(); }

            $tags = array_values(array_unique(array_intersect($tagsIn, VALID_TAGS)));
            if (count($tags) > 4) { $tags = array_slice($tags, 0, 4); }
            // 一个标签都没选时，默认归为「日常记录」，保证每篇故事都有归属分类
            if (empty($tags)) { $tags = ['daily']; }

            $db = db_load();
            $story = [
                'id'           => (int)$db['nextId']++,
                'title'        => $title,
                'content'      => $mode === 'html' ? sanitize_html($content) : $content,
                'mode'         => $mode,
                'tags'         => $tags,
                'author'       => ['nickname' => $nickname, 'info' => '缘分旅人 · 匿名分享'],
                'likes'        => 0,
                'views'        => 0,
                'commentsBase' => 0,
                'comments'     => [],
                'createdAt'    => time(),
            ];
            // 生成发布者删除凭证（仅本次响应返回，存储在发布者浏览器里）
            $editKey = substr(bin2hex(random_bytes(5)), 0, 8);
            $story['editKey'] = $editKey;
            array_unshift($db['stories'], $story);
            db_save($db);
            $full = story_full($story);
            $full['editKey'] = $editKey;
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

    /* ---- 故事原文（沙箱隔离渲染，供 iframe/新窗口阅读） ---- */
    case 'raw': {
        $db = db_load();
        foreach ($db['stories'] as $s) {
            if ((int)$s['id'] === $id) {
                if (($s['mode'] ?? 'text') === 'html') {
                    serve_raw_story($s);
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

    /* ---- 故事详情（浏览量按 IP 24h 去重计数） ---- */
    case 'story': {
        $db = db_load();
        foreach ($db['stories'] as $i => $s) {
            if ((int)$s['id'] === $id) {
                // 前端会话内重复打开(count=0)或同 IP 24h 内重复访问，都不重复计数
                if (($_GET['count'] ?? '1') !== '0' && should_count_view(client_ip(), $id)) {
                    $db['stories'][$i]['views'] = (int)$s['views'] + 1;
                    db_save($db);
                }
                $full = story_full($db['stories'][$i]);
                respond(['ok' => true, 'story' => $full]);
            }
        }
        fail('故事不存在或已被删除', 404);
    }

    /* ---- 点赞（like / unlike） ---- */
    case 'like': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        $undo = !empty($in['undo']);
        $db = db_load();
        foreach ($db['stories'] as $i => $s) {
            if ((int)$s['id'] === (int)($in['id'] ?? 0)) {
                $db['stories'][$i]['likes'] = max(0, (int)$s['likes'] + ($undo ? -1 : 1));
                db_save($db);
                respond(['ok' => true, 'likes' => (int)$db['stories'][$i]['likes']]);
            }
        }
        fail('故事不存在或已被删除', 404);
    }

    /* ---- 评论 ---- */
    case 'comments': {
        $db = db_load();
        $idx = null;
        foreach ($db['stories'] as $i => $s) {
            if ((int)$s['id'] === $id) { $idx = $i; break; }
        }
        if ($idx === null) { fail('故事不存在或已被删除', 404); }

        if ($method === 'POST') {
            $in = body_json();
            $content = trim((string)($in['content'] ?? ''));
            $nickname = trim((string)($in['nickname'] ?? ''));
            $replyTo = trim((string)($in['replyTo'] ?? ''));
            if ($content === '') { fail('评论内容不能为空'); }
            if (mb_strlen($content) > 500) { fail('评论最多 500 个字'); }
            if (mb_strlen($nickname) > 20) { fail('昵称最多 20 个字符'); }
            if ($nickname === '') { $nickname = random_nickname(); }
            $entry = [
                'id'        => 'c' . time() . mt_rand(1000, 9999),
                'nickname'  => $nickname,
                'content'   => $content,
                'createdAt' => time(),
            ];
            if ($replyTo !== '') {
                /* 两级楼中楼：replyTo 为目标评论或回复的 id，
                   回复统一挂在其所属一级评论的 replies 下，并记录被回复人昵称 */
                $comments = array_values($db['stories'][$idx]['comments'] ?? []);
                if (!comment_reply_attach($comments, $replyTo, $entry)) {
                    fail('要回复的评论不存在或已被删除', 404);
                }
                $db['stories'][$idx]['comments'] = $comments;
            } else {
                $db['stories'][$idx]['comments'][] = $entry;
            }
            db_save($db);
            respond(['ok' => true, 'commentsCount' => comments_count($db['stories'][$idx]), 'comment' => $entry]);
        }

        respond(['ok' => true, 'comments' => array_map('comment_out', array_values($db['stories'][$idx]['comments'] ?? []))]);
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
        if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
        $db = ['nextId' => 1, 'items' => []];
        if (file_exists(FEEDBACK_FILE)) {
            $loaded = json_decode((string)file_get_contents(FEEDBACK_FILE), true);
            if (is_array($loaded)) { $db = $loaded; }
        }
        $item = [
            'id'        => (int)$db['nextId']++,
            'nickname'  => $nickname,
            'contact'   => $contact,
            'content'   => $content,
            'createdAt' => time(),
        ];
        $db['items'][] = $item;
        @file_put_contents(FEEDBACK_FILE, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        respond(['ok' => true, 'message' => '反馈已收到，感谢你的每一句建议 💕']);
    }

    /* ---- 发布者删除自己的故事（凭发布时下发的凭证） ---- */
    case 'delete_story': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        $id = (int)($in['id'] ?? 0);
        $editKey = trim((string)($in['editKey'] ?? ''));
        $db = db_load();
        foreach ($db['stories'] as $i => $s) {
            if ((int)$s['id'] === $id) {
                if (empty($s['editKey']) || !hash_equals((string)$s['editKey'], $editKey)) {
                    fail('删除凭证不正确，无法删除这篇故事');
                }
                array_splice($db['stories'], $i, 1);
                db_save($db);
                respond(['ok' => true, 'message' => '故事已删除']);
            }
        }
        fail('故事不存在或已被删除', 404);
    }

    /* ---- 管理页：故事管理（查看/删除任意故事，支持标签筛选、最新在前） ---- */
    case 'admin_stories': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        if (!hash_equals(ADMIN_KEY, (string)($in['key'] ?? ''))) { fail('管理密码错误', 403); }

        $db = db_load();
        if (($in['op'] ?? 'list') === 'delete') {
            $id = (int)($in['id'] ?? 0);
            $before = count($db['stories']);
            $db['stories'] = array_values(array_filter($db['stories'], static fn(array $s): bool => (int)$s['id'] !== $id));
            if (count($db['stories']) === $before) { fail('未找到该故事，可能已被删除'); }
            db_save($db);
        }

        $tag = (string)($in['tag'] ?? 'all');
        $items = array_values(array_filter($db['stories'], static function (array $s) use ($tag): bool {
            if ($tag !== 'all' && (!isset($s['tags']) || !in_array($tag, (array)$s['tags'], true))) { return false; }
            return true;
        }));
        usort($items, static fn(array $a, array $b): int => ((int)($b['createdAt'] ?? 0)) <=> ((int)($a['createdAt'] ?? 0)));
        $items = array_map(static fn(array $s): array => [
            'id'            => (int)$s['id'],
            'title'         => (string)$s['title'],
            'author'        => (string)($s['author']['nickname'] ?? '匿名'),
            'tags'          => array_values((array)($s['tags'] ?? [])),
            'likes'         => (int)$s['likes'],
            'views'         => (int)$s['views'],
            'commentsCount' => comments_count($s),
            'createdAt'     => (int)($s['createdAt'] ?? 0),
        ], $items);
        respond(['ok' => true, 'total' => count($items), 'items' => $items]);
    }

    /* ---- 管理页：查看/删除反馈 ---- */
    case 'admin_feedback': {
        if ($method !== 'POST') { fail('请使用 POST', 405); }
        $in = body_json();
        if (!hash_equals(ADMIN_KEY, (string)($in['key'] ?? ''))) { fail('管理密码错误', 403); }

        $db = ['nextId' => 1, 'items' => []];
        if (file_exists(FEEDBACK_FILE)) {
            $loaded = json_decode((string)file_get_contents(FEEDBACK_FILE), true);
            if (is_array($loaded)) { $db = $loaded; }
        }
        if (!isset($db['items']) || !is_array($db['items'])) { $db['items'] = []; }

        if (($in['op'] ?? 'list') === 'delete') {
            $id = (int)($in['id'] ?? 0);
            $before = count($db['items']);
            $db['items'] = array_values(array_filter($db['items'], static fn(array $it): bool => (int)$it['id'] !== $id));
            if (count($db['items']) === $before) { fail('未找到该条反馈，可能已被删除'); }
            @file_put_contents(FEEDBACK_FILE, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        }

        $items = array_reverse($db['items']);
        respond(['ok' => true, 'total' => count($items), 'items' => $items]);
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

    /* ---- 本周热门 TOP N ---- */
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
            'commentsCount' => comments_count($s),
        ], $arr)]);
    }

    default:
        fail('未知接口 route=' . htmlspecialchars($route, ENT_QUOTES), 404);
}
