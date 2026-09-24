# 缘分故事屋 - Docker 部署（PHP + Apache）
# 构建：docker compose up -d --build
# 访问：http://服务器IP:8010
FROM php:8.2-apache

# 复制站点文件
COPY index.html /var/www/html/
COPY api.php /var/www/html/

# data 目录挂载点（数据持久化）
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data
VOLUME ["/var/www/html/data"]

ENV APACHE_DOCUMENT_ROOT=/var/www/html
EXPOSE 80
