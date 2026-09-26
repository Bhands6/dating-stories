#!/bin/sh
# 容器启动前修正数据目录属主：容器内 Apache worker 以 www-data 运行，
# 宿主机挂载的 ./data 若属主不符会导致写浏览量/评论/点赞失败（PHP Warning 混入 JSON 响应）。
chown -R www-data:www-data /var/www/html/data 2>/dev/null || true
chmod -R u+rwX /var/www/html/data 2>/dev/null || true
exec apache2-foreground "$@"
