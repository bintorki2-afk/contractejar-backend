<?php

namespace Database\Seeders;

use App\Models\ContractStatus;
use Illuminate\Database\Seeder;

class ContractStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['name' => 'جديد', 'color' => '#3B82F6', 'color_text' => '#FFFFFF', 'description' => 'عقد جديد تم إنشاؤه', 'client_explanation' => 'تم استلام طلبك وهو قيد الإعداد.', 'order' => 1],
            ['name' => 'قيد المراجعة', 'color' => '#F59E0B', 'color_text' => '#000000', 'description' => 'بعد الدفع وقبل استلام الموظف للطلب', 'client_explanation' => 'تم استلام دفعتك — فريقنا يراجع بيانات طلبك الآن.', 'order' => 2],
            ['name' => 'مكتمل', 'color' => '#10B981', 'color_text' => '#FFFFFF', 'description' => 'تم إكمال العقد بنجاح', 'client_explanation' => 'تم إكمال طلبك بنجاح.', 'order' => 3],
            ['name' => 'ملغى', 'color' => '#EF4444', 'color_text' => '#FFFFFF', 'description' => 'تم إلغاء العقد', 'client_explanation' => 'تم إلغاء هذا الطلب.', 'order' => 4],
            ['name' => 'معلق', 'color' => '#6B7280', 'color_text' => '#FFFFFF', 'description' => 'العقد معلق حتى استكمال المستندات', 'client_explanation' => 'طلبك معلق حالياً حتى استكمال المطلوب.', 'order' => 5],
            // دفعة (و) — D1: «مستلم» دُمجت في «مستلم من الموظف» — تبقى معطّلة للبيانات التاريخية فقط.
            ['name' => 'مستلم', 'color' => '#8B5CF6', 'color_text' => '#FFFFFF', 'description' => 'حالة قديمة (دُمجت في «مستلم من الموظف») — لا تُستخدم', 'client_explanation' => 'استلم موظفنا طلبك ويعمل عليه الآن.', 'order' => 6, 'is_active' => false],
            ['name' => 'مستلم من الموظف', 'color' => '#F97316', 'color_text' => '#FFFFFF', 'description' => 'استلم الموظف الطلب ويعمل عليه', 'client_explanation' => 'استلم موظفنا طلبك ويعمل عليه الآن.', 'order' => 7],
            // دفعة (هـ): حالة قديمة (أُلغيت مرحلة المسودة) — تبقى معطّلة للبيانات التاريخية فقط.
            ['name' => 'إرسال مسودة العقد لكم عبر واتساب', 'color' => '#22C55E', 'color_text' => '#FFFFFF', 'description' => 'حالة قديمة (أُلغيت) — لا تُستخدم', 'client_explanation' => 'استلم موظفنا طلبك ويعمل عليه الآن.', 'order' => 8, 'is_active' => false],
            ['name' => 'توثيق العقد في إيجار', 'color' => '#0EA5E9', 'color_text' => '#FFFFFF', 'description' => 'يُوثّق العقد ويصبح جاهزاً للتحميل', 'client_explanation' => 'يُوثّق العقد في إيجار ويصبح جاهزاً للتحميل.', 'order' => 9],
            // دفعة (د): حالة الاسترجاع المستقلة (كانت «قيد المراجعة» تُستخدم لها خطأً).
            ['name' => 'مسترجع', 'color' => '#DC2626', 'color_text' => '#FFFFFF', 'description' => 'تم استرجاع مبلغ الطلب للعميل', 'client_explanation' => 'تم استرجاع مبلغ طلبك.', 'order' => 10],
        ];

        foreach ($statuses as $status) {
            $key = \App\Support\ContractFrontendStatus::knownKeyFromName($status['name']);
            ContractStatus::updateOrCreate(
                ['name' => $status['name']],
                array_merge(['is_active' => true], $status, $key !== null ? ['status_key' => $key] : [])
            );
        }
        ContractStatus::flushKeyCache();
    }
}
