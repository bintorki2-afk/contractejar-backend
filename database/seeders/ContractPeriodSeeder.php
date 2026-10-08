<?php

namespace Database\Seeders;

use App\Models\ContractPeriod;
use Illuminate\Database\Seeder;

/**
 * مدد العقد المعروضة للعميل: «سنة» و«سنتين» فقط (و«مدة أخرى» تُرسل كسنوات + أشهر بدون صف هنا).
 * الرسوم لا تُقرأ من price هنا بل من قواعد DocFee (الإعدادات).
 */
class ContractPeriodSeeder extends Seeder
{
    public function run(): void
    {
        $periods = [];

        foreach (['housing' => 'عقد إيجار', 'commercial' => 'عقد إيجار تجاري'] as $type => $prefix) {
            $periods[] = [
                'period' => 'سنوي',
                'months' => 12,
                'note_ar' => "{$prefix} لمدة سنة",
                'note_en' => 'One-year contract',
                'contract_type' => $type,
                'price' => null,
                'is_active' => true,
            ];
            $periods[] = [
                'period' => 'سنتين',
                'months' => 24,
                'note_ar' => "{$prefix} لمدة سنتين",
                'note_en' => 'Two-year contract',
                'contract_type' => $type,
                'price' => null,
                'is_active' => true,
            ];
        }

        foreach ($periods as $period) {
            ContractPeriod::updateOrCreate(
                ['period' => $period['period'], 'contract_type' => $period['contract_type']],
                $period
            );
        }

        // أي مدد قديمة (شهري / ربع سنوي / نصف سنوي) تبقى للعقود المرتبطة بها لكنها لا تُعرض.
        ContractPeriod::query()
            ->whereNotIn('period', ['سنوي', 'سنتين'])
            ->update(['is_active' => false]);
    }
}
