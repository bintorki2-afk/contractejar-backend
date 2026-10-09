<?php

namespace App\Modules\Contracts\Services;

use App\Models\Contract;
use App\Models\ServicesPricing;
use App\Support\ContractPricing;
use App\Support\DocFee;

class ContractFinancialService
{
    /**
     * Preserve the v1 `/financial/{uuid}` payload shape.
     *
     * @return array<string, mixed>
     */
    public function forApiV1(Contract $contract): array
    {
        $pricing = ContractPricing::for($contract);
        $meterFees = $pricing['meter_fees'];

        $priceDetails = [
            'contract_period_price' => $pricing['fee'],
            'application_fees' => 0,
            // "tax" kept for backward-compat but now the proportional VAT (0 when VAT is off).
            'tax' => $pricing['vat'],
            'vat' => $pricing['vat'],
            'vat_rate' => $pricing['vat_rate'],
            'vat_label' => $pricing['vat_label'],
            'electricity_meter_fee' => $meterFees['electricity_meter_fee'],
            'water_meter_fee' => $meterFees['water_meter_fee'],
            ...\App\Support\MeterFees::countFields($meterFees),
        ];

        // الفاتورة الرسمية لها بنودها في `items[]` (ContractInvoiceService). جدول
        // services_pricings مزروع ببيانات تجريبية لا علاقة لها بالمبلغ المحصّل، فكانت
        // تظهر للعميل كبنود فاتورة وهمية — نُفرّغها مع إبقاء المفتاح للتوافق. (CROSS-1)
        $services = [];

        $responseData = [
            'price_details' => $priceDetails,
            'services' => $services,
            'fee' => $pricing['fee'],
            'vat' => $pricing['vat'],
            'vat_rate' => $pricing['vat_rate'],
            'vat_label' => $pricing['vat_label'],
            'meter_fees_total' => $pricing['meter_fees_total'],
            'total_price' => $pricing['total'],
        ];

        if ($pricing['coupon'] > 0) {
            $responseData['coupon'] = $pricing['coupon'];
            $responseData['total_price_after_coupon'] = $pricing['total'];
        }

        return $responseData;
    }

    /**
     * Preserve the v2 `/financial/{uuid}` payload shape.
     *
     * @return array<string, mixed>
     */
    public function forApiV2(Contract $contract): array
    {
        $pricing = ContractPricing::for($contract);
        $meterFees = $pricing['meter_fees'];
        $docFeeSummary = $pricing['doc_fee_summary'];

        $priceDetails = [
            'contract_period_price' => $pricing['fee'],
            'application_fees' => 0,
            // "tax" kept for backward-compat but now the proportional VAT (0 when VAT is off).
            'tax' => $pricing['vat'],
            'vat' => $pricing['vat'],
            'vat_rate' => $pricing['vat_rate'],
            'vat_label' => $pricing['vat_label'],
            'electricity_meter_fee' => $meterFees['electricity_meter_fee'],
            'water_meter_fee' => $meterFees['water_meter_fee'],
            ...\App\Support\MeterFees::countFields($meterFees),
            'document_surcharge' => $pricing['document_surcharge'],
            'document_surcharge_applies' => $pricing['document_surcharge_applies'],
            'shared_meters' => $meterFees['shared_meters'] ?? null,
        ];

        if ($docFeeSummary) {
            $priceDetails['doc_fee'] = $docFeeSummary['doc_fee'];
            $priceDetails['billable_years'] = $docFeeSummary['billable_years'];
            $priceDetails['total_months'] = $docFeeSummary['total_months'];
            $priceDetails['has_extra_months'] = $docFeeSummary['has_extra_months'];
            $priceDetails['duration_preset'] = $docFeeSummary['duration_preset'];
            $priceDetails['duration_years'] = $docFeeSummary['duration_years'];
            $priceDetails['duration_months'] = $docFeeSummary['duration_months'];
        }

        // بنود الفاتورة الرسمية في `items[]` (ContractInvoiceService). لا نُعيد بنود
        // services_pricings التجريبية (كانت تظهر كبنود/إجمالي خدمات وهمية). (CROSS-1)
        $services = [];

        $responseData = [
            'price_details' => $priceDetails,
            'services' => $services,
            'additional_services' => $services,
            'services_total' => 0.0,
            'fee' => $pricing['fee'],
            'document_surcharge' => $pricing['document_surcharge'],
            'document_surcharge_applies' => $pricing['document_surcharge_applies'],
            'vat' => $pricing['vat'],
            'vat_rate' => $pricing['vat_rate'],
            'vat_label' => $pricing['vat_label'],
            'meter_fees_total' => $pricing['meter_fees_total'],
            'shared_meters' => $meterFees['shared_meters'] ?? null,
            'total_price' => $pricing['total'],
            // التطبيق يقرأ ملخص الدفع من هنا: حالة «حفظ بيانات العقار».
            'saved_property' => \App\Support\SavedPropertyState::forContract($contract),
            ...\App\Support\MeterFees::countFields($meterFees),
            'refund' => $refund = \App\Services\Payments\PaymentRefundService::summaryFor($contract),
            'refunded_amount' => $refund['amount'],
        ];

        if ($docFeeSummary) {
            $responseData['doc_fee'] = $docFeeSummary['doc_fee'];
            $responseData['doc_fee_lines'] = $docFeeSummary['doc_fee_lines'];
            $responseData['billable_years'] = $docFeeSummary['billable_years'];
            $responseData['has_extra_months'] = $docFeeSummary['has_extra_months'];
        }

        if ($pricing['coupon'] > 0) {
            $responseData['coupon'] = $pricing['coupon'];
            $responseData['total_price_after_coupon'] = $pricing['total'];
        }

        return $responseData;
    }
}
