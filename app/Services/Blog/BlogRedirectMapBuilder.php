<?php

namespace App\Services\Blog;

use RuntimeException;

/**
 * يربط كل رابط مدونة ظهر في Search Console (خط الأساس) بمقال موجود:
 *  - same:     الرابط هو نفس رابط المقال (ما يحتاج تحويل إذا بقي الهيكل /blog/{slug})
 *  - redirect: نسخة مشوّهة لنفس المقال (/ في الآخر، قوس، مسافة…) ⇒ 301 تلقائي للأصل
 *  - suggest:  أقرب مقال بالتشابه (أخطاء إملائية) ⇒ يحتاج موافقة يدوية، ما يدخل الملف تلقائياً
 *  - unmatched: ما له مقال ⇒ قرار يدوي (لو له نقرات = حرج)
 */
class BlogRedirectMapBuilder
{
    public function __construct(private readonly float $suggestThreshold = 85.0)
    {
    }

    /**
     * @param  list<string>  $slugs  كل الـ slugs الموجودة بعد الاستيراد (حرفياً)
     * @param  list<array<string, mixed>>  $baselineRows  صفوف CSV خط الأساس (url, clicks, impressions…)
     * @return list<array<string, mixed>>
     */
    public function build(array $slugs, array $baselineRows, string $pathPrefix = '/blog/', string $targetBase = 'https://aqdi.sa/blog/'): array
    {
        $exact = array_fill_keys($slugs, true);

        $byNormal = [];
        foreach ($slugs as $slug) {
            $byNormal[$this->normalize($slug)][] = $slug;
        }

        $out = [];
        foreach ($baselineRows as $row) {
            $url = (string) ($row['url'] ?? '');
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            if (! str_starts_with($path, $pathPrefix)) {
                continue;
            }

            $legacySlug = rawurldecode(substr($path, strlen($pathPrefix)));
            $clicks = (int) ($row['clicks'] ?? 0);
            $line = [
                'source_url' => $url,
                'source_slug' => $legacySlug,
                'clicks' => $clicks,
                'impressions' => (int) ($row['impressions'] ?? 0),
                'type' => 'unmatched',
                'target_slug' => '',
                'target_url' => '',
                'similarity' => '',
                'critical' => $clicks > 0 ? 'yes' : 'no',
            ];

            if (! mb_check_encoding($legacySlug, 'UTF-8')) {
                // رابط مقطوع وسط حرف في بيانات قوقل — ما نقدر نطابقه آلياً
                $line['type'] = 'unmatched';
                $line['source_slug'] = mb_convert_encoding($legacySlug, 'UTF-8', 'UTF-8');
                $line['similarity'] = 'invalid_utf8';
            } elseif (isset($exact[$legacySlug])) {
                $line['type'] = 'same';
                $line['target_slug'] = $legacySlug;
            } elseif (count($byNormal[$this->normalize($legacySlug)] ?? []) === 1) {
                $line['type'] = 'redirect';
                $line['target_slug'] = $byNormal[$this->normalize($legacySlug)][0];
            } else {
                [$best, $score] = $this->closest($legacySlug, $slugs);
                if ($best !== null && $score >= $this->suggestThreshold) {
                    $line['type'] = 'suggest';
                    $line['target_slug'] = $best;
                    $line['similarity'] = number_format($score, 1);
                }
            }

            if ($line['target_slug'] !== '') {
                $line['target_url'] = rtrim($targetBase, '/').'/'.rawurlencode($line['target_slug']);
            }

            $out[] = $line;
        }

        usort($out, fn ($a, $b) => $b['clicks'] <=> $a['clicks']);

        return $out;
    }

    /**
     * ملف nginx للتحويلات التلقائية فقط (type=redirect). الـ Location مرمّز percent-encoding
     * دائماً عشان ما يطلع UTF-8 خام في الترويسة.
     *
     * @param  list<array<string, mixed>>  $map
     */
    public function toNginx(array $map, string $pathPrefix = '/blog/'): string
    {
        $lines = [
            '# مولّد آلياً بواسطة blog:import-legacy — تحويلات 301 للنسخ المشوّهة من روابط المدونة.',
            '# لا تعدّل يدوياً؛ أعد توليده. الاقتراحات (suggest) ما تدخل هنا إلا بعد موافقة.',
        ];
        foreach ($map as $line) {
            if ($line['type'] !== 'redirect') {
                continue;
            }
            $source = $pathPrefix.$line['source_slug'];
            if (preg_match('/["\\\\\x00-\x1F\x7F]/', $source)) {
                $lines[] = '# SKIPPED (unsafe chars, handle manually): '.rawurlencode($line['source_slug']);
                continue;
            }
            $lines[] = sprintf('location = "%s" { return 301 %s; }', $source, $line['target_url']);
        }

        return implode("\n", $lines)."\n";
    }

    /** يوحّد النسخ المشوّهة: يشيل / والأقواس والمسافات والتشكيل في الأطراف. */
    public function normalize(string $slug): string
    {
        $s = trim($slug);
        $s = (string) preg_replace('/[\/\)\(\]\[\.\s,،]+$/u', '', $s);
        $s = (string) preg_replace('/^[\/\s]+/u', '', $s);
        if (class_exists(\Normalizer::class)) {
            $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_C);
        }

        return $s;
    }

    /**
     * @param  list<string>  $slugs
     * @return array{0: ?string, 1: float}
     */
    private function closest(string $needle, array $slugs): array
    {
        $best = null;
        $bestScore = 0.0;
        $a = $this->chars($needle);
        foreach ($slugs as $slug) {
            $b = $this->chars($slug);
            $max = max(count($a), count($b));
            if ($max === 0) {
                continue;
            }
            // فرق الطول وحده يمنع الوصول للعتبة ⇒ نتخطى الحساب المكلف
            $bound = 100.0 * (1 - abs(count($a) - count($b)) / $max);
            if ($bound < $this->suggestThreshold || $bound <= $bestScore) {
                continue;
            }
            $score = 100.0 * (1 - $this->levenshtein($a, $b) / $max);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $slug;
            }
        }

        return [$best, $bestScore];
    }

    /** @return list<string> */
    private function chars(string $s): array
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new RuntimeException('Invalid UTF-8 slug');
        }

        return $chars;
    }

    /**
     * Levenshtein على الحروف (مو البايتات) عشان العربي.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function levenshtein(array $a, array $b): int
    {
        $n = count($a);
        $m = count($b);
        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cur[$j] = min(
                    $prev[$j] + 1,
                    $cur[$j - 1] + 1,
                    $prev[$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1)
                );
            }
            $prev = $cur;
        }

        return $prev[$m];
    }
}
