<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Services\Blog\BlogRedirectMapBuilder;
use App\Services\Blog\LegacyBlogImporter;
use App\Services\Blog\LegacyBlogSource;
use Illuminate\Console\Command;

/**
 * البند 6 — نقل مدونة عقدي القديمة بنفس الروابط حرفياً.
 * افتراضياً تجربة فقط (لا يكتب شي). الكتابة الفعلية تحتاج --commit.
 */
class ImportLegacyBlogsCommand extends Command
{
    protected $signature = 'blog:import-legacy
        {--file= : تصدير phpMyAdmin لجدول blogs (.json أو .csv)}
        {--connection= : اتصال قاعدة فيها جدول blogs القديم (مثلاً legacy)}
        {--table=blogs : اسم الجدول عند استخدام --connection}
        {--commit : اكتب فعلياً (بدونه: تجربة فقط ولا يتغير شي)}
        {--on-conflict=skip : skip أو update لو الـ slug موجود في صقر ١}
        {--accept-collisions : اسمح بالحفظ مع وجود روابط تختلف بالتشكيل فقط (تُتجاهل ويبقى الأول)}
        {--images-dir= : مسار storage/app/public للنسخة القديمة للتأكد من وجود الصور}
        {--baseline= : CSV خط الأساس من Search Console (افتراضي docs/seo-baseline)}
        {--target-base=https://aqdi.sa/blog/ : بداية رابط المقال الجديد في خريطة 301}
        {--out= : مجلد التقارير (افتراضي storage/app/blog-import/<الوقت>)}';

    protected $description = 'Import the legacy aqdi.sa blog (same slugs, byte-for-byte) and build the 301 map — dry-run unless --commit';

    public function handle(LegacyBlogSource $source, LegacyBlogImporter $importer): int
    {
        $file = $this->option('file');
        $connection = $this->option('connection');
        if (! $file === ! $connection) {
            $this->error('حدد مصدر واحد: --file=… أو --connection=…');

            return self::INVALID;
        }

        $rows = $file
            ? $source->fromFile((string) $file)
            : $source->fromConnection((string) $connection, (string) $this->option('table'));

        $commit = (bool) $this->option('commit');
        $this->line($commit ? '<fg=yellow>وضع الكتابة الفعلية (--commit)</>' : '<fg=green>تجربة فقط — لن يُكتب شي</>');
        $this->line('عدد الصفوف في المصدر: '.count($rows));

        $result = $importer->import($rows, [
            'commit' => $commit,
            'on_conflict' => (string) $this->option('on-conflict'),
            'images_dir' => $this->option('images-dir') ?: null,
            'block_on_collision' => ! $this->option('accept-collisions'),
        ]);

        $out = $this->option('out') ?: storage_path('app/blog-import/'.now()->format('Ymd_His').($commit ? '_commit' : '_dryrun'));
        if (! is_dir($out)) {
            mkdir($out, 0775, true);
        }

        $this->writeCsv($out.'/import-report.csv', $result['rows'], [
            'row', 'legacy_id', 'slug', 'title', 'is_active', 'status', 'action', 'reason', 'warnings', 'blog_id', 'verified',
        ]);

        $summary = $result['summary'];
        $this->table(['البند', 'العدد'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());

        // خريطة الروابط مقابل خط الأساس
        $baseline = $this->option('baseline') ?: base_path('docs/seo-baseline/aqdi-sa-gsc-pages-2025-06-15_to_2026-10-08.csv');
        $critical = 0;
        if (is_file($baseline)) {
            $live = Blog::query()->pluck('slug')->all();
            $imported = collect($result['rows'])
                ->whereIn('action', [LegacyBlogImporter::ACTION_CREATE, LegacyBlogImporter::ACTION_UPDATE, LegacyBlogImporter::ACTION_SKIP_EXISTS])
                ->pluck('slug')->all();
            $slugs = array_values(array_unique(array_merge($live, $imported)));

            $builder = new BlogRedirectMapBuilder();
            $map = $builder->build($slugs, $this->readCsv($baseline), '/blog/', (string) $this->option('target-base'));
            $this->writeCsv($out.'/redirect-map.csv', $map, [
                'type', 'critical', 'clicks', 'impressions', 'source_slug', 'target_slug', 'similarity', 'source_url', 'target_url',
            ]);
            file_put_contents($out.'/redirects.nginx.conf', $builder->toNginx($map));

            $byType = collect($map)->countBy('type');
            $critical = collect($map)->where('critical', 'yes')->whereIn('type', ['unmatched', 'suggest'])->count();
            $this->table(['نوع الرابط في قوقل', 'العدد'], [
                ['same (نفس الرابط)', $byType['same'] ?? 0],
                ['redirect (301 تلقائي)', $byType['redirect'] ?? 0],
                ['suggest (يحتاج موافقة)', $byType['suggest'] ?? 0],
                ['unmatched (بلا مقال)', $byType['unmatched'] ?? 0],
                ['⚠ روابط لها نقرات بلا تطابق مؤكد', $critical],
            ]);
        } else {
            $this->warn("ما لقيت ملف خط الأساس: {$baseline} — تخطيت خريطة 301");
        }

        file_put_contents($out.'/summary.json', json_encode([
            'committed' => $result['committed'],
            'summary' => $summary,
            'critical_unmatched' => $critical,
            'generated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line('التقارير في: '.$out);

        if ($summary[LegacyBlogImporter::ACTION_ERROR] > 0) {
            $this->error('فيه أخطاء — ما انكتب شي. راجع import-report.csv');

            return self::FAILURE;
        }
        $collisions = collect($result['rows'])->where('action', LegacyBlogImporter::ACTION_SKIP_COLLISION);
        if ($collisions->isNotEmpty()) {
            $this->warn($collisions->count().' مقال رابطه يختلف عن مقال ثاني بالتشكيل فقط (MySQL يعتبرهم نفس الرابط) — محتواه ما بينتقل:');
            foreach ($collisions as $c) {
                $this->line('  - #'.$c['legacy_id'].'  '.$c['slug'].'  ← '.$c['reason']);
            }
            if (! $this->option('accept-collisions')) {
                $this->error('ما انكتب شي. قرّر لكل واحد، ثم أعد التشغيل مع --accept-collisions');

                return self::FAILURE;
            }
        }
        if ($critical > 0) {
            $this->warn("فيه {$critical} رابط له نقرات في قوقل بلا تطابق مؤكد — لازم قرار يدوي قبل نقل الدومين (redirect-map.csv)");
        }

        $this->info($result['committed'] ? 'تم الحفظ والتحقق من كل صف.' : 'انتهت التجربة — ما تغيّر شي.');

        return self::SUCCESS;
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $path): array
    {
        $h = fopen($path, 'rb');
        $header = fgetcsv($h, 0, ',', '"', '');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $rows = [];
        while (($line = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if (count($line) === count($header)) {
                $rows[] = array_combine($header, $line);
            }
        }
        fclose($h);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     */
    private function writeCsv(string $path, array $rows, array $columns): void
    {
        $h = fopen($path, 'wb');
        fwrite($h, "\xEF\xBB\xBF"); // Excel يقرأ العربي صح
        fputcsv($h, $columns, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($h, array_map(fn ($c) => is_scalar($row[$c] ?? null) ? (string) $row[$c] : '', $columns), ',', '"', '');
        }
        fclose($h);
    }
}
