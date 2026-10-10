#!/usr/bin/env bash
# KVM4 — 1/7: أساسيات السيرفر (Ubuntu 24.04). آمن لإعادة التشغيل.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "شغّله بـ sudo"; exit 1; }

echo "[1/7] تحديث النظام"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y && apt-get upgrade -y
apt-get install -y ufw fail2ban unattended-upgrades curl git unzip ca-certificates gnupg rsync cron

echo "[1/7] توقيت الرياض"
timedatectl set-timezone Asia/Riyadh

echo "[1/7] مستخدم deploy (بدون كلمة سر، دخول بمفتاح SSH)"
if ! id deploy >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" deploy
  usermod -aG sudo deploy
  echo "deploy ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/90-deploy
  chmod 440 /etc/sudoers.d/90-deploy
fi
mkdir -p /home/deploy/.ssh && chmod 700 /home/deploy/.ssh
if [ -f /root/.ssh/authorized_keys ] && [ ! -s /home/deploy/.ssh/authorized_keys ]; then
  cp /root/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys
fi
touch /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh

echo "[1/7] السواب 2GB"
if ! swapon --show | grep -q /swapfile; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "[1/7] الجدار الناري (SSH + HTTP + HTTPS فقط)"
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo "[1/7] fail2ban + تحديثات الأمان التلقائية"
systemctl enable --now fail2ban
dpkg-reconfigure -f noninteractive unattended-upgrades

cat <<'MSG'

تم. قبل ما تكمل:
  1) افتح جلسة SSH ثانية باسم deploy وتأكد إنها تدخل.
  2) لا تقفل تسجيل الدخول بكلمة السر/الجذر إلا بعد التأكد (خطوة يدوية).
المرحلة التالية: 02-stack.sh
MSG
