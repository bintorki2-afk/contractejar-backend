<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * استعادة نسخة احتياطية (ف21) إلى قاعدة البيانات المضبوطة حالياً.
 *
 *  - يرفض العمل في الإنتاج إلا مع --force (ويطلب تأكيداً كتابياً).
 *  - MySQL: ملف .sql.gz → gunzip | mysql
 *  - SQLite: ملف .sqlite.gz → يستبدل ملف القاعدة (مع نسخة أمان قبل الاستبدال).
 * تمرين الاستعادة موثّق في OWNER-GUIDE.md.
 */
class DatabaseRestoreCommand extends Command
{
    protected $signature = 'aqdi:db-restore
        {file : مسار ملف النسخة (.sql.gz أو .sqlite.gz) — مطلق أو نسبة إلى storage/app/backups}
        {--force : السماح بالاستعادة في الإنتاج (خطر: يستبدل البيانات الحالية)}
        {--yes : تخطّي سؤال التأكيد (للسكربتات)}';

    protected $description = 'استعادة نسخة احتياطية إلى قاعدة البيانات الحالية (مسموح خارج الإنتاج، أو مع --force)';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->argument('file'));
        if ($path === null) {
            $this->error('ملف النسخة غير موجود.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('الاستعادة في الإنتاج ممنوعة بدون --force.');

            return self::FAILURE;
        }

        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}", []);
        $driver = (string) ($config['driver'] ?? '');
        $target = (string) ($config['database'] ?? '');

        $this->warn("سيتم استبدال محتوى قاعدة البيانات [{$connection}] ({$driver}: ".basename($target).') بمحتوى '.basename($path));
        if (! $this->option('yes') && ! $this->confirm('هل أنت متأكد؟', false)) {
            $this->line('أُلغيت العملية.');

            return self::SUCCESS;
        }

        if (app()->environment('production')) {
            $typed = (string) $this->ask('اكتب كلمة RESTORE للتأكيد النهائي (إنتاج)');
            if ($typed !== 'RESTORE') {
                $this->line('أُلغيت العملية.');

                return self::SUCCESS;
            }
        }

        try {
            match ($driver) {
                'mysql', 'mariadb' => $this->restoreMysql($config, $path),
                'sqlite' => $this->restoreSqlite($target, $path),
                default => throw new \RuntimeException("نوع قاعدة البيانات غير مدعوم: {$driver}"),
            };
        } catch (\Throwable $e) {
            $this->error('فشلت الاستعادة: '.$e->getMessage());
            Log::error('aqdi:db-restore failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info('تمت الاستعادة بنجاح من '.basename($path));
        Log::warning('aqdi:db-restore executed', ['file' => basename($path), 'env' => app()->environment()]);

        return self::SUCCESS;
    }

    private function resolvePath(string $file): ?string
    {
        $candidates = [
            $file,
            DatabaseBackupCommand::localDir().'/'.$file,
            base_path($file),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function restoreMysql(array $config, string $path): void
    {
        if (! str_ends_with($path, '.sql.gz') && ! str_ends_with($path, '.sql')) {
            throw new \RuntimeException('نسخة MySQL يجب أن تكون .sql.gz أو .sql');
        }

        $mysql = (string) (config('backup.mysql_path') ?: 'mysql');
        $args = implode(' ', array_map('escapeshellarg', [
            $mysql,
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 3306),
            '--user='.(string) ($config['username'] ?? ''),
            '--default-character-set=utf8mb4',
            (string) ($config['database'] ?? ''),
        ]));

        $reader = str_ends_with($path, '.gz') ? 'gunzip -c '.escapeshellarg($path) : 'cat '.escapeshellarg($path);
        $pipeline = $reader.' | '.$args;

        $process = new Process(['bash', '-o', 'pipefail', '-c', $pipeline], base_path(), [
            'MYSQL_PWD' => (string) ($config['password'] ?? ''),
        ], null, 3600);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            throw new \RuntimeException('mysql: '.($error !== '' ? mb_substr($error, 0, 300) : 'exit '.$process->getExitCode()));
        }
    }

    private function restoreSqlite(string $target, string $path): void
    {
        if ($target === '' || $target === ':memory:') {
            throw new \RuntimeException('قاعدة sqlite الحالية ليست ملفاً.');
        }

        $contents = str_ends_with($path, '.gz')
            ? $this->gunzip($path)
            : (string) file_get_contents($path);

        if ($contents === '' || ! str_starts_with($contents, 'SQLite format 3')) {
            throw new \RuntimeException('الملف ليس قاعدة sqlite صالحة.');
        }

        DB::purge();

        if (is_file($target)) {
            $safety = $target.'.before-restore-'.now()->format('Ymd-His');
            if (! copy($target, $safety)) {
                throw new \RuntimeException('تعذّر أخذ نسخة أمان من القاعدة الحالية');
            }
            $this->line('نسخة أمان من القاعدة الحالية: '.basename($safety));
        }

        if (file_put_contents($target, $contents) === false) {
            throw new \RuntimeException('تعذّر كتابة ملف القاعدة');
        }
    }

    private function gunzip(string $path): string
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('تعذّر فتح الملف المضغوط');
        }

        $out = '';
        while (! gzeof($gz)) {
            $chunk = gzread($gz, 1048576);
            if ($chunk === false) {
                break;
            }
            $out .= $chunk;
        }
        gzclose($gz);

        return $out;
    }
}
