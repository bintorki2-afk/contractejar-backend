<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * نسخة احتياطية لقاعدة البيانات (ف21):
 *  - MySQL: mysqldump → gzip → storage/app/backups/aqdi-YYYY-MM-DD-HHMM.sql.gz
 *  - SQLite (محلي): نسخ الملف → gzip (نفس الاسم بامتداد .sqlite.gz) حتى يُختبر الأمر محلياً.
 *  - يُبقي آخر 7 نسخ محلياً، ويرفع النسخة إلى قرص S3 متوافق (Cloudflare R2) عند ضبط BACKUP_DISK=s3.
 * مجدول يومياً 03:10 بتوقيت الرياض (app/Console/Kernel.php).
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'aqdi:db-backup
        {--keep= : عدد النسخ المحلية المحتفظ بها (افتراضياً BACKUP_KEEP_LOCAL أو 7)}
        {--no-upload : عدم الرفع للقرص الخارجي حتى لو كان مضبوطاً}';

    protected $description = 'نسخة احتياطية مضغوطة لقاعدة البيانات (mysqldump أو نسخ ملف sqlite) مع الاحتفاظ بآخر 7 نسخ ورفع اختياري إلى S3/R2';

    public const LOCAL_DIR = 'backups';

    /** مجلد النسخ المحلية (قابل للتغيير عبر config backup.local_path — مفيد للاختبارات). */
    public static function localDir(): string
    {
        $configured = (string) config('backup.local_path', '');

        return $configured !== '' ? $configured : storage_path('app/'.self::LOCAL_DIR);
    }

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}", []);
        $driver = (string) ($config['driver'] ?? '');

        $dir = self::localDir();
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("تعذّر إنشاء مجلد النسخ: {$dir}");

            return self::FAILURE;
        }

        $stamp = now()->format('Y-m-d-Hi');

        try {
            $file = match ($driver) {
                'mysql', 'mariadb' => $this->dumpMysql($config, $dir, $stamp),
                'sqlite' => $this->copySqlite($config, $dir, $stamp),
                default => throw new \RuntimeException("نوع قاعدة البيانات غير مدعوم للنسخ الاحتياطي: {$driver}"),
            };
        } catch (\Throwable $e) {
            $this->error('فشل النسخ الاحتياطي: '.$e->getMessage());
            Log::error('aqdi:db-backup failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $size = @filesize($file) ?: 0;
        $this->info(sprintf('تم إنشاء النسخة: %s (%s)', basename($file), $this->humanSize($size)));

        $keep = $this->option('keep') !== null && $this->option('keep') !== ''
            ? (int) $this->option('keep')
            : (int) config('backup.keep_local', 7);
        $this->prune($dir, max(1, $keep));

        if (! $this->option('no-upload')) {
            $this->upload($file);
        }

        Log::info('aqdi:db-backup finished', ['file' => basename($file), 'bytes' => $size]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dumpMysql(array $config, string $dir, string $stamp): string
    {
        $binary = (string) (config('backup.mysqldump_path') ?: 'mysqldump');
        $file = $dir.'/aqdi-'.$stamp.'.sql.gz';

        $command = [
            $binary,
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 3306),
            '--user='.(string) ($config['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            (string) ($config['database'] ?? ''),
        ];

        // كلمة المرور عبر متغير البيئة (لا تظهر في قائمة العمليات ولا في اللوق).
        $env = ['MYSQL_PWD' => (string) ($config['password'] ?? '')];

        // بث مباشر إلى gzip (بدون تحميل النسخة كاملة في الذاكرة)، مع pipefail حتى يُكتشف فشل mysqldump.
        $pipeline = implode(' ', array_map('escapeshellarg', $command)).' | gzip -6 > '.escapeshellarg($file);
        $dump = new Process(['bash', '-o', 'pipefail', '-c', $pipeline], base_path(), $env, null, 1800);
        $dump->run();

        if (! $dump->isSuccessful()) {
            @unlink($file);
            $error = trim($dump->getErrorOutput());
            throw new \RuntimeException('mysqldump: '.($error !== '' ? mb_substr($error, 0, 300) : 'exit '.$dump->getExitCode()));
        }

        return $file;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function copySqlite(array $config, string $dir, string $stamp): string
    {
        $source = (string) ($config['database'] ?? '');
        if ($source === '' || $source === ':memory:' || ! is_file($source)) {
            throw new \RuntimeException('ملف sqlite غير موجود: '.($source ?: '(فارغ)'));
        }

        $file = $dir.'/aqdi-'.$stamp.'.sqlite.gz';
        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new \RuntimeException('تعذّر قراءة ملف sqlite');
        }

        $this->gzipToFile($contents, $file);

        return $file;
    }

    private function gzipToFile(string $contents, string $file): void
    {
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('تعذّر إنشاء الملف المضغوط');
        }
        gzwrite($gz, $contents);
        gzclose($gz);
    }

    private function prune(string $dir, int $keep): void
    {
        $files = glob($dir.'/aqdi-*.gz') ?: [];
        rsort($files, SORT_STRING); // الاسم يحمل التاريخ → الأحدث أولاً

        foreach (array_slice($files, $keep) as $old) {
            if (@unlink($old)) {
                $this->line('حُذفت النسخة القديمة: '.basename($old));
            }
        }
    }

    private function upload(string $file): void
    {
        $disk = (string) config('backup.disk', '');
        if ($disk === '' || $disk === 'local') {
            $this->line('الرفع الخارجي غير مضبوط (BACKUP_DISK) — النسخة محلية فقط.');

            return;
        }

        $diskName = $disk === 's3' ? 'backups' : $disk;
        $bucket = (string) config("filesystems.disks.{$diskName}.bucket", '');
        $key = (string) config("filesystems.disks.{$diskName}.key", '');
        if ($diskName === 'backups' && ($bucket === '' || $key === '')) {
            $this->warn('BACKUP_DISK=s3 لكن BACKUP_S3_BUCKET/KEY غير مضبوطة — تخطّينا الرفع.');

            return;
        }

        try {
            $stream = fopen($file, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('تعذّر فتح الملف للرفع');
            }
            $ok = Storage::disk($diskName)->put(basename($file), $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! $ok) {
                throw new \RuntimeException('القرص رفض الكتابة');
            }
            $this->info("رُفعت النسخة إلى القرص {$diskName}: ".basename($file));
        } catch (\Throwable $e) {
            $this->warn('فشل الرفع الخارجي: '.$e->getMessage());
            Log::warning('aqdi:db-backup upload failed', ['disk' => $diskName, 'error' => $e->getMessage()]);
        }
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
