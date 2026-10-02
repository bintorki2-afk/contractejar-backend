# 🔌 مرجع الـ API — contractejar-backend

> خريطة الـ API (Laravel 10). إجمالي ~573 endpoint موزّعة على 16 module.
> آخر تحديث: 2026-10-02

## البنية
الـ API مبني بنظام **Modules** — كل ميزة في مجلد مستقل تحت `app/Modules/<Name>/`.
ملفات المسارات داخل `app/Modules/<Name>/Routes/`:

| الملف | الغرض | الجمهور |
|-------|-------|---------|
| `api.php` | واجهة التطبيق/العملاء (v1) | تطبيق الجوال والعملاء |
| `api_v2.php` | واجهة الموقع الجديد | **الموقع (contractejar-frontend)** |
| `admin.php` | واجهة الإدارة | **لوحة التحكم (dashboard)** |

> نقطة فحص الصحة العامة (بدون مصادقة): `GET /api/v2/health` — ترجع `ok` أو `degraded`.

## الـ Modules وعدد الـ endpoints

| Module | الوظيفة | api_v2 | admin | api |
|--------|---------|:---:|:---:|:---:|
| **Contracts** | العقود (إنشاء، خطوات، حالات) | 22 | 67 | 14 |
| **Content** | محتوى الموقع (صفحات، أقسام) | 14 | 77 | 16 |
| **Catalog** | التصنيفات (مدن، مناطق، أنواع) | 15 | 53 | 11 |
| **RealEstate** | العقارات والوحدات | 18 | 6 | 18 |
| **Payments** | الدفع (Moyasar، روابط دفع) | 12 | 13 | 7 |
| **Analytics** | التحليلات والتقارير | 0 | 34 | 0 |
| **Employees** | الموظفين و KPIs | 0 | 30 | 0 |
| **Settings** | الإعدادات العامة | 8 | 20 | 2 |
| **Seo** | تحسين محركات البحث | 0 | 19 | 0 |
| **Users** | المستخدمين والأدوار | 6 | 16 | 6 |
| **Auth** | المصادقة والصلاحيات | 8 | 6 | 8 |
| **Payments/Finance** | المالية والمصاريف | 0 | 12 | 0 |
| **Marketing** | التسويق والمحتوى | 0 | 11 | 0 |
| **Notifications** | الإشعارات | 0 | 9 | 0 |
| **Coupons** | كوبونات الخصم | 3 | 7 | 3 |
| **Leads** | العملاء المحتملين | 1 | 1 | 0 |

## أمثلة — مسار إنشاء العقد (Contracts / api_v2)
```
POST /contract/start                      بدء عقد جديد
POST /contract/step1 ... /step6           خطوات تعبئة العقد
GET  /contract/check-uncompleted-contract فحص عقد غير مكتمل
GET  /contracts                           قائمة العقود
GET  /contracts/{id}                      تفاصيل عقد
GET  /contract/financial/{uuid}           ملخص مالي للعقد
GET  /contract/{id}/payment-link          رابط الدفع
```

## كيف تستكشف المزيد
- مسارات أي module: `app/Modules/<Name>/Routes/{api_v2,admin,api}.php`
- المنطق: `app/Modules/<Name>/` (Controllers / Services / Models)
- الجداول: `database/migrations/`

> ملاحظة: الأرقام تقريبية ومستخرجة آلياً من ملفات الـ Routes. المرجع الأدق هو ملفات المسارات نفسها.
