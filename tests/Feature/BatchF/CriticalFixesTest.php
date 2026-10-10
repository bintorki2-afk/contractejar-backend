<?php

namespace Tests\Feature\BatchF;

use App\Models\LessorChangeRequest;
use Tests\Feature\BatchD\BatchDTestCase;

/**
 * QA-F: اختبارات الإصلاحات الحرجة (C5، C6، C8، C12).
 */
class CriticalFixesTest extends BatchDTestCase
{
    public function test_c5_payment_gateway_link_requires_auth(): void
    {
        $contract = $this->contract();

        $this->getJson('/api/admin/payment-gateway/'.$contract->uuid)->assertUnauthorized();
    }

    public function test_c5_bank_accounts_are_not_public(): void
    {
        $response = $this->getJson('/api/v2/bank-accounts')->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_c6_paid_filter_uses_real_payments_not_is_completed(): void
    {
        $this->employee('manager');
        $flagOnly = $this->paidContract(); // is_completed=1 بلا أي دفعة
        $reallyPaid = $this->contract();    // is_completed=0 مع دفعة ناجحة
        $this->payment($reallyPaid, 249.0, 'pay_real');

        $ids = collect($this->getJson('/api/admin/orders?per_page=100&is_completed=1')->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertContains($reallyPaid->id, $ids);
        $this->assertNotContains($flagOnly->id, $ids);
    }

    public function test_c8_rejected_unpaid_lessor_change_is_not_paid(): void
    {
        $row = new LessorChangeRequest(['status' => 'rejected']);
        $this->assertFalse($row->isPaid());

        $paid = new LessorChangeRequest(['status' => 'rejected', 'paid_at' => now()]);
        $this->assertTrue($paid->isPaid());
    }
}
