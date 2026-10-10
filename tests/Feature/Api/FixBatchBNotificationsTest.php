<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\NotificationDispatch;
use App\Models\Offer;
use App\Models\Role;
use App\Models\Setting;
use App\Modules\Users\Models\User;
use App\Services\CustomerNotificationService;
use App\Support\ContractEndDate;
use App\Support\HijriDate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * دفعة الإصلاحات (ب) — ف8: الإشعارات الذكية (المجدولة + الأحداث + نقاط الإشعارات + سجل الإرسال).
 */
class FixBatchBNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
            'services.firebase.disabled' => true,
            'app.frontend_url' => 'https://contractejar.com',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'RoleSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'ContractStatusSeeder', '--force' => true]);
        Setting::query()->create(['whatsapp' => '966500000000']);
    }

    private function customer(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'عميل', 'mobile' => '0551234567', 'email' => 'c'.uniqid().'@test.local',
            'password' => bcrypt('x'), 'is_active' => true, 'fcm_token' => 'token-'.uniqid(),
        ], $overrides));
    }

    private function contract(User $user, array $overrides = []): Contract
    {
        $contract = Contract::query()->create(array_merge([
            'user_id' => $user->id, 'contract_type' => 'housing', 'instrument_type' => 'electronic',
            'duration_preset' => '1_year', 'total_months' => 12, 'step' => 7,
            'contract_status_id' => ContractStatus::NEW_ID,
        ], $overrides));

        if (isset($overrides['updated_at'])) {
            DB::table('contracts')->where('id', $contract->id)->update(['updated_at' => $overrides['updated_at']]);
        }

        return $contract->fresh();
    }

    public function test_dispatch_command_sends_each_kind_once_per_contract(): void
    {
        $user = $this->customer();
        $notarizedId = ContractStatus::EJAR_AUTHENTICATION_ID;

        $abandoned24 = $this->contract($user, ['step' => 5, 'updated_at' => now()->subHours(30)]);
        $abandoned3d = $this->contract($user, ['step' => 5, 'updated_at' => now()->subHours(100)]);
        $fresh = $this->contract($user, ['step' => 5, 'updated_at' => now()->subHours(2)]);          // لم تمر 24 ساعة
        $awaiting = $this->contract($user, ['step' => 7, 'is_draft' => false, 'updated_at' => now()->subHours(3)]);
        $justSubmitted = $this->contract($user, ['step' => 7, 'is_draft' => false, 'updated_at' => now()->subMinutes(30)]);
        $renew60 = $this->contract($user, [
            'is_completed' => 1, 'contract_status_id' => $notarizedId, 'type_contract_starting_date' => 'gregorian',
            'contract_starting_date' => now()->addDays(58)->subMonths(12)->toDateString(),
        ]);
        $renew30 = $this->contract($user, [
            'is_completed' => 1, 'contract_status_id' => $notarizedId, 'type_contract_starting_date' => 'gregorian',
            'contract_starting_date' => now()->addDays(27)->subMonths(12)->toDateString(),
        ]);
        $farAway = $this->contract($user, [
            'is_completed' => 1, 'contract_status_id' => $notarizedId, 'type_contract_starting_date' => 'gregorian',
            'contract_starting_date' => now()->addDays(120)->subMonths(12)->toDateString(),
        ]);
        // ضيف مدموج: لا يُشعَر.
        $merged = $this->customer(['is_guest' => true, 'fcm_token' => null, 'merged_into_user_id' => $user->id]);
        $this->contract($merged, ['step' => 5, 'updated_at' => now()->subHours(50)]);

        Artisan::call('notifications:dispatch');
        $first = Artisan::output();

        $this->assertStringContainsString('order_abandoned_3d     1', $first);
        $this->assertStringContainsString('order_abandoned_24h    1', $first);
        $this->assertStringContainsString('awaiting_payment_2h    1', $first);
        $this->assertStringContainsString('renewal_60d            1', $first);
        $this->assertStringContainsString('renewal_30d            1', $first);

        $this->assertSame(5, NotificationDispatch::query()->count());
        $this->assertSame(5, Offer::query()->count());

        $kinds = NotificationDispatch::query()->pluck('kind', 'contract_id')->all();
        $this->assertSame('order_abandoned_24h', $kinds[$abandoned24->id]);
        $this->assertSame('order_abandoned_3d', $kinds[$abandoned3d->id]);
        $this->assertSame('awaiting_payment_2h', $kinds[$awaiting->id]);
        $this->assertSame('renewal_60d', $kinds[$renew60->id]);
        $this->assertSame('renewal_30d', $kinds[$renew30->id]);
        $this->assertArrayNotHasKey($fresh->id, $kinds);
        $this->assertArrayNotHasKey($justSubmitted->id, $kinds);
        $this->assertArrayNotHasKey($farAway->id, $kinds);

        $offer = Offer::query()->where('contract_id', $renew60->id)->first();
        $this->assertSame('renewal_60d', $offer->kind);
        $this->assertSame('https://contractejar.com/r/'.$renew60->uuid, $offer->url);
        $this->assertStringContainsString('ينتهي عقدك رقم '.$renew60->uuid.' بعد 58 يوم', $offer->body);

        // التشغيلة الثانية لا تكرّر شيئاً.
        Artisan::call('notifications:dispatch');
        $this->assertSame(5, NotificationDispatch::query()->count());
        $this->assertSame(5, Offer::query()->count());
        $this->assertNotNull(Cache::get('scheduler.last_run'));

        // الصف الذي صار عمره 3 أيام لاحقاً يستلم تذكير الأيام الثلاثة أيضاً (نوع مختلف).
        DB::table('contracts')->where('id', $abandoned24->id)->update(['updated_at' => now()->subHours(80)]);
        Artisan::call('notifications:dispatch');
        $this->assertSame(6, NotificationDispatch::query()->count());
        $this->assertSame(2, NotificationDispatch::query()->where('contract_id', $abandoned24->id)->count());
    }

    public function test_renewal_date_math_for_gregorian_and_hijri_start_dates(): void
    {
        $user = $this->customer();

        $gregorian = $this->contract($user, [
            'type_contract_starting_date' => 'gregorian', 'contract_starting_date' => '2026-01-15', 'total_months' => 24,
        ]);
        $this->assertSame('2028-01-15', ContractEndDate::for($gregorian)->toDateString());

        $hijri = $this->contract($user, [
            'type_contract_starting_date' => 'hijri', 'contract_starting_date' => '01-01-1447', 'total_months' => 12,
        ]);
        // 1 محرم 1448 ≈ 16 يونيو 2026 (التقويم الحسابي ± يوم)
        $end = ContractEndDate::for($hijri);
        $this->assertSame('2026-06', $end->format('Y-m'));
        $this->assertTrue($end->day >= 14 && $end->day <= 18, 'hijri end day '.$end->day);

        $this->assertSame('2025-06', HijriDate::toGregorian(1447, 1, 1)->format('Y-m'));
        $this->assertSame([1448, 1, 1], HijriDate::addMonths(1447, 1, 1, 12));
        $this->assertSame([1447, 12, 15], HijriDate::addMonths(1447, 6, 15, 6));

        $noDate = $this->contract($user, ['contract_starting_date' => null]);
        $this->assertNull(ContractEndDate::for($noDate));
    }

    public function test_status_change_sends_received_and_notarized_notifications_once(): void
    {
        $user = $this->customer();
        $contract = $this->contract($user, ['is_completed' => 1]);
        $service = app(CustomerNotificationService::class);

        // دفعة (هـ): لا مرحلة مسودة — «مستلم من الموظف» إشعار حالة عادي.
        $receivedId = (int) ContractStatus::query()->where('name', 'مستلم من الموظف')->value('id');
        $contract->update(['contract_status_id' => $receivedId]);
        app(\App\Services\ContractStatusHistoryService::class)->record($contract->fresh(['contractStatus']), ['source' => 'admin']);
        $offer = $service->contractStatusChanged($contract->fresh(['contractStatus', 'user']));

        $this->assertNotNull($offer);
        $this->assertSame('status_changed', $offer->kind);
        $this->assertStringContainsString('استلم موظفنا طلبك', $offer->body);
        $this->assertSame('https://contractejar.com/r/'.$contract->uuid, $offer->url);

        // نفس الحالة مرة ثانية → لا تكرار.
        $this->assertNull($service->contractStatusChanged($contract->fresh(['contractStatus', 'user'])));

        $contract->update(['contract_status_id' => ContractStatus::EJAR_AUTHENTICATION_ID]);
        app(\App\Services\ContractStatusHistoryService::class)->record($contract->fresh(['contractStatus']), ['source' => 'admin']);
        $notarized = $service->contractStatusChanged($contract->fresh(['contractStatus', 'user']));

        $this->assertSame('notarized', $notarized->kind);
        $this->assertTrue((bool) ($notarized->data['ask_rating'] ?? false));
        $this->assertStringContainsString('تم توثيق عقدك في إيجار', $notarized->body);

        // حالة عامة (معلق) → تحديث حالة.
        $onHold = (int) ContractStatus::query()->where('name', 'معلق')->value('id');
        $contract->update(['contract_status_id' => $onHold]);
        app(\App\Services\ContractStatusHistoryService::class)->record($contract->fresh(['contractStatus']), ['source' => 'admin']);
        $generic = $service->contractStatusChanged($contract->fresh(['contractStatus', 'user']));
        $this->assertSame('status_changed', $generic->kind);
        $this->assertStringContainsString('معلق', $generic->body);

        $this->assertSame(3, NotificationDispatch::query()->where('contract_id', $contract->id)->count());
        $this->assertSame('disabled', NotificationDispatch::query()->where('kind', 'status_changed')->value('push_result'));
    }

    public function test_notification_endpoints_expose_kind_url_and_read_state(): void
    {
        $user = $this->customer();
        $contract = $this->contract($user);
        $service = app(CustomerNotificationService::class);
        $service->paymentSucceeded($contract->fresh(['user']));
        $service->notify($user, 'offer', 'عرض', 'خصم', ['url' => 'https://contractejar.com/offers'], dedupe: false);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v2/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 2);

        $list = $this->getJson('/api/v2/notifications?keep_unread=1')->assertOk();
        $list->assertJsonPath('data.unread_count', 2);
        $items = $list->json('data.data');
        $this->assertCount(2, $items);
        $this->assertSame('offer', $items[0]['kind']);
        $this->assertSame('https://contractejar.com/offers', $items[0]['url']);
        $this->assertSame('payment_success', $items[1]['kind']);
        $this->assertSame((string) $contract->uuid, $items[1]['order_number']);
        $this->assertSame((string) $contract->uuid, $items[1]['contract_uuid']);
        $this->assertSame('https://contractejar.com/r/'.$contract->uuid, $items[1]['url']);
        $this->assertNull($items[1]['read_at']);
        $this->assertFalse($items[1]['is_read']);

        $this->postJson('/api/v2/notifications/'.$items[1]['id'].'/read')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->assertNotNull(Offer::query()->find($items[1]['id'])->read_at);

        $this->postJson('/api/v2/notifications/read-all')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->getJson('/api/v2/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);

        // إشعار مستخدم آخر → 404
        $other = $this->customer(['mobile' => '0559999999']);
        $foreign = $service->notify($other, 'offer', 'x', 'y', [], dedupe: false);
        $this->postJson('/api/v2/notifications/'.$foreign->id.'/read')->assertStatus(404);
    }

    public function test_admin_manual_send_stores_list_item_and_dispatch_log(): void
    {
        $customer = $this->customer();
        $role = Role::query()->where('name', 'admin')->firstOrFail();
        $employee = Employee::query()->create([
            'name' => 'مدير', 'email' => 'admin'.uniqid().'@test.local', 'password' => Hash::make('secret'),
            'is_active' => true, 'role_id' => $role->id, 'role' => $role->name,
        ]);
        Sanctum::actingAs($employee);

        $this->postJson('/api/admin/notifications/user', [
            'user_id' => $customer->id,
            'title' => 'عرض خاص',
            'body' => 'خصم 10% على توثيق عقدك',
            'kind' => 'offer',
            'url' => 'https://contractejar.com/offers/ramadan',
        ])->assertOk()->assertJsonPath('data.kind', 'offer')->assertJsonPath('data.result.stored', true);

        $offer = Offer::query()->where('user_id', $customer->id)->first();
        $this->assertSame('offer', $offer->kind);
        $this->assertSame('https://contractejar.com/offers/ramadan', $offer->url);

        $this->postJson('/api/admin/notifications/all-users', [
            'title' => 'إعلان', 'body' => 'تحديث جديد', 'kind' => 'announcement',
        ])->assertOk()->assertJsonPath('data.result.recipients', 1);
        $this->assertSame(2, Offer::query()->where('user_id', $customer->id)->count());

        $log = $this->getJson('/api/admin/notification-dispatches')->assertOk();
        $this->assertSame(2, $log->json('data.pagination.total'));
        $this->getJson('/api/admin/notification-dispatches?kind=offer')->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/admin/notification-dispatches?kind=announcement')->assertOk()
            ->assertJsonPath('data.items.0.is_broadcast', true)
            ->assertJsonPath('data.items.0.recipients_count', 1);
        $this->getJson('/api/admin/notification-dispatches?date='.now()->addDays(3)->toDateString())->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }
}
