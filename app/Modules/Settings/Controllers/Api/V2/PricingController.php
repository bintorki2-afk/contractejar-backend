<?php

namespace App\Modules\Settings\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Shared\Responses\Responser;
use App\Support\DocFee;
use App\Support\DocumentSurcharge;

/**
 * الأسعار المعلنة (عام، بدون مصادقة) — مصدر واحد للموقع والتطبيق:
 * رسوم السنة الأولى / كل سنة إضافية (سكني وتجاري)، رسوم المستندات الإضافية، رسوم نقل العداد،
 * ورسوم خدمة تغيير المؤجر. كلها من الإعدادات (قابلة للتعديل من لوحة التحكم).
 */
class PricingController extends Controller
{
    use Responser;

    public function show()
    {
        $setting = Setting::query()->first();
        DocFee::flushSettingsCache();
        $rates = DocFee::rates();

        $meter = fn (string $column, float $fallback): float => $setting && is_numeric($setting->{$column})
            ? (float) $setting->{$column}
            : $fallback;

        $lessorChangeFee = $setting && is_numeric($setting->lessor_change_fee) ? (float) $setting->lessor_change_fee : 400.0;

        return $this->apiResponse([
            'currency' => 'SAR',
            'housing' => [
                'first_year' => $rates['housing_first'],
                'extra_year' => $rates['housing_extra'],
            ],
            'commercial' => [
                'first_year' => $rates['commercial_first'],
                'extra_year' => $rates['commercial_extra'],
            ],
            'rule' => 'أي عقد لمدة سنة أو أقل يُحسب سنة؛ وكل سنة زيادة أو جزء منها تُحسب سنة إضافية.',
            'includes_ejar_fees' => true,
            'note' => 'الأسعار شاملة جميع الرسوم بما فيها رسوم منصة إيجار.',
            'document_surcharge' => [
                'fee' => DocumentSurcharge::fee($setting),
                'once_per_contract' => true,
                'instrument_types' => DocumentSurcharge::INSTRUMENT_TYPES,
                'label' => 'رسوم إضافية لأنواع محددة من المستندات',
            ],
            'meter_transfer_fee' => [
                'housing' => [
                    'electricity' => $meter('electricity_meter_fee_housing_tenant', 15.0),
                    'water' => $meter('water_meter_fee_housing_tenant', 15.0),
                ],
                'commercial' => [
                    'electricity' => $meter('electricity_meter_fee_commercial_tenant', 25.0),
                    'water' => $meter('water_meter_fee_commercial_tenant', 25.0),
                ],
                'label' => 'رسوم نقل العداد باسم المستأجر (لكل عداد)',
            ],
            'lessor_change_fee' => $lessorChangeFee,
            'vat_rate' => $setting && is_numeric($setting->vat_rate) ? (float) $setting->vat_rate : 0.0,
        ], trans('api.success'));
    }
}
