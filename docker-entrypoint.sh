#!/bin/sh
# 容器启动前修正数据目录属主：容器内 Apache worker 以 www-data 运行，
# 宿主机挂载的 ./data 若属主不符会导致写浏览量/评论/点赞失败（PHP Warning 混入 JSON 响应）。
chown -R www-data:www-data /var/www/html/data 2>/dev/null || true
chmod -R u+rwX /var/www/html/data 2>/dev/null || true
# 首次启动自动生成随机管理密码（admin.html 登录用），docker logs 可查看
if [ ! -s /var/www/html/data/admin_key.txt ]; then
  head -c 9 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 12 > /var/www/html/data/admin_key.txt 2>/dev/null || true
  echo "[entrypoint] generated random admin key: $(cat /var/www/html/data/admin_key.txt 2>/dev/null)" >&2
fi
exec apache2-foreground "$@"
