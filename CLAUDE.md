# CLAUDE.md — contractejar-backend (الخلفية/API)

> 📌 سياق المشروع الكامل (أعمال + حسابات + نشر): اقرأ **@AQDI-CONTEXT.md**

## نبذة تقنية
الواجهة الخلفية والـ API لـ «عقد إيجار» — **Laravel 10 (PHP)** + قاعدة بيانات **MySQL**.
المنطقة الزمنية Asia/Riyadh، اللغة عربية. بوابة الدفع **Moyasar**.

## الأوامر
```bash
composer install
php artisan key:generate      # عند أول إعداد
php artisan migrate           # تهيئة قاعدة البيانات
php artisan serve             # تشغيل محلي
```

## البنية
- المسارات: `routes/api_v2.php` (الـ API الرئيسي v2)، `routes/web.php`، `routes/admin.php`.
- المتحكمات في `app/Http/Controllers/` — أبرزها:
  - العقود: `ContractController`, `ContractStatusController`, `ContractWhatsAppController`, `SettingContractController`
  - المالية: `FinanceController`, `OperatingExpenseController`, `CouponAdminController`
  - التسويق/المحتوى: `MarketingArticlesController`, `BlogController`, `SeoCrawlController`, `LocationAnalyticsController`
  - الموظفين: `EmployeeKpiController`, `UserController`, `RoleController`, `PermissionController`
  - التقارير: `ReportController`, `AppContentOverviewController`
- قاعدة البيانات: جداول `contracts`, `bank_accounts`, `ad_spend_dailies`, `operating_expenses`, `coupons`, `blogs`, `google_seo_connections` وغيرها (انظر `database/migrations/`).

## النشر
**Railway** عبر Docker (`Dockerfile.railway`) — إعدادات في `railway.json`. فرع `master` ينشر تلقائياً.

## الإعدادات (.env — انسخ من .env.example)
`DB_*` (MySQL) · `APP_KEY` · `PAYMENTS_DRIVER=moyasar` + مفاتيح Moyasar · `MAIL_*` · Firebase. القيم الحقيقية في إعدادات Railway.
