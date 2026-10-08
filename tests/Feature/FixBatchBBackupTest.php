<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * دفعة الإصلاحات (ب) — ف21: النسخ الاحتياطي والاستعادة (sqlite محلياً) في مجلد مؤقت معزول.
 */
class FixBatchBBackupTest extends TestCase
{
    private string $dbFile;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/aqdi-backup-test-'.uniqid();
        File::ensureDirectoryExists($base);
        $this->dbFile = $base.'/demo.sqlite';
        $this->backupDir = $base.'/backups';
        touch($this->dbFile);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->dbFile,
            'backup.disk' => 'local',
            'backup.local_path' => $this->backupDir,
            'backup.keep_local' => 7,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        DB::statement('create table notes (id integer primary key, body text)');
        DB::table('notes')->insert(['body' => 'قبل النسخ']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory(dirname($this->dbFile));

        parent::tearDown();
    }

    public function test_backup_then_restore_round_trip_on_sqlite(): void
    {
        $this->artisan('aqdi:db-backup', ['--no-upload' => true])->assertExitCode(0);

        $created = glob($this->backupDir.'/aqdi-*.sqlite.gz') ?: [];
        $this->assertCount(1, $created);
        $backup = $created[0];
        $this->assertGreaterThan(0, filesize($backup));

        // تغيير بعد النسخة ثم استعادة → يعود المحتوى القديم.
        DB::table('notes')->update(['body' => 'بعد النسخ']);
        $this->assertSame('بعد النسخ', DB::table('notes')->value('body'));

        // الاستعادة بالاسم النسبي داخل مجلد النسخ.
        $this->artisan('aqdi:db-restore', ['file' => basename($backup), '--yes' => true])->assertExitCode(0);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->assertSame('قبل النسخ', DB::table('notes')->value('body'));
        $this->assertNotEmpty(glob($this->dbFile.'.before-restore-*'));
    }

    public function test_restore_is_refused_in_production_without_force_and_for_missing_files(): void
    {
        $this->artisan('aqdi:db-restore', ['file' => 'does-not-exist.sqlite.gz', '--yes' => true])->assertExitCode(1);

        $fake = $this->backupDir.'/aqdi-fake.sqlite.gz';
        File::ensureDirectoryExists($this->backupDir);
        File::put($fake, 'x');

        app()->detectEnvironment(fn () => 'production');
        $this->artisan('aqdi:db-restore', ['file' => $fake, '--yes' => true])->assertExitCode(1);
        $this->assertSame('قبل النسخ', DB::table('notes')->value('body'));
    }

    public function test_prune_keeps_only_the_newest_backups(): void
    {
        File::ensureDirectoryExists($this->backupDir);
        for ($i = 1; $i <= 9; $i++) {
            File::put($this->backupDir.'/aqdi-2020-01-0'.$i.'-0000.sqlite.gz', 'old');
        }

        $this->artisan('aqdi:db-backup', ['--no-upload' => true, '--keep' => 3])->assertExitCode(0);

        $remaining = glob($this->backupDir.'/aqdi-*.gz') ?: [];
        sort($remaining);
        $this->assertCount(3, $remaining);
        // الأحدث (نسخة اليوم) + أحدث نسختين قديمتين.
        $this->assertStringContainsString('aqdi-2020-01-08', $remaining[0]);
        $this->assertStringContainsString('aqdi-2020-01-09', $remaining[1]);
        $this->assertStringContainsString('aqdi-'.now()->format('Y-m-d'), $remaining[2]);
    }
}
