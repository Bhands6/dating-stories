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

## 数据文件（data/）
- `stories.json` 故事+评论+点赞 / `feedback.json` 反馈
- `admin_key.txt` 管理密码（12 位随机，gitignore）/ `admin_guard.json` 登录失败锁定
- `rate_guard.json` 写接口限流 / `views_log.json` 浏览量防刷
- `*.lock` 独立 flock 互斥锁（**不能锁数据文件本身**，rename 后锁失效）
- 写盘统一「临时文件 + rename」原子替换；PHP 侧读-改-写走 `db_transaction()` 全程持锁
- 🔒 `data/` 必须禁止 HTTP 访问：Apache 靠自带 `.htaccess`；**nginx 无效，需站点配置加
  `location ^~ /data/ { deny all; return 404; }`**

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

## 2026-10-09 观察到的问题（未提交）
「访客删除动态」功能开发到一半，**双后端不对齐**：
- `server.js` 侧完整：`recordDeletion()` + `deletionsLoad()` + `admin_deletions` 路由
  （op=list/read、unread 统计、readUpTo 推进），已在 delete_comment / delete_story 处调用
- `api.php` 侧只加了 `DELETIONS_FILE` / `DELETIONS_MAX` 常量和 `record_deletion()` 函数体，
  **没有 `admin_deletions` 路由、没有任何调用点** → 切到生产 PHP 后端该功能直接失效
- `admin.html` 也还没接「删除动态」UI（grep `deletions` 无命中）
- 数据文件 `data/deletions.json` 尚不存在
