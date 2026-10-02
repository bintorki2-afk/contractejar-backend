# 🏗️ المخطط المعماري — صقر واحد (contractejar)

> كيف تتكامل مكوّنات المشروع والخدمات الخارجية. آخر تحديث: 2026-10-02

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

## ملاحظات
- **النشر تلقائي بالكامل:** `push` إلى `master` ← Vercel/Railway ينشران دون تدخّل.
- **الموقع** يتصل بالـ API مباشرة عبر `/api/v2`؛ **اللوحة** تمرّر عبر proxy داخلي (`API_PROXY_TARGET`).
- **قاعدة البيانات** مصدر الحقيقة لكل البيانات (عقود، عملاء، مالية) — احرص على نسخها الاحتياطي (راجع `OWNER-GUIDE.md`).
