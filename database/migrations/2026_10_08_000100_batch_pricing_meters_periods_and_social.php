<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة تعديلات ريان (2026-10-08):
 *  - الأسعار من الإعدادات (السنة الأولى / كل سنة إضافية، سكني وتجاري) + رسوم المستندات الإضافية 75 + رسوم تغيير المؤجر 400.
 *  - رسوم نقل العداد باسم المستأجر: سكني 15 / تجاري 25 (لكل عداد).
 *  - خيار «عداد مشترك» مع مبلغ شهري (بند من بنود العقد — ليس من رسومنا).
 *  - مدد العقد: سنة / سنتين فقط (is_active + months).
 *  - العقار المحفوظ: ربط العقار بالعقد الذي أُنشئ منه (منع التكرار).
 *  - تنظيف حسابات التواصل التجريبية.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── settings: pricing ──
        // حراسات hasColumn: الترحيل قابل لإعادة التشغيل بعد فشل جزئي على MySQL (DDL لا يتراجع). (CROSS-11)
        Schema::table('settings', function (Blueprint $table) {
            $after = 'water_meter_fee_housing_tenant';
            foreach ([
                'doc_fee_housing_first_year' => 249,
                'doc_fee_housing_extra_year' => 150,
                'doc_fee_commercial_first_year' => 349,
                'doc_fee_commercial_extra_year' => 450, // دفعة (د): قرار المالك 450 (الصف القائم يُحدَّث في 2026_10_09_000600)
                'document_surcharge_fee' => 75,
                'lessor_change_fee' => 400,
            ] as $column => $default) {
                if (! Schema::hasColumn('settings', $column)) {
                    $table->decimal($column, 10, 2)->default($default)->after($after);
                }
                $after = $column;
            }
        });

        // رسوم نقل العداد (لكل عداد): سكني 15 / تجاري 25 — تُضبط فقط إذا كانت فارغة أو صفر.
        foreach ([
            'electricity_meter_fee_housing_tenant' => 15,
            'water_meter_fee_housing_tenant' => 15,
            'electricity_meter_fee_commercial_tenant' => 25,
            'water_meter_fee_commercial_tenant' => 25,
        ] as $column => $value) {
            DB::table('settings')
                ->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<=', 0))
                ->update([$column => $value]);
        }

        // حسابات التواصل: القيم التجريبية من الـ seeder فقط تُمسح (القيم الحقيقية لا تُلمس).
        $dummy = [
            'instagram' => ['https://instagram.com/aqdi'],
            'twitter' => ['https://twitter.com/aqdi'],
            'snapchat' => ['aqdi_app'],
            'facebook' => ['https://facebook.com/aqdi'],
            'tiktok' => ['https://tiktok.com/@aqdi'],
            'linkedIn' => ['https://linkedin.com/company/aqdi'],
        ];
        foreach ($dummy as $column => $values) {
            DB::table('settings')->whereIn($column, $values)->update([$column => null]);
        }

        // ── contract periods: months + is_active ──
        Schema::table('contract_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('contract_periods', 'months')) {
                $table->unsignedSmallInteger('months')->nullable()->after('period');
            }
            if (! Schema::hasColumn('contract_periods', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('price');
            }
        });

        $monthsByLabel = ['شهري' => 1, 'ربع سنوي' => 3, 'نصف سنوي' => 6, 'سنوي' => 12, 'سنتين' => 24];
        foreach ($monthsByLabel as $label => $months) {
            DB::table('contract_periods')->where('period', $label)->update(['months' => $months]);
        }
        // الخيارات المعروضة للعميل: سنة / سنتين فقط.
        DB::table('contract_periods')->update(['is_active' => false]);
        foreach (['housing', 'commercial'] as $type) {
            foreach ([['سنوي', 12, 'عقد لمدة سنة'], ['سنتين', 24, 'عقد لمدة سنتين']] as [$label, $months, $note]) {
                $existing = DB::table('contract_periods')->where('contract_type', $type)->where('period', $label)->first();
                if ($existing) {
                    DB::table('contract_periods')->where('id', $existing->id)->update(['is_active' => true, 'months' => $months]);
                } else {
                    DB::table('contract_periods')->insert([
                        'period' => $label,
                        'months' => $months,
                        'note_ar' => $note,
                        'note_en' => $months === 12 ? 'One-year contract' : 'Two-year contract',
                        'contract_type' => $type,
                        'price' => null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // ── shared meter: ownership enum + monthly amount ──
        $this->widenOwnershipEnum('real_units');
        $this->widenOwnershipEnum('contracts');
        $this->widenOwnershipEnum('real_estates');

        Schema::table('real_units', function (Blueprint $table) {
            if (! Schema::hasColumn('real_units', 'electricity_shared_monthly_fee')) {
                $table->decimal('electricity_shared_monthly_fee', 10, 2)->nullable()->after('electricity_meter_ownership');
            }
            if (! Schema::hasColumn('real_units', 'water_shared_monthly_fee')) {
                $table->decimal('water_shared_monthly_fee', 10, 2)->nullable()->after('water_meter_ownership');
            }
        });

        // ── saved property: source contract ──
        if (! Schema::hasColumn('real_estates', 'source_contract_id')) {
            Schema::table('real_estates', function (Blueprint $table) {
                $table->unsignedBigInteger('source_contract_id')->nullable()->after('user_id')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::table('real_estates', fn (Blueprint $t) => $t->dropColumn('source_contract_id'));
        Schema::table('real_units', fn (Blueprint $t) => $t->dropColumn(['electricity_shared_monthly_fee', 'water_shared_monthly_fee']));
        Schema::table('contract_periods', fn (Blueprint $t) => $t->dropColumn(['months', 'is_active']));
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn([
            'doc_fee_housing_first_year', 'doc_fee_housing_extra_year',
            'doc_fee_commercial_first_year', 'doc_fee_commercial_extra_year',
            'document_surcharge_fee', 'lessor_change_fee',
        ]));
    }

    /**
     * MySQL: توسيع ENUM ليقبل shared. SQLite (محلي/اختبارات): الأعمدة نصية مع قيد CHECK في الجداول
     * المُنشأة قبل هذا التعديل — ملفات الإنشاء الأصلية حُدِّثت لتشمل shared للتنصيبات الجديدة.
     */
    private function widenOwnershipEnum(string $table): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['electricity_meter_ownership', 'water_meter_ownership'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM('owner','tenant','shared') NULL");
            }
        }
    }
};
