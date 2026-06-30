#!/usr/bin/env bash
set -euo pipefail
REPO="https://raw.githubusercontent.com/kirillamolkin2016-afk/progectx/main"
WEBROOT="/var/www/tools"
DOMAIN="tools.hide-x.ru"

echo ">>> Установка nginx и PHP..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nginx php-fpm curl

echo ">>> Скачивание файлов портала с GitHub..."
mkdir -p "$WEBROOT"
curl -fsSL "$REPO/index.html"        -o "$WEBROOT/index.html"
curl -fsSL "$REPO/doska-brigad.html" -o "$WEBROOT/doska-brigad.html"
curl -fsSL "$REPO/api.php"           -o "$WEBROOT/api.php"

echo ">>> Права доступа..."
chown -R www-data:www-data "$WEBROOT"
find "$WEBROOT" -type d -exec chmod 755 {} \;
find "$WEBROOT" -type f -exec chmod 644 {} \;

SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1)"
[ -z "$SOCK" ] && { echo "php-fpm сокет не найден"; exit 1; }
SVC="$(basename "$SOCK" .sock)"
echo ">>> php-fpm: $SOCK"

echo ">>> Конфиг nginx..."
cat > /etc/nginx/sites-available/tools.conf <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name $DOMAIN _;
    root $WEBROOT;
    index index.html;
    location = /data.json { deny all; return 404; }
    location ~ /\. { deny all; return 404; }
    location / { try_files \$uri \$uri/ =404; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$SOCK;
        client_max_body_size 2m;
    }
}
NGINX

rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/tools.conf /etc/nginx/sites-enabled/tools.conf
systemctl enable --now "$SVC" >/dev/null 2>&1 || true
nginx -t
systemctl enable nginx >/dev/null 2>&1 || true
systemctl restart nginx

echo ""
echo "==================================================="
echo " Готово! Откройте в браузере:  http://5.129.226.171"
echo " Должен открыться портал Инструменты Hide-X."
echo "==================================================="
