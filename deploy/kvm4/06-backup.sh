#!/usr/bin/env bash
# KVM4 — 6/7: نسخ احتياطي يومي 03:10 (الرياض): القاعدة + ملفات الرفع. يحتفظ بآخر 7.
# ملاحظة: مجدول Laravel يعمل أيضاً aqdi:db-backup. هذا طبقة ثانية على مستوى السيرفر (تشمل الملفات).
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "شغّله بـ sudo"; exit 1; }
APP_DIR="${APP_DIR:-/var/www/aqdi-backend}"
install -d -m 700 /var/backups/aqdi

cat > /usr/local/bin/aqdi-backup <<BK
#!/usr/bin/env bash
set -euo pipefail
. /root/.aqdi-db-credentials
D=/var/backups/aqdi; T=\$(date +%F_%H%M)
mysqldump --single-transaction --routines --triggers "\$DB_DATABASE" | gzip > "\$D/db_\$T.sql.gz"
tar -czf "\$D/files_\$T.tar.gz" -C "${APP_DIR}/storage/app" public
ls -1t "\$D"/db_*.sql.gz    2>/dev/null | tail -n +8 | xargs -r rm -f
ls -1t "\$D"/files_*.tar.gz 2>/dev/null | tail -n +8 | xargs -r rm -f
echo "backup ok \$T"
BK
chmod 700 /usr/local/bin/aqdi-backup
echo '10 3 * * * root /usr/local/bin/aqdi-backup >> /var/log/aqdi-backup.log 2>&1' > /etc/cron.d/aqdi-backup
chmod 644 /etc/cron.d/aqdi-backup
echo "تم. جرّب يدوياً:  sudo aqdi-backup  — والنسخ في /var/backups/aqdi"
echo "تنبيه: النسخ على نفس السيرفر؛ لازم نسخة خارجية (R2/Hostinger snapshot) قبل الإطلاق."
echo "المرحلة التالية (بعد ما الدومين يشاور على السيرفر فقط): 07-ssl.sh"
