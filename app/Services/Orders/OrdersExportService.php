<?php

namespace App\Services\Orders;

use App\Models\Contract;
use App\Modules\Contracts\Services\AdminOrderQueryService;
use App\Services\Payments\ContractPaymentState;
use App\Support\ContractFrontendStatus;
use Illuminate\Http\Request;
use InvalidArgumentException;
use ZipArchive;

/**
 * تصدير الطلبات (دفعة هـ — 2.7): نفس فلاتر «جميع الطلبات» + أعمدة المال الكاملة
 * (المدفوع الأصلي / إضافي / مسترجع / الصافي / طريقة الدفع).
 */
class OrdersExportService
{
    public const MAX_ROWS = 5000;

    public const COLUMNS = [
        'uuid' => 'رقم الطلب',
        'created_at' => 'تاريخ الإنشاء',
        'contract_type' => 'نوع العقد',
        'status_label' => 'الحالة',
        'customer_name' => 'العميل',
        'customer_mobile' => 'جوال العميل',
        'employee_name' => 'الموظف',
        'payment_status_label' => 'حالة الدفع',
        'paid_original' => 'المدفوع الأصلي',
        'paid_extra' => 'إضافي',
        'refunded' => 'مسترجع',
        'net' => 'الصافي',
        'payment_method' => 'طريقة الدفع',
        'outstanding' => 'المتبقي',
        'data_request_pending' => 'بانتظار العميل',
    ];

    /** الأعمدة الرقمية الوحيدة في xlsx — ما عداها نص (رقم الطلب والجوال تبقى بأصفارها البادئة). */
    public const NUMERIC_COLUMNS = ['paid_original', 'paid_extra', 'refunded', 'net', 'outstanding'];

    /** QA-F DASH-21: أعمدة المال لا تُصدَّر إلا لمن يملك payments.view. */
    public const MONEY_COLUMNS = ['paid_original', 'paid_extra', 'refunded', 'net', 'payment_method', 'outstanding'];

    /** @var array<string, string> */
    private array $columns = self::COLUMNS;

    public function __construct(
        private readonly AdminOrderQueryService $orders,
        private readonly ContractPaymentState $paymentState,
    ) {}

    /**
     * @return array{filename: string, bytes: string, mime: string}
     */
    public function build(Request $request, string $format = 'xlsx'): array
    {
        if (! in_array($format, ['xlsx', 'csv'], true)) {
            throw new InvalidArgumentException('صيغة التصدير غير مدعومة: xlsx | csv');
        }

        $employee = \App\Support\AuthenticatedEmployee::from($request);
        $withMoney = $employee === null || $employee->hasPermission('payments.view');
        $this->columns = $withMoney
            ? self::COLUMNS
            : array_diff_key(self::COLUMNS, array_flip(self::MONEY_COLUMNS));

        $request->merge(['per_page' => self::MAX_ROWS, 'page' => 1]);
        $request->attributes->set('export_max', self::MAX_ROWS);
        $result = $this->orders->paginateOrders($request);
        $rows = [];
        foreach ($result['paginator']->items() as $contract) {
            $rows[] = array_intersect_key($this->row($contract), $this->columns);
        }

        $stamp = now()->format('Y-m-d_Hi');

        return $format === 'csv'
            ? ['filename' => "orders-{$stamp}.csv", 'bytes' => $this->toCsv($rows), 'mime' => 'text/csv; charset=UTF-8']
            : ['filename' => "orders-{$stamp}.xlsx", 'bytes' => $this->toXlsx($rows), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /**
     * @return array<string, string|float|int|null>
     */
    public function row(Contract $contract): array
    {
        $details = $this->paymentState->details($contract, false);
        $state = $details['state'];
        $status = ContractFrontendStatus::for($contract);
        $pending = app(\App\Services\DataRequests\ContractDataRequestService::class)->pendingSummary($contract);
        $received = $contract->relationLoaded('receivedContract') ? $contract->receivedContract : $contract->receivedContract()->with('employee')->first();

        return [
            'uuid' => (string) $contract->uuid,
            'created_at' => $contract->created_at?->format('Y-m-d H:i'),
            'contract_type' => Contract::contractTypeLabel((string) $contract->contract_type, 'ar'),
            'status_label' => $status['status_label'],
            'customer_name' => $contract->user?->name,
            'customer_mobile' => $contract->user?->contact_mobile ?: $contract->user?->mobile,
            'employee_name' => $received?->employee?->name,
            'payment_status_label' => $state['status_label'],
            'paid_original' => $details['totals']['original'],
            'paid_extra' => $details['totals']['extra'],
            'refunded' => $details['totals']['refunded'],
            'net' => $details['totals']['net'],
            'payment_method' => $state['method_label'],
            'outstanding' => $state['outstanding'],
            'data_request_pending' => $pending ? implode('، ', $pending['items']) : null,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function toCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM حتى يفتحه Excel بالعربية
        fputcsv($out, array_values($this->columns));
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn ($v) => $v === null ? '' : (string) $v, array_values(array_merge(array_fill_keys(array_keys($this->columns), null), $row))));
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @param list<array<string, mixed>> $rows */
    private function toXlsx(array $rows): string
    {
        $sheetRows = [array_values($this->columns)];
        foreach ($rows as $row) {
            $sheetRows[] = array_values(array_map(static fn ($v) => $v === null ? '' : (string) $v, array_merge(array_fill_keys(array_keys($this->columns), null), $row)));
        }

        // B-1: `rightToLeft` سمة لـ sheetView فقط (CT_SheetView) — وضعها على workbookView يخالف مخطط OOXML
        // ويرفضه openpyxl ويطلب Excel «إصلاح» الملف. اتجاه الورقة يُضبط في sheet1.xml.
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets><sheet name="orders" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $this->sheetXml($sheetRows),
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($files as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** @param list<list<string>> $rows */
    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><sheetData>';
        $columnKeys = array_keys($this->columns);
        foreach ($rows as $r => $cols) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach ($cols as $c => $value) {
                $cell = $this->cellName($c).($r + 1);
                // B-2: الأعمدة المالية فقط أرقام؛ الجوال/رقم الطلب نص حتى لا يضيع الصفر البادئ (0598…).
                $numericColumn = in_array($columnKeys[$c] ?? '', self::NUMERIC_COLUMNS, true);
                if ($r > 0 && $numericColumn && is_numeric($value) && $value !== '' && ! preg_match('/^0\d/', (string) $value)) {
                    $xml .= '<c r="'.$cell.'"><v>'.htmlspecialchars((string) $value, ENT_XML1).'</v></c>';
                } else {
                    $xml .= '<c r="'.$cell.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function cellName(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }
}
