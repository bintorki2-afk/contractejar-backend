# 🔌 مرجع الـ API — contractejar-backend

> خريطة الـ API (Laravel 10). إجمالي ~573 endpoint موزّعة على 16 module.
> آخر تحديث: 2026-10-09

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

## نقاط دفعة الإصلاحات (ب) — 2026-10-08

### عامة (بدون مصادقة) — مع كاش 10 دقائق و`Cache-Control: public, max-age=300`
```
GET  /api/v2/pricing                 الأسعار (المصدر الوحيد) — يُفرَّغ الكاش عند حفظ الإعدادات
GET  /api/v2/settings                الإعدادات: whatsapp / whatsapp_contact / support_phone (دولي، أرقام فقط — احتياطياً 966597500014)
                                     + support_phone_local + support_whatsapp_url + social{instagram,twitter,snapchat,facebook,tiktok,linkedin}
GET  /api/v2/contract-periods?contract_type=housing|commercial
GET  /api/v2/coupons/available       { available: bool }
GET  /api/v2/app/version             { ios:{min_version,latest_version,store_url,force_update}, android:{...}, force_update_message }
GET  /api/v2/health                  { status, time, db:'ok'|'error', scheduler_last_run, scheduler_stale, reference_data_ok } (503 عند الخلل)
                                     — لا يكشف أسماء الجداول/أعدادها (تبقى في السجلّات فقط).
POST /api/v2/contract/track          { order, mobile } — 10 طلبات/دقيقة لكل IP — الرد يحوي journey (6 خطوات) + journey_sentence
```

### العميل (auth:sanctum)
```
GET  /api/v2/contracts/{id}          يحوي journey: [{step,key,label,description,done,current,at}] (6 خطوات ثابتة)
                                     + status_timeline (سجل الحالات الفعلي كما كان)
GET  /api/v2/invoices                فواتير العقود + طلبات تغيير المؤجر (kind: contract | lessor_change) — مرقّمة
GET  /api/v2/invoices/{contractId}   | /contracts/{contractId}/invoice | /invoices/number/{INV-..}
GET  /api/v2/lessor-change/{uuid}/invoice
     شكل الفاتورة: items[{index,key,description,quantity,amount,amount_label,is_discount}], subtotal(+_label),
     discount(+_label), coupon_code, vat(+_label «مجانًا» عند 0), total_amount(+_label), amount_mismatch, computed_total,
     invoice_number, order_number (#رقم الطلب), status/status_label, kind.
GET  /api/v2/notifications?per_page=15&keep_unread=1
     عناصر: id, title, body, kind, url, is_read, read_at, contract_id, contract_uuid, order_number, smart_link, data, created_at
     بدون keep_unread=1 تُعلَّم الصفحة المعروضة مقروءة بعد الرد (سلوك التطبيق القديم).
GET  /api/v2/notifications/unread-count          { unread_count }
POST /api/v2/notifications/{id}/read             { id, is_read, read_at, unread_count }
POST /api/v2/notifications/read-all              { updated, unread_count }
```
أنواع الإشعارات (`kind`): `draft_sent`، `notarized` (+ data.ask_rating)، `payment_success`، `status_changed`، `lessor_change_status`،
`order_abandoned_24h`، `order_abandoned_3d`، `awaiting_payment_2h`، `renewal_60d`، `renewal_30d`، `offer`، `announcement`.
بيانات الـ push (FCM data): `kind`, `url` (الرابط الذكي `https://contractejar.com/r/{order}`), `contract_uuid`, `order_number`, `notification_id` (+ `type` للتوافق).

### لوحة التحكم (auth:sanctum + permission)
```
POST /api/admin/orders/{id}/status            { status_id, ... }  — 422 { message, errors } عند محاولة «توثيق العقد في إيجار»/«مكتمل»
                                              قبل حالة «إرسال مسودة العقد عبر واتساب»؛ مدير النظام يتجاوز بـ force=1 (يُسجَّل).
                                              الانتقال إلى المسودة يتطلب: ejar_contract_draft_number + contact_number_mode=same|another (+ contact_number)
                                              الانتقال إلى التوثيق يتطلب: deed_number + deed_type=paper|electronic|other
GET  /api/admin/orders/{id}                   قسم invoice بنفس شكل فاتورة العميل (items/subtotal/discount/vat/total)
POST /api/admin/notifications/{user|all-users|send}   + kind: offer|announcement (افتراضي offer) + url اختياري — يُخزَّن في صندوق العميل
GET  /api/admin/notification-dispatches?kind=&date=&from=&to=&user_id=&contract_id=&search=&per_page=   (permission: notifications.view)
     items[{id,kind,kind_label,title,body,url,push_result,recipients_count,is_broadcast,user,contract_id,order_number,sent_at,created_at}]
     + kinds[] + last_run + pagination
GET/POST /api/admin/settings                  قسم app_version (app_ios_min_version, app_ios_latest_version, app_ios_store_url,
                                              app_android_*, app_force_update_message) + قسم support — وحقل whatsapp_contact يُطبَّع دولياً
```

### أوامر مجدولة (schedule:work — يشغّله railway-start.sh)
```
notifications:dispatch     كل 15 دقيقة — الإشعارات الذكية (مرة لكل نوع لكل طلب)
aqdi:db-backup             يومياً 03:10 الرياض — نسخة احتياطية (آخر 7 + رفع اختياري إلى R2)
aqdi:db-restore {file}     يدوي — الاستعادة (خارج الإنتاج أو --force)
```

## نقاط دفعة (د) — 2026-10-09
> التفاصيل الكاملة بالأمثلة: `docs/field-mapping.md` (خريطة الحقول) — وفي هذا القسم ملخص.

### حالات الطلب (ب2)
كل صف في `contract_statuses` يحمل `status_key` ثابتاً: `new, under_review, received, received_by_employee, whatsapp_draft,
ejar_authenticated, completed, cancelled, on_hold, refunded` (+ `paid` افتراضية = جديد مدفوع). لا تعتمد على أرقام الحالات.
الدفع ⇒ `under_review` تلقائياً؛ الاستلام ⇒ `received_by_employee`؛ «مسترجع» حالة مستقلة.

### لوحة التحكم (auth:sanctum + permission)
```
GET    /api/admin/orders?status_key=a,b|tab=all|incomplete|<key>   كل الحالات افتراضياً (طلب = الخطوة ≥ 4)
GET    /api/admin/orders/status-counts          all/paid/unpaid/incomplete/by_key/statuses/tabs (نفس فلاتر القائمة)
GET    /api/admin/orders/attention              «عليك الحين»: awaiting_receive/draft/notarize + delayed (الأقدم أولاً)
GET    /api/admin/orders/trash                  السلة (30 يوماً) · DELETE /api/admin/orders/{id} · POST /api/admin/orders/{id}/restore
PATCH  /api/admin/orders/{id}                   تعديل حقول صغيرة مع سجل قبل/بعد · GET /api/admin/orders/editable-fields
GET    /api/admin/orders/{id}/stages            المرحلة الحالية/التالية وحقولها
POST   /api/admin/orders/{id}/stage/{received|draft_sent|notarized}   + رسالة واتساب جاهزة (wa.me)
GET    /api/admin/orders/{id}/ejar-copy[?format=text]               كتل بيانات إيجار بالترتيب (هجري + ميلادي)
POST   /api/admin/orders/{id}/notify            { kind: data_missing|status_changed, message?, step? }
GET    /api/admin/orders/{id}                   + activities[] · notifications_sent[] · applied_discount · payments[] · refunds[] · delay_flags[] · status_key
POST   /api/admin/payments/{payment}/refund     { amount?, reason } — Moyasar (permission: payments.refund)
GET    /api/admin/payments/refunds              قائمة الاسترجاعات + ملخص
GET    /api/admin/reports/overview?range=today|week|month|year|all   6 أرقام
GET|POST /api/admin/message-templates (+ /{id}, /{id}/delete, /preview)   قوالب واتساب/SMS/Push
POST   /api/admin/notifications/broadcast/preview   عدد مستلمي الشريحة · all-users يقبل segment/city_id/coupon_code/valid_until
GET|POST /api/admin/settings                    + قسم auto_assign (auto_assign_orders, auto_assign_strategy, auto_assign_employee_ids)
DELETE /api/admin/lessor-change/{id} · GET /api/admin/lessor-change/trash · POST /api/admin/lessor-change/{id}/restore
```

### عامة / العميل
```
GET  /api/v2/status                 صفحة الحالة: api/db/scheduler/payments (فحص البوابة مخزّن 5 دقائق)
GET  /api/v2/contracts/{id}         + activities[] (نسخة آمنة)    ·   POST /api/v2/contract/track  + activities[]
GET  /api/v2/pricing                meter_transfer_fee.per_meter = true · commercial.extra_year = 450 (من الإعدادات)
```
أنواع إشعارات جديدة: `assigned`, `data_missing` (data.step, data.deep_link), `refund` (data.amount, data.full), `discount_applied`;
و`offer`/`announcement` قد تحمل `coupon_code` + `valid_until`.

### أوامر مجدولة جديدة
```
orders:flag-delays     كل 15 دقيقة — علامات التأخير (2/24/72 ساعة) + إشعار الموظفين بالجديد
trash:purge            يومياً 04:00 — حذف نهائي لما مضى عليه 30 يوماً في السلة
qa:daily-smoke         يومياً 06:00 — فحص اصطناعي لمسار العميل + تيليجرام (✅/❌)
reports:weekly-owner   الأحد 09:00 — تقرير المالك الأسبوعي عبر تيليجرام
docs:field-mapping     يدوي — يولّد docs/field-mapping.md
```

## كيف تستكشف المزيد
- مسارات أي module: `app/Modules/<Name>/Routes/{api_v2,admin,api}.php`
- المنطق: `app/Modules/<Name>/` (Controllers / Services / Models)
- الجداول: `database/migrations/`

> ملاحظة: الأرقام تقريبية ومستخرجة آلياً من ملفات الـ Routes. المرجع الأدق هو ملفات المسارات نفسها.
