<?php
/**
 * 频道迁移脚本（2026-11-05「相亲故事屋」→「人间故事屋」）
 *
 * 把历史故事里的旧标签（sweet / touching / complain …）映射成新的 7 个频道
 * （love / daily / funny / work / family / growth / rant），每个故事只留一个 tag。
 *
 * 用法：
 *   php tools/migrate_channels.php data/stories.json --dry-run   # 只看会改什么，不写盘
 *   php tools/migrate_channels.php data/stories.json             # 真改（自动备份 .bak-时间戳）
 *
 * 容器内执行（线上）：
 *   docker cp migrate_channels.php yuanfen-stories:/tmp/m.php
 *   docker exec yuanfen-stories php /tmp/m.php /var/www/html/data/stories.json --dry-run
 *
 * 只改 `tags` 字段，正文、点赞、评论、删除凭证一律原样保留。
 */

const VALID = ['love', 'daily', 'funny', 'work', 'family', 'growth', 'rant'];

// 必须与 api.php / server.js 的 LEGACY_TAG_MAP 保持一致
const MAP = [
    'sweet'    => 'love',
    'touching' => 'love',
    'chujian'  => 'love',
    'liaotian' => 'love',
    'jianjia'  => 'love',
    'yilian'   => 'love',
    'shanhun'  => 'love',
    'yidi'     => 'love',
    'complain' => 'rant',
    'cuihun'   => 'family',
    'jiaolv'   => 'growth',
    'daily'    => 'daily',
    'funny'    => 'funny',
];

$file = $argv[1] ?? '';
$dry  = in_array('--dry-run', $argv, true);

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "用法：php migrate_channels.php <stories.json 路径> [--dry-run]\n");
    exit(1);
}

$raw = file_get_contents($file);
$db = json_decode($raw, true);
if (!is_array($db) || !isset($db['stories']) || !is_array($db['stories'])) {
    fwrite(STDERR, "文件不是预期的故事库格式（缺 stories 数组）：$file\n");
    exit(1);
}

$changed = 0;
$unknown = [];

foreach ($db['stories'] as $i => $s) {
    $old = array_values((array)($s['tags'] ?? []));
    $new = [];

    foreach ($old as $t) {
        $t = (string)$t;
        if (isset(MAP[$t])) { $new[] = MAP[$t]; break; }         // 旧标签 → 频道
        if (in_array($t, VALID, true)) { $new[] = $t; break; }    // 已经是新频道
    }
    if (!$new) {
        // 空标签或无法识别的值 → 兜底「日常分享」，同时打出来让人看一眼
        if ($old) { $unknown[] = '#' . ($s['id'] ?? '?') . ' tags=' . implode(',', $old); }
        $new = ['daily'];
    }

    if ($new !== $old) {
        $changed++;
        if ($dry) {
            printf("  #%s  %-38s  [%s] → [%s]\n",
                (string)($s['id'] ?? '?'),
                mb_substr((string)($s['title'] ?? ''), 0, 18),
                implode(',', $old),
                implode(',', $new)
            );
        }
        $db['stories'][$i]['tags'] = $new;
    }
}

// 分布统计（迁移后）
// ⚠️ 不能写成 `(string)((array)($s['tags'] ?? ['daily'])[0] ?? 'daily')`：
//    PHP 会把 `[0]` 先作用在外面，cast 成 array 后 `(string)` 得到 "Array"（分布全变 Array=11）
$dist = [];
foreach ($db['stories'] as $s) {
    $tags = array_values((array)($s['tags'] ?? []));
    $t = (string)($tags[0] ?? 'daily');
    $dist[$t] = ($dist[$t] ?? 0) + 1;
}
ksort($dist);

echo "\n文件：$file\n";
echo '故事总数：' . count($db['stories']) . "，需要改动：$changed\n";
if ($unknown) {
    echo "无法识别的标签（已归入 daily，请人工确认）：\n  " . implode("\n  ", $unknown) . "\n";
}
echo '迁移后分布：' . implode('  ', array_map(
    static fn($k, $v) => $k . '=' . $v,
    array_keys($dist),
    array_values($dist)
)) . "\n";

if ($dry) {
    echo "\n[DRY-RUN] 没有写盘。去掉 --dry-run 才会真正保存。\n";
    exit(0);
}

$bak = $file . '.bak-' . date('Ymd-His');
if (!@copy($file, $bak)) {
    fwrite(STDERR, "备份失败，已中止（未改动原文件）：$bak\n");
    exit(1);
}
$tmp = $file . '.tmp';
$json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
    fwrite(STDERR, "写入失败：$file（原文件备份在 $bak）\n");
    exit(1);
}
echo "已写入。备份：$bak\n";
