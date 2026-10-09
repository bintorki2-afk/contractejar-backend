<?php

namespace App\Modules\Contracts\Services;

use App\Models\Contract;
use App\Models\ServicesPricing;
use App\Support\ContractPricing;

/**
 * Shared price breakdown used by public API v1 and v2. Single-source: delegates to
 * {@see ContractPricing} so the fee/VAT/total match the payment screen and dashboard.
 */
class ContractFinancialSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function v1Payload(Contract $contract): array
    {
        $pricing = ContractPricing::for($contract);
        $meterFees = $pricing['meter_fees'];

        $responseData = [
            'price_details' => [
                'contract_period_price' => $pricing['fee'],
                'application_fees' => 0,
                'tax' => $pricing['vat'],
                'vat' => $pricing['vat'],
                'vat_rate' => $pricing['vat_rate'],
                'vat_label' => $pricing['vat_label'],
                'electricity_meter_fee' => $meterFees['electricity_meter_fee'],
                'water_meter_fee' => $meterFees['water_meter_fee'],
                ...\App\Support\MeterFees::countFields($meterFees),
            ],
            'services' => ServicesPricing::where('contract_type', $contract->contract_type)->get()
                ->map(function ($service) {
                    return [
                        'service_name' => $service->name_ar,
                        'service_price' => $service->price,
                    ];
                })->toArray(),
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
     * @return array<string, mixed>
     */
    public function v2Payload(Contract $contract): array
    {
        $pricing = ContractPricing::for($contract);
        $meterFees = $pricing['meter_fees'];
        $docFeeSummary = $pricing['doc_fee_summary'];

        $priceDetails = [
            'contract_period_price' => $pricing['fee'],
            'application_fees' => 0,
            'tax' => $pricing['vat'],
            'vat' => $pricing['vat'],
            'vat_rate' => $pricing['vat_rate'],
            'vat_label' => $pricing['vat_label'],
            'electricity_meter_fee' => $meterFees['electricity_meter_fee'],
            'water_meter_fee' => $meterFees['water_meter_fee'],
            ...\App\Support\MeterFees::countFields($meterFees),
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
            'vat' => $pricing['vat'],
            'vat_rate' => $pricing['vat_rate'],
            'vat_label' => $pricing['vat_label'],
            'meter_fees_total' => $pricing['meter_fees_total'],
            'total_price' => $pricing['total'],
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
