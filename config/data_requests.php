<?php

/*
|--------------------------------------------------------------------------
| دفعة (هـ) — E4: كتالوج طلب «مرفق ناقص / تصحيح بيانات»
|--------------------------------------------------------------------------
| لكل قسم في صفحة الطلب (المؤجر / العقار والعنوان / المستأجر) قائمة بنود دقيقة.
| لكل بند: key ثابت، label يراه الموظف والعميل، step = خطوة المعالج التي يُفتح عليها العميل،
| fields = أعمدة العقد / مفاتيح المرفقات التي يحلّ تغيّرُ أيٍّ منها الطلبَ تلقائياً.
|
| يُعرض للّوحة عبر GET /api/admin/data-requests/catalogue.
*/

return [
    'sections' => [
        'lessor' => [
            'label' => 'المؤجر',
            'items' => [
                ['key' => 'owner_id_unclear', 'label' => 'هوية الناظر/المالك غير واضحة', 'step' => 3, 'fields' => ['property_owner_id_num', 'name_owner', 'copy_of_the_trusteeship_deed']],
                ['key' => 'owner_id_number', 'label' => 'رقم الهوية خطأ', 'step' => 3, 'fields' => ['property_owner_id_num']],
                ['key' => 'owner_dob', 'label' => 'تاريخ الميلاد', 'step' => 3, 'fields' => ['property_owner_dob', 'type_dob_property_owner']],
                ['key' => 'owner_mobile', 'label' => 'الجوال', 'step' => 3, 'fields' => ['property_owner_mobile']],
                ['key' => 'endowment_certificate', 'label' => 'شهادة الوقف', 'step' => 1, 'fields' => ['copy_of_the_endowment_registration_certificate']],
                ['key' => 'trusteeship_deed', 'label' => 'صك النظارة', 'step' => 1, 'fields' => ['copy_of_the_trusteeship_deed']],
                ['key' => 'agency', 'label' => 'الوكالة', 'step' => 3, 'fields' => ['copy_of_the_authorization_or_agency', 'copy_power_of_attorney_from_heirs_to_agent', 'copy_of_guardians_power_of_attorney_for_agent', 'agency_number_in_instrument_of_property_owner', 'agency_instrument_date_of_property_owner', 'id_num_of_property_owner_agent']],
            ],
        ],
        'property' => [
            'label' => 'العقار والعنوان',
            'items' => [
                ['key' => 'deed_image_unclear', 'label' => 'صورة الصك غير واضحة', 'step' => 1, 'fields' => ['image_instrument', 'image_instrument_pages', 'image_instrument_from_the_front', 'image_instrument_from_the_back']],
                ['key' => 'deed_number', 'label' => 'رقم الصك', 'step' => 1, 'fields' => ['instrument_number']],
                ['key' => 'deed_date', 'label' => 'تاريخ الصك', 'step' => 1, 'fields' => ['instrument_history', 'type_instrument_history']],
                ['key' => 'document_type', 'label' => 'نوع المستند', 'step' => 1, 'fields' => ['instrument_type']],
                ['key' => 'address_image', 'label' => 'صورة العنوان الوطني', 'step' => 2, 'fields' => ['image_address']],
                ['key' => 'address_mismatch', 'label' => 'العنوان غير مطابق', 'step' => 2, 'fields' => ['property_place_id', 'property_city_id', 'neighborhood', 'street', 'building_number', 'postal_code', 'extra_figure', 'address_url', 'latitude', 'longitude', 'image_address']],
            ],
        ],
        'tenant' => [
            'label' => 'المستأجر',
            'items' => [
                ['key' => 'tenant_id_unclear', 'label' => 'هوية المستأجر غير واضحة', 'step' => 4, 'fields' => ['tenant_id_num', 'copy_of_the_owner_record', 'tenant_entity_unified_registry_number']],
                ['key' => 'tenant_id_number', 'label' => 'رقم الهوية', 'step' => 4, 'fields' => ['tenant_id_num']],
                ['key' => 'tenant_dob', 'label' => 'تاريخ الميلاد', 'step' => 4, 'fields' => ['tenant_dob', 'type_tenant_dob']],
                ['key' => 'tenant_mobile', 'label' => 'الجوال', 'step' => 4, 'fields' => ['tenant_mobile']],
            ],
        ],
    ],

    /** بعد كم ساعة بلا رد يُعرض الطلب في «عليك الحين» ويُعلَّم customer_no_reply_24h. */
    'reminder_after_hours' => 24,

    /** بعد كم ساعة بلا رد يُنبَّه المالك عبر تيليجرام (مرة واحدة لكل طلب). */
    'owner_alert_after_hours' => 72,
];
