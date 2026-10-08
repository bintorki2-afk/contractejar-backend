<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\LessorChangeRequest;
use App\Models\Setting;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * فحص (DASHBOARD-7 / APP-4): نقاط الدفع العامة لا تكشف معرّف العقد ومبلغ الدفعة وبيانات
 * طلب تغيير المؤجر لغير صاحب الطلب؛ صاحب الطلب (توكن) يرى التفاصيل كما كان.
 */
class PublicPaymentRedactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
            'services.moyasar.secret_key' => 'test_secret', 'services.moyasar.base_url' => 'https://api.moyasar.com']);
        DB::purge('sqlite'); DB::setDefaultConnection('sqlite'); DB::reconnect('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Setting::query()->create(['whatsapp' => '966500000000']);
        Http::fake(['https://api.moyasar.com/*' => Http::response(['invoices' => [], 'payments' => []], 200)]);
    }

    private function paidContract(User $owner): Contract
    {
        $c = Contract::query()->create(['user_id' => $owner->id, 'contract_type' => 'housing', 'is_completed' => 1, 'step' => 7]);
        DB::table('payments')->insert([
            'name' => 'pay_x', 'contract_uuid' => $c->uuid, 'amount' => 249, 'status' => 'success',
            'payment_method' => 'mada', 'tran_currency' => 'SAR', 'payment_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $c;
    }

    public function test_anonymous_result_hides_internal_ids_and_amount(): void
    {
        $owner = User::query()->create(['email' => 'o@t.l', 'password' => bcrypt('x'), 'is_active' => true]);
        $c = $this->paidContract($owner);

        $data = $this->getJson('/api/v2/payment/result/'.$c->uuid)->assertOk()->json('data');
        $this->assertTrue($data['paid']);
        $this->assertNull($data['contract_id']);
        $this->assertNull($data['payment']);

        $url = $this->getJson('/api/v2/payment/'.$c->uuid)->json();
        $this->assertTrue($url['already_paid']);
        $this->assertArrayNotHasKey('contract_id', $url);
        $this->assertArrayNotHasKey('payment', $url);
    }

    public function test_owner_still_sees_details(): void
    {
        $owner = User::query()->create(['email' => 'o2@t.l', 'password' => bcrypt('x'), 'is_active' => true]);
        $c = $this->paidContract($owner);
        Sanctum::actingAs($owner, ['*']);

        $data = $this->getJson('/api/v2/payment/result/'.$c->uuid)->assertOk()->json('data');
        $this->assertSame($c->id, $data['contract_id']);
        $this->assertEquals(249, $data['payment']['amount']);
    }

    public function test_public_callback_does_not_leak_lessor_change_owner_identity(): void
    {
        $user = User::query()->create(['email' => 'l@t.l', 'password' => bcrypt('x'), 'is_active' => true]);
        $lc = LessorChangeRequest::query()->create([
            'uuid' => LessorChangeRequest::generateUuid(), 'user_id' => $user->id, 'mobile' => '966551234567',
            'old_deed_image' => 'x', 'new_deed_image' => 'y', 'new_owner_id_number' => '1098765432',
            'new_owner_dob' => '10/05/1410', 'new_owner_dob_type' => 'hijri',
            'fee' => 400, 'status' => 'pending_payment', 'platform' => 'web',
        ]);

        $body = $this->postJson('/api/v2/status/'.$lc->uuid, [])->getContent();
        $this->assertStringNotContainsString('1098765432', $body);
        $this->assertStringNotContainsString('lessor_change"', str_replace('"kind":"lessor_change"', '', $body));
    }
}
