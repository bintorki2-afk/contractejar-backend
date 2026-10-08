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
        ];

        $services = ServicesPricing::where('contract_type', $contract->contract_type)->get()
            ->map(function ($service) {
                return [
                    'service_name' => $service->name_ar,
                    'service_price' => $service->price,
                ];
            })->toArray();

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

        $pricingRows = ServicesPricing::where('contract_type', $contract->contract_type)->get();
        $services = $pricingRows->map(function ($service) {
            return [
                'id' => $service->id,
                'name_ar' => $service->name_ar,
                'name_en' => $service->name_en,
                'name' => $service->name_trans ?? $service->name_ar,
                'service_name' => $service->name_ar,
                'price' => (float) $service->price,
                'service_price' => (float) $service->price,
                'contract_type' => $service->contract_type,
            ];
        })->values()->all();

        $responseData = [
            'price_details' => $priceDetails,
            'services' => $services,
            'additional_services' => $services,
            'services_total' => (float) $pricingRows->sum(fn ($service) => (float) $service->price),
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
