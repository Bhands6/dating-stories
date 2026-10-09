# 项目长期记忆：缘分故事屋

## 项目定位
- 匿名相亲故事分享社区，线上 https://bhands.me
- 仓库：`F:\UE\MyBase\念爱故事屋`，远端 https://github.com/Bhands6/dating-stories.git（分支 main）
- **零数据库**：数据即 JSON 文件，备份 = 复制 `data/` 目录

## 技术架构（关键约束：双后端必须对齐）
- 前端：纯静态多页 HTML（index / publish / admin / feedback / terms / privacy / disclaimer），
  JS 内联在 HTML 里，index.html 约 2200+ 行、128KB，最大单页
- 后端双实现，**同一份 `data/` 无缝迁移**：
  - `api.php`（约 1560 行，70KB）→ 生产（宝塔 / PHP 7.4+）
  - `server.js`（约 1280 行，59KB，零依赖 node:http）→ 本地开发，`npm start`，端口 8000 起
- ⚠️ **改任何接口必须两边同步改**（`RATE_LIMITS` 常量也要一致），这是本项目最容易漏的坑

## 路由清单（20 个）
stories / story / raw / like / real / comments / delete_comment / delete_story /
tags / stats / hot / feedback /
admin_stories / admin_comments / admin_feedback / admin_deletions /
admin_stats / admin_export / admin_change_key

## 运行时数据文件清单（data/）
- `stories.json` 故事+评论+点赞 / `feedback.json` 反馈
- `deletions.json` **访客删除动态**（含访客昵称与被删内容摘录 → 属隐私数据，三处排除清单都要有）
- `admin_key.txt` 管理密码（12 位随机，gitignore）/ `admin_guard.json` 登录失败锁定
- `rate_guard.json` 写接口限流 / `views_log.json` 浏览量防刷
- `*.lock` 独立 flock 互斥锁（**不能锁数据文件本身**，rename 后锁失效）
- 写盘统一「临时文件 + rename」原子替换；PHP 侧读-改-写走 `db_transaction()` 全程持锁
- 🔒 `data/` 必须禁止 HTTP 访问：Apache 靠自带 `.htaccess`（目录级，新文件自动覆盖）；
  **nginx 无效，需站点配置加 `location ^~ /data/ { deny all; return 404; }`**；
  Docker 由镜像内 `deny-data.conf` 兜底

## 新增数据文件时的固定动作（踩过坑）
漏了排除清单 = 隐私泄露进仓库/镜像/上传包。四处都要改：
1. `.gitignore`
2. `.dockerignore`
3. `一键部署.bat` 的 tar exclude 串（⚠️ **GBK 编码，必须用 Buffer+latin1 字节级替换，Edit 工具会毁编码**）
4. `data/.htaccess` 顶部文件清单注释 + `docs/部署说明.md` 目录树/备份表 + `README.md` 目录树

## PHP ↔ Node 数值语义差异（重要）
- PHP `json_decode` 把 `4.0` 解成 **float**，`is_int()` 判定非法 → 会把 `nextId` 重置成 1 造成 **id 复用**
- JS 里 `4.0 === 4`，`Number.isInteger` 正常 → **只有 PHP 侧有这个坑，校验统一用 `is_numeric()`**


## 安全设计（已实现的，别破坏）
- UGC 服务端净化：script / 事件属性 / 危险协议 / CSS 表达式全清
- HTML 排版故事用 `CSP: sandbox` iframe 渲染，隔离用户 CSS
- 不信任 `X-Forwarded-For`（可伪造，会绕过限流）
- 接口不做跨域授权（同源部署）
- 发布者 / 评论者删除凭证（随机 key，仅存本机，服务端不返回）
- 写接口按 IP 滑动窗口限流，校验不通过不消耗额度

## 部署
- 生产走宝塔（不勾数据库）；另有 Dockerfile + docker-compose（端口 8010）
- Windows 一键部署脚本 `一键部署.bat`（本地保留，不进仓库）
- 部署必须等用户明确指令，不自动部署

## Git 习惯
- 改代码前先 git 快照；按主题分组提交（`fix(index):` / `feat(admin):` / `style(index):` / `chore:`）
- 中文 commit 信息，`.gitattributes` 统一 LF（*.bat 例外 CRLF）

## 2026-10-09 进度
- 起点：发现「访客删除动态」功能双后端不对齐（server.js 完整 / api.php 只有函数体无路由无调用）
- 已补完：api.php 补两处调用点 + `admin_deletions` 路由；admin.html 接「访客删除」计数 + 面板 UI
- 改动量：api.php +150 行、admin.html +130 行；本地端到端联调全通过
- 快照提交 `70a1d90`；功能改动本身**尚未提交**，等用户验收后按主题提交


## 接口调用陷阱（踩过，务必记住）
- **`id` 走 URL query，不走 body**：`api.php?route=delete_comment&id=43`。
  放 body 里会被当成 0 → 404「评论不存在或已被删除」，排查时极易误判成逻辑 bug
- `data/stories.json` 等数据文件**不在 git 里**，`git checkout --` 恢复不了。
  改数据前必须 `cp -r data /tmp/xxx` 备份，测完拷回

## 「访客删除动态」功能（2026-10-09 补完，21 个路由之一）
需求：访客自己删掉发布的故事/评论时，站长能在管理页看到留痕（原先完全无感知）。

| 层 | 实现 |
|----|------|
| 数据 | `data/deletions.json`，结构 `{nextId, readUpTo, items[]}`，最多 200 条 |
| 记录 | 访客删除时调 `record_deletion`（php）/ `recordDeletion`（js）；**站长自己在管理页删的不记** |
| 读取 | `admin_deletions` 路由，op=list（默认）/ op=read；返回 unread / unreadStory / unreadComment |
| 未读 | `readUpTo` 水位制：打开面板即视为已看，推到当前最大 id |
| UI | 概览栏第 7 格「访客删除」红色计数，点击展开面板；无未读时置灰 |

- 事务边界：`db_transaction()` 内部已写盘并释放锁，所以 `record_deletion` 必须在**事务外**调用
- 事务闭包里用 `use (&$removed)` 引用传出被删内容快照，别在事务内写第二个文件
- 快照字段：title / author / excerpt（`make_excerpt` 摘要 90 字）/ extra / 删前 likes·views·comments
- `extra` 区分「发布者自行删除」「一级评论（连带 N 条回复）」「一条回复」
- 所有访客字段渲染前过 `esc()`（与 `833a03d` 补 esc 那次的规范一致）

