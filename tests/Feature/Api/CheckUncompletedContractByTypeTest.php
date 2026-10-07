<?php

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Modules\Users\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckUncompletedContractByTypeTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        foreach (['contracts', 'contract_statuses', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_v2_requires_contract_type(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v2/contract/check-uncompleted-contract')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_v2_housing_check_ignores_incomplete_commercial_contract(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeIncompleteContract($user->id, 'commercial', 2);

        $this->getJson('/api/v2/contract/check-uncompleted-contract?contract_type=housing')
            ->assertOk()
            ->assertJsonPath('data.check', false);
    }

    public function test_v2_commercial_check_returns_same_type_incomplete_contract(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeIncompleteContract($user->id, 'housing', 3);
        $commercial = $this->makeIncompleteContract($user->id, 'commercial', 4);

        $this->getJson('/api/v2/contract/check-uncompleted-contract?contract_type=commercial')
            ->assertOk()
            ->assertJsonPath('data.check', true)
            ->assertJsonPath('data.contract_id', $commercial->id)
            ->assertJsonPath('data.uuid', (string) $commercial->uuid)
            ->assertJsonPath('data.step', 4)
            ->assertJsonPath('data.contract_type', 'commercial');
    }

    public function test_v2_housing_check_returns_same_type_when_both_types_exist(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $housing = $this->makeIncompleteContract($user->id, 'housing', 4);
        $this->makeIncompleteContract($user->id, 'commercial', 5);

        $this->getJson('/api/v2/contract/check-uncompleted-contract?contract_type=housing')
            ->assertOk()
            ->assertJsonPath('data.check', true)
            ->assertJsonPath('data.contract_id', $housing->id)
            ->assertJsonPath('data.contract_type', 'housing');
    }

    /**
     * A draft is offered for resume only after deed (1), address (2) and owner (3)
     * were submitted — i.e. `step` >= 4. Earlier drafts are invisible to the customer.
     */
    public function test_v2_check_ignores_drafts_before_owner_step(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeIncompleteContract($user->id, 'housing', 1);
        $this->makeIncompleteContract($user->id, 'housing', 2);
        $this->makeIncompleteContract($user->id, 'housing', 3);

        $this->getJson('/api/v2/contract/check-uncompleted-contract?contract_type=housing')
            ->assertOk()
            ->assertJsonPath('data.check', false);

        $resumable = $this->makeIncompleteContract($user->id, 'housing', 4);

        $this->getJson('/api/v2/contract/check-uncompleted-contract?contract_type=housing')
            ->assertOk()
            ->assertJsonPath('data.check', true)
            ->assertJsonPath('data.contract_id', $resumable->id)
            ->assertJsonPath('data.step', 4);
    }

    public function test_v1_commercial_check_ignores_incomplete_housing_contract(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeIncompleteContract($user->id, 'housing', 1);

        $this->getJson('/api/contract/check-uncompleted-contract?contract_type=commercial')
            ->assertOk()
            ->assertJsonPath('data.check', false);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'fname' => 'Test',
            'lname' => 'User',
            'email' => 'user-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function makeIncompleteContract(int $userId, string $type, int $step): Contract
    {
        return Contract::query()->create([
            'user_id' => $userId,
            'contract_type' => $type,
            'is_completed' => false,
            'is_delete' => false,
            'step' => $step,
        ]);
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
            $table->string('photo')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('platform')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('verification_code')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('contract_type')->nullable();
            $table->unsignedInteger('step')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_delete')->default(false);
            $table->string('app_or_web')->nullable();
            $table->unsignedBigInteger('contract_status_id')->nullable();
            $table->timestamps();
        });
    }
}
