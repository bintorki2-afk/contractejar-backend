# 🔌 مرجع الـ API — contractejar-backend

> خريطة الـ API (Laravel 10). إجمالي ~573 endpoint موزّعة على 16 module.
> آخر تحديث: 2026-10-10

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
POST /api/v2/contract/track          { order, mobile } — 10 طلبات/دقيقة لكل IP — الرد يحوي journey (3 خطوات منذ دفعة هـ) + journey_sentence
```

### العميل (auth:sanctum)
```
GET  /api/v2/contracts/{id}          يحوي journey: [{step,key,label,description,done,current,at,by}] (3 خطوات منذ دفعة هـ)
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
أنواع الإشعارات (`kind`): `notarized` (+ data.ask_rating)، `payment_success`، `status_changed`، `lessor_change_status`،
`order_abandoned_24h`، `order_abandoned_3d`، `awaiting_payment_2h`، `renewal_60d`، `renewal_30d`، `offer`، `announcement`.
بيانات الـ push (FCM data): `kind`, `url` (الرابط الذكي `https://contractejar.com/r/{order}`), `contract_uuid`, `order_number`, `notification_id` (+ `type` للتوافق).

### لوحة التحكم (auth:sanctum + permission)
```
POST /api/admin/orders/{id}/status            { status_id, ... }  — 422 { code: payment_required|charge_pending, message, errors } عند محاولة «توثيق العقد في إيجار»/«مكتمل»
                                              قبل الدفع الكامل (دفعة هـ — حلّت محل قاعدة المسودة)؛ مدير النظام يتجاوز بـ force=1 (يُسجَّل).
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
ejar_authenticated, completed, cancelled, on_hold, refunded` (+ `paid` افتراضية = جديد مدفوع؛ `whatsapp_draft` بيانات قديمة فقط منذ دفعة هـ). لا تعتمد على أرقام الحالات.
الدفع ⇒ `under_review` تلقائياً؛ الاستلام ⇒ `received_by_employee`؛ «مسترجع» حالة مستقلة.

### لوحة التحكم (auth:sanctum + permission)
```
GET    /api/admin/orders?status_key=a,b|tab=all|incomplete|<key>   كل الحالات افتراضياً (طلب = الخطوة ≥ 4)
GET    /api/admin/orders/status-counts          all/paid/unpaid/incomplete/by_key/statuses/tabs (نفس فلاتر القائمة)
GET    /api/admin/orders/attention              «عليك الحين»: awaiting_receive/notarize/customer + delayed (الأقدم أولاً)
GET    /api/admin/orders/trash                  السلة (30 يوماً) · DELETE /api/admin/orders/{id} · POST /api/admin/orders/{id}/restore
PATCH  /api/admin/orders/{id}                   تعديل حقول صغيرة مع سجل قبل/بعد · GET /api/admin/orders/editable-fields
GET    /api/admin/orders/{id}/stages            المرحلة الحالية/التالية وحقولها
POST   /api/admin/orders/{id}/stage/{received|notarized}   + رسالة واتساب جاهزة (wa.me) — draft_sent ⇒ 410 منذ دفعة هـ
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

## نقاط دفعة (هـ) — 2026-10-10
> التفاصيل بالأمثلة الحقيقية: `docs/field-mapping.md` (أقسام دفعة هـ) و`OWNER-GUIDE.md` §4.2. **الخادم هو المصدر الوحيد** لحالة الدفع والأسعار والرحلة.

### الرحلة والمراحل (E3 — بلا مرحلة مسودة)
```
GET  /api/admin/orders/{id}/stages              current_stage: null|received|notarized · next_stage_locked + next_stage_lock_reason (payment_required|charge_pending)
                                                + journey (3 خطوات) + journey_side_state + payment_state + warnings[] (data_request_pending)
POST /api/admin/orders/{id}/stage/received      كما هو
POST /api/admin/orders/{id}/stage/notarized     {deed_number, deed_type} — 422 {code: payment_required|charge_pending} حتى الدفع الكامل (مدير النظام force=1)
POST /api/admin/orders/{id}/stage/draft_sent    410 — أُلغيت
```
`journey[]` = `{step, key: under_review|received_by_employee|ejar_authenticated, label, done, current, at, by}` · `journey_side_state` = `null | {key: cancelled|refunded, label, color, at}` (اللوحة + `/api/v2/contracts/{id}` + `/contract/track`).

### حالة الدفع (2.1) — في تفاصيل الطلب والعميل والتتبّع والفاتورة
```
payment_state   {status: unpaid|paid|partially_paid|partially_refunded|refunded, method: null|moyasar|bank_transfer|mixed,
                 due_total, original_due, extra_due, paid_total, outstanding, refunded_total, net_total, refund_due,
                 pending_charges_count/total, label «مدفوع · Moyasar · 279 ر.س», can_notarize, notarize_block_reason, notarize_block_message}
payment_details {lines[{key,label,amount,kind: fee|document|meter|discount|vat|extra_fee|price_difference|refund}],
                 transactions[{id, kind: original|price_difference|extra_fee|bank_transfer|refund, amount, method, status, paid_at, reference, employee, reason, receipt_url, charge_id}],
                 charges[], invoice_number, invoice_url (HTML موقّع 7 أيام), totals{original, extra, refunded, net, due, outstanding, refund_due}}
GET  /api/admin/orders/{id}/payment-state       نفس الكائنين
GET  /api/v2/invoices/print/{contract}          صفحة فاتورة قابلة للطباعة (رابط موقّع فقط)
GET  /api/v2/payments/{payment}/receipt         إيصال الحوالة (رابط موقّع فقط)
```

### الحوالة البنكية (2.2) — صلاحية payments.record_transfer
```
GET  /api/admin/orders/{id}/bank-transfer-message?amount=&charge_id=   رسالة القالب bank_transfer_instructions + wa.me + bank{...}
POST /api/admin/orders/{id}/payments/bank-transfer   multipart {amount, receipt ≤4MB, reference?, paid_at?, note?, charge_id?}
                                                     → {payment_state, transaction, payment_details, charge, contract}
GET/POST /api/admin/settings                         قسم bank_transfer {bank_name, bank_iban, bank_account_name, is_configured}
```

### الرسوم بعد الدفع (2.3)
```
GET  /api/admin/orders/{id}/charges                    items[{id, kind: price_difference|extra_fee, amount, message, status, payment_url, paid_at, created_by_name}] + payment_state
POST /api/admin/orders/{id}/charges                    {amount, message, internal_reason?} — صلاحية payments.add_fee (الرسالة تصل للعميل كما هي)
POST /api/admin/orders/{id}/charges/{cid}/payment-link {payment_url (Moyasar بمبلغ الرسم فقط، مفتاح chg-{uuid}-{id}), whatsapp_url, message}
POST /api/admin/orders/{id}/charges/{cid}/cancel
PATCH/POST /api/admin/orders/{id}                      تعديل يغيّر السعر (instrument_type, duration_*, total_months, *_meter_ownership) ⇒ price_difference {difference, refund_due, reason, charge}
POST /api/status/{chg-key}/success                     webhook/callback Moyasar ⇒ الرسم مدفوع + الفاتورة + الإشعارات + النشاط
GET  /api/v2/contracts/{uuid|id}/charges/{cid}/pay     رابط دفع رسم العميل المعلّق (توكن المالك/الزائر)
GET  /api/v2/contracts/{id} · /contract/track · /invoices/{id}   charges[] + فاتورة تراكمية (original_total, extra_total, refunded_total, net_total, is_cumulative, transactions)
GET  /api/admin/orders?attention=charge_pending|awaiting_customer|unpaid   فلاتر القائمة · الصفوف: payment_state, paid_original/paid_extra/refunded_total/net_total, awaiting_charge, data_request_pending
```

### طلب مرفق ناقص / تصحيح (2.4)
```
GET  /api/admin/data-requests/catalogue                sections[lessor|property|tenant].items[{key,label,step,fields}]
POST /api/admin/orders/{id}/data-requests              {section, items[keys], note?} → {request, whatsapp_url, message} (يستبدل المعلّق لنفس القسم؛ إشعار data_missing برابط ?fix=ID&step=N)
GET  /api/admin/orders/{id}/data-requests · POST …/{rid}/resolve · …/cancel · …/remind
POST /api/v2/contract/step1..6 (طلب مدفوع)            مسموح فقط للخطوات التي لها طلب معلّق؛ الرد يحوي fix{fix_mode, changed_fields, resolved_request_ids, pending_data_requests, message}
GET  /api/admin/orders/attention                       + awaiting_customer{count, items[]} + unpaid_received{...}؛ القواعد: paid_not_received(2h) · received_not_notarized(24h) · customer_no_reply_24h · customer_no_reply_72h
GET  /api/admin/employee-notifications?unread=1        إشعارات اللوحة للموظف (data_request_resolved, charge_paid) · POST …/{id}/read · …/read-all
```

### تفاصيل الطلب (2.6) — GET /api/admin/orders/{id}
`creator_mobile{local,dial,whatsapp_url}` · `customer_orders_summary{count_paid,count_unpaid,items[]}` · `address_entry_mode: map|manual|image` + `address{...}` · `document{type_key,type_label,deed_number,deed_date_hijri,deed_date_gregorian}` · `units[]` مهيكلة (`ac_count, furnished, meters[]`) · `ejar_entry_progress` (+ `PUT /api/admin/orders/{id}/ejar-entry-progress {section, done}`) · `attachments[]` · `charges[]` · `data_requests[]` · `payment_state/payment_details`.

### التقارير والتصدير (2.7)
```
GET  /api/admin/reports/overview|sales|performance     + extra_fees, price_differences, refunds, net_revenue (+ original_revenue, bank_transfers)
GET  /api/admin/employees/{id}/kpis                    + fees_added_count/amount, price_difference_count, data_requests_count, bank_transfers_recorded
GET  /api/admin/payments                               الصفوف تحمل kind/kind_label/charge_id/receipt_url
GET  /api/admin/orders/export?format=xlsx|csv          أعمدة: المدفوع الأصلي / إضافي / مسترجع / الصافي / طريقة الدفع (+ فلاتر القائمة)
reports:weekly-owner                                   سطور «رسوم إضافية · فروقات · استرجاعات» و«طلبات مرفق ناقص مفتوحة»
```
القوالب الجديدة: `data_request`, `data_request_reminder`, `charge_payment_request`, `bank_transfer_instructions` (حُذف `draft_sent`/`stage_draft_sent`). الصلاحيات الجديدة: `payments.record_transfer`, `payments.add_fee`.

### متابعة ملاحظات اللوحة/الموقع/التطبيق (2026-10-10)
```
POST /api/v2/contract/uncompleted-contract {uuid}     طلب مدفوع له طلب مرفق ناقص معلّق ⇒ 200 «وضع التصحيح»:
                                                     {step, contract_id, uuid, fix_mode: true, pending_data_requests[], editable_steps[], step1..step6 (كل الخطوات المطبّقة)}
                                                     مكتمل بلا طلب معلّق ⇒ 400 كما كان · غير مكتمل ⇒ كما كان (fix_mode: false، الخطوات السابقة فقط)
GET  /api/v2/contracts/{id}                           + حقول الخطوات 1/2/3/4 للتعبئة المسبقة (بلا أسماء): instrument_number, instrument_history, type_instrument_history,
                                                     property_type_id, property_usages_id, number_of_floors, العنوان (neighborhood, street, building_number, postal_code, extra_figure, property_city_id…),
                                                     property_owner_dob (+ _day/_month/_year), type_dob_property_owner, property_owner_mobile, الوكيل…, tenant_dob (+ أجزاؤه), type_tenant_dob, tenant_mobile, tenant_entity…
GET  /api/v2/payment/result/{chg-uuid-id}             مفتاح رسم ⇒ kind: charge + charge{id, kind, amount, message, status} + contract_id/payment/is_completed للطلب الأصل (لصاحب الطلب أو عودة بوابة موثّقة؛ غيرهم: paid/kind فقط)
GET  /api/admin/employees/{id}/kpis                   revenue = «إيراد التوثيق» (دفعات الطلبات المستلمة المنجزة فقط) · revenue_total = «الإجمالي (توثيق + رسوم + حوالات)»
                                                     {key: revenue_total_sar, value, parts[{notarization|fees|bank_transfers}]} · في metrics[] المفتاحان revenue_sar و revenue_total_sar
GET  /api/admin/employees/kpis                        summary + revenue_total_sar_total + revenue_labels
```
- Push (FCM): كل قيم `data` نصوص — المصفوفات تُرمَّز JSON (مثل `items` في `data_missing`)، المنطقي `1/0`.
- قالب `payment_reminder`: «… لنبدأ توثيق عقدك» (ترحيل يحدّث النص الافتراضي القديم فقط إن لم يعدّله المالك).

## كيف تستكشف المزيد
- مسارات أي module: `app/Modules/<Name>/Routes/{api_v2,admin,api}.php`
- المنطق: `app/Modules/<Name>/` (Controllers / Services / Models)
- الجداول: `database/migrations/`

> ملاحظة: الأرقام تقريبية ومستخرجة آلياً من ملفات الـ Routes. المرجع الأدق هو ملفات المسارات نفسها.
