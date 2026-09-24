<div align="center">

# 💕 缘分故事屋

**一个零数据库依赖的匿名相亲故事分享社区**
—— 把每一段相亲经历，写成值得被认真讲述的故事。

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![Database](https://img.shields.io/badge/数据库-无-orange) ![Node](https://img.shields.io/badge/Node-开发服务器-green) ![Docker](https://img.shields.io/badge/Docker-支持-2496ed) ![License](https://img.shields.io/badge/用途-个人项目-pink)

**在线访问**：部署后 `http://你的域名` · **站长管理**：`/admin.html`

</div>

---

## ✨ 这是什么

一个让网友匿名分享相亲经历的小站：甜蜜的、搞笑的、感动的、吐槽的——每一段经历都有被倾听的机会。访客无需注册即可发布与评论，站长通过密码管理页维护内容。

| 模块 | 能力 |
|------|------|
| 🏠 **故事广场** | 热门/最新排序、13 类标签筛选、分页、详情弹窗、匿名评论、点赞（本地记忆） |
| ✍️ **发布系统** | 匿名发布（随机昵称）、纯文本/HTML 双模式编辑、上传 HTML 文件、富文本粘贴、实时预览 |
| 🎨 **HTML 排版故事** | 上传你排版的网页，阅读时以沙箱 iframe 呈现——CSS 变量、`@keyframes`、滚动动画 **100% 原样**，还提供「原文阅读」全屏打开 |
| 🛡️ **防刷机制** | 浏览量按 IP 24h 去重、点赞本地记忆防连点、内容服务端净化、用户 CSS 沙箱隔离 |
| 🔐 **站长管理** | 反馈箱（查看/删除网友反馈）、故事管理（最新排序/标签筛选/点击预览/删除），密码保护 |
| 📮 **互动与合规** | 反馈建议页、内容规范、隐私声明、免责声明、标签云实时计数、站点统计 |

---

## 🚀 快速开始

### 本地体验（无需安装任何依赖）

```bash
git clone https://github.com/Bhands6/dating-stories.git
cd dating-stories
node server.js          # 打开 http://localhost:8000
```

> 需要 Node.js 18+。端口被占用会自动 +1。

### 服务器部署

**方式一：宝塔面板（推荐）**

1. 建站（PHP 7.4+，**不勾选数据库**），将仓库文件放入站点根目录
2. `data/` 目录赋予写权限（755/775）
3. 完成。无需任何 PHP 扩展与数据库

**方式二：Docker**

```bash
docker compose up -d --build
# http://服务器IP:8010（端口在 docker-compose.yml 中修改）
```

> 📋 完整部署步骤、nginx 注意事项、数据备份与迁移，见 **[部署说明.md](docs/部署说明.md)**

### ⚠️ 部署前必改：管理密码

`api.php` 与 `server.js` 顶部的 `ADMIN_KEY`（两处保持一致），是站长管理页（`/admin.html`）的解锁密码。**使用默认密码等于对外开放管理后台，务必修改。**

---

## 🖼️ 页面一览

| 页面 | 路径 | 说明 |
|------|------|------|
| 🏠 故事广场 | `/` | 浏览、筛选、阅读、评论、点赞 |
| ✍️ 发布故事 | `/publish.html` | 单选标签 + 双模式编辑器 |
| 💌 反馈建议 | `/feedback.html` | 网友提交反馈（存服务端） |
| 🔐 站长管理 | `/admin.html` | 反馈箱 + 故事管理 |
| 📖 内容规范 / 🔒 隐私声明 / 📜 免责声明 | `/terms.html` 等 | 合规页面 |

---

## 🏗️ 技术架构

```
浏览器 ──► 纯静态前端（index/publish/admin/...）
                │
                ▼  fetch api.php?route=xxx
        ┌───────────────┐
        │  api.php      │  PHP 单文件后端（生产）
        │  或 server.js │  Node 零依赖开发服务器（接口完全对齐）
        └───────┬───────┘
                ▼  JSON 文件读写（flock 文件锁）
        data/stories.json   故事/评论/点赞/删除凭证
        data/feedback.json  网友反馈
        data/views_log.json 浏览量防刷记录
```

- **零数据库**：无 MySQL/SQLite，数据即文件，备份 = 复制 `data/` 目录
- **双后端对齐**：`server.js`（本地）与 `api.php`（生产）接口行为一致，同一份 `data/` 无缝迁移
- **安全设计**：UGC 内容服务端净化（script/事件属性/危险协议/CSS 表达式全清）；HTML 排版故事以 `CSP: sandbox` 沙箱 iframe 渲染，内部脚本无法触碰站点数据；管理接口密码校验；发布者删除凭证（随机 editKey，仅存发布者本机）

## 📂 目录结构

```
├── index.html          # 首页
├── publish.html        # 发布页
├── admin.html          # 站长管理页
├── feedback.html       # 反馈建议页
├── terms / privacy / disclaimer.html
├── api.php             # PHP 后端（生产）
├── server.js           # Node 开发服务器
├── data/
│   ├── seed.json       # 种子数据（首次运行初始化）
│   ├── stories.json    # 运行时生成：故事/评论/点赞
│   ├── feedback.json   # 运行时生成：反馈
│   └── views_log.json  # 运行时生成：防刷记录
├── Dockerfile / docker-compose.yml
└── 部署说明.md          # 详细部署文档
```

## 📖 API 概览

统一入口 `api.php?route=xxx`，完整参数见 [部署说明.md](部署说明.md#-api-一览)：

| route | 说明 |
|-------|------|
| `stories` | 列表（筛选/分页）/ 发布（返回一次性删除凭证） |
| `story` / `raw` | 详情（防刷计数）/ 原文沙箱渲染 |
| `like` / `comments` | 点赞 / 评论 |
| `delete_story` | 发布者凭凭证删除 |
| `tags` / `stats` / `hot` | 标签计数 / 统计 / 热门榜 |
| `feedback` | 提交反馈 |
| `admin_feedback` / `admin_stories` | 管理接口（需 ADMIN_KEY） |



## 🤝 贡献

欢迎 Issue 与 PR。发布内容请遵守站内[内容规范](terms.html)：真实分享、尊重他人、不传播隐私。

---

<div align="center">

**每一段缘分都值得被记录** · Made with ❤ · 每一个故事都值得被听见

</div>
