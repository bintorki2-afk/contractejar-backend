<?php

namespace App\Support;

/**
 * القوالب الافتراضية (دفعة د — ب16، دفعة هـ). المتغيرات: {order} {name} {link} {amount} {support} {items} {reason} {payment_url} {bank} {iban} {account_name}.
 */
final class MessageTemplateDefaults
{
    public const PLACEHOLDERS = [
        '{order}' => 'رقم الطلب',
        '{name}' => 'اسم العميل',
        '{link}' => 'رابط الطلب',
        '{amount}' => 'المبلغ',
        '{support}' => 'رقم الدعم',
        // دفعة (هـ)
        '{items}' => 'بنود المرفق الناقص',
        '{reason}' => 'سبب الرسوم (يراه العميل)',
        '{payment_url}' => 'رابط الدفع',
        '{bank}' => 'اسم البنك',
        '{iban}' => 'الآيبان',
        '{account_name}' => 'اسم صاحب الحساب',
    ];

    public const CHANNELS = ['whatsapp', 'sms', 'push'];

    /** @var list<array{key: string, channel: string, title: string|null, body: string, description: string}> */
    public const ROWS = [
        // مراحل الطلب — واتساب (ترجعها نقاط المراحل ب14 لتفتحها اللوحة في wa.me)
        ['key' => 'stage_received', 'channel' => 'whatsapp', 'title' => null, 'description' => 'عند استلام الموظف للطلب',
            'body' => "مرحباً {name} 👋\nاستلمنا طلبك رقم {order} في «عقد إيجار» ونعمل عليه الآن.\nتابع طلبك: {link}"],
        ['key' => 'stage_notarized', 'channel' => 'whatsapp', 'title' => null, 'description' => 'عند توثيق العقد',
            'body' => "🎉 مبروك {name}!\nتم توثيق عقدك لطلب رقم {order} في منصة إيجار.\nنسعد بتقييمك للخدمة: {link}"],
        ['key' => 'data_missing', 'channel' => 'whatsapp', 'title' => null, 'description' => 'طلب بيانات ناقصة',
            'body' => "مرحباً {name}\nطلبك رقم {order} يحتاج استكمال بعض البيانات. أكمل المطلوب من هنا: {link}"],
        ['key' => 'refund', 'channel' => 'whatsapp', 'title' => null, 'description' => 'بعد استرجاع المبلغ',
            'body' => "مرحباً {name}\nتم استرجاع مبلغ {amount} ر.س لطلبك رقم {order}. يصل لحسابك خلال 3–14 يوم عمل حسب البنك."],
        ['key' => 'payment_reminder', 'channel' => 'whatsapp', 'title' => null, 'description' => 'تذكير بالدفع',
            'body' => "مرحباً {name}\nطلبك رقم {order} جاهز للدفع ({amount} ر.س). ادفع الآن لنبدأ توثيق عقدك: {link}"],

        // دفعة (هـ) — E4: طلب مرفق ناقص/تصحيح (البنود تُدرج في {items})
        ['key' => 'data_request', 'channel' => 'whatsapp', 'title' => null, 'description' => 'طلب مرفق ناقص أو تصحيح بيانات',
            'body' => "مرحباً {name}\nبخصوص طلبك رقم {order} في «عقد إيجار»، نحتاج منك:\n{items}\nأرسلها من هذا الرابط مباشرة (بدون إعادة تعبئة الطلب): {link}"],
        ['key' => 'data_request_reminder', 'channel' => 'whatsapp', 'title' => null, 'description' => 'تذكير العميل بطلب المرفق الناقص',
            'body' => "تذكير 🔔 {name}\nما زلنا بانتظار:\n{items}\nلطلبك رقم {order}. أرسلها من هنا لنكمل التوثيق: {link}"],
        // دفعة (هـ) — E5: طلب دفع رسوم (فرق سعر / رسوم إضافية)
        ['key' => 'charge_payment_request', 'channel' => 'whatsapp', 'title' => null, 'description' => 'طلب دفع فرق سعر أو رسوم إضافية',
            'body' => "مرحباً {name}\nبخصوص طلبك رقم {order}: {reason}\nالمبلغ المطلوب: {amount} ر.س\nادفع من هذا الرابط: {payment_url}"],
        // دفعة (هـ) — E2: تعليمات الحوالة البنكية (الموظف يرسلها بنفسه)
        ['key' => 'bank_transfer_instructions', 'channel' => 'whatsapp', 'title' => null, 'description' => 'تعليمات الحوالة البنكية للعميل',
            'body' => "مرحباً {name}\nلإتمام طلبك رقم {order} حوّل مبلغ {amount} ر.س إلى:\nالبنك: {bank}\nالآيبان: {iban}\nباسم: {account_name}\nثم أرسل لنا صورة الإيصال هنا."],

        // إشعارات التطبيق/الموقع — Push (تستخدمها خدمة الإشعارات عند وجودها مفعّلة)
        ['key' => 'status_under_review', 'channel' => 'push', 'title' => 'تحديث حالة طلبك', 'description' => 'الحالة: قيد المراجعة',
            'body' => 'تم استلام دفعتك — طلبك رقم {order} قيد المراجعة الآن'],
        ['key' => 'status_received_by_employee', 'channel' => 'push', 'title' => 'تحديث حالة طلبك', 'description' => 'الحالة: مستلم من الموظف',
            'body' => 'استلم موظفنا طلبك رقم {order} وبدأ العمل عليه'],
        ['key' => 'status_on_hold', 'channel' => 'push', 'title' => 'تحديث حالة طلبك', 'description' => 'الحالة: معلق',
            'body' => 'طلبك رقم {order} معلق — نحتاج استكمال بعض البيانات، تواصل معنا'],
        ['key' => 'status_cancelled', 'channel' => 'push', 'title' => 'تحديث حالة طلبك', 'description' => 'الحالة: ملغى',
            'body' => 'تم إلغاء طلبك رقم {order} — للاستفسار تواصل معنا'],
        ['key' => 'notarized', 'channel' => 'push', 'title' => '🎉 تم توثيق عقدك', 'description' => 'التوثيق',
            'body' => '🎉 تم توثيق عقدك في إيجار — نسعد بتقييمك للخدمة'],
        ['key' => 'refund', 'channel' => 'push', 'title' => 'تم استرجاع المبلغ', 'description' => 'الاسترجاع',
            'body' => 'تم استرجاع {amount} ر.س من طلبك رقم {order} — يصل لحسابك خلال 3–14 يوم عمل حسب البنك'],

        // SMS
        ['key' => 'stage_notarized', 'channel' => 'sms', 'title' => null, 'description' => 'رسالة نصية عند التوثيق',
            'body' => 'عقد إيجار: تم توثيق عقدك لطلب {order}. تفاصيل: {link}'],
    ];
}
