<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * فحص (هـ): حذف الحساب من داخل التطبيق (App Store 5.1.1(v)).
 */
class AccountDeletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--force' => true]);
    }

    private function customer(): User
    {
        $user = User::query()->create([
            'fname' => 'ريان', 'lname' => 'الرشيدي',
            'mobile' => '0551112233', 'email' => 'r@test.local',
            'fcm_token' => 'device-token-xyz',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_delete_requires_confirmation(): void
    {
        $this->customer();

        $this->postJson('/api/v2/account/delete', [])->assertStatus(422);
        $this->postJson('/api/v2/account/delete', ['confirm' => false])->assertStatus(422);
    }

    public function test_delete_anonymizes_pii_revokes_tokens_and_keeps_contracts(): void
    {
        $user = $this->customer();
        $contract = Contract::query()->create([
            'uuid' => '900001', 'user_id' => $user->id, 'contract_type' => 'housing',
            'step' => 7, 'is_completed' => 1,
        ]);

        $this->postJson('/api/v2/account/delete', ['confirm' => true])->assertOk();

        // الحساب محذوف حذفاً ناعماً ومُجهّل.
        $fresh = User::withTrashed()->find($user->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertNull($fresh->getRawOriginal('fname'));
        $this->assertNull($fresh->mobile);
        $this->assertNull($fresh->fcm_token);
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertStringContainsString('removed.invalid', (string) $fresh->email);

        // التوكنات أُلغيت.
        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)->count());

        // العقد ما زال محفوظاً (للأغراض المحاسبية).
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'user_id' => $user->id]);
    }
}
