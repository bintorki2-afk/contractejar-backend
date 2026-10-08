<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\Offer;
use App\Modules\Auth\Services\GuestAccountService;
use App\Modules\Users\Models\User;
use App\Services\MoyasarPaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * المرحلة ٤: جلسة الزائر على الموقع، تتبّع الطلب بدون حساب، ودمج الضيوف في الحساب الموثّق.
 */
class GuestSessionAndTrackingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.url' => 'http://localhost',
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        URL::forceRootUrl('http://localhost');

        $this->createSchema();

        $this->mock(MoyasarPaymentService::class, function ($mock) {
            $mock->shouldReceive('isPaymentConfirmed')->andReturn(false);
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'contract_status_histories', 'received_contracts', 'draft_contract_statuses',
            'contracts', 'contract_statuses', 'offers', 'settings', 'personal_access_tokens', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_guest_session_returns_token_and_can_start_contract(): void
    {
        $response = $this->postJson('/api/v2/auth/guest', ['platform' => 'website'])
            ->assertOk()
            ->assertJsonPath('data.is_guest', true);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        $guest = User::query()->latest('id')->first();
        $this->assertTrue($guest->isGuest());
        $this->assertSame(User::PLATFORM_WEBSITE, $guest->platform);

        // التوكن يعمل على مسارات العملاء (ensure.customer يقبل الضيف لأنه User).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/auth/guest/contact', ['mobile' => '0551234567'])
            ->assertOk()
            ->assertJsonPath('data.contact_mobile', '00966551234567');

        $this->assertSame('00966551234567', $guest->fresh()->contact_mobile);
    }

    public function test_guest_contact_rejects_non_saudi_mobile(): void
    {
        $guest = app(GuestAccountService::class)->create('website');
        Sanctum::actingAs($guest);

        $this->postJson('/api/v2/auth/guest/contact', ['mobile' => '12345'])
            ->assertStatus(422);
    }

    public function test_track_requires_matching_mobile(): void
    {
        $guest = app(GuestAccountService::class)->create('website');
        app(GuestAccountService::class)->setContactMobile($guest, '0551234567');
        $contract = $this->makeContract($guest->id, 7);

        // رقم صحيح + جوال صحيح → ملخص الحالة.
        $this->postJson('/api/v2/contract/track', ['order' => (string) $contract->id, 'mobile' => '0551234567'])
            ->assertOk()
            ->assertJsonPath('data.uuid', (string) $contract->uuid)
            ->assertJsonPath('data.awaiting_payment', true)
            ->assertJsonPath('data.order_number', (string) $contract->uuid);

        // رقم الطلب الظاهر للعميل (uuid من 6 أرقام) يعمل أيضاً.
        $this->postJson('/api/v2/contract/track', ['order' => (string) $contract->uuid, 'mobile' => '966551234567'])
            ->assertOk()
            ->assertJsonPath('data.uuid', (string) $contract->uuid);

        // جوال غير مطابق → 404 (نفس رسالة «غير موجود» لعدم كشف أرقام الطلبات).
        $this->postJson('/api/v2/contract/track', ['order' => (string) $contract->id, 'mobile' => '0559999999'])
            ->assertStatus(404);

        // رقم طلب غير موجود → 404.
        $this->postJson('/api/v2/contract/track', ['order' => '999999', 'mobile' => '0551234567'])
            ->assertStatus(404);
    }

    public function test_track_matches_owner_or_tenant_mobile_on_contract(): void
    {
        $user = $this->makeUser();
        $contract = $this->makeContract($user->id, 7, ['tenant_mobile' => '0557777777']);

        $this->postJson('/api/v2/contract/track', ['order' => (string) $contract->id, 'mobile' => '0557777777'])
            ->assertOk();
    }

    public function test_guest_contracts_merge_into_verified_user_by_mobile(): void
    {
        $service = app(GuestAccountService::class);

        $guestA = $service->create('website');
        $service->setContactMobile($guestA, '0551234567');
        $guestB = $service->create('website');
        $service->setContactMobile($guestB, '966551234567');
        $guestOther = $service->create('website');
        $service->setContactMobile($guestOther, '0550000000');

        $c1 = $this->makeContract($guestA->id, 7);
        $c2 = $this->makeContract($guestB->id, 4);
        $c3 = $this->makeContract($guestOther->id, 7);
        Offer::query()->create(['user_id' => $guestA->id, 'contract_id' => $c1->id, 'title' => 't', 'body' => 'b']);

        $verified = $this->makeUser(['mobile' => '00966551234567']);

        $moved = $service->mergeGuestsInto($verified, '0551234567');

        $this->assertSame(2, $moved);
        $this->assertSame($verified->id, (int) $c1->fresh()->user_id);
        $this->assertSame($verified->id, (int) $c2->fresh()->user_id);
        $this->assertSame($guestOther->id, (int) $c3->fresh()->user_id);
        $this->assertSame($verified->id, (int) Offer::query()->first()->user_id);
        $this->assertSame($verified->id, (int) $guestA->fresh()->merged_into_user_id);
        $this->assertNull($guestOther->fresh()->merged_into_user_id);

        // الدمج لا يتكرر.
        $this->assertSame(0, $service->mergeGuestsInto($verified, '0551234567'));
    }

    public function test_merge_is_noop_for_guest_target(): void
    {
        $service = app(GuestAccountService::class);
        $guest = $service->create('website');

        $this->assertSame(0, $service->mergeGuestsInto($guest, '0551234567'));
    }

    public function test_contracts_index_supports_server_search_and_per_page(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $a = $this->makeContract($user->id, 7, ['name_real_estate' => 'برج الريان']);
        $b = $this->makeContract($user->id, 7, ['name_real_estate' => 'فيلا الحمراء']);
        $c = $this->makeContract($user->id, 7, ['name_real_estate' => 'شقة']);
        // أرقام طلب ثابتة حتى لا يتطابق البحث بالمعرّف مع بداية رقم طلب عشوائي (كان الاختبار متقلباً).
        foreach ([$a, $b, $c] as $i => $contract) {
            DB::table('contracts')->where('id', $contract->id)->update(['uuid' => '90000'.($i + 1)]);
        }

        $this->getJson('/api/v2/contracts?search='.urlencode('الريان'))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $a->id);

        $this->getJson('/api/v2/contracts?search='.$a->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->getJson('/api/v2/contracts?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    private function makeUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'fname' => 'Test',
            'lname' => 'User',
            'email' => 'user-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ], $overrides));
    }

    private function makeContract(int $userId, int $step, array $overrides = []): Contract
    {
        return Contract::query()->create(array_merge([
            'user_id' => $userId,
            'contract_type' => 'housing',
            'is_completed' => false,
            'is_delete' => false,
            'is_draft' => false,
            'step' => $step,
        ], $overrides));
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('fname')->nullable();
            $table->string('lname')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('mobile')->nullable();
            $table->string('contact_mobile')->nullable();
            $table->string('photo')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('platform')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_guest')->default(false);
            $table->unsignedBigInteger('merged_into_user_id')->nullable();
            $table->string('verification_code')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('time_to_documentation_contract')->nullable();
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->text('title')->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        Schema::create('contract_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->text('client_explanation')->nullable();
        });

        Schema::create('draft_contract_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->text('client_explanation')->nullable();
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('contract_type')->nullable();
            $table->unsignedInteger('step')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_delete')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->string('app_or_web')->nullable();
            $table->unsignedBigInteger('contract_status_id')->nullable();
            $table->unsignedBigInteger('draft_contract_status_id')->nullable();
            $table->unsignedBigInteger('real_id')->nullable();
            $table->string('name_real_estate')->nullable();
            $table->string('instrument_number')->nullable();
            $table->string('property_owner_id_num')->nullable();
            $table->string('property_owner_mobile')->nullable();
            $table->string('tenant_id_num')->nullable();
            $table->string('tenant_mobile')->nullable();
            $table->string('mobile_of_property_owner_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('received_contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('status_type', 20)->default('contract');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->string('status');
            $table->string('status_label');
            $table->string('status_color')->nullable();
            $table->text('status_description')->nullable();
            $table->text('client_explanation')->nullable();
            $table->string('source', 40)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
}
