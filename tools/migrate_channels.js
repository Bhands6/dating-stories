#!/usr/bin/env node
/**
 * 频道迁移脚本（2026-11-05「相亲故事屋」→「人生故事屋」）
 *
 * 把历史故事里的旧标签（sweet / touching / complain …）映射成新的 7 个频道
 * （love / daily / funny / work / family / growth / rant），每个故事只留一个 tag。
 *
 * 用法：
 *   node tools/migrate_channels.js data/stories.json --dry-run   # 只看会改什么，不写盘
 *   node tools/migrate_channels.js data/stories.json             # 真改（自动备份 .bak-时间戳）
 *
 * 容器内执行（线上）：
 *   docker exec yuanfen-stories node tools/migrate_channels.js /app/data/stories.json --dry-run
 *
 * 只改 `tags` 字段，正文、点赞、评论、删除凭证一律原样保留。
 */

'use strict';
const fs = require('fs');

const VALID = ['love', 'daily', 'funny', 'work', 'family', 'growth', 'rant'];

// 必须与 server.js 的 LEGACY_TAG_MAP 保持一致
const MAP = {
  sweet: 'love',
  touching: 'love',
  chujian: 'love',
  liaotian: 'love',
  jianjia: 'love',
  yilian: 'love',
  shanhun: 'love',
  yidi: 'love',
  complain: 'rant',
  cuihun: 'family',
  jiaolv: 'growth',
  daily: 'daily',
  funny: 'funny',
};

const args = process.argv.slice(2);
const dry = args.includes('--dry-run');
const file = args.find(a => !a.startsWith('--'));

if (!file || !fs.existsSync(file)) {
  console.error('用法：node migrate_channels.js <stories.json 路径> [--dry-run]');
  process.exit(1);
}

let db;
try {
  db = JSON.parse(fs.readFileSync(file, 'utf8'));
} catch (e) {
  console.error('JSON 解析失败：' + file + '（' + e.message + '）');
  process.exit(1);
}
if (!db || !Array.isArray(db.stories)) {
  console.error('文件不是预期的故事库格式（缺 stories 数组）：' + file);
  process.exit(1);
}

let changed = 0;
const unknown = [];

for (const s of db.stories) {
  const old = Array.isArray(s.tags) ? s.tags.map(String) : [];
  let nw = [];

  for (const t of old) {
    if (Object.prototype.hasOwnProperty.call(MAP, t)) { nw.push(MAP[t]); break; }  // 旧标签 → 频道
    if (VALID.includes(t)) { nw.push(t); break; }                                   // 已经是新频道
  }
  if (!nw.length) {
    // 空标签或无法识别的值 → 兜底「日常分享」，同时打出来让人看一眼
    if (old.length) unknown.push('#' + (s.id != null ? s.id : '?') + ' tags=' + old.join(','));
    nw = ['daily'];
  }

  if (JSON.stringify(nw) !== JSON.stringify(old)) {
    changed++;
    if (dry) {
      console.log('  #' + (s.id != null ? s.id : '?')
        + '  ' + String(s.title || '').slice(0, 18).padEnd(20)
        + '  [' + old.join(',') + '] → [' + nw.join(',') + ']');
    }
    s.tags = nw;
  }
}

// 分布统计（迁移后）
const dist = {};
for (const s of db.stories) {
  const t = (Array.isArray(s.tags) && s.tags[0]) || 'daily';
  dist[t] = (dist[t] || 0) + 1;
}

console.log('\n文件：' + file);
console.log('故事总数：' + db.stories.length + '，需要改动：' + changed);
if (unknown.length) {
  console.log('无法识别的标签（已归入 daily，请人工确认）：\n  ' + unknown.join('\n  '));
}
console.log('迁移后分布：' + Object.keys(dist).sort()
  .map(k => k + '=' + dist[k]).join('  '));

if (dry) {
  console.log('\n[DRY-RUN] 没有写盘。去掉 --dry-run 才会真正保存。');
  process.exit(0);
}

// 备份 → 临时文件 + rename 原子落盘（与 server.js 的写盘姿势一致，2 空格缩进）
const p2 = n => String(n).padStart(2, '0');
const d = new Date();
const stamp = '' + d.getFullYear() + p2(d.getMonth() + 1) + p2(d.getDate())
  + '-' + p2(d.getHours()) + p2(d.getMinutes()) + p2(d.getSeconds());
const bak = file + '.bak-' + stamp;
try {
  fs.copyFileSync(file, bak);
} catch (e) {
  console.error('备份失败，已中止（未改动原文件）：' + bak);
  process.exit(1);
}
const tmp = file + '.tmp';
try {
  fs.writeFileSync(tmp, JSON.stringify(db, null, 2), 'utf8');
  fs.renameSync(tmp, file);
} catch (e) {
  console.error('写入失败：' + file + '（原文件备份在 ' + bak + '）');
  process.exit(1);
}
console.log('已写入。备份：' + bak);
