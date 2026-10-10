<?php

namespace App\Services\Blog;

use App\Models\Blog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * استيراد مقالات مدونة عقدي القديمة (جدول blogs في aqdisa_123) إلى صقر ١ بدون أي تغيير
 * في الرابط: الـ slug يُنقل حرفياً (بايت ببايت)، والعنوان والمحتوى والصورة والـ Meta
 * والتواريخ كما هي. الحماية:
 *  - تجربة افتراضية (dry-run): كل شي داخل معاملة تُلغى في الآخر، فنشوف النتيجة الحقيقية
 *    (بما فيها تصادمات الـ collation في MySQL) بدون ما ينكتب شي.
 *  - لا نكتب فوق مقال موجود إلا بخيار صريح (update).
 *  - بعد الكتابة نقرأ كل صف ونقارنه بالأصل (تحقق ذهاب وعودة).
 */
class LegacyBlogImporter
{
    /** ترتيب أعمدة جدول blogs القديم (لملف CSV بلا صف عناوين). */
    public const LEGACY_COLUMNS = [
        'id', 'created_at', 'updated_at', 'description', 'title', 'image',
        'meta_title', 'meta_description', 'slug', 'is_active', 'status', 'publish_at',
    ];

    /** الحقول اللي لازم تطابق الأصل بالضبط بعد الاستيراد. */
    private const VERIFY_FIELDS = ['slug', 'title', 'description', 'image', 'meta_title', 'meta_description'];

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_SKIP_EXISTS = 'skip_exists';
    public const ACTION_SKIP_DUPLICATE = 'skip_duplicate_in_source';
    public const ACTION_SKIP_COLLISION = 'skip_collation_collision';
    public const ACTION_ERROR = 'error';

    /**
     * @param  list<array<string, mixed>>  $rows  صفوف الجدول القديم
     * @param  array{commit?: bool, on_conflict?: string, images_dir?: ?string, block_on_collision?: bool}  $options
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, committed: bool}
     */
    public function import(array $rows, array $options = []): array
    {
        $commit = (bool) ($options['commit'] ?? false);
        $onConflict = (string) ($options['on_conflict'] ?? 'skip');
        if (! in_array($onConflict, ['skip', 'update'], true)) {
            throw new RuntimeException("on_conflict must be skip or update, got [{$onConflict}]");
        }
        $imagesDir = $options['images_dir'] ?? null;
        // تصادم تشكيل = محتوى مقال ما ينتقل ⇒ افتراضياً يمنع الحفظ لين المالك يقرر
        $blockOnCollision = (bool) ($options['block_on_collision'] ?? true);

        $report = [];
        $seen = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $raw) {
                $report[] = $this->importRow($this->normalizeRow($raw), $index, $onConflict, $imagesDir, $seen);
            }

            $this->verifyRoundTrip($report);
        } catch (\Throwable $e) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }

        $blocked = $this->hasErrors($report)
            || ($blockOnCollision && $this->count($report, self::ACTION_SKIP_COLLISION) > 0);

        if ($commit && ! $blocked) {
            DB::commit();
            $committed = true;
        } else {
            DB::rollBack();
            $committed = false;
        }

        return [
            'rows' => $report,
            'summary' => $this->summarize($report),
            'committed' => $committed,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $seen  slug => أول legacy_id
     * @return array<string, mixed>
     */
    private function importRow(array $row, int $index, string $onConflict, ?string $imagesDir, array &$seen): array
    {
        $legacyId = $row['id'];
        $slug = $row['slug'];
        $warnings = [];

        $base = [
            'legacy_id' => $legacyId,
            'row' => $index + 1,
            'slug' => $slug,
            'title' => $row['title'],
            'is_active' => $row['is_active'],
            'status' => $row['status'],
        ];

        if ($slug === null || $slug === '') {
            if (blank($row['title'])) {
                return $base + ['action' => self::ACTION_ERROR, 'reason' => 'no_slug_and_no_title', 'warnings' => '', 'blog_id' => null];
            }
            // نفس خوارزمية الموقع القديم (Blog::saving) — يعني نفس الرابط اللي كان بيطلع
            $slug = $this->legacySlugFromTitle((string) $row['title']);
            $warnings[] = 'slug_generated_from_title';
            $base['slug'] = $slug;
        }

        // عربي مقروء بترميز خاطئ (latin1) يطلع «ØªØ¹…» — لو انكتب كذا تنكسر كل الروابط
        if ($this->looksLikeMojibake($slug) || $this->looksLikeMojibake((string) $row['title'])) {
            return $base + ['action' => self::ACTION_ERROR, 'reason' => 'mojibake_detected (re-export/load with utf8mb4)', 'warnings' => '', 'blog_id' => null];
        }
        foreach (['meta_title', 'meta_description'] as $field) {
            if ($this->looksLikeMojibake((string) $row[$field])) {
                return $base + ['action' => self::ACTION_ERROR, 'reason' => 'mojibake_detected in '.$field, 'warnings' => '', 'blog_id' => null];
            }
        }
        if ($this->looksLikeMojibake((string) $row['description'])) {
            $warnings[] = 'possible_mojibake_in_description';
        }
        if (! mb_check_encoding($slug, 'UTF-8')) {
            return $base + ['action' => self::ACTION_ERROR, 'reason' => 'invalid_utf8_slug', 'warnings' => '', 'blog_id' => null];
        }

        if ($slug !== trim($slug) || preg_match('/\s/u', $slug)) {
            $warnings[] = 'slug_has_whitespace';
        }
        if (preg_match('/["\\\\\x00-\x1F\x7F]/', $slug)) {
            $warnings[] = 'slug_has_control_or_quote_chars';
        }
        if (blank($row['title'])) {
            $warnings[] = 'missing_title';
        }
        if (blank($row['meta_title'])) {
            $warnings[] = 'missing_meta_title';
        }
        if (blank($row['meta_description'])) {
            $warnings[] = 'missing_meta_description';
        }
        if (filled($row['image']) && $imagesDir !== null) {
            $path = rtrim($imagesDir, '/').'/'.ltrim((string) $row['image'], '/');
            if (! is_file($path)) {
                $warnings[] = 'image_file_missing';
            }
        }

        if (isset($seen[$slug])) {
            return $base + [
                'action' => self::ACTION_SKIP_DUPLICATE,
                'reason' => 'same slug as legacy_id '.$seen[$slug],
                'warnings' => implode('|', $warnings),
                'blog_id' => null,
            ];
        }
        $seen[$slug] = $legacyId;

        $existing = Blog::query()->where('slug', $slug)->first();
        if ($existing && $existing->slug !== $slug) {
            // تطابق في الـ collation (مثل حروف بتشكيل) وليس نفس النص: لا نلمسه أبداً
            return $base + [
                'action' => self::ACTION_SKIP_COLLISION,
                'reason' => 'collides with existing blog #'.$existing->id.' slug ['.$existing->slug.']',
                'warnings' => implode('|', $warnings),
                'blog_id' => $existing->id,
            ];
        }

        if ($existing && $onConflict === 'skip') {
            return $base + [
                'action' => self::ACTION_SKIP_EXISTS,
                'reason' => 'blog #'.$existing->id.' already has this slug',
                'warnings' => implode('|', $warnings),
                'blog_id' => $existing->id,
            ];
        }

        $blog = $existing ?? new Blog();
        $blog->timestamps = false;
        $blog->forceFill([
            'slug' => $slug,
            'title' => $row['title'],
            'description' => $row['description'],
            'image' => $row['image'],
            'meta_title' => $row['meta_title'],
            'meta_description' => $row['meta_description'],
            'is_active' => $row['is_active'],
            'status' => $row['status'],
            'publish_at' => $row['publish_at'],
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? $row['created_at'] ?? now(),
        ]);

        DB::beginTransaction(); // savepoint: فشل صف واحد ما يلغي الباقي
        try {
            $blog->save();
            DB::commit();
        } catch (QueryException $e) {
            DB::rollBack();
            // 1062 = Duplicate entry (MySQL/MariaDB)، وsqlite: UNIQUE constraint failed.
            // باقي أخطاء 23000 (NOT NULL / مفتاح أجنبي) = خطأ حقيقي يمنع الحفظ.
            $isUnique = (int) ($e->errorInfo[1] ?? 0) === 1062
                || str_contains($e->getMessage(), 'UNIQUE constraint failed');

            return $base + [
                'action' => $isUnique ? self::ACTION_SKIP_COLLISION : self::ACTION_ERROR,
                'reason' => $isUnique ? 'unique slug collision (database collation)' : 'db_error: '.mb_substr($e->getMessage(), 0, 300),
                'warnings' => implode('|', $warnings),
                'blog_id' => null,
            ];
        }

        return $base + [
            'action' => $existing ? self::ACTION_UPDATE : self::ACTION_CREATE,
            'reason' => '',
            'warnings' => implode('|', $warnings),
            'blog_id' => $blog->id,
            '_expected' => array_intersect_key(['slug' => $slug] + $row, array_flip(self::VERIFY_FIELDS)) + [
                'status' => $row['status'],
                'is_active' => (string) (int) $row['is_active'],
                'publish_at' => $row['publish_at']?->format('Y-m-d H:i:s'),
            ] + ($row['created_at'] ? ['created_at' => $row['created_at']->format('Y-m-d H:i:s')] : []),
        ];
    }

    /**
     * يقرأ كل صف انكتب ويقارنه بالأصل بايت ببايت. أي اختلاف = خطأ يمنع الحفظ.
     *
     * @param  list<array<string, mixed>>  $report
     */
    private function verifyRoundTrip(array &$report): void
    {
        foreach ($report as &$line) {
            if (! isset($line['_expected'])) {
                $line['verified'] = '';
                continue;
            }

            $fresh = Blog::query()->find($line['blog_id']);
            $mismatch = [];
            foreach ($line['_expected'] as $field => $value) {
                $stored = $fresh?->getAttribute($field);
                if ($stored instanceof \DateTimeInterface) {
                    $stored = $stored->format('Y-m-d H:i:s');
                } elseif (is_bool($stored)) {
                    $stored = (string) (int) $stored;
                }
                if ((string) ($stored ?? '') !== (string) ($value ?? '')) {
                    $mismatch[] = $field;
                }
            }
            unset($line['_expected']);

            if ($mismatch !== []) {
                $line['action'] = self::ACTION_ERROR;
                $line['reason'] = 'round_trip_mismatch: '.implode(',', $mismatch);
                $line['verified'] = 'no';
            } else {
                $line['verified'] = 'yes';
            }
        }
        unset($line);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalizeRow(array $raw): array
    {
        $get = function (string $key) use ($raw) {
            if (! array_key_exists($key, $raw)) {
                return null;
            }
            $v = $raw[$key];
            // phpMyAdmin يكتب NULL كنص في CSV
            if ($v === null || $v === 'NULL' || $v === '\\N') {
                return null;
            }

            return is_string($v) ? $v : (is_scalar($v) ? (string) $v : null);
        };

        $status = $get('status') ?? 'published';
        $isActive = $get('is_active');

        return [
            'id' => $get('id'),
            'title' => $get('title'),
            'description' => $get('description'),
            'image' => $get('image'),
            'meta_title' => $get('meta_title'),
            'meta_description' => $get('meta_description'),
            // الـ slug ما نلمسه: لا trim ولا تحويل — حرفياً كما في القاعدة القديمة
            'slug' => $get('slug'),
            'is_active' => $isActive === null ? 0 : (int) $isActive,
            'status' => $status,
            'publish_at' => $this->date($get('publish_at')),
            'created_at' => $this->date($get('created_at')),
            'updated_at' => $this->date($get('updated_at')),
        ];
    }

    /** نص UTF-8 انقرأ كـ latin1/cp1252 ثم انحفظ UTF-8 مرة ثانية (ترميز مزدوج). */
    public function looksLikeMojibake(string $value): bool
    {
        return (bool) preg_match('/[\x{00C3}\x{00D8}-\x{00DB}][\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}\x{2013}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}]/u', $value);
    }

    /** نسخة مطابقة لـ App\Models\Blog::saving في الموقع القديم. */
    public function legacySlugFromTitle(string $title): string
    {
        $slug = trim($title);
        $slug = mb_strtolower($slug, 'UTF-8');
        $slug = str_replace(['/', '\\'], '-', $slug);
        $slug = preg_replace("/[^a-z0-9_\sءاأإآؤئبتثجحخدذرزسشصضطظعغفقكلمنهويةى]/u", '', $slug);
        $slug = preg_replace("/[\s-]+/", ' ', (string) $slug);

        return (string) preg_replace("/[\s_]/", '-', (string) $slug);
    }

    private function date(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            // القيم القديمة محفوظة بتوقيت الرياض (app.timezone) — نقرأها بنفس التوقيت
            return Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<array<string, mixed>> $report */
    private function count(array $report, string $action): int
    {
        return count(array_filter($report, fn ($line) => $line['action'] === $action));
    }

    /** @param list<array<string, mixed>> $report */
    private function hasErrors(array $report): bool
    {
        foreach ($report as $line) {
            if ($line['action'] === self::ACTION_ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $report
     * @return array<string, int>
     */
    private function summarize(array $report): array
    {
        $summary = ['total' => count($report)];
        foreach ([self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_SKIP_EXISTS, self::ACTION_SKIP_DUPLICATE, self::ACTION_SKIP_COLLISION, self::ACTION_ERROR] as $a) {
            $summary[$a] = 0;
        }
        $summary['with_warnings'] = 0;
        $summary['active'] = 0;
        foreach ($report as $line) {
            $summary[$line['action']]++;
            if ($line['warnings'] !== '') {
                $summary['with_warnings']++;
            }
            if ((int) $line['is_active'] === 1) {
                $summary['active']++;
            }
        }

        return $summary;
    }
}
