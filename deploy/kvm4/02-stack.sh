#!/usr/bin/env bash
# KVM4 — 2/7: nginx + PHP 8.3 + MySQL 8 + Composer + Node 20. آمن لإعادة التشغيل.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "شغّله بـ sudo"; exit 1; }
export DEBIAN_FRONTEND=noninteractive

echo "[2/7] الحزم"
apt-get update -y
apt-get install -y nginx mysql-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-gd php8.3-bcmath php8.3-intl php8.3-opcache php8.3-readline \
  certbot python3-certbot-nginx

echo "[2/7] إعدادات PHP (نفس حدود Railway: رفع 25MB)"
cat > /etc/php/8.3/fpm/conf.d/99-aqdi.ini <<'INI'
upload_max_filesize=25M
post_max_size=40M
memory_limit=256M
max_execution_time=120
max_input_time=120
opcache.enable=1
opcache.validate_timestamps=1
date.timezone=Asia/Riyadh
INI
cp /etc/php/8.3/fpm/conf.d/99-aqdi.ini /etc/php/8.3/cli/conf.d/99-aqdi.ini
systemctl restart php8.3-fpm

echo "[2/7] Composer"
if ! command -v composer >/dev/null; then
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL="$(php -r "echo hash_file('sha384','/tmp/composer-setup.php');")"
  [ "$EXPECTED" = "$ACTUAL" ] || { echo "فشل التحقق من مثبّت Composer"; rm -f /tmp/composer-setup.php; exit 1; }
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

echo "[2/7] Node 20 (للواجهات إذا استضفناها هنا)"
if ! command -v node >/dev/null || ! node -v | grep -q '^v20'; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi

echo "[2/7] MySQL: ربط محلي فقط"
cat > /etc/mysql/mysql.conf.d/99-aqdi.cnf <<'CNF'
[mysqld]
bind-address = 127.0.0.1
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
default-time-zone = '+03:00'
CNF
systemctl restart mysql
systemctl enable nginx php8.3-fpm mysql

echo "تم. النسخ المثبتة:"
nginx -v 2>&1; php -v | head -1; mysql --version; composer --version | head -1; node -v
echo "المرحلة التالية: 03-database.sh"
