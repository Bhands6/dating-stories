# 缘分故事屋 - Docker 部署（PHP + Apache）
# 构建：docker compose up -d --build
# 访问：http://服务器IP:8010
FROM php:8.2-apache

# 使用生产级 PHP 配置（display_errors=Off）：
# 避免任何运行时 Warning 混入 JSON 响应开头导致前端解析失败（报错形如 Unexpected token '<'）
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# 复制站点文件（全部页面 + 生产后端 api.php；data 运行时数据由 compose 卷挂载，不入镜像）
# 注意：server.js / package.json 只是本地开发用的 Node 服务器，不进生产镜像
COPY index.html publish.html admin.html feedback.html terms.html privacy.html disclaimer.html /var/www/html/
COPY api.php /var/www/html/
COPY .htaccess /var/www/html/.htaccess

# 种子数据兜底：宿主机 ./data 为空时首次运行可自动初始化
COPY data/seed.json /var/www/html/data/seed.json

# data 目录挂载点（数据持久化）
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data
VOLUME ["/var/www/html/data"]

# 🔒 禁止通过 HTTP 访问 data/ 目录
# 里面是管理密码（admin_key.txt）、全站数据库（stories.json，含每篇故事的删除凭证 editKey）
# 和网友反馈（feedback.json）。应用只通过 PHP 的文件系统读取，HTTP 层必须挡住——
# 否则 Apache 会把这些文件当普通静态资源直接吐给公网（曾真实发生：/data/admin_key.txt 可下载）。
RUN printf '<Directory /var/www/html/data>\n    Require all denied\n</Directory>\n' > /etc/apache2/conf-available/deny-data.conf \
 && a2enconf deny-data

# HTML 页面禁止强缓存：发布页内联了大量前端逻辑，浏览器缓存旧版会导致
# 「代码已更新但行为还是旧的」这类极难察觉的问题（曾导致导入 HTML 时丢 <header>）。
# ⚠️ 容器里 Apache 是 AllowOverride None，根目录 .htaccess 不生效，必须写进 conf-enabled。
# 根 .htaccess 仍保留，供宝塔等允许 AllowOverride 的部署方式使用。
RUN a2enmod headers \
 && printf '<FilesMatch "\\.(html|htm)$">\n    Header set Cache-Control "no-cache, must-revalidate"\n</FilesMatch>\n' > /etc/apache2/conf-available/no-cache.conf \
 && a2enconf no-cache

# 启动前自动修正 data 目录属主（宿主机挂载目录属主可能不是 www-data）
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]

ENV APACHE_DOCUMENT_ROOT=/var/www/html
EXPOSE 80
