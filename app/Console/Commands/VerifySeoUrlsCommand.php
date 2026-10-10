<?php

namespace App\Console\Commands;

use App\Services\Seo\UrlMigrationVerifier;
use Illuminate\Console\Command;

/**
 * البند 4/5/6 — فحص ما بعد النقل: يمر على روابط خط الأساس ويتأكد إنها ما انكسرت.
 * قراءة فقط (طلبات GET)، ما يغيّر شي.
 */
class VerifySeoUrlsCommand extends Command
{
    protected $signature = 'seo:verify-urls
        {--file= : CSV فيه عمود url (افتراضي docs/seo-baseline/critical-urls-with-clicks.csv)}
        {--ip= : اختبار سيرفر جديد قبل DNS: يوجّه الدومين لهذا الـ IP (نفس الرابط والـ https)}
        {--map= : redirect-map.csv من blog:import-legacy — يتأكد إن كل 301 يروح للمقال الصحيح}
        {--allow-host=* : دومين مقبول كوجهة (افتراضي aqdi.sa و www.aqdi.sa)}
        {--limit=0 : أول N رابط فقط (0 = الكل)}
        {--delay=200 : انتظار بين الطلبات بالمللي ثانية}
        {--out= : ملف التقرير CSV}';

    protected $description = 'Check that every baseline URL still returns 200 or a single 301 to a 200 page (read-only)';

    public function handle(): int
    {
        $file = $this->option('file') ?: base_path('docs/seo-baseline/critical-urls-with-clicks.csv');
        if (! is_file($file)) {
            $this->error("ما لقيت الملف: {$file}");

            return self::INVALID;
        }

        $urls = $this->readUrls($file);
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $urls = array_slice($urls, 0, $limit);
        }

        $hosts = $this->option('allow-host') ?: ['aqdi.sa', 'www.aqdi.sa'];
        $expected = [];
        if ($map = $this->option('map')) {
            if (! is_file($map)) {
                $this->error("ما لقيت الخريطة: {$map}");

                return self::INVALID;
            }
            $h = fopen($map, 'rb');
            $head = array_map(fn ($v) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)), fgetcsv($h, 0, ',', '"', ''));
            while (($line = fgetcsv($h, 0, ',', '"', '')) !== false) {
                if (count($line) !== count($head)) {
                    continue;
                }
                $r = array_combine($head, $line);
                if (($r['type'] ?? '') === 'redirect' && ($r['target_url'] ?? '') !== '') {
                    $expected[$r['source_url']] = $r['target_url'];
                }
            }
            fclose($h);
        }

        $verifier = new UrlMigrationVerifier($this->option('ip') ?: null, array_map('strtolower', $hosts), 20, $expected);

        $this->line('عدد الروابط: '.count($urls).($this->option('ip') ? ' — على السيرفر '.$this->option('ip') : '').($expected ? ' — وجهات متوقعة: '.count($expected) : ''));
        $results = [];
        $bar = $this->output->createProgressBar(count($urls));
        foreach ($urls as $i => $row) {
            $results[] = $verifier->check($row['url']) + ['clicks' => $row['clicks']];
            $bar->advance();
            if ($i < count($urls) - 1 && (int) $this->option('delay') > 0) {
                usleep((int) $this->option('delay') * 1000);
            }
        }
        $bar->finish();
        $this->newLine(2);

        $out = $this->option('out') ?: storage_path('app/seo-verify/verify-'.now()->format('Ymd_His').'.csv');
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        $h = fopen($out, 'wb');
        fwrite($h, "\xEF\xBB\xBF");
        $cols = ['result', 'clicks', 'status', 'location', 'final_status', 'canonical', 'note', 'url'];
        fputcsv($h, $cols, ',', '"', '');
        foreach ($results as $r) {
            fputcsv($h, array_map(fn ($c) => (string) ($r[$c] ?? ''), $cols), ',', '"', '');
        }
        fclose($h);

        $failed = array_values(array_filter($results, fn ($r) => ! UrlMigrationVerifier::passed($r)));
        $lostClicks = array_sum(array_map(fn ($r) => (int) $r['clicks'], $failed));
        $byResult = collect($results)->countBy('result')->sortDesc();

        $this->table(['النتيجة', 'العدد'], $byResult->map(fn ($v, $k) => [$k, $v])->values()->all());
        $canonical = collect($results)->where('note', 'canonical_points_elsewhere')->count();
        if ($canonical > 0) {
            $this->warn("{$canonical} صفحة الـ canonical فيها يشاور لرابط ثاني — راجعها");
        }
        $this->line('التقرير: '.$out);

        if ($failed !== []) {
            $this->error(count($failed)." رابط فشل (نقرات سابقة معرّضة للخسارة: {$lostClicks}) — لا تكمل النقل");
            foreach (array_slice($failed, 0, 10) as $f) {
                $this->line(' - '.$f['result'].'  '.rawurldecode($f['url']));
            }

            return self::FAILURE;
        }

        $this->info('كل الروابط سليمة ✓');

        return self::SUCCESS;
    }

    /** @return list<array{url: string, clicks: int}> */
    private function readUrls(string $file): array
    {
        $h = fopen($file, 'rb');
        $header = fgetcsv($h, 0, ',', '"', '');
        $header = array_map(fn ($v) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $v))), $header);
        $urlCol = array_search('url', $header, true);
        $clickCol = array_search('clicks', $header, true);
        $rows = [];
        while (($line = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($urlCol === false || ! isset($line[$urlCol]) || ! str_starts_with((string) $line[$urlCol], 'http')) {
                continue;
            }
            $rows[] = ['url' => (string) $line[$urlCol], 'clicks' => $clickCol !== false ? (int) ($line[$clickCol] ?? 0) : 0];
        }
        fclose($h);

        return $rows;
    }
}
