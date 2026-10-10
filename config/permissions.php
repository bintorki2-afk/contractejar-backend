<?php

return [
    'actions' => [
        'view' => ['ar' => 'عرض القسم', 'en' => 'View'],
        'create' => ['ar' => 'إضافة', 'en' => 'Create'],
        'edit' => ['ar' => 'تعديل', 'en' => 'Edit'],
        'delete' => ['ar' => 'حذف', 'en' => 'Delete'],
        'retrieve' => ['ar' => 'استرجاع', 'en' => 'Retrieve'],
        // دفعة (د) — ب8: استرجاع المبلغ عبر Moyasar (لقسم المدفوعات فقط — انظر section_only_actions).
        'refund' => ['ar' => 'استرجاع المدفوعات', 'en' => 'Refund payments'],
        // دفعة (هـ) — E2/E5 (لقسم المدفوعات فقط).
        'record_transfer' => ['ar' => 'تسجيل حوالة بنكية', 'en' => 'Record bank transfer'],
        'add_fee' => ['ar' => 'إضافة رسوم', 'en' => 'Add extra fee'],
    ],

    /**
     * Actions that exist only for the listed sections (not a column for every section).
     *
     * @var array<string, list<string>>
     */
    'section_only_actions' => [
        'refund' => ['payments'],
        'record_transfer' => ['payments'],
        'add_fee' => ['payments'],
    ],

    'sections' => [
        'analytics' => ['ar' => 'التحليلات', 'en' => 'Analytics'],
        'operating_expenses' => ['ar' => 'مصاريف تشغيلية', 'en' => 'Operating Expenses'],
        'finance_expenses' => ['ar' => 'مصاريف (قديم)', 'en' => 'Finance Expenses'],
        'all_requests' => ['ar' => 'جميع الطلبات', 'en' => 'All Requests'],
        'completed_request' => ['ar' => 'طلب مكتمل', 'en' => 'Completed Request'],
        'incomplete_request' => ['ar' => 'طلب غير مكتمل', 'en' => 'Incomplete Request'],
        'completed_whatsapp_request' => ['ar' => 'طلب واتساب مكتمل', 'en' => 'Completed WhatsApp Request'],
        'incomplete_whatsapp_request' => ['ar' => 'طلب واتساب غير مكتمل', 'en' => 'Incomplete WhatsApp Request'],
        'returned_request' => ['ar' => 'طلب مسترجع', 'en' => 'Returned Request'],
        'request_classification' => ['ar' => 'تصنيف الطلبات', 'en' => 'Request Classification'],
        'roles' => ['ar' => 'الأدوار', 'en' => 'Roles'],
        'permissions' => ['ar' => 'الصلاحيات', 'en' => 'Permissions'],
        'employees' => ['ar' => 'الموظفين', 'en' => 'Employees'],
        'employee_salaries' => ['ar' => 'رواتب الموظفين', 'en' => 'Employee Salaries'],
        'employee_kpis' => ['ar' => 'مؤشرات الموظفين', 'en' => 'Employee KPIs'],
        'users' => ['ar' => 'العملاء', 'en' => 'Users / Clients'],
        'notifications' => ['ar' => 'الإشعارات', 'en' => 'Push Notifications'],
        'payments' => ['ar' => 'المدفوعات', 'en' => 'Payments'],
        'contract_payments' => ['ar' => 'تحصيل الموظف', 'en' => 'Employee Contract Payments'],
        'regions' => ['ar' => 'المناطق', 'en' => 'Regions'],
        'cities' => ['ar' => 'المدن', 'en' => 'Cities'],
        'real_estates' => ['ar' => 'العقارات', 'en' => 'Real Estates'],
        'property_reference' => ['ar' => 'مرجع العقارات والوحدات', 'en' => 'Property & Unit Reference'],
        'tenant_roles' => ['ar' => 'صفات المستأجر', 'en' => 'Tenant Roles'],
        'contract_statuses' => ['ar' => 'حالات العقد', 'en' => 'Contract Statuses'],
        'draft_contract_statuses' => ['ar' => 'حالات المسودة', 'en' => 'Draft Contract Statuses'],
        'contract_periods' => ['ar' => 'مدة الطلب', 'en' => 'Order duration'],
        'contract_whatsapp' => ['ar' => 'طلبات واتساب', 'en' => 'Contract WhatsApp'],
        'lessor_change' => ['ar' => 'طلبات تغيير المؤجر', 'en' => 'Lessor change requests'],
        'instrument_settings' => ['ar' => 'إعدادات نوع الصك', 'en' => 'Instrument Type Settings'],
        'coupons' => ['ar' => 'الكوبونات', 'en' => 'Coupons'],
        'blogs' => ['ar' => 'المدونة', 'en' => 'Blogs'],
        'ads' => ['ar' => 'إعلانات التطبيق', 'en' => 'In-app ads'],
        'faqs' => ['ar' => 'الأسئلة الشائعة', 'en' => 'FAQs'],
        'paperworks' => ['ar' => 'المستندات', 'en' => 'Paperworks'],
        'popup_contracts' => ['ar' => 'نوافذ العقود', 'en' => 'Popup Contracts'],
        'instruction_sections' => ['ar' => 'الصور التعليمية', 'en' => 'Instruction images'],
        'message_alerts' => ['ar' => 'رسائل توضيحية', 'en' => 'Message Alerts'],
        'app_content' => ['ar' => 'محتوى التطبيق', 'en' => 'App Content'],
        'payment_messages' => ['ar' => 'رسائل الدفع', 'en' => 'Payment Messages'],
        'settings' => ['ar' => 'الاعدادات', 'en' => 'Settings'],
        'sms' => ['ar' => 'الرسائل النصية', 'en' => 'SMS'],
        'seo_crawl' => ['ar' => 'SEO (زحف وترتيب الكلمات)', 'en' => 'SEO crawl & keywords'],
        'website_images' => ['ar' => 'صور الموقع (SEO)', 'en' => 'Website images (SEO)'],
        // دفعة (و) — D7
        'customer_reviews' => ['ar' => 'تقييمات العملاء', 'en' => 'Customer reviews'],
    ],

    /**
     * Admin dashboard page/tab ids → catalog section the API actually gates.
     * Values are keys of `sections`. Do not invent a gate the matrix will never send.
     *
     * @var array<string, string>
     */
    'screens' => [
        'lessor-change' => 'lessor_change',
        'unit-types' => 'property_reference',
        'unit-usage' => 'property_reference',
        'property-types' => 'property_reference',
        'property-usage' => 'property_reference',

        'message-sections' => 'message_alerts',
        'message-section-items' => 'message_alerts',
        'message-for-employee' => 'message_alerts',
        'message-for-property' => 'message_alerts',
        'customer-app-messages' => 'app_content',

        'order-duration' => 'contract_periods',

        'seo-crawl' => 'seo_crawl',
        'seo-keywords' => 'seo_crawl',
        'seo-search-console' => 'seo_crawl',
        'website-images' => 'website_images',

        'terms' => 'app_content',
        'privacy' => 'app_content',
        'payment-types' => 'app_content',

        'meter-fees' => 'settings',

        'marketing-overview' => 'analytics',
        'marketing-campaigns' => 'analytics',
        'marketing-reports' => 'analytics',
        'marketing-tracking' => 'analytics',
        'marketing-keywords' => 'analytics',
        'marketing-pixels' => 'analytics',
        'marketing-content-articles' => 'blogs',
        'marketing-content-pages' => 'analytics',
        'marketing-content-reviews' => 'customer_reviews',

        'contract-statuses' => 'contract_statuses',
        'draft-contract-statuses' => 'draft_contract_statuses',
        'contract-whatsapp' => 'contract_whatsapp',

        'instruction-sections' => 'instruction_sections',
    ],

    /**
     * Frontend routes that must not get their own gate. Key is the extra route;
     * value is the canonical screen id (see `screens`).
     *
     * @var array<string, string>
     */
    'duplicate_screens' => [
        'message-for-clients' => 'customer-app-messages',
    ],

    /**
     * Role `name` values that skip the permission matrix (full access).
     * An employee whose role matches these has the entire dashboard even when
     * no rows exist in `role_permissions`. Only non-admin employees are limited
     * to the permissions assigned to their role.
     *
     * Compared after lowercasing and normalizing hyphens/spaces to underscores.
     *
     * @var list<string>
     */
    'full_access_roles' => [
        'admin',
        'super_admin',
        'superadmin',
        'super-admin',
        'administrator',
        'system_admin',
        'system-admin',
    ],

    /**
     * Role titles (AR/EN) that also skip the permission matrix.
     * Compared after trim + lowercase (English) / trim (Arabic).
     *
     * @var list<string>
     */
    'full_access_titles' => [
        'admin',
        'administrator',
        'system admin',
        'super admin',
        'superadmin',
        'أدمن',
        'ادمن',
        'الادمن',
        'الأدمن',
        'مدير النظام',
        'مدير عام',
    ],
];
