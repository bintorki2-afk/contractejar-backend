<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فحص (CROSS-0): حذف صف من جدول مرجعي (مدينة/منطقة/مدة/نوع دفع/نوع وحدة…) أو مستخدم
 * أو موظف كان يحذف طلبات العملاء وسجلاتهم المالية متسلسلاً (cascadeOnDelete).
 *
 *  - المراجع (lookup) + المستخدم + الموظف → RESTRICT: الحذف يُرفض ما دام مرتبطاً (والتطبيق
 *    يُرجع 422 برسالة عربية قبل الوصول للقاعدة).
 *  - عقار/وحدة العميل المحفوظة → SET NULL: حذف العقار المحفوظ لا يحذف العقد.
 *
 * MySQL فقط (إسقاط المفتاح وإعادة إضافته). على sqlite (محلياً/الاختبارات) لا يمكن تعديل
 * المفاتيح الأجنبية بعد الإنشاء — الترحيل لا يفعل شيئاً هناك، والحماية تتم في التطبيق.
 */
return new class extends Migration
{
    /**
     * [جدول, عمود, اسم المفتاح, الجدول المرجعي, السلوك الجديد]
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:string}>
     */
    private function keys(): array
    {
        $c = 'contracts';

        return [
            [$c, 'city_of_the_tenant_legal_agent', 'contracts_city_of_the_tenant_legal_agent_foreign', 'cities', 'RESTRICT'],
            [$c, 'contract_period_id', 'contracts_contract_period_id_foreign', 'contract_periods', 'RESTRICT'],
            [$c, 'contract_term_in_years', 'contracts_contract_term_in_years_foreign', 'contract_periods', 'RESTRICT'],
            [$c, 'payment_type_id', 'contracts_payment_type_id_foreign', 'payment_types', 'RESTRICT'],
            [$c, 'property_city_id', 'contracts_property_city_id_foreign', 'cities', 'RESTRICT'],
            [$c, 'property_place_id', 'contracts_property_place_id_foreign', 'regions', 'RESTRICT'],
            [$c, 'property_type_id', 'contracts_property_type_id_foreign', 'rea_estat_types', 'RESTRICT'],
            [$c, 'property_usages_id', 'contracts_property_usages_id_foreign', 'rea_estat_usages', 'RESTRICT'],
            [$c, 'region_of_the_tenant_legal_agent', 'contracts_region_of_the_tenant_legal_agent_foreign', 'regions', 'RESTRICT'],
            [$c, 'tenant_entity_city_id', 'contracts_tenant_entity_city_id_foreign', 'cities', 'RESTRICT'],
            [$c, 'tenant_entity_region_id', 'contracts_tenant_entity_region_id_foreign', 'regions', 'RESTRICT'],
            [$c, 'unit_type_id', 'contracts_unit_type_id_foreign', 'unit_types', 'RESTRICT'],
            [$c, 'unit_usage_id', 'contracts_unit_usage_id_foreign', 'unit_usages', 'RESTRICT'],
            [$c, 'user_id', 'contracts_user_id_foreign', 'users', 'RESTRICT'],
            [$c, 'real_id', 'contracts_real_id_foreign', 'real_estates', 'SET NULL'],
            [$c, 'real_units_id', 'contracts_real_units_id_foreign', 'real_units', 'SET NULL'],
            // سجلات مالية/تشغيلية مرتبطة بالموظف: لا تُحذف بحذف الموظف.
            ['contract_comments', 'employee_id', 'contract_comments_employee_id_foreign', 'employees', 'RESTRICT'],
            ['contract_paid_by_employees', 'employee_id', 'contract_paid_by_employees_employee_id_foreign', 'employees', 'RESTRICT'],
            ['received_contracts', 'employee_id', 'received_contracts_employee_id_foreign', 'employees', 'RESTRICT'],
            ['refundable_contracts', 'employee_id', 'refundable_contracts_employee_id_foreign', 'employees', 'RESTRICT'],
            ['custom_discounts', 'user_id', 'custom_discounts_user_id_foreign', 'users', 'RESTRICT'],
            // طلبات واتساب العملاء مرتبطة بمدة العقد: لا تُحذف بحذف المدة.
            ['contract_whatsapp', 'contract_duration', 'contract_whatsapp_contract_duration_foreign', 'contract_periods', 'RESTRICT'],
        ];
    }

    public function up(): void
    {
        $this->apply(false);
    }

    public function down(): void
    {
        $this->apply(true);
    }

    private function apply(bool $revert): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->keys() as [$table, $column, $name, $ref, $onDelete]) {
            if (! Schema::hasTable($table) || ! Schema::hasTable($ref) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $action = $revert ? 'CASCADE' : $onDelete;

            if ($this->foreignKeyExists($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
            }

            DB::statement(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) "
                ."REFERENCES `{$ref}` (`id`) ON DELETE {$action}"
            );
        }
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
