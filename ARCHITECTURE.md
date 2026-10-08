# 🏗️ المخطط المعماري — صقر واحد (contractejar)

> كيف تتكامل مكوّنات المشروع والخدمات الخارجية. آخر تحديث: 2026-10-09

## نظرة عامة
```mermaid
flowchart TD
    subgraph Users[المستخدمون]
        C[العميل / الزائر]
        A[الموظف / الأدمن]
    end

    subgraph Vercel[Vercel]
        FE["الموقع<br/>contractejar-frontend<br/>Next.js 16"]
        DB_UI["لوحة التحكم<br/>contractejar-dashboard<br/>Next.js 15"]
    end

    subgraph Railway[Railway]
        API["الخلفية / API<br/>contractejar-backend<br/>Laravel 10"]
        DBMS[("قاعدة البيانات<br/>MySQL")]
    end

    subgraph External[خدمات خارجية]
        MOY[Moyasar<br/>الدفع]
        FB[Firebase<br/>الإشعارات]
        TG[Telegram<br/>تنبيه الطلبات]
    end

    C --> FE
    A --> DB_UI
    FE -->|"/api/v2"| API
    DB_UI -->|"proxy → /api"| API
    API --> DBMS
    API --> MOY
    API --> FB
    FE --> TG

    GH["GitHub (bintorki2-afk)<br/>master branch"] -.->|push ينشر تلقائياً| Vercel
    GH -.->|push ينشر تلقائياً| Railway
```

## تدفّق العمل الأساسي (إنشاء عقد)
```mermaid
sequenceDiagram
    participant C as العميل
    participant FE as الموقع (Frontend)
    participant API as الخلفية (API)
    participant MOY as Moyasar
    C->>FE: يملأ بيانات العقد (step1..step6)
    FE->>API: POST /contract/start ثم /step1..6
    API->>API: حفظ العقد (حالة: غير مكتمل)
    C->>FE: يتابع للدفع
    FE->>API: طلب رابط الدفع
    API->>MOY: إنشاء عملية دفع
    MOY-->>C: صفحة الدفع
    MOY-->>API: تأكيد الدفع (webhook)
    API->>API: تفعيل العقد + إشعار
```

## قاعدة ظهور الطلبات (دفعة د — ب5) — قاعدة واحدة في كل مكان
| المفهوم | الشرط | أين يظهر |
|---|---|---|
| **طلب** | `is_delete = 0` و `step ≥ 4` (أرسل العميل الصك + العنوان + المالك) — `Contract::scopeAdminListed()` / `reachedAdminOrderStep()` | قائمة «جميع الطلبات» وعدّاداتها (`/orders/status-counts`)، ملف العميل في اللوحة (`orders_count`, `contracts[]`)، قائمة طلبات العميل (`/api/v2/contracts`)، التقارير |
| **مسودة غير مكتملة** | `is_delete = 0` و `step < 4` و `is_completed = 0` — `Contract::scopeIncompleteDraft()` | تبويب «غير مكتمل» فقط (`/orders?tab=incomplete`, `incomplete` في العدّادات، `incomplete_drafts_count` في ملف العميل). لا تظهر للعميل |
| مدفوع / غير مدفوع | `is_completed = 1 / 0` ضمن «طلب» | `paid` / `unpaid` في العدّادات = `completed_orders_count` / `incomplete_orders_count` في ملف العميل |

## مسار حالة الطلب (دفعة د — ب2)
`new → paid → under_review → received_by_employee → whatsapp_draft → ejar_authenticated → completed` — جانبية: `cancelled`, `on_hold`, `refunded`.
- المفتاح الثابت في `contract_statuses.status_key`؛ الكود يبحث بالمفتاح (`ContractStatus::idFor()`) لا بالرقم.
- الدفع الناجح ⇒ «قيد المراجعة» تلقائياً؛ استلام الموظف ⇒ «مستلم من الموظف»؛ «مسترجع» حالة مستقلة (ليست «قيد المراجعة»).
- المنطق في `App\Services\Orders\OrderFlowService`.

## ملاحظات
- **النشر تلقائي بالكامل:** `push` إلى `master` ← Vercel/Railway ينشران دون تدخّل.
- **الموقع** يتصل بالـ API مباشرة عبر `/api/v2`؛ **اللوحة** تمرّر عبر proxy داخلي (`API_PROXY_TARGET`).
- **قاعدة البيانات** مصدر الحقيقة لكل البيانات (عقود، عملاء، مالية) — احرص على نسخها الاحتياطي (راجع `OWNER-GUIDE.md`).
