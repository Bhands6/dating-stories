# 人生故事屋 - Docker 部署（Node.js，零依赖）
# 构建：docker compose up -d --build
# 访问：http://服务器IP:8010
#
# 2026-11-06 起后端统一为 Node（server.js），api.php 退役（git 历史里可找回）。
# server.js 零 npm 依赖，无需 npm install；前端所有页面仍请求 /api.php?route=xxx，
# 由 server.js 原样接住，页面代码零改动。
# data/ 运行时数据由 compose 卷挂载，不入镜像。

FROM node:22-alpine

WORKDIR /app
ENV NODE_ENV=production PORT=80

# 静态页面与公共资源（server.js 的 serveStatic 托管）。
# admin.html 自 v1.12 起是自包含设计，但仍随页面一起 COPY。
COPY index.html publish.html admin.html feedback.html terms.html privacy.html disclaimer.html ./
COPY theme.css theme.js logo.png ./
# 根 .htaccess 仅宝塔等 Apache 部署方式使用，Node 容器不读它，放进来无妨
COPY .htaccess ./

# 后端 + 频道迁移工具（线上执行：docker exec yuanfen-stories node tools/migrate_channels.js ...）
COPY server.js package.json ./
COPY tools/migrate_channels.js ./tools/migrate_channels.js

# 种子数据兜底：宿主机 ./data 为空时首次运行可自动初始化
COPY data/seed.json ./data/seed.json

# data 目录挂载点（数据持久化）。
# 🔒 禁止通过 HTTP 访问 data/ 由 server.js 内部实现（serveStatic 对 DATA_DIR 一律 403），
# 与旧 Apache 的 deny-data 规则等价；管理密码、全站 JSON、上传图片都只走应用层读取。
RUN mkdir -p /app/data
VOLUME ["/app/data"]

# HTML/CSS/JS 的 no-cache 响应头由 server.js 设置，无需额外配置。
# /uploads/{id}.html 直出（CSP sandbox）也由 server.js 内置实现，
# 旧 Apache 的 uploads-alias.conf 不再需要。

EXPOSE 80

CMD ["node", "server.js"]
