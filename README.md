# contractejar-backend — الخلفية / API ⚙️

الواجهة الخلفية والـ API لـ **«عقد إيجار»** — يخدم الموقع ولوحة التحكم.
جزء من مشروع **صقر واحد**.

**التقنية:** Laravel 10 (PHP) + MySQL · بنية Modules · بوابة الدفع Moyasar
**الاستضافة:** Railway عبر Docker (`Dockerfile.railway`، ينشر تلقائياً من `master`)

## التشغيل محلياً
```bash
cp .env.example .env         # ثم عبّئ القيم
composer install
php artisan key:generate
php artisan migrate
php artisan serve            # http://localhost:8000
```

## فحص الصحة
`GET /api/v2/health` — يرجّع `ok` أو `degraded` (يُستخدم للمراقبة).

## 📚 التوثيق (ابدأ من هنا)
| الملف | المحتوى |
|-------|---------|
| [`AGENTS.md`](AGENTS.md) | دليل أي مساعد ذكي يعمل على المشروع |
| [`AQDI-CONTEXT.md`](AQDI-CONTEXT.md) | السياق الكامل للمشروع |
| [`OWNER-GUIDE.md`](OWNER-GUIDE.md) | دليل المالك وخطة الاستمرارية |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | المخطط المعماري |
| [`API-REFERENCE.md`](API-REFERENCE.md) | خريطة الـ API (573 endpoint · 16 module) |
| [`CLAUDE.md`](CLAUDE.md) | إرشادات تقنية لهذا الريبو |
| [`DEPLOY.md`](DEPLOY.md) | دليل النشر |
| [`سجل-العمل.md`](سجل-العمل.md) | سجل التغييرات الزمني |
