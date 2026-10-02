<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the catalog of tenant roles (صلاحيات المستأجر) shown in the finance step
 * of the contract wizard. The GET /api/v2/tenant-roles route exists, but the
 * `tenant_roles` table was never populated on this (صقر ١) environment, so the
 * finance step rendered no options. This inserts the canonical four roles,
 * preserving their original ids (1, 4, 5, 6) for parity. Idempotent via
 * updateOrInsert, so re-running (or a fresh install that also seeds) is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tenant_roles')) {
            return;
        }

        $dailyPenaltyDefinition = <<<'HTML'
<p><span style="font-size: 32px;">الغرامة اليومية</span></p><p> هي مبلغ يحدده المؤجر ويوافق عليه المستأجر عند توثيق العقد، ويُحتسب عن كل يوم يتأخر فيه المستأجر عن إخلاء الوحدة وتسليمها بعد انتهاء العقد أو إنهائه. مثال: إذا كانت الغرامة اليومية 100 ريال، وتأخر المستأجر 5 أيام، يصبح المبلغ المستحق 500 ريال.</p><p> الغرض منها هو تعويض المؤجر عن استمرار إشغال العقار بعد الموعد المحدد، وليست غرامة بسبب التأخر في دفع الإيجار. كما أن المستأجر ملزم بتسليم الوحدة عند انتهاء العقد وفق إجراءات التسليم المعتمدة في «إيجار».</p>
HTML;

        $securityDepositDefinition = <<<'TEXT'
بناءً على متطلبات نظام الوساطة العقارية والتي تشمل حفظ مبلغ الضمان لدى الهيئة أو من تخوله، إلى حين إعادة تسليم العقار دون أضرار. سيتم إتاحة حجز قيمة الضمان من محفظة المستأجر عند توثيق العقد وبشكل إلزامي، بحيث يتم حفظ الضمان لدى «إيجار» كطرف محايد.

وعند انتهاء/إلغاء العقد يتم الاعتماد على نموذج تسليم الوحدة المتفق عليه من كلا الطرفين، بحيث يتم إعادة المبالغ المستحقة لكلٍّ منهم بشكل آلي كرصيد متاح في المحافظ الإلكترونية الخاصة بهم.
TEXT;

        $now = now();

        $roles = [
            [
                'id' => 1,
                'text_of_reason' => 'يحق للمستاجر التاجير من الباطن',
                'service_definition' => null,
                'input_field_label' => null,
                'input_field_type' => null,
                'icon' => null,
                'input_icon' => null,
                'pop' => false,
            ],
            [
                'id' => 4,
                'text_of_reason' => 'يحق للمستاجر تعديل الزياده والنقصان بالوحده',
                'service_definition' => null,
                'input_field_label' => null,
                'input_field_type' => null,
                'icon' => null,
                'input_icon' => null,
                'pop' => false,
            ],
            [
                'id' => 5,
                'text_of_reason' => 'غرامه يومية للتاخير',
                'service_definition' => $dailyPenaltyDefinition,
                'input_field_label' => 'ادخل مبلغ الغرامه اليوميه',
                'input_field_type' => 'number',
                'icon' => null,
                'input_icon' => null,
                'pop' => false,
            ],
            [
                'id' => 6,
                'text_of_reason' => 'مبلغ الضمان',
                'service_definition' => $securityDepositDefinition,
                'input_field_label' => 'ادخل المبلغ',
                'input_field_type' => 'number',
                'icon' => null,
                'input_icon' => null,
                'pop' => true,
            ],
        ];

        foreach ($roles as $role) {
            $id = $role['id'];
            unset($role['id']);

            DB::table('tenant_roles')->updateOrInsert(
                ['id' => $id],
                array_merge($role, ['updated_at' => $now, 'created_at' => $now]),
            );
        }
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tenant_roles')) {
            return;
        }

        DB::table('tenant_roles')->whereIn('id', [1, 4, 5, 6])->delete();
    }
};
