<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractStatus;

/**
 * دفعة (د) — ب3: «جميع الطلبات» تعرض كل الحالات افتراضياً + فلتر status_key + عدّادات التبويبات.
 * ب5: قاعدة ظهور واحدة (الخطوة ≥ 4) بين القائمة والعدّادات وملف العميل.
 */
class AdminOrdersListTest extends BatchDTestCase
{
    private function seedOrders(): array
    {
        $user = $this->customer();
        $orders = [
            'new' => $this->contract(['contract_status_id' => ContractStatus::NEW_ID], $user),
            'paid_new' => $this->paidContract(['contract_status_id' => ContractStatus::NEW_ID], $user),
            'review' => $this->paidContract(['contract_status_id' => $this->statusId('under_review')], $user),
            'received' => $this->paidContract(['contract_status_id' => $this->statusId('received_by_employee')], $user),
            'draft_sent' => $this->paidContract(['contract_status_id' => $this->statusId('whatsapp_draft')], $user),
            'refunded' => $this->paidContract(['contract_status_id' => (int) ContractStatus::refundedId()], $user),
            'cancelled' => $this->contract(['contract_status_id' => $this->statusId('cancelled')], $user),
        ];
        // مسودات مبكرة (غير مكتملة) — لا تظهر في «جميع الطلبات».
        $orders['early1'] = $this->contract(['step' => 2, 'contract_status_id' => ContractStatus::NEW_ID], $user);
        $orders['early2'] = $this->contract(['step' => 3, 'contract_status_id' => ContractStatus::NEW_ID], $user);
        $orders['deleted'] = $this->contract(['is_delete' => 1], $user);

        return [$user, $orders];
    }

    public function test_all_orders_default_lists_every_status(): void
    {
        $this->employee('manager');
        [, $orders] = $this->seedOrders();

        $ids = collect($this->getJson('/api/admin/orders?per_page=100')->assertOk()->json('data.items'))->pluck('id')->all();

        foreach (['new', 'paid_new', 'review', 'received', 'draft_sent', 'refunded', 'cancelled'] as $k) {
            $this->assertContains($orders[$k]->id, $ids, $k);
        }
        $this->assertNotContains($orders['early1']->id, $ids);
        $this->assertNotContains($orders['early2']->id, $ids);
        $this->assertNotContains($orders['deleted']->id, $ids);
    }

    public function test_status_key_filter_and_tabs(): void
    {
        $this->employee('manager');
        [, $orders] = $this->seedOrders();

        $ids = fn (string $q) => collect($this->getJson('/api/admin/orders?per_page=100&'.$q)->assertOk()->json('data.items'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$orders['review']->id], $ids('status_key=under_review'));
        $this->assertSame([$orders['review']->id], $ids('status_case=under_review'));
        $this->assertSame([$orders['paid_new']->id], $ids('status_key=paid'));
        $this->assertSame([$orders['new']->id], $ids('status_key=new'));
        $this->assertSame(collect([$orders['review']->id, $orders['refunded']->id])->sort()->values()->all(), $ids('status_key=under_review,refunded'));
        $this->assertSame(collect([$orders['early1']->id, $orders['early2']->id])->sort()->values()->all(), $ids('tab=incomplete'));
        // status_id للتوافق
        $this->assertSame([$orders['review']->id], $ids('status_id='.$this->statusId('under_review')));

        $this->getJson('/api/admin/orders?status_key=nope')->assertStatus(422);
    }

    public function test_status_counts_match_the_list(): void
    {
        $this->employee('manager');
        [$user] = $this->seedOrders();

        $counts = $this->getJson('/api/admin/orders/status-counts')->assertOk()->json('data');

        $this->assertSame(7, $counts['all']);
        $this->assertSame(2, $counts['incomplete']);
        $this->assertSame(1, $counts['by_key']['under_review']);
        $this->assertSame(1, $counts['by_key']['refunded']);
        $this->assertSame(1, $counts['by_key']['paid']);
        $this->assertSame(2, $counts['by_key']['new']); // صف «جديد» (مدفوع وغير مدفوع)
        $this->assertSame(5, $counts['paid']);
        $this->assertSame(collect($counts['statuses'])->sum('count'), $counts['all']);
        $this->assertSame('all', $counts['tabs'][0]['key']);
        $this->assertSame('incomplete', collect($counts['tabs'])->last()['key']);

        // نفس العدّاد في القائمة
        $this->assertSame($counts['all'], count($this->getJson('/api/admin/orders?per_page=200')->json('data.items')));

        // فلتر العميل
        $this->assertSame(7, $this->getJson('/api/admin/orders/status-counts?user_id='.$user->id)->json('data.all'));
        $this->assertSame(0, $this->getJson('/api/admin/orders/status-counts?user_id=999')->json('data.all'));
    }

    public function test_client_profile_counts_match_orders_list_for_that_client(): void
    {
        $this->employee('admin');
        [$user, $orders] = $this->seedOrders();

        $profile = $this->getJson('/api/admin/users/'.$user->id)->assertOk()->json('data');
        $listTotal = count($this->getJson('/api/admin/orders?per_page=200&user_id='.$user->id)->json('data.items'));
        $counts = $this->getJson('/api/admin/orders/status-counts?user_id='.$user->id)->json('data');

        $this->assertSame(7, $listTotal);
        $this->assertSame($listTotal, $profile['user']['orders_count']);
        $this->assertSame($listTotal, count($profile['contracts']));
        $this->assertSame($counts['paid'], $profile['user']['completed_orders_count']);
        $this->assertSame($counts['unpaid'], $profile['user']['incomplete_orders_count']);
        $this->assertSame($counts['incomplete'], $profile['user']['incomplete_drafts_count']);

        // العميل يرى نفس الطلبات (الخطوة ≥ 4)
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $customerIds = collect($this->getJson('/api/v2/contracts?per_page=100')->assertOk()->json('data.items')
            ?? $this->getJson('/api/v2/contracts?per_page=100')->json('data'))->pluck('id')->filter()->all();
        $this->assertNotContains($orders['early1']->id, $customerIds);
        $this->assertNotContains($orders['early2']->id, $customerIds);
    }
}
