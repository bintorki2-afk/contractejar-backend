<?php

namespace Tests\Feature\BlogMigration;

use App\Models\Blog;
use App\Services\Blog\BlogRedirectMapBuilder;
use App\Services\Blog\LegacyBlogImporter;
use App\Services\Blog\LegacyBlogSource;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\BatchD\BatchDTestCase;

/**
 * البند 6 — نقل مدونة عقدي القديمة: الروابط والمحتوى حرفياً، تجربة افتراضية، خريطة 301.
 */
class LegacyBlogImportTest extends BatchDTestCase
{
    private const SLUG_TOP = 'تعرف-الآن-على-شروط-حساب-المواطن-للفرد';
    private const SLUG_EJAR = 'تعرف-على-طريقة-التسجيل-في-منصة-إيجار';

    private function legacyRow(array $over = []): array
    {
        static $id = 0;
        $id++;

        return array_merge([
            'id' => (string) $id,
            'created_at' => '2025-03-01 10:15:00',
            'updated_at' => '2025-04-02 11:20:00',
            'description' => "<h2>مقدمة</h2>\n<p>نص المقال مع <a href=\"https://aqdi.sa\">رابط</a> و«اقتباس».</p>",
            'title' => 'عنوان مقال '.$id,
            'image' => 'blogs/AMLLmIt5ipmC7dPmIwqwkMv9VEvMw1Mcb2sQljL6.jpg',
            'meta_title' => 'Meta عنوان '.$id,
            'meta_description' => 'وصف ميتا '.$id,
            'slug' => 'مقال-رقم-'.$id,
            'is_active' => '1',
            'status' => 'published',
            'publish_at' => null,
        ], $over);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $result = app(LegacyBlogImporter::class)->import([$this->legacyRow(['slug' => self::SLUG_TOP])]);

        $this->assertFalse($result['committed']);
        $this->assertSame(1, $result['summary']['create']);
        $this->assertSame('yes', $result['rows'][0]['verified']);
        $this->assertSame(0, Blog::query()->count());
    }

    public function test_commit_keeps_slug_content_meta_and_dates_byte_for_byte(): void
    {
        $long = str_repeat('وصف طويل جداً للمقال ', 30); // > 255 حرف
        $row = $this->legacyRow(['slug' => self::SLUG_TOP, 'title' => 'تعرف الآن على شروط حساب المواطن للفرد', 'meta_description' => $long]);

        $result = app(LegacyBlogImporter::class)->import([$row], ['commit' => true]);

        $this->assertTrue($result['committed']);
        $blog = Blog::query()->firstOrFail();
        $this->assertSame(self::SLUG_TOP, $blog->slug);
        $this->assertSame($row['title'], $blog->title);
        $this->assertSame($row['description'], $blog->description);
        $this->assertSame($row['image'], $blog->image);
        $this->assertSame($long, $blog->meta_description);
        $this->assertSame('2025-03-01 10:15:00', $blog->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2025-04-02 11:20:00', $blog->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_imported_post_is_served_at_the_same_arabic_slug(): void
    {
        app(LegacyBlogImporter::class)->import([$this->legacyRow(['slug' => self::SLUG_EJAR])], ['commit' => true]);

        $this->getJson('/api/blogs/'.rawurlencode(self::SLUG_EJAR))->assertOk()->assertJsonPath('data.slug', self::SLUG_EJAR);
    }

    public function test_inactive_and_draft_posts_keep_their_state(): void
    {
        app(LegacyBlogImporter::class)->import([
            $this->legacyRow(['slug' => 'مخفي', 'is_active' => '0']),
            $this->legacyRow(['slug' => 'مسودة', 'status' => 'draft']),
        ], ['commit' => true]);

        $this->assertFalse(Blog::query()->where('slug', 'مخفي')->first()->is_active);
        $this->assertSame('draft', Blog::query()->where('slug', 'مسودة')->first()->status);
        $this->getJson('/api/blogs/'.rawurlencode('مخفي'))->assertNotFound();
    }

    public function test_duplicates_in_source_and_existing_slugs_are_never_overwritten_by_default(): void
    {
        Blog::query()->create(['slug' => 'موجود', 'title' => 'الأصلي في صقر ١', 'status' => 'published', 'is_active' => 1]);

        $result = app(LegacyBlogImporter::class)->import([
            $this->legacyRow(['slug' => 'مكرر']),
            $this->legacyRow(['slug' => 'مكرر', 'title' => 'نسخة ثانية']),
            $this->legacyRow(['slug' => 'موجود', 'title' => 'من القديم']),
        ], ['commit' => true]);

        $actions = array_column($result['rows'], 'action');
        $this->assertSame(['create', 'skip_duplicate_in_source', 'skip_exists'], $actions);
        $this->assertSame('الأصلي في صقر ١', Blog::query()->where('slug', 'موجود')->value('title'));
    }

    public function test_on_conflict_update_overwrites_existing(): void
    {
        Blog::query()->create(['slug' => 'موجود', 'title' => 'قديم', 'status' => 'published', 'is_active' => 1]);

        $result = app(LegacyBlogImporter::class)->import([$this->legacyRow(['slug' => 'موجود', 'title' => 'محدّث'])], ['commit' => true, 'on_conflict' => 'update']);

        $this->assertSame('update', $result['rows'][0]['action']);
        $this->assertSame('محدّث', Blog::query()->where('slug', 'موجود')->value('title'));
        $this->assertSame(1, Blog::query()->count());
    }

    public function test_missing_slug_uses_the_old_site_algorithm(): void
    {
        $importer = app(LegacyBlogImporter::class);
        $result = $importer->import([$this->legacyRow(['slug' => null, 'title' => 'ما هي رسوم عقد الإيجار؟'])], ['commit' => true]);

        $this->assertSame('ما-هي-رسوم-عقد-الإيجار', Blog::query()->value('slug'));
        $this->assertStringContainsString('slug_generated_from_title', $result['rows'][0]['warnings']);
    }

    public function test_row_without_slug_and_title_is_an_error_and_blocks_commit(): void
    {
        $result = app(LegacyBlogImporter::class)->import([
            $this->legacyRow(),
            $this->legacyRow(['slug' => null, 'title' => null]),
        ], ['commit' => true]);

        $this->assertFalse($result['committed']);
        $this->assertSame(1, $result['summary']['error']);
        $this->assertSame(0, Blog::query()->count(), 'any error must roll back everything');
    }

    public function test_mojibake_export_is_rejected_and_nothing_is_written(): void
    {
        // «تعرف» بعد ما انقرأ UTF-8 كـ latin1 وانحفظ مرة ثانية (يصير لو انحمّل الملف بترميز خاطئ)
        $broken = mb_convert_encoding(self::SLUG_TOP, 'UTF-8', 'ISO-8859-1');

        $result = app(LegacyBlogImporter::class)->import([
            $this->legacyRow(),
            $this->legacyRow(['slug' => $broken]),
        ], ['commit' => true]);

        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('mojibake', $result['rows'][1]['reason']);
        $this->assertSame(0, Blog::query()->count());
        $this->assertFalse(app(LegacyBlogImporter::class)->looksLikeMojibake(self::SLUG_TOP));
    }

    public function test_truncated_google_url_does_not_crash_the_map(): void
    {
        $map = (new BlogRedirectMapBuilder())->build([self::SLUG_TOP], [
            ['url' => 'https://aqdi.sa/blog/%D8%AA%D8', 'clicks' => '2'],
        ]);

        $this->assertSame('unmatched', $map[0]['type']);
        $this->assertSame('invalid_utf8', $map[0]['similarity']);
    }

    public function test_mojibake_in_meta_is_rejected(): void
    {
        $result = app(LegacyBlogImporter::class)->import([
            $this->legacyRow(['meta_title' => mb_convert_encoding('عقد إيجار', 'UTF-8', 'ISO-8859-1')]),
        ], ['commit' => true]);

        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('meta_title', $result['rows'][0]['reason']);
    }

    public function test_byte_identical_duplicate_does_not_block_commit(): void
    {
        // تصادم التشكيل الحقيقي يحتاج MySQL (مجرّب في البروفة على MariaDB)؛ هنا نتأكد إن المكرر بالبايت ما يمنع
        $rows = [$this->legacyRow(['slug' => 'ماهو-العقد-الكتروني']), $this->legacyRow(['slug' => 'ماهو-العقد-الكتروني'])];

        $result = app(LegacyBlogImporter::class)->import($rows, ['commit' => true]);

        $this->assertTrue($result['committed']);
        $this->assertSame(1, $result['summary']['skip_duplicate_in_source']);
    }

    public function test_reads_phpmyadmin_csv_with_bom_null_and_multiline_html(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'blogs').'.csv';
        $html = "<p>سطر أول</p>\n<p>فيه \"تنصيص\", وفاصلة</p>";
        $csv = "\xEF\xBB\xBF".'"id","created_at","updated_at","description","title","image","meta_title","meta_description","slug","is_active","status","publish_at"'."\n"
            .'"7","2025-01-01 09:00:00","2025-01-02 09:00:00","'.str_replace('"', '""', $html).'","عنوان","blogs/a.jpg","NULL","NULL","'.self::SLUG_EJAR.'","1","published","NULL"'."\n";
        file_put_contents($path, $csv);

        $rows = app(LegacyBlogSource::class)->fromFile($path);
        $norm = app(LegacyBlogImporter::class)->normalizeRow($rows[0]);

        $this->assertCount(1, $rows);
        $this->assertSame($html, $norm['description']);
        $this->assertSame(self::SLUG_EJAR, $norm['slug']);
        $this->assertNull($norm['meta_title']);
        $this->assertNull($norm['publish_at']);
    }

    public function test_reads_phpmyadmin_json_export(): void
    {
        $json = json_encode([
            ['type' => 'header', 'version' => '5.2.1'],
            ['type' => 'database', 'name' => 'aqdisa_123'],
            ['type' => 'table', 'name' => 'blogs', 'database' => 'aqdisa_123', 'data' => [$this->legacyRow(['slug' => self::SLUG_TOP])]],
        ], JSON_UNESCAPED_UNICODE);

        $rows = app(LegacyBlogSource::class)->fromJson($json);

        $this->assertSame(self::SLUG_TOP, $rows[0]['slug']);
    }

    public function test_redirect_map_types_and_nginx_output(): void
    {
        $enc = fn (string $s) => 'https://aqdi.sa/blog/'.rawurlencode($s);
        $baseline = [
            ['url' => $enc(self::SLUG_TOP), 'clicks' => '18973', 'impressions' => '6676617'],
            ['url' => $enc(self::SLUG_TOP).'/', 'clicks' => '0', 'impressions' => '3'],
            ['url' => $enc(self::SLUG_TOP.')'), 'clicks' => '0', 'impressions' => '1'],
            ['url' => $enc('تعرف-الآن-على-شرًط-حساب-المواطن-للفردا'), 'clicks' => '1', 'impressions' => '1'],
            ['url' => $enc('مقال-محذوف-تماماً-من-زمان'), 'clicks' => '5', 'impressions' => '50'],
            ['url' => 'https://aqdi.sa/about-us', 'clicks' => '632', 'impressions' => '9'],
        ];

        $builder = new BlogRedirectMapBuilder();
        $map = collect($builder->build([self::SLUG_TOP, self::SLUG_EJAR], $baseline))->keyBy('source_url');

        $this->assertCount(5, $map, 'non-blog URLs are ignored');
        $this->assertSame('same', $map[$enc(self::SLUG_TOP)]['type']);
        $this->assertSame('redirect', $map[$enc(self::SLUG_TOP).'/']['type']);
        $this->assertSame('redirect', $map[$enc(self::SLUG_TOP.')')]['type']);
        $typo = $map[$enc('تعرف-الآن-على-شرًط-حساب-المواطن-للفردا')];
        $this->assertSame('suggest', $typo['type']);
        $this->assertSame(self::SLUG_TOP, $typo['target_slug']);
        $this->assertSame('unmatched', $map[$enc('مقال-محذوف-تماماً-من-زمان')]['type']);
        $this->assertSame('yes', $map[$enc('مقال-محذوف-تماماً-من-زمان')]['critical']);

        $nginx = $builder->toNginx($map->values()->all());
        $this->assertStringContainsString('location = "/blog/'.self::SLUG_TOP.')" { return 301 https://aqdi.sa/blog/'.rawurlencode(self::SLUG_TOP).'; }', $nginx);
        $this->assertStringNotContainsString('للفردا', $nginx, 'suggestions need manual approval');
        // الـ Location ASCII فقط (percent-encoded)
        preg_match_all('/return 301 (\S+);/', $nginx, $m);
        foreach ($m[1] as $location) {
            $this->assertMatchesRegularExpression('/^[\x21-\x7E]+$/', $location);
        }
    }

    public function test_command_dry_run_writes_reports_and_changes_nothing(): void
    {
        $dir = sys_get_temp_dir().'/blog-import-test-'.uniqid();
        $file = $dir.'.json';
        file_put_contents($file, json_encode([$this->legacyRow(['slug' => self::SLUG_TOP])], JSON_UNESCAPED_UNICODE));
        $baseline = $dir.'-baseline.csv';
        file_put_contents($baseline, "url,url_decoded,clicks,impressions,ctr_pct,position\n"
            .'https://aqdi.sa/blog/'.rawurlencode(self::SLUG_TOP).',x,18973,6676617,0.3,6.3'."\n"
            .'https://aqdi.sa/blog/'.rawurlencode('غير-موجود').',x,4,10,0,9'."\n");

        $code = Artisan::call('blog:import-legacy', ['--file' => $file, '--baseline' => $baseline, '--out' => $dir]);

        $this->assertSame(0, $code);
        $this->assertSame(0, Blog::query()->count());
        foreach (['import-report.csv', 'redirect-map.csv', 'redirects.nginx.conf', 'summary.json'] as $f) {
            $this->assertFileExists($dir.'/'.$f);
        }
        $summary = json_decode(file_get_contents($dir.'/summary.json'), true);
        $this->assertFalse($summary['committed']);
        $this->assertSame(1, $summary['critical_unmatched']);
        $this->assertStringContainsString(self::SLUG_TOP, file_get_contents($dir.'/import-report.csv'));
    }

    public function test_command_requires_exactly_one_source(): void
    {
        $this->assertSame(2, Artisan::call('blog:import-legacy'));
        $this->assertSame(2, Artisan::call('blog:import-legacy', ['--file' => 'a.json', '--connection' => 'legacy']));
    }
}
