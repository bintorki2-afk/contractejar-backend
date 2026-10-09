<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>فاتورة {{ $invoice['invoice_number'] ?? '' }} — {{ $invoice['platform_name'] }}</title>
<style>
  :root { --ink:#111827; --muted:#6B7280; --line:#E5E7EB; --brand:#0F766E; --bg:#F9FAFB; }
  * { box-sizing:border-box; }
  body { margin:0; font-family: "Tajawal","Segoe UI",Tahoma,Arial,sans-serif; color:var(--ink); background:var(--bg); }
  .sheet { max-width:760px; margin:24px auto; background:#fff; border:1px solid var(--line); border-radius:12px; padding:28px 32px; }
  header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; border-bottom:2px solid var(--brand); padding-bottom:14px; margin-bottom:18px; }
  h1 { font-size:22px; margin:0; color:var(--brand); }
  .sub { color:var(--muted); font-size:13px; margin-top:4px; }
  .meta { font-size:13px; line-height:1.9; text-align:left; }
  .status { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; color:#fff; background:{{ $invoice['status_color'] ?? '#6B7280' }}; }
  table { width:100%; border-collapse:collapse; margin-top:12px; font-size:14px; }
  th, td { padding:9px 8px; border-bottom:1px solid var(--line); text-align:right; }
  th { background:var(--bg); font-weight:700; font-size:13px; color:var(--muted); }
  td.num, th.num { text-align:left; font-variant-numeric:tabular-nums; white-space:nowrap; }
  tr.extra td { background:#FFFBEB; }
  tr.refund td { background:#FEF2F2; color:#991B1B; }
  .totals { margin-top:14px; margin-right:auto; width:320px; font-size:14px; }
  .totals div { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed var(--line); }
  .totals .net { font-weight:800; font-size:16px; border-bottom:0; border-top:2px solid var(--brand); margin-top:4px; padding-top:10px; }
  .tx { margin-top:22px; }
  .tx h2 { font-size:15px; margin:0 0 6px; }
  .tx table { font-size:13px; }
  footer { margin-top:24px; color:var(--muted); font-size:12px; display:flex; justify-content:space-between; }
  .print { margin:0 auto 24px; max-width:760px; display:flex; justify-content:flex-end; }
  .print button { background:var(--brand); color:#fff; border:0; border-radius:8px; padding:10px 18px; font-size:14px; cursor:pointer; }
  @media print { body{background:#fff} .sheet{border:0; margin:0; max-width:none} .print{display:none} }
</style>
</head>
<body>
<div class="print"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
<div class="sheet">
  <header>
    <div>
      <h1>{{ $invoice['platform_name'] }}</h1>
      <div class="sub">{{ $invoice['platform_subtitle'] }}</div>
      <div class="sub">الفاتورة {{ $invoice['invoice_number'] ?? '—' }} · الطلب {{ $invoice['order_number'] }}</div>
    </div>
    <div class="meta">
      <div><span class="status">{{ $invoice['status_label'] }}</span></div>
      <div>التاريخ: {{ $invoice['datetime_label'] }}</div>
      <div>المرجع: {{ $invoice['reference_number'] }}</div>
      @if(!empty($invoice['customer_name']))<div>العميل: {{ $invoice['customer_name'] }}</div>@endif
      @if(!empty($invoice['payment_method_label']))<div>طريقة الدفع: {{ $invoice['payment_method_label'] }}</div>@endif
    </div>
  </header>

  <table>
    <thead><tr><th>#</th><th>البند</th><th class="num">الكمية</th><th class="num">المبلغ</th></tr></thead>
    <tbody>
    @foreach($invoice['items'] as $item)
      <tr class="{{ ($item['kind'] ?? '') === 'refund' ? 'refund' : (in_array($item['kind'] ?? '', ['extra_fee','price_difference']) ? 'extra' : '') }}">
        <td>{{ $item['index'] }}</td>
        <td>{{ $item['description'] }}</td>
        <td class="num">{{ $item['quantity'] }}</td>
        <td class="num">{{ $item['amount_label'] }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div><span>المجموع الفرعي</span><span>{{ $invoice['subtotal_label'] }}</span></div>
    @if(($invoice['discount'] ?? 0) > 0)<div><span>الخصم{{ !empty($invoice['coupon_code']) ? ' ('.$invoice['coupon_code'].')' : '' }}</span><span>{{ $invoice['discount_label'] }}</span></div>@endif
    <div><span>ضريبة القيمة المضافة</span><span>{{ $invoice['vat_label'] }}</span></div>
    @if(!empty($invoice['is_cumulative']))
      <div><span>إجمالي الطلب الأصلي</span><span>{{ $invoice['original_total_label'] }}</span></div>
      @if(($invoice['extra_total'] ?? 0) > 0)<div><span>رسوم إضافية / فرق سعر</span><span>{{ $invoice['extra_total_label'] }}</span></div>@endif
      @if(($invoice['refunded_total'] ?? 0) > 0)<div><span>المسترجع</span><span>- {{ rtrim(rtrim(number_format($invoice['refunded_total'], 2, '.', ''), '0'), '.') }} ريال</span></div>@endif
    @endif
    <div class="net"><span>الصافي</span><span>{{ $invoice['total_amount_label'] }}</span></div>
  </div>

  @if(!empty($invoice['transactions']))
  <div class="tx">
    <h2>سجل الدفعات</h2>
    <table>
      <thead><tr><th>النوع</th><th>الطريقة</th><th>الحالة</th><th>التاريخ</th><th>المرجع</th><th class="num">المبلغ</th></tr></thead>
      <tbody>
      @foreach($invoice['transactions'] as $t)
        <tr class="{{ $t['kind'] === 'refund' ? 'refund' : '' }}">
          <td>{{ $t['kind_label'] }}</td>
          <td>{{ $t['method_label'] }}</td>
          <td>{{ $t['status_label'] }}</td>
          <td>{{ !empty($t['paid_at']) ? \Illuminate\Support\Carbon::parse($t['paid_at'])->timezone('Asia/Riyadh')->format('Y-m-d H:i') : '—' }}</td>
          <td>{{ $t['reference'] ?? '—' }}</td>
          <td class="num">{{ rtrim(rtrim(number_format($t['amount'], 2, '.', ''), '0'), '.') }} ريال</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
  @endif

  <footer>
    <span>شكراً لثقتكم — {{ $invoice['platform_name'] }}</span>
    <span>contractejar.com</span>
  </footer>
</div>
</body>
</html>
