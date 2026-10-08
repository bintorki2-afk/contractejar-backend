<?php

/*
|--------------------------------------------------------------------------
| النسخ الاحتياطي لقاعدة البيانات (ف21) — أمر `php artisan aqdi:db-backup`
|--------------------------------------------------------------------------
|
| BACKUP_DISK=s3 يرفع النسخة إلى قرص S3 متوافق (Cloudflare R2) مضبوط عبر
| BACKUP_S3_BUCKET / BACKUP_S3_KEY / BACKUP_S3_SECRET / BACKUP_S3_ENDPOINT / BACKUP_S3_REGION
| (انظر filesystems.disks.backups). بدون ذلك تبقى النسخ محلية (آخر 7) في storage/app/backups.
|
*/
return [
    'disk' => env('BACKUP_DISK', 'local'),

    'keep_local' => (int) env('BACKUP_KEEP_LOCAL', 7),

    // مجلد النسخ المحلية (افتراضياً storage/app/backups)
    'local_path' => env('BACKUP_LOCAL_PATH'),

    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),

    'mysql_path' => env('BACKUP_MYSQL_PATH', 'mysql'),
];
