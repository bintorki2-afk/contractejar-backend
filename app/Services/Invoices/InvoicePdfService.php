<?php

namespace App\Services\Invoices;

use App\Models\Contract;
use App\Models\LessorChangeRequest;
use App\Services\ContractInvoiceService;
use App\Support\CustomerLinks;
use Mpdf\Mpdf;

/**
 * دفعة (و) — D4: فاتورة PDF حقيقية بالعربي (mPDF: تشكيل الحروف + RTL + خط عربي مضمّن، بلا متصفح).
 * المصدر نفس حمولة الفاتورة ({@see ContractInvoiceService}) — لا يُحسب أي مبلغ هنا.
 */
class InvoicePdfService
{
    public const LINK_DAYS = 7;

    public function __construct(private readonly ContractInvoiceService $invoices) {}

    /** @return array{content: string, filename: string} */
    public function forContract(Contract $contract): array
    {
        $payload = $this->invoices->forContract($contract, persist: (bool) $contract->is_completed);

        return [
            'content' => $this->render($payload),
            'filename' => 'invoice-'.($contract->uuid ?: $contract->id).'.pdf',
        ];
    }

    /** @return array{content: string, filename: string} */
    public function forLessorChange(LessorChangeRequest $request): array
    {
        $payload = $this->invoices->forLessorChange($request, persist: $request->isPaid());

        return [
            'content' => $this->render($payload),
            'filename' => 'invoice-lessor-change-'.$request->uuid.'.pdf',
        ];
    }

    /** @param  array<string, mixed>  $invoice */
    public function render(array $invoice): string
    {
        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 16,
            'default_font' => 'xbriyaz',
            'tempDir' => $tempDir,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle('فاتورة '.($invoice['invoice_number'] ?? '').' — '.($invoice['platform_name'] ?? 'عقدي'));
        $mpdf->SetAuthor((string) ($invoice['platform_name'] ?? 'عقدي'));
        $mpdf->SetCreator((string) ($invoice['platform_name'] ?? 'عقدي'));

        $logo = resource_path('pdf/aqdi-logo.png');
        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'logo' => is_file($logo) ? $logo : null,
        ])->render();
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    /** رابط موقّع مؤقت لملف PDF فاتورة الطلب (null قبل الدفع). */
    public static function contractUrl(Contract $contract, bool $hasInvoice = true): ?string
    {
        if (! $hasInvoice) {
            return null;
        }
        try {
            return CustomerLinks::temporarySignedRoute('v2.invoices.pdf', now()->addDays(self::LINK_DAYS), ['contract' => $contract->getKey()]);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function lessorChangeUrl(LessorChangeRequest $request): ?string
    {
        if (! $request->isPaid()) {
            return null;
        }
        try {
            return CustomerLinks::temporarySignedRoute('v2.lessor-change.invoice-pdf', now()->addDays(self::LINK_DAYS), ['lessorChange' => $request->getKey()]);
        } catch (\Throwable) {
            return null;
        }
    }
}
