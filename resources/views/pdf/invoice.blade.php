@php
    $fmt = static fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').' ر.س';
    $isRefundRow = static fn ($item) => ($item['kind'] ?? '') === 'refund';
    $isExtraRow = static fn ($item) => in_array($item['kind'] ?? '', ['extra_fee', 'price_difference'], true);
    $serviceLabel = match ((string) ($invoice['contract_type'] ?? '')) {
        'housing' => 'عقد إيجار سكني',
        'commercial' => 'عقد إيجار تجاري',
        'lessor_change' => 'تغيير المؤجر',
        default => (string) ($invoice['contract_type_label'] ?? ''),
    };
@endphp
<html dir="rtl" lang="ar">
<head>
<meta charset="utf-8">
<style>
    body { font-family: xbriyaz; color: #111827; font-size: 11pt; direction: rtl; }
    .brand { color: #0d5a50; }
    .muted { color: #6B7280; font-size: 9.5pt; }
    table { border-collapse: collapse; width: 100%; }
    .head td { vertical-align: top; }
    .title { font-size: 22pt; font-weight: bold; color: #0d5a50; }
    .platform { font-size: 15pt; font-weight: bold; color: #0d5a50; }
    .badge { background-color: {{ $invoice['status_color'] ?? '#6B7280' }}; color: #ffffff; padding: 3px 12px; font-size: 10.5pt; }
    .rule { border-bottom: 2px solid #0db38b; height: 4px; }
    .meta td { padding: 3px 0; font-size: 10pt; }
    .meta .k { color: #6B7280; width: 32%; }
    .items th { background-color: #F0FDF8; color: #0d5a50; font-size: 10pt; padding: 7px 6px; border-bottom: 1px solid #0db38b; text-align: right; }
    .items td { padding: 7px 6px; border-bottom: 1px solid #E5E7EB; text-align: right; }
    .items .num { text-align: left; white-space: nowrap; }
    .items tr.extra td { background-color: #FFFBEB; }
    .items tr.refund td { background-color: #FEF2F2; color: #991B1B; }
    .totals td { padding: 5px 6px; font-size: 10.5pt; border-bottom: 1px dashed #E5E7EB; }
    .totals .num { text-align: left; white-space: nowrap; }
    .totals .net td { font-weight: bold; font-size: 13pt; color: #0d5a50; border-top: 2px solid #0d5a50; border-bottom: 0; padding-top: 8px; }
    .section { font-size: 11.5pt; font-weight: bold; color: #0d5a50; margin: 16px 0 6px 0; }
    .tx th { background-color: #F9FAFB; color: #6B7280; font-size: 9pt; padding: 5px; text-align: right; border-bottom: 1px solid #E5E7EB; }
    .tx td { font-size: 9pt; padding: 5px; border-bottom: 1px solid #F3F4F6; text-align: right; }
    .tx .num { text-align: left; white-space: nowrap; }
    .foot { margin-top: 22px; font-size: 9pt; color: #6B7280; text-align: center; }
</style>
</head>
<body>

<table class="head">
    <tr>
        <td style="width: 60%;">
            <table>
                <tr>
                    @if($logo)
                        <td style="width: 58px;"><img src="{{ $logo }}" style="width: 52px; height: 52px;"></td>
                    @endif
                    <td>
                        <div class="platform">{{ $invoice['platform_name'] ?? 'عقدي' }}</div>
                        <div class="muted">{{ $invoice['platform_subtitle'] ?? '' }}</div>
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 40%; text-align: left;">
            <div class="title">فاتورة</div>
            <div><span class="badge">{{ $invoice['status_label'] ?? '' }}</span></div>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table class="meta" style="margin-top: 10px;">
    <tr>
        <td style="width: 50%; vertical-align: top;">
            <table>
                <tr><td class="k">رقم الفاتورة</td><td><bdo dir="ltr">{{ $invoice['invoice_number'] ?? '—' }}</bdo></td></tr>
                <tr><td class="k">رقم الطلب</td><td>{{ $invoice['order_number'] ?? '—' }}</td></tr>
                @if($serviceLabel !== '')
                    <tr><td class="k">الخدمة</td><td>{{ $serviceLabel }}</td></tr>
                @endif
                <tr><td class="k">التاريخ</td><td>{{ $invoice['datetime_label'] ?? '' }}</td></tr>
            </table>
        </td>
        <td style="width: 50%; vertical-align: top;">
            <table>
                @if(!empty($invoice['customer_name']))
                    <tr><td class="k">العميل</td><td>{{ $invoice['customer_name'] }}</td></tr>
                @endif
                @if(!empty($invoice['customer_phone']))
                    <tr><td class="k">الجوال</td><td><bdo dir="ltr">{{ $invoice['customer_phone'] }}</bdo></td></tr>
                @endif
                @if(!empty($invoice['payment_method_label']))
                    <tr><td class="k">طريقة الدفع</td><td>{{ $invoice['payment_method_label'] }}</td></tr>
                @endif
                <tr><td class="k">المرجع</td><td>{{ $invoice['reference_number'] ?? '—' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="items" style="margin-top: 14px;">
    <thead>
        <tr>
            <th style="width: 8%;">#</th>
            <th>البند</th>
            <th class="num" style="width: 10%;">الكمية</th>
            <th class="num" style="width: 22%;">المبلغ</th>
        </tr>
    </thead>
    <tbody>
    @foreach(($invoice['items'] ?? []) as $item)
        <tr class="{{ $isRefundRow($item) ? 'refund' : ($isExtraRow($item) ? 'extra' : '') }}">
            <td>{{ $item['index'] ?? '' }}</td>
            <td>{{ $item['description'] ?? '' }}</td>
            <td class="num">{{ $item['quantity'] ?? 1 }}</td>
            <td class="num">{{ $fmt($item['amount'] ?? 0) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table style="margin-top: 12px;">
    <tr>
        <td style="width: 45%;"></td>
        <td style="width: 55%;">
            <table class="totals">
                <tr><td>المجموع الفرعي</td><td class="num">{{ $fmt($invoice['subtotal'] ?? 0) }}</td></tr>
                @if(($invoice['discount'] ?? 0) > 0)
                    <tr><td>الخصم{{ !empty($invoice['coupon_code']) ? ' ('.$invoice['coupon_code'].')' : '' }}</td><td class="num">- {{ $fmt($invoice['discount']) }}</td></tr>
                @endif
                <tr><td>ضريبة القيمة المضافة</td><td class="num">{{ $invoice['vat_label'] ?? '—' }}</td></tr>
                @if(!empty($invoice['is_cumulative']))
                    <tr><td>إجمالي الطلب الأصلي</td><td class="num">{{ $fmt($invoice['original_total'] ?? 0) }}</td></tr>
                    @if(($invoice['extra_total'] ?? 0) > 0)
                        <tr><td>رسوم إضافية / فرق سعر</td><td class="num">{{ $fmt($invoice['extra_total']) }}</td></tr>
                    @endif
                    @if(($invoice['refunded_total'] ?? 0) > 0)
                        <tr><td>المسترجع</td><td class="num">- {{ $fmt($invoice['refunded_total']) }}</td></tr>
                    @endif
                @endif
                <tr class="net"><td>{{ !empty($invoice['is_cumulative']) ? 'الصافي' : 'الإجمالي' }}</td><td class="num">{{ $fmt($invoice['total_amount'] ?? 0) }}</td></tr>
                @if(!empty($invoice['has_outstanding']))
                    <tr><td>المتبقي للدفع</td><td class="num">{{ $fmt($invoice['outstanding'] ?? 0) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if(!empty($invoice['transactions']))
    <div class="section">سجل الدفعات</div>
    <table class="tx">
        <thead>
            <tr><th>النوع</th><th>الطريقة</th><th>الحالة</th><th>التاريخ</th><th class="num">المبلغ</th></tr>
        </thead>
        <tbody>
        @foreach($invoice['transactions'] as $t)
            <tr>
                <td>{{ $t['kind_label'] ?? '' }}</td>
                <td>{{ $t['method_label'] ?? '' }}</td>
                <td>{{ $t['status_label'] ?? '' }}</td>
                <td><bdo dir="ltr">{{ !empty($t['paid_at']) ? \Illuminate\Support\Carbon::parse($t['paid_at'])->timezone('Asia/Riyadh')->format('Y-m-d H:i') : '—' }}</bdo></td>
                <td class="num">{{ $fmt($t['amount'] ?? 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<div class="foot">
    شكراً لثقتكم — {{ $invoice['platform_name'] ?? 'عقدي' }} · {{ $invoice['platform_subtitle'] ?? '' }}<br>
    مؤسسة عقدي العقارية · هذه فاتورة صادرة إلكترونياً
</div>

</body>
</html>
