<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فحص استخدام صف مرجعي قبل حذفه (CROSS-0): لا يُحذف صف مرجعي مرتبط بطلبات عملاء.
 * الرسالة الموحّدة: «لا يمكن الحذف: مرتبط بطلبات» (trans api.lookup_in_use).
 */
final class LookupUsage
{
    /** أعمدة جدول contracts لكل جدول مرجعي. */
    public const CONTRACT_COLUMNS = [
        'cities' => ['property_city_id', 'tenant_entity_city_id', 'city_of_the_tenant_legal_agent'],
        'regions' => ['property_place_id', 'tenant_entity_region_id', 'region_of_the_tenant_legal_agent'],
        'contract_periods' => ['contract_term_in_years', 'contract_period_id'],
        'payment_types' => ['payment_type_id'],
        'unit_types' => ['unit_type_id'],
        'unit_usages' => ['unit_usage_id'],
        'rea_estat_types' => ['property_type_id'],
        'rea_estat_usages' => ['property_usages_id'],
    ];

    public static function contractsReference(string $lookupTable, int $id): bool
    {
        $columns = self::CONTRACT_COLUMNS[$lookupTable] ?? [];
        if ($columns === [] || ! Schema::hasTable('contracts')) {
            return false;
        }

        $query = DB::table('contracts');
        $query->where(function ($q) use ($columns, $id) {
            foreach ($columns as $column) {
                if (Schema::hasColumn('contracts', $column)) {
                    $q->orWhere($column, $id);
                }
            }
        });

        if ($query->exists()) {
            return true;
        }

        // حذف منطقة يحذف مدنها متسلسلاً — نتحقق أيضاً من الطلبات المرتبطة بمدن هذه المنطقة.
        if ($lookupTable === 'regions' && Schema::hasTable('cities')) {
            $cityIds = DB::table('cities')->where('region_id', $id)->pluck('id')->all();
            foreach ($cityIds as $cityId) {
                if (self::contractsReference('cities', (int) $cityId)) {
                    return true;
                }
            }
        }

        return false;
    }
}
