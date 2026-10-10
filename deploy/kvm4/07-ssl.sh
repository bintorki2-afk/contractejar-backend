#!/usr/bin/env bash
# KVM4 — 7/7: شهادة Let's Encrypt. ⚠ لا تشغّله إلا بعد ما DNS يشاور على السيرفر.
# الاستخدام:  sudo bash 07-ssl.sh aqdi.sa www.aqdi.sa
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "شغّله بـ sudo"; exit 1; }
[ "$#" -ge 1 ] || { echo "اكتب الدومينات: sudo bash 07-ssl.sh aqdi.sa www.aqdi.sa"; exit 1; }
ARGS=(); for d in "$@"; do ARGS+=(-d "$d"); done
certbot --nginx "${ARGS[@]}" --redirect --agree-tos -m "${CERT_EMAIL:?حدد CERT_EMAIL=بريدك}" --non-interactive
systemctl list-timers | grep -i certbot || true
nginx -t && systemctl reload nginx
echo "تم. التجديد التلقائي شغال عبر certbot.timer."
