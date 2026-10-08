<?php

namespace Tests\Feature\Users;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationsTest extends TestCase
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
        DB::reconnect('sqlite');
        URL::forceRootUrl('http://localhost');

        $this->createMinimalSchema();
    }

    protected function tearDown(): void
    {
        foreach (['offers', 'personal_access_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_notifications_list_returns_ok_using_is_read_not_read_at(): void
    {
        $userId = DB::table('users')->insertGetId([
            'fname' => 'عميل',
            'lname' => 'تجريبي',
            'email' => 'client@example.com',
            'mobile' => '00966500000000',
            'password' => Hash::make('password'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->findOrFail($userId);

        Offer::query()->create([
            'user_id' => $user->id,
            'title' => 'إشعار تجريبي',
            'body' => 'هذا إشعار للتجربة',
            'is_active' => true,
            'is_read' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.unread_notifications', 1)
            ->assertJsonPath('data.data.0.title', 'إشعار تجريبي')
            ->assertJsonPath('data.data.0.is_read', false);
    }

    private function createMinimalSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('fname')->nullable();
            $table->string('lname')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('fcm_token')->nullable();
            $table->string('photo')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('title')->nullable();
            $table->text('body')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(false);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->string('kind', 48)->nullable();
            $table->string('url', 500)->nullable();
            $table->json('data')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('lessor_change_request_id')->nullable();
            $table->timestamps();
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
    }
}
