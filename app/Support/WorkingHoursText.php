<?php

namespace App\Support;

/**
 * دفعة (و) — D8: استبدال نصوص ساعات العمل القديمة (9 ص – 6 م / 9 ص – 1 بعد منتصف الليل / السبت 9 ص – 2 م)
 * بالساعات الجديدة. يستبدل فقط العبارات الواضحة لساعات قديمة؛ بقية النص كما هو.
 */
final class WorkingHoursText
{
    public const AR = 'يومياً من 12 ظهراً حتى 12 منتصف الليل، والجمعة من 3 عصراً حتى 12 منتصف الليل';

    public const EN = 'Daily from 12 PM to 12 AM (midnight); Fridays from 3 PM to 12 AM (midnight)';

    private const AM = '(?:صباح(?:اً|ًا|ا)?|ص)';

    private const PM = '(?:مساء(?:ً|اً)?|م|ظهر(?:اً|ًا|ا)?|عصر(?:اً|ًا|ا)?)';

    private const NUM = '(?:الساعة\s*)?[0-9٠-٩]{1,2}(?:[:٫.][0-9٠-٩]{2})?';

    private const TO = '\s*(?:حتى|إلى|الى|-|–|—)\s*';

    /** @return list<string> */
    private static function arPatterns(): array
    {
        $am = self::AM;
        $pm = self::PM;
        $n = self::NUM;
        $to = self::TO;
        $days = '(?:يومي(?:اً|ًا|ا)\s*|(?:من\s+)?(?:ال)?(?:سبت|أحد|احد|إثنين|اثنين|ثلاثاء|أربعاء|اربعاء|خميس|جمعة)\s*(?:(?:إلى|الى|حتى|-|–)\s*(?:ال)?(?:سبت|أحد|احد|إثنين|اثنين|ثلاثاء|أربعاء|اربعاء|خميس|جمعة)\s*)?:?\s*|يوم\s+(?:ال)?(?:سبت|جمعة)\s*)?';

        return [
            // «من الساعة 9:00 صباحًا حتى الساعة 1:00 بعد منتصف الليل. (يوميًا)»
            '/(?:من\s+)?'.$n.'\s*'.$am.$to.$n.'\s*بعد\s+منتصف\s+الليل\.?\s*(?:\(يومي(?:اً|ًا|ا)\))?/u',
            // «الإثنين – الجمعة: 9:00 ص – 6:00 م» / «يوم السبت من 9:00 صباحاً حتى 2:00 ظهراً»
            '/'.$days.'(?:من\s+)?'.$n.'\s*'.$am.$to.$n.'\s*'.$pm.'(?![\p{L}])/u',
        ];
    }

    public static function looksOld(?string $text): bool
    {
        if ($text === null || trim($text) === '' || str_contains($text, self::AR)) {
            return false;
        }
        foreach (self::arPatterns() as $re) {
            if (preg_match($re, $text) === 1) {
                return true;
            }
        }

        return (bool) preg_match('/\b9(?::00)?\s*(?:AM|am)\b.*\b(?:6|1)(?::00)?\s*(?:PM|pm|AM|am)\b/u', $text);
    }

    /** يستبدل العبارات القديمة (عربي + إنجليزي) — يعيد null إن لم يتغير شيء. */
    public static function replaceOld(?string $text): ?string
    {
        if (! self::looksOld($text)) {
            return null;
        }
        $out = (string) $text;
        $first = true;
        foreach (self::arPatterns() as $re) {
            $out = (string) preg_replace_callback($re, function (array $m) use (&$first) {
                $r = $first ? self::AR.(str_contains($m[0], '.') ? '.' : '') : '';
                $first = false;

                return $r;
            }, $out);
        }
        $out = (string) preg_replace('/(?:(?:Mon(?:day)?|Sat(?:urday)?|Sun(?:day)?|Daily)[^.\n]{0,25})?\b9(?::00)?\s*(?:AM|am)\s*(?:to|-|–)\s*(?:6|1)(?::00)?\s*(?:PM|pm|AM|am)(?:\s*\(?(?:after midnight)\)?)?/u', self::EN, $out);
        // تنظيف فواصل/أسطر فارغة خلّفها حذف السطر الثاني (مثل «السبت ...»).
        $out = (string) preg_replace("/(\n\s*){2,}/u", "\n", $out);
        $out = trim((string) preg_replace('/\s*[،,]\s*(?=[.\n]|$)/u', '', $out));

        return $out !== (string) $text ? $out : null;
    }
}
