#!/usr/bin/env bash
# KVM4 — 5/7: موقع nginx للخلفية. بدون SSL (يضاف في 07). متغيرات: APP_DIR  SERVER_NAME
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "شغّله بـ sudo"; exit 1; }
APP_DIR="${APP_DIR:-/var/www/aqdi-backend}"
SERVER_NAME="${SERVER_NAME:-_}"   # أثناء التجربة: _ (يرد على IP). عند التحويل: aqdi.sa www.aqdi.sa
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"

# بعد 07-ssl.sh يضيف certbot بلوك 443 لهذا الملف — إعادة الكتابة تمسحه وتطيّح HTTPS
if grep -q "managed by Certbot" /etc/nginx/sites-available/aqdi 2>/dev/null; then
  echo "الموقع فيه إعدادات SSL من certbot — ما أعيد كتابته."
  echo "التحويلات تتعدّل من /etc/nginx/aqdi/redirects.conf ثم: sudo nginx -t && sudo systemctl reload nginx"
  exit 1
fi

install -d /etc/nginx/aqdi
[ -f /etc/nginx/aqdi/redirects.conf ] || cp "$SRC_DIR/nginx/aqdi-redirects.conf.example" /etc/nginx/aqdi/redirects.conf

cat > /etc/nginx/sites-available/aqdi <<NGX
server {
    listen 80;
    server_name ${SERVER_NAME};
    root ${APP_DIR}/public;
    index index.php;
    charset utf-8;
    client_max_body_size 25M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    # تحويلات 301 (الروابط القديمة) — تُملأ بعد قرار الهيكل واعتماد الخريطة
    include /etc/nginx/aqdi/redirects.conf;

    location /storage/ {
        alias ${APP_DIR}/storage/app/public/;
        try_files \$uri =404;
        location ~ \.php\$ { deny all; }
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120s;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGX
ln -sf /etc/nginx/sites-available/aqdi /etc/nginx/sites-enabled/aqdi
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
echo "تم. جرّب:  curl -I http://IP_السيرفر/api/v2/health"
echo "المرحلة التالية: 06-backup.sh"
