<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractPeriod;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Setting;
use App\Services\CouponDiscountResolver;

/**
 * Single source of truth for contract money.
 *
 * Rules (product truths):
 *  - The documentation fee comes from ONE place (DocFee rules; legacy ContractPeriod as a fallback).
 *    No double counting of service/application fees.
 *  - VAT is proportional: vat = fee * vat_rate% (rate read from settings.vat_rate; currently 0 => VAT 0).
 *  - document_surcharge = fixed one-time fee for special deed types (DocumentSurcharge, settings-driven).
 *  - total = fee + document_surcharge + vat + meter_fees - coupon (meter fees are a genuine, separate, conditional charge).
 *  - The shared-meter monthly amount is a contract TERM between the parties, never part of our total.
 *  - When vat == 0 it is displayed as "مجانًا".
 */
final class ContractPricing
{
    public const VAT_FREE_LABEL = 'مجانًا';

    /**
     * Documentation fee (single source). DocFee rules first, legacy ContractPeriod second.
     */
    public static function fee(Contract $contract): float
    {
        $docFee = DocFee::forContract($contract);
        if ($docFee !== null && isset($docFee['doc_fee'])) {
            return (float) $docFee['doc_fee'];
        }

        if ($contract->contract_term_in_years) {
            $legacy = ContractPeriod::query()
                ->where('contract_type', $contract->contract_type)
                ->where('id', $contract->contract_term_in_years)
                ->value('price');

            if ($legacy !== null) {
                return (float) $legacy;
            }
        }

        return 0.0;
    }

    /**
     * Proportional VAT rate (percent) from settings. Defaults to 0 (VAT off).
     */
    public static function vatRate(?Setting $setting = null): float
    {
        $setting ??= Setting::query()->first();

        if (! $setting) {
            return 0.0;
        }

        $rate = $setting->vat_rate ?? 0;

        return max(0.0, (float) $rate);
    }

    public static function vatAmount(float $fee, ?Setting $setting = null): float
    {
        return round($fee * self::vatRate($setting) / 100, 2);
    }

    public static function vatLabel(float $vat): string
    {
        return $vat > 0 ? self::money($vat) : self::VAT_FREE_LABEL;
    }

    /**
     * Full canonical breakdown used everywhere (website step, payment screen, dashboard).
     *
     * @return array{
     *     fee: float,
     *     vat_rate: float,
     *     vat: float,
     *     vat_label: string,
     *     meter_fees: array{electricity_meter_fee: float, water_meter_fee: float, meter_fees_total: float},
     *     meter_fees_total: float,
     *     coupon: float,
     *     subtotal: float,
     *     total: float,
     *     doc_fee_summary: array<string, mixed>|null
     * }
     */
    public static function for(Contract $contract, bool $applyCoupon = true): array
    {
        $setting = Setting::query()->first();

        $docFeeSummary = DocFee::forContract($contract);
        $fee = isset($docFeeSummary['doc_fee']) ? (float) $docFeeSummary['doc_fee'] : self::fee($contract);
        $rate = self::vatRate($setting);
        $vat = round($fee * $rate / 100, 2);
        $meter = MeterFees::forContract($contract, $setting);
        $surcharge = DocumentSurcharge::forContract($contract, $setting);

        $coupon = 0.0;
        if ($applyCoupon && filled($contract->uuid)) {
            $usage = CouponUsage::query()->where('contract_uuid', $contract->uuid)->first();
            if ($usage) {
                $coupon = (float) app(CouponDiscountResolver::class)
                    ->amount(Coupon::find($usage->coupon_id), $contract, $fee);
            }
        }

        $total = round(max(0, $fee + $surcharge + $vat + $meter['meter_fees_total'] - $coupon), 2);

        return [
            'fee' => round($fee, 2),
            'document_surcharge' => round($surcharge, 2),
            'document_surcharge_applies' => $surcharge > 0,
            'vat_rate' => $rate,
            'vat' => $vat,
            'vat_label' => self::vatLabel($vat),
            'meter_fees' => $meter,
            'meter_fees_total' => $meter['meter_fees_total'],
            'coupon' => round($coupon, 2),
            'subtotal' => round($fee + $surcharge, 2),
            'total' => $total,
            'doc_fee_summary' => $docFeeSummary,
        ];
    }

    /**
     * Canonical amount charged / shown as the order total.
     */
    public static function total(Contract $contract, bool $applyCoupon = true): float
    {
        return self::for($contract, $applyCoupon)['total'];
    }

    private static function money(float $amount): string
    {
        $formatted = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');

        return $formatted . ' ر.س';
    }
}
