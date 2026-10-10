#!/usr/bin/env bash
# KVM4 — 3/7: قاعدة aqdi + مستخدم بكلمة سر عشوائية (تنحفظ في ملف للجذر فقط، ولا تُطبع).
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "شغّله بـ sudo"; exit 1; }
DB_NAME="${DB_NAME:-aqdi}"
DB_USER="${DB_USER:-aqdi}"
CRED=/root/.aqdi-db-credentials

if [ -f "$CRED" ]; then
  echo "ملف البيانات موجود ($CRED) — ما أغيّر كلمة السر. أتأكد بس من وجود القاعدة."
  # shellcheck disable=SC1090
  . "$CRED"
  DB_PASS="$DB_PASSWORD"
else
  DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-28)"
  umask 077
  printf 'DB_DATABASE=%s\nDB_USERNAME=%s\nDB_PASSWORD=%s\n' "$DB_NAME" "$DB_USER" "$DB_PASS" > "$CRED"
fi

mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "تم. القاعدة: ${DB_NAME} — المستخدم: ${DB_USER}"
echo "كلمة السر محفوظة في: ${CRED} (للجذر فقط). انسخها لـ .env يدوياً ولا ترسلها في أي محادثة."
echo "المرحلة التالية: 04-app.sh"
