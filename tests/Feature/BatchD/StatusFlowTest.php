<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\Payment;
use App\Models\RefundableContract;
use App\Services\MoyasarPaymentService;
use App\Support\ContractFrontendStatus;
use App\Support\ContractStatusCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * دفعة (د) — ب2: مفاتيح الحالات الثابتة، «قيد المراجعة» بعد الدفع، «مسترجع» حالة مستقلة.
 */
class StatusFlowTest extends BatchDTestCase
{
    public function test_every_seeded_status_has_a_stable_key_and_refunded_exists(): void
    {
        $keys = ContractStatus::query()->orderBy('id')->pluck('status_key', 'name')->all();

        $this->assertSame('new', $keys['جديد']);
        $this->assertSame('under_review', $keys['قيد المراجعة']);
        $this->assertSame('refunded', $keys['مسترجع']);
        $this->assertSame('received_by_employee', $keys['مستلم من الموظف']);
        $this->assertSame(1, ContractStatus::idFor('new'));
        $this->assertSame(2, ContractStatus::idFor('under_review'));
        $this->assertNotSame(2, ContractStatus::refundedId());

        // «قيد المراجعة» ليست حالة استرجاع (لا حقول إضافية)، و«مسترجع» هي.
        $this->assertNull(ContractStatus::query()->find(2)->status_case);
        $this->assertSame(ContractStatusCase::RETURN, ContractStatus::query()->find(ContractStatus::refundedId())->status_case['key']);
    }

    public function test_successful_payment_moves_order_to_under_review(): void
    {
        $contract = $this->contract(['contract_status_id' => ContractStatus::NEW_ID]);
        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_1' => Http::response([
                'id' => 'pay_1', 'status' => 'paid', 'amount' => 24900, 'currency' => 'SAR',
                'metadata' => ['contract_uuid' => (string) $contract->uuid], 'source' => ['type' => 'creditcard'],
            ], 200),
            'https://api.moyasar.com/v1/*' => Http::response([], 404),
        ]);

        app(MoyasarPaymentService::class)->processIpn(new Request(['id' => 'pay_1', 'status' => 'paid']), (string) $contract->uuid);

        $fresh = $contract->fresh();
        $this->assertTrue((bool) $fresh->is_completed);
        $this->assertSame($this->statusId('under_review'), (int) $fresh->contract_status_id);
        $this->assertSame('under_review', ContractFrontendStatus::for($fresh)['status']);
        $this->assertTrue(ContractStatusHistory::query()->where('contract_id', $contract->id)->where('status', 'under_review')->exists());
    }

    public function test_payment_does_not_regress_an_order_already_in_progress(): void
    {
        $draftId = $this->statusId('whatsapp_draft');
        $contract = $this->contract(['contract_status_id' => $draftId]);
        $this->payment($contract);

        app(\App\Services\Orders\OrderFlowService::class)->afterPayment($contract);

        $this->assertSame($draftId, (int) $contract->fresh()->contract_status_id);
    }

    public function test_employee_receive_works_from_under_review_and_sets_received_by_employee(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('under_review')]);

        $this->postJson('/api/admin/received-contracts', ['contract_id' => $contract->id])->assertOk();

        $this->assertSame($this->statusId('received_by_employee'), (int) $contract->fresh()->contract_status_id);

        // مرة ثانية ⇒ 409
        $this->postJson('/api/admin/received-contracts', ['contract_id' => $contract->id])->assertStatus(409);
    }

    public function test_receive_is_refused_after_the_order_progressed(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('whatsapp_draft')]);

        $this->postJson('/api/admin/received-contracts', ['contract_id' => $contract->id])->assertStatus(422);
    }

    public function test_moving_to_under_review_no_longer_requires_a_refund_request(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract(['contract_status_id' => ContractStatus::NEW_ID]);

        $this->postJson('/api/admin/orders/'.$contract->id.'/status', ['status_id' => $this->statusId('under_review')])
            ->assertOk();

        $this->assertSame(2, (int) $contract->fresh()->contract_status_id);
    }

    /** دفعة (و) — D2: «مسترجع» لا يُوضع يدوياً أبداً (حتى مع طلب استرجاع) — يضعه الخادم بعد استرجاع ميسر الكامل. */
    public function test_moving_to_refunded_manually_is_always_rejected(): void
    {
        $this->employee('manager');
        $contract = $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')]);
        $refunded = (int) ContractStatus::refundedId();

        $this->postJson('/api/admin/orders/'.$contract->id.'/status', ['status_id' => $refunded])
            ->assertStatus(422)
            ->assertJsonPath('code', 'refund_auto_only')
            ->assertJsonPath('message', ContractStatus::REFUND_AUTO_ONLY_MESSAGE);

        RefundableContract::query()->create([
            'contract_id' => $contract->id, 'user_id' => $contract->user_id, 'refund_amount' => 100, 'employee_id' => 1,
            'admin_confirmed' => null, 'is_refunded' => false,
        ]);

        $this->postJson('/api/admin/orders/'.$contract->id.'/contract-status', ['contract_status_id' => $refunded])
            ->assertStatus(422)->assertJsonPath('code', 'refund_auto_only');
        $this->assertSame($this->statusId('received_by_employee'), (int) $contract->fresh()->contract_status_id);
    }

    public function test_migration_backfills_keys_adds_refunded_and_moves_real_refunds(): void
    {
        // بيئة قديمة: لا «مسترجع»، و«قيد المراجعة» (2) كانت تُستخدم للاسترجاع.
        $refundedId = ContractStatus::refundedId();
        DB::table('contract_statuses')->where('id', $refundedId)->delete();
        DB::table('contract_statuses')->update(['status_key' => null]);
        ContractStatus::flushKeyCache();

        $refundedContract = $this->paidContract(['contract_status_id' => 2]);
        $reviewContract = $this->paidContract(['contract_status_id' => 2]);
        RefundableContract::query()->create([
            'contract_id' => $refundedContract->id, 'user_id' => $refundedContract->user_id, 'refund_amount' => 100, 'employee_id' => 1,
            'admin_confirmed' => true, 'is_refunded' => true,
        ]);

        $migration = require database_path('migrations/2026_10_09_000100_add_status_key_to_contract_statuses.php');
        $migration->up();
        $migration->up(); // آمن لإعادة التشغيل
        ContractStatus::flushKeyCache();

        $newRefunded = ContractStatus::refundedId();
        $this->assertNotNull($newRefunded);
        $this->assertSame(1, DB::table('contract_statuses')->where('status_key', 'refunded')->count());
        $this->assertSame('under_review', DB::table('contract_statuses')->where('id', 2)->value('status_key'));
        $this->assertSame($newRefunded, (int) $refundedContract->fresh()->contract_status_id);
        $this->assertSame(2, (int) $reviewContract->fresh()->contract_status_id);
    }

    public function test_status_rows_expose_status_key_in_admin_api(): void
    {
        $this->employee('admin');

        $rows = collect($this->getJson('/api/admin/contract-statuses?per_page=50')->assertOk()->json('data.items'));
        $this->assertContains('refunded', $rows->pluck('status_key')->all());
        $this->assertContains('under_review', $rows->pluck('status_key')->all());
    }
}
