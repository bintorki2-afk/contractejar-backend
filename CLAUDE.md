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
php artisan test              # الاختبارات (sqlite في الذاكرة)
php artisan schedule:work     # المجدول (الإشعارات الذكية كل 15 دقيقة + النسخ الاحتياطي اليومي) — يشغّله railway-start.sh في الإنتاج
php artisan notifications:dispatch --dry-run   # معاينة الإشعارات المجدولة
php artisan aqdi:db-backup    # نسخة احتياطية (انظر OWNER-GUIDE.md للاستعادة)
```
> دليل البدء للمطوّر الجديد: `كيف-تبدأ.md`

## البنية
- المسارات: `routes/api_v2.php` (الـ API الرئيسي v2)، `routes/web.php`، `routes/admin.php`.
- المتحكمات في `app/Http/Controllers/` — أبرزها:
  - العقود: `ContractController`, `ContractStatusController`, `ContractWhatsAppController`, `SettingContractController`
  - المالية: `FinanceController`, `OperatingExpenseController`, `CouponAdminController`
  - التسويق/المحتوى: `MarketingArticlesController`, `BlogController`, `SeoCrawlController`, `LocationAnalyticsController`
  - الموظفين: `EmployeeKpiController`, `UserController`, `RoleController`, `PermissionController`
  - التقارير: `ReportController`, `AppContentOverviewController`
- مساعدات مهمة في `app/Support/`: `ContractPricing`/`DocFee` (الأسعار)، `ContractJourney` (رحلة الطلب و قاعدة المسودة قبل التوثيق)، `SupportContact` (رقم الدعم)، `PublicCache` (كاش النقاط العامة)، `SmartLink`.
- الإشعارات: `app/Services/CustomerNotificationService.php` + الأمر `notifications:dispatch` (جدول `notification_dispatches` يمنع التكرار).
- قاعدة البيانات: جداول `contracts`, `bank_accounts`, `ad_spend_dailies`, `operating_expenses`, `coupons`, `blogs`, `google_seo_connections` وغيرها (انظر `database/migrations/`).

## النشر
**Railway** عبر Docker (`Dockerfile.railway`) — إعدادات في `railway.json`. فرع `master` ينشر تلقائياً.

## الإعدادات (.env — انسخ من .env.example)
`DB_*` (MySQL) · `APP_KEY` · `PAYMENTS_DRIVER=moyasar` + مفاتيح Moyasar · `MAIL_*` · Firebase. القيم الحقيقية في إعدادات Railway.
