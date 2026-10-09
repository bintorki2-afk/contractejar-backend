<?php

namespace App\Support;

/**
 * القوالب الافتراضية (دفعة د — ب16). المتغيرات: {order} {name} {link} {amount} {draft_number} {support}.
 */
final class MessageTemplateDefaults
{
    public const PLACEHOLDERS = [
        '{order}' => 'رقم الطلب',
        '{name}' => 'اسم العميل',
        '{link}' => 'رابط الطلب',
        '{amount}' => 'المبلغ',
        '{draft_number}' => 'رقم مسودة إيجار',
        '{support}' => 'رقم الدعم',
    ];

    public const CHANNELS = ['whatsapp', 'sms', 'push'];

    /** @var list<array{key: string, channel: string, title: string|null, body: string, description: string}> */
    public const ROWS = [
        // مراحل الطلب — واتساب (ترجعها نقاط المراحل ب14 لتفتحها اللوحة في wa.me)
        ['key' => 'stage_received', 'channel' => 'whatsapp', 'title' => null, 'description' => 'عند استلام الموظف للطلب',
            'body' => "مرحباً {name} 👋\nاستلمنا طلبك رقم {order} في «عقد إيجار» ونعمل عليه الآن.\nتابع طلبك: {link}"],
        ['key' => 'stage_draft_sent', 'channel' => 'whatsapp', 'title' => null, 'description' => 'عند إرسال مسودة العقد',
            'body' => "مرحباً {name}\nأرسلنا لك عبر منصة إيجار مسودة عقدك لطلب رقم {order} (رقم المسودة {draft_number}).\nفضلاً اطّلع عليها وأكّد لنا صحة البيانات لنوثّق العقد.\n{link}"],
        ['key' => 'stage_notarized', 'channel' => 'whatsapp', 'title' => null, 'description' => 'عند توثيق العقد',
            'body' => "🎉 مبروك {name}!\nتم توثيق عقدك لطلب رقم {order} في منصة إيجار.\nنسعد بتقييمك للخدمة: {link}"],
        ['key' => 'data_missing', 'channel' => 'whatsapp', 'title' => null, 'description' => 'طلب بيانات ناقصة',
            'body' => "مرحباً {name}\nطلبك رقم {order} يحتاج استكمال بعض البيانات. أكمل المطلوب من هنا: {link}"],
        ['key' => 'refund', 'channel' => 'whatsapp', 'title' => null, 'description' => 'بعد استرجاع المبلغ',
            'body' => "مرحباً {name}\nتم استرجاع مبلغ {amount} ر.س لطلبك رقم {order}. يصل لحسابك خلال 3–14 يوم عمل حسب البنك."],
        ['key' => 'payment_reminder', 'channel' => 'whatsapp', 'title' => null, 'description' => 'تذكير بالدفع',
            'body' => "مرحباً {name}\nطلبك رقم {order} جاهز للدفع ({amount} ر.س). ادفع الآن لنبدأ إعداد مسودة عقدك: {link}"],

        // إشعارات التطبيق/الموقع — Push (تستخدمها خدمة الإشعارات عند وجودها مفعّلة)
        ['key' => 'status_under_review', 'channel' => 'push', 'title' => 'تحديث حالة طلبك', 'description' => 'الحالة: قيد المراجعة',
            'body' => 'تم استلام دفعتك — طلبك رقم {order} قيد المراجعة الآن'],
        ['key' => 'status_received_by_employee', 'channel' => 'push', 'title' => 'تحديث حالة طلبك', 'description' => 'الحالة: مستلم من الموظف',
            'body' => 'استلم موظفنا طلبك رقم {order} وبدأ العمل عليه'],
        ['key' => 'status_on_hold', 'channel' => 'push', 'title' => 'تحديث حالة طلبك', 'description' => 'الحالة: معلق',
            'body' => 'طلبك رقم {order} معلق — نحتاج استكمال بعض البيانات، تواصل معنا'],
        ['key' => 'status_cancelled', 'channel' => 'push', 'title' => 'تحديث حالة طلبك', 'description' => 'الحالة: ملغى',
            'body' => 'تم إلغاء طلبك رقم {order} — للاستفسار تواصل معنا'],
        ['key' => 'draft_sent', 'channel' => 'push', 'title' => 'وصلتك مسودة العقد', 'description' => 'إرسال المسودة',
            'body' => 'أرسلنا لك مسودة العقد عبر واتساب — اطّلع عليها وأكّد لنا لنوثّقه'],
        ['key' => 'notarized', 'channel' => 'push', 'title' => '🎉 تم توثيق عقدك', 'description' => 'التوثيق',
            'body' => '🎉 تم توثيق عقدك في إيجار — نسعد بتقييمك للخدمة'],
        ['key' => 'refund', 'channel' => 'push', 'title' => 'تم استرجاع المبلغ', 'description' => 'الاسترجاع',
            'body' => 'تم استرجاع {amount} ر.س من طلبك رقم {order} — يصل لحسابك خلال 3–14 يوم عمل حسب البنك'],

        // SMS
        ['key' => 'stage_notarized', 'channel' => 'sms', 'title' => null, 'description' => 'رسالة نصية عند التوثيق',
            'body' => 'عقد إيجار: تم توثيق عقدك لطلب {order}. تفاصيل: {link}'],
    ];
}
