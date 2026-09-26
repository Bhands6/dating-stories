# 缘分故事屋 - Docker 部署（PHP + Apache）
# 构建：docker compose up -d --build
# 访问：http://服务器IP:8010
FROM php:8.2-apache

# 使用生产级 PHP 配置（display_errors=Off）：
# 避免任何运行时 Warning 混入 JSON 响应开头导致前端解析失败（报错形如 Unexpected token '<'）
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# 复制站点文件（全部页面 + 后端；data 运行时数据由 compose 卷挂载，不入镜像）
COPY index.html publish.html admin.html feedback.html terms.html privacy.html disclaimer.html /var/www/html/
COPY api.php server.js package.json /var/www/html/

# 种子数据兜底：宿主机 ./data 为空时首次运行可自动初始化
COPY data/seed.json /var/www/html/data/seed.json

# data 目录挂载点（数据持久化）
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data
VOLUME ["/var/www/html/data"]

# 启动前自动修正 data 目录属主（宿主机挂载目录属主可能不是 www-data）
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]

ENV APACHE_DOCUMENT_ROOT=/var/www/html
EXPOSE 80
