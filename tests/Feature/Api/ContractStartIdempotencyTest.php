<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** فحص (WEBSITE-3): تكرار contract/start بنفس مفتاح منع التكرار يعيد نفس العقد. */
class ContractStartIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite'); DB::setDefaultConnection('sqlite'); DB::reconnect('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_same_key_returns_same_contract_and_other_key_or_none_creates_new(): void
    {
        $user = User::query()->create(['email' => 's@t.l', 'password' => bcrypt('x'), 'is_active' => true]);
        Sanctum::actingAs($user, ['*']);

        $a = $this->postJson('/api/v2/contract/start', ['contract_type' => 'housing'], ['Idempotency-Key' => 'draft-abc'])
            ->assertSuccessful()->json('data.contract_id');
        $b = $this->postJson('/api/v2/contract/start', ['contract_type' => 'housing'], ['Idempotency-Key' => 'draft-abc'])
            ->assertSuccessful()->json('data.contract_id');
        $c = $this->postJson('/api/v2/contract/start', ['contract_type' => 'housing', 'client_reference' => 'draft-abc'])
            ->assertSuccessful()->json('data.contract_id');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertSame(1, Contract::query()->where('user_id', $user->id)->count());

        $this->postJson('/api/v2/contract/start', ['contract_type' => 'housing'], ['Idempotency-Key' => 'draft-xyz'])->assertSuccessful();
        $this->postJson('/api/v2/contract/start', ['contract_type' => 'housing'])->assertSuccessful();
        $this->assertSame(3, Contract::query()->where('user_id', $user->id)->count());

        // مستخدم آخر بنفس المفتاح لا يحصل على عقد الأول.
        $other = User::query()->create(['email' => 'o@t.l', 'password' => bcrypt('x'), 'is_active' => true]);
        Sanctum::actingAs($other, ['*']);
        $d = $this->postJson('/api/v2/contract/start', ['contract_type' => 'housing'], ['Idempotency-Key' => 'draft-abc'])->json('data.contract_id');
        $this->assertNotSame($a, $d);
    }
}
