#!/usr/bin/env bash
# KVM4 — 4/7: سحب الخلفية وتجهيزها. يحتاج deploy key للقراءة في ~deploy/.ssh/id_ed25519
# متغيرات اختيارية:  REPO  BRANCH  APP_DIR
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "شغّله بـ sudo"; exit 1; }
REPO="${REPO:-git@github.com:bintorki2-afk/contractejar-backend.git}"
BRANCH="${BRANCH:-master}"
APP_DIR="${APP_DIR:-/var/www/aqdi-backend}"

mkdir -p "$(dirname "$APP_DIR")"
if [ ! -d "$APP_DIR/.git" ]; then
  sudo -u deploy git clone --branch "$BRANCH" "$REPO" "$APP_DIR"
else
  sudo -u deploy git -C "$APP_DIR" fetch origin && sudo -u deploy git -C "$APP_DIR" checkout "$BRANCH" && sudo -u deploy git -C "$APP_DIR" pull --ff-only
fi
chown -R deploy:www-data "$APP_DIR"

cd "$APP_DIR"
sudo -u deploy composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
  sudo -u deploy cp .env.example .env
  echo
  echo ">>> أنشأت .env من القالب. عدّله يدوياً قبل المتابعة (APP_ENV=production, APP_URL, DB_*, Moyasar, Firebase, ...):"
  echo "    sudo -u deploy nano $APP_DIR/.env"
  echo ">>> بيانات القاعدة في /root/.aqdi-db-credentials"
  echo ">>> بعد التعديل أعد تشغيل هذا السكربت."
  exit 0
fi

grep -q '^APP_KEY=base64:' .env || sudo -u deploy php artisan key:generate --force

# لا نخدم بمخطط ناقص في الإنتاج: لو فشل الترحيل نوقف
sudo -u deploy php artisan migrate --force
sudo -u deploy php artisan storage:link || true
sudo -u deploy php artisan config:cache
sudo -u deploy php artisan route:cache
sudo -u deploy php artisan view:cache

chgrp -R www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

cat > /etc/systemd/system/aqdi-scheduler.service <<UNIT
[Unit]
Description=Aqdi Laravel scheduler (schedule:work)
After=mysql.service
[Service]
User=deploy
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php artisan schedule:work
Restart=always
RestartSec=10
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now aqdi-scheduler

echo "تم. المرحلة التالية: 05-nginx.sh"
