<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\LessorChangeRequest;
use App\Models\Payment;
use App\Models\Setting;
use App\Modules\Users\Models\User;
use App\Services\MoyasarPaymentService;
use App\Support\DocFee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * فحص (CROSS-8): تأكيد الدفع يطابق المبلغ (هللات) والعملة مع المستحق قبل اعتماده.
 */
class PaymentAmountVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
            'services.moyasar.secret_key' => 'test_secret',
            'services.moyasar.base_url' => 'https://api.moyasar.com',
            'services.firebase.disabled' => true,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Setting::query()->create(['whatsapp' => '966500000000']);
        DocFee::flushSettingsCache();
    }

    private function housingContract(): Contract
    {
        $user = User::query()->create(['email' => uniqid().'@t.local', 'password' => bcrypt('x'), 'is_active' => true]);

        // سكني سنة، صك إلكتروني، بلا عدادات ⇒ 249 ر.س.
        return Contract::query()->create([
            'user_id' => $user->id, 'contract_type' => 'housing', 'instrument_type' => 'electronic',
            'duration_preset' => '1_year', 'total_months' => 12, 'step' => 7,
        ]);
    }

    private function fakeGatewayPayment(string $id, string $uuid, int $amountMinor, string $currency = 'SAR'): void
    {
        Http::fake([
            "https://api.moyasar.com/v1/payments/{$id}" => Http::response([
                'id' => $id, 'status' => 'paid', 'amount' => $amountMinor, 'currency' => $currency,
                'metadata' => ['contract_uuid' => $uuid], 'source' => ['type' => 'creditcard'],
            ], 200),
            'https://api.moyasar.com/v1/*' => Http::response([], 404),
        ]);
    }

    public function test_exact_amount_is_accepted(): void
    {
        $contract = $this->housingContract();
        $this->fakeGatewayPayment('pay_ok', (string) $contract->uuid, 24900);

        app(MoyasarPaymentService::class)->processIpn(new Request(['id' => 'pay_ok', 'status' => 'paid']), (string) $contract->uuid);

        $this->assertTrue((bool) $contract->fresh()->is_completed);
        $this->assertTrue(Payment::query()->where('contract_uuid', $contract->uuid)->where('status', 'success')->exists());
    }

    public function test_underpayment_is_not_marked_paid_and_is_flagged_for_review(): void
    {
        $contract = $this->housingContract();
        // دفعة مُنشأة من العميل بمفتاح عام بمبلغ 1 ر.س وبيانات العقد.
        $this->fakeGatewayPayment('pay_cheap', (string) $contract->uuid, 100);

        app(MoyasarPaymentService::class)->processIpn(new Request(['id' => 'pay_cheap', 'status' => 'paid']), (string) $contract->uuid);
        $sync = app(MoyasarPaymentService::class)->syncGatewayPaymentStatus((string) $contract->uuid, 'pay_cheap');

        $this->assertFalse((bool) $contract->fresh()->is_completed);
        $this->assertFalse(Payment::query()->where('contract_uuid', $contract->uuid)->where('status', 'success')->exists());
        $this->assertTrue(Payment::query()->where('contract_uuid', $contract->uuid)->where('status', 'pending')
            ->where('name', 'like', 'مراجعة:%')->exists());
        $this->assertSame('amount_mismatch', $sync['reason'] ?? null);
    }

    public function test_wrong_currency_is_not_marked_paid(): void
    {
        $contract = $this->housingContract();
        $this->fakeGatewayPayment('pay_usd', (string) $contract->uuid, 24900, 'USD');

        app(MoyasarPaymentService::class)->processIpn(new Request(['id' => 'pay_usd', 'status' => 'paid']), (string) $contract->uuid);

        $this->assertFalse((bool) $contract->fresh()->is_completed);
        $this->assertFalse(Payment::query()->where('contract_uuid', $contract->uuid)->where('status', 'success')->exists());
    }

    public function test_lessor_change_fee_is_verified(): void
    {
        $user = User::query()->create(['email' => uniqid().'@t.local', 'password' => bcrypt('x'), 'is_active' => true]);
        $request = LessorChangeRequest::query()->create([
            'uuid' => LessorChangeRequest::generateUuid(), 'user_id' => $user->id, 'mobile' => '966551234567',
            'old_deed_image' => 'x', 'new_deed_image' => 'y', 'new_owner_id_number' => '1098765432',
            'new_owner_dob' => '10/05/1410', 'new_owner_dob_type' => 'hijri',
            'fee' => 400, 'status' => 'pending_payment', 'platform' => 'web',
        ]);

        $uuid = (string) $request->uuid;
        Http::fake([
            'https://api.moyasar.com/v1/payments/pay_lc_low' => Http::response([
                'id' => 'pay_lc_low', 'status' => 'paid', 'amount' => 1000, 'currency' => 'SAR', 'metadata' => ['contract_uuid' => $uuid],
            ], 200),
            'https://api.moyasar.com/v1/payments/pay_lc_ok' => Http::response([
                'id' => 'pay_lc_ok', 'status' => 'paid', 'amount' => 40000, 'currency' => 'SAR', 'metadata' => ['contract_uuid' => $uuid],
            ], 200),
            'https://api.moyasar.com/v1/*' => Http::response([], 404),
        ]);

        app(MoyasarPaymentService::class)->processIpn(new Request(['id' => 'pay_lc_low', 'status' => 'paid']), (string) $request->uuid);
        $this->assertNotSame('paid', $request->fresh()->status);

        app(MoyasarPaymentService::class)->processIpn(new Request(['id' => 'pay_lc_ok', 'status' => 'paid']), (string) $request->uuid);
        $this->assertSame('paid', $request->fresh()->status);
    }
}
