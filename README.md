<div align="center">

# 💕 缘分故事屋

**一个零数据库依赖的匿名相亲故事分享社区**
—— 把每一段相亲经历，写成值得被认真讲述的故事。

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![Database](https://img.shields.io/badge/数据库-无-orange) ![Node](https://img.shields.io/badge/Node-开发服务器-green) ![Docker](https://img.shields.io/badge/Docker-支持-2496ed) ![License](https://img.shields.io/badge/用途-个人项目-pink)

**在线访问**：部署后 `https://bhands.me` 
</div>

---

## ✨ 这是什么

一个让网友匿名分享相亲经历的小站：甜蜜的、搞笑的、感动的、吐槽的——每一段经历都有被倾听的机会。访客无需注册即可发布与评论，站长通过密码管理页维护内容。

| 模块 | 能力 |
|------|------|
| 🏠 **故事广场** | 热门/最新排序、13 类标签筛选、分页、详情弹窗、匿名评论、点赞（本地记忆） |
| 💬 **评论楼中楼** | 评论回复（「回复 @昵称」标注）、≥3 条自动折叠/展开、发评论/回复局部刷新不跳顶并高亮新内容 |
| ✍️ **发布系统** | 匿名发布（随机昵称）、纯文本/HTML 双模式编辑、上传 HTML 文件、富文本粘贴、实时预览、**AI 排版引导弹窗**（三步指引 + 一键复制提示词模板 + DeepSeek/豆包/小米 MiMo 直达） |
| 🎨 **HTML 排版故事** | 上传你排版的网页，阅读时以沙箱 iframe 呈现——CSS 变量、`@keyframes`、滚动动画 **100% 原样**，还提供「原文阅读」全屏打开 |
| 🛡️ **防刷机制** | 浏览量按 IP 24h 去重、点赞本地记忆防连点、**写接口按 IP 滑动窗口限流**（发布 5 篇/10 分钟、评论 15 条/5 分钟、反馈 3 条/30 分钟）、内容服务端净化、用户 CSS 沙箱隔离 |
| 🔐 **站长管理** | 反馈箱（查看/删除网友反馈）、故事管理（最新排序/标签筛选/点击预览/删除），两个列表均支持分页；密码自动生成（`data/admin_key.txt`），连续输错 5 次锁定 15 分钟 |
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
3. 🔒 **确认 `data/` 无法通过 HTTP 访问**：Apache 站点由仓库自带的 `data/.htaccess` 自动生效；
   **nginx 站点 `.htaccess` 无效**，必须在站点配置里加 `location ^~ /data/ { deny all; return 404; }`。
   验证：访问 `你的域名/data/admin_key.txt` **必须返回 403 或 404**——能下载到内容说明密码已泄露，请立即轮换。
4. 完成。无需任何 PHP 扩展与数据库

**方式二：Docker**

```bash
docker compose up -d --build
# http://服务器IP:8010（端口在 docker-compose.yml 中修改）
```

> 📋 完整部署步骤、nginx 注意事项、数据备份与迁移，见 **[部署说明.md](docs/部署说明.md)**

### 🔑 管理页密码（无需改代码）

管理页 `/admin.html` 的密码**自动生成**，**代码里不再有任何默认密码**：

- **Docker**：容器首次启动生成 **12 位随机密码**，写入 `data/admin_key.txt`，并打印在 `docker logs` 里
- **宝塔 / 本地 Node**：首次调用管理接口时若该文件不存在，同样会**自动生成**并写入 `data/admin_key.txt`
- 手动修改：`echo '新密码' > data/admin_key.txt`（立即生效，无需重启）
- ⚠️ 这个文件是唯一的管理入口，请妥善保存；它**已在 `.gitignore` 中排除**，不要提交进仓库
- 🔒 **登录限流**：同一 IP 连续输错 5 次即锁定 15 分钟（返回 429），防脚本暴力猜密码

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
                ▼  JSON 文件读写（独立 .lock 互斥 + 临时文件原子替换）
        data/stories.json   故事/评论/点赞/删除凭证
        data/feedback.json  网友反馈
        data/views_log.json 浏览量防刷记录
        data/*.lock         互斥锁（0 字节，运行时生成，删掉会自动重建）
```

- **零数据库**：无 MySQL/SQLite，数据即文件，备份 = 复制 `data/` 目录
- **数据一致性**：所有写盘走「临时文件 + `rename` 原子替换」，进程中断不会留下残缺 JSON；互斥用**独立的 `.lock` 文件**（`flock` 锁的是 inode，若直接锁数据文件，`rename` 之后锁就失效了）；PHP 侧的「读-改-写」全程持锁（`db_transaction()`），并发下不会丢更新
- **双后端对齐**：`server.js`（本地）与 `api.php`（生产）接口行为一致，同一份 `data/` 无缝迁移
- **安全设计**：UGC 内容服务端净化（script/事件属性/危险协议/CSS 表达式全清）；HTML 排版故事以 `CSP: sandbox` 沙箱 iframe 渲染，内部脚本无法触碰站点数据；管理接口密码校验 + 同 IP 失败限流锁定；**写接口按 IP 滑动窗口限流**（防脚本刷屏）；**接口不做跨域授权**（站点为同源部署，杜绝第三方站点跨域猜密码）；**不信任 `X-Forwarded-For`**（无反向代理时该头可由客户端伪造，信任它会让限流被一个请求头绕过）；🔒 **`/data/` 目录禁止 HTTP 访问**（内含管理密码与故事删除凭证，Apache 由 `.htaccess` + 镜像内 deny 规则双重拦截）；发布者删除凭证（随机 editKey，仅存发布者本机）

## 📂 目录结构

```
├── index.html          # 首页
├── publish.html        # 发布页
├── admin.html          # 站长管理页
├── feedback.html       # 反馈建议页
├── terms / privacy / disclaimer.html
├── api.php             # PHP 后端（生产）
├── server.js           # Node 开发服务器
├── docker-entrypoint.sh # 容器启动钩子（data 权限修复 + 管理密码自动生成）
├── .gitattributes      # 换行规则（* text=auto eol=lf 统一 LF；*.sh=LF、*.bat=CRLF 作例外）
├── 一键部署.bat         # Windows 一键部署脚本（仅本地保留，不进仓库）
├── data/               # 🔒 必须禁止 HTTP 访问（含密码与删除凭证）
│   ├── .htaccess       # Apache 拒绝 HTTP 访问本目录（nginx 需自行配置）
│   ├── seed.json       # 种子数据（首次运行初始化）
│   ├── stories.json    # 运行时生成：故事/评论/点赞
│   ├── feedback.json   # 运行时生成：反馈
│   ├── admin_key.txt   # 运行时生成：管理页密码（随机 12 位，已 gitignore）
│   ├── admin_guard.json# 运行时生成：管理登录失败限流记录
│   ├── rate_guard.json # 运行时生成：写接口（发布/评论/反馈）限流记录
│   ├── views_log.json  # 运行时生成：防刷记录
│   └── *.lock          # 运行时生成：互斥锁（0 字节，删掉会自动重建）
├── Dockerfile / docker-compose.yml
└── docs/部署说明.md    # 详细部署文档（docs/ 目录）
```

## 📖 API 概览

统一入口 `api.php?route=xxx`，完整参数见 [部署说明.md](docs/部署说明.md#-api-一览)：

| route | 说明 |
|-------|------|
| `stories` | 列表（筛选/分页）/ 发布（返回一次性删除凭证） |
| `story` / `raw` | 详情（防刷计数）/ 原文沙箱渲染 |
| `like` / `comments` | 点赞 / 评论与楼中楼回复（replyTo） |
| `delete_story` | 发布者凭凭证删除 |
| `tags` / `stats` / `hot` | 标签计数 / 统计 / 热门榜 |
| `feedback` | 提交反馈 |
| `admin_feedback` / `admin_stories` | 管理接口（密码为 `data/admin_key.txt` 中的值，连续错 5 次锁定 15 分钟）；`op=list` 支持 `page` / `pageSize`（默认 20、上限 100），返回 `total` / `page` / `pageSize` / `hasMore` |

**写接口限流**（按 IP 滑动窗口，超限返回 `429` + 友好提示，记录在 `data/rate_guard.json`）：

| 动作 | 上限 | 窗口 |
|------|------|------|
| 发布故事 | 5 篇 | 10 分钟 |
| 评论 / 回复 | 15 条 | 5 分钟 |
| 提交反馈 | 3 条 | 30 分钟 |

> 阈值刻意留得宽松，正常用户基本碰不到。**校验不通过的提交不消耗额度**（填错重试不会被罚）。
> 想调整就改 `api.php` 顶部的 `RATE_LIMITS` 与 `server.js` 的 `RATE_LIMITS`（两边要一致）。
> 清理过期记录时**按各自动作自己的窗口**判过期（三个动作窗口不同），不会互相误删。



## 🤝 贡献

欢迎 Issue 与 PR。发布内容请遵守站内[内容规范](terms.html)：真实分享、尊重他人、不传播隐私。

---

<div align="center">

**每一段缘分都值得被记录** · Made with ❤ · 每一个故事都值得被听见

</div>
