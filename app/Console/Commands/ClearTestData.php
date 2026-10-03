<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wipes the test transactional data (properties, units, contracts/orders,
 * payments, invoices, coupons, expenses, ads) and the test marketing content
 * (blog/articles) from the database.
 *
 * It deliberately KEEPS: customer & employee accounts, roles & permissions,
 * reference/catalog data (cities, types, tenant roles, …), site content/config
 * pages, and settings.
 *
 * Destructive and irreversible — run intentionally:
 *   php artisan aqdi:clear-test-data --force
 */
class ClearTestData extends Command
{
    protected $signature = 'aqdi:clear-test-data {--force : Run without the confirmation prompt}';

    protected $description = 'Delete test data: properties, units, orders, payments, coupons, expenses, ads, and blog/articles. Keeps accounts, roles, reference data, site content and settings.';

    /**
     * Tables emptied, grouped for a readable summary. Order does not matter —
     * foreign-key checks are disabled around the truncation.
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        'العقارات والوحدات' => [
            'real_estates',
            'real_units',
        ],
        'الطلبات/العقود وما يرتبط بها' => [
            'contracts',
            'contract_comments',
            'contract_paid_by_employees',
            'contract_status_histories',
            'contract_units',
            'received_contracts',
            'refundable_contracts',
            'popup_contracts',
            'contract_whatsapp',
        ],
        'المدفوعات والفواتير' => [
            'payments',
            'payment_messages',
            'invoices',
        ],
        'الكوبونات' => [
            'coupons',
            'coupon_usages',
            'user_coupons',
            'custom_discounts',
        ],
        'المصاريف والإعلانات' => [
            'operating_expenses',
            'expenses',
            'ad_spend_dailies',
        ],
        'المحتوى التسويقي (مدونة/مقالات)' => [
            'blogs',
        ],
    ];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->warn('بيئة الإنتاج: شغّل الأمر مع --force للتأكيد.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('هذا حذف نهائي لبيانات الاختبار ولا يمكن التراجع. متابعة؟')) {
            $this->info('أُلغي.');

            return self::SUCCESS;
        }

        $cleared = [];
        $skipped = [];

        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::GROUPS as $group => $tables) {
                foreach ($tables as $table) {
                    if (! Schema::hasTable($table)) {
                        $skipped[] = $table;
                        continue;
                    }

                    $count = DB::table($table)->count();
                    DB::table($table)->truncate();
                    $cleared[] = [$group, $table, $count];
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->newLine();
        $this->table(['القسم', 'الجدول', 'الصفوف المحذوفة'], $cleared);

        if ($skipped) {
            $this->warn('جداول غير موجودة (تم تخطّيها): ' . implode(', ', $skipped));
        }

        $this->newLine();
        $this->info('تم تنظيف بيانات الاختبار. (حسابات العملاء/الموظفين والأدوار والبيانات المرجعية والإعدادات ومحتوى الموقع لم تُمَس.)');

        return self::SUCCESS;
    }
}
