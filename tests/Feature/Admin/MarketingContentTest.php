<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\MarketingServicePage;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketingContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.url' => 'http://localhost',
            'seo_crawl.base_url' => 'https://aqdi.sa',
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        URL::forceRootUrl('http://localhost');

        $this->createSchema();
        $this->actingEmployee();
        $this->seedContent();
    }

    protected function tearDown(): void
    {
        foreach ([
            'payments',
            'contracts',
            'contract_statuses',
            'blogs',
            'marketing_service_pages',
            'personal_access_tokens',
            'role_permissions',
            'permissions',
            'employees',
            'roles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_service_pages_list_and_crud(): void
    {
        $list = $this->getJson('/api/admin/marketing/service-pages')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $list['summary']['total']);
        $this->assertSame(1, $list['summary']['published']);
        $this->assertSame(1, $list['summary']['drafts']);
        $residential = collect($list['items'])->firstWhere('path', '/residential');
        $this->assertSame('منشور', $residential['status_label_ar']);
        $this->assertSame('https://aqdi.sa/residential', $residential['url']);

        $created = $this->postJson('/api/admin/marketing/service-pages', [
            'title' => 'توثيق عقد تجاري',
            'path' => 'commercial',
            'target_keyword' => 'عقد إيجار تجاري',
            'status' => 'draft',
        ])->assertCreated()->json('data');

        $this->assertSame('/commercial', $created['path']);
        $this->assertSame('مسودة', $created['status_label_ar']);

        $this->postJson('/api/admin/marketing/service-pages', [
            'title' => 'مكرر',
            'path' => '/residential',
            'status' => 'draft',
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'المسار مستخدم مسبقاً.');

        $this->putJson('/api/admin/marketing/service-pages/'.$created['id'], [
            'status' => 'published',
        ])->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->deleteJson('/api/admin/marketing/service-pages/'.$created['id'])
            ->assertOk();

        $this->assertNull(MarketingServicePage::query()->find($created['id']));
    }

    public function test_articles_endpoint_includes_attribution_and_queue(): void
    {
        $body = $this->getJson('/api/admin/marketing/articles?period=last_30_days')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $body['summary']['total']);
        $this->assertSame(1, $body['summary']['published']);
        $this->assertSame(1, $body['summary']['scheduled']);
        $this->assertSame(4820, $body['summary']['views']);
        $this->assertSame(200, $body['summary']['attributed_revenue']);
        $this->assertSame('all', $body['categories'][0]['key']);
        $this->assertTrue(collect($body['categories'])->contains(fn ($row) => $row['key'] === 'guides'));

        $published = collect($body['items'])->firstWhere('status', 'published');
        $this->assertSame('منشور', $published['status_label_ar']);
        $this->assertSame(4820, $published['views']);
        $this->assertSame(1, $published['leads']);
        $this->assertSame(200, $published['attributed_revenue']);
        $this->assertGreaterThan(0, $published['words']);
        $this->assertNull($published['scheduled_at']);

        $this->assertSame('scheduled', $body['editorial_queue'][0]['status']);
        $this->assertSame('مجدول', $body['editorial_queue'][0]['status_label_ar']);
        $this->assertNotNull($body['editorial_queue'][0]['scheduled_at']);
    }

    private function seedContent(): void
    {
        MarketingServicePage::query()->create([
            'title' => 'توثيق عقد إيجار سكني',
            'path' => '/residential',
            'target_keyword' => 'عقد إيجار سكني',
            'status' => 'published',
        ]);
        MarketingServicePage::query()->create([
            'title' => 'صفحة مسودة',
            'path' => '/draft-page',
            'status' => 'draft',
        ]);

        $published = Blog::query()->create([
            'title' => 'دليلك الكامل لتوثيق عقد الإيجار',
            'description' => 'محتوى المقال هنا مع عدة كلمات عربية للعد.',
            'slug' => 'ejar-guide',
            'status' => 'published',
            'is_active' => 1,
            'publish_at' => now()->subDays(10),
            'category' => 'guides',
            'category_label_ar' => 'أدلة إرشادية',
            'author' => 'ريان',
            'views_count' => 4820,
        ]);
        Blog::query()->create([
            'title' => 'رسوم توثيق العقود التجارية',
            'description' => 'مسودة',
            'slug' => 'commercial-fees',
            'status' => 'scheduled',
            'is_active' => 0,
            'publish_at' => now()->addDays(3),
            'category' => 'news',
            'category_label_ar' => 'أخبار تنظيمية',
            'author' => 'سارة',
            'views_count' => 0,
        ]);

        $user = User::query()->create([
            'fname' => 'خالد',
            'mobile' => '0500000011',
            'password' => Hash::make('password'),
        ]);
        // utm_* are not mass-assignable; production sets them server-side via
        // AttributionService. Attribution is resolved by joining users, so
        // seeding the user's first touch drives the article attribution.
        $user->forceFill([
            'utm_source' => 'google',
            'utm_content' => $published->slug,
            'utm_campaign' => $published->slug,
        ])->save();
        $paid = Contract::query()->create([
            'user_id' => $user->id,
            'contract_type' => 'housing',
            'step' => 4,
            'is_delete' => 0,
            'is_completed' => 1,
        ]);
        Payment::query()->create([
            'contract_uuid' => (string) $paid->uuid,
            'amount' => 200,
            'status' => 'success',
        ]);
    }

    private function actingEmployee(): void
    {
        $role = Role::query()->create([
            'name' => 'content',
            'title_ar' => 'محتوى',
            'is_active' => true,
        ]);

        foreach ([
            ['analytics.view', 'analytics', 'view'],
            ['analytics.create', 'analytics', 'create'],
            ['analytics.edit', 'analytics', 'edit'],
            ['analytics.delete', 'analytics', 'delete'],
            ['blogs.view', 'blogs', 'view'],
        ] as [$name, $section, $action]) {
            $permission = Permission::query()->create([
                'name' => $name,
                'section' => $section,
                'action' => $action,
                'action_label_ar' => $action,
                'is_active' => true,
            ]);
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs(Employee::query()->create([
            'name' => 'Content Admin',
            'email' => 'content@aqdi.test',
            'password' => Hash::make('password'),
            'is_active' => true,
            'role_id' => $role->id,
        ]));
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('fname')->nullable();
            $table->string('mobile')->nullable();
            $table->string('password')->nullable();
            $table->string('verification_code')->nullable();
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->timestamp('attributed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('contract_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('contract_statuses')->insert(['id' => 1, 'name' => 'جديد']);
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uuid')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->string('contract_type')->nullable();
            $table->string('app_or_web')->nullable();
            $table->unsignedBigInteger('contract_status_id')->nullable();
            $table->string('status')->nullable();
            $table->integer('step')->default(1);
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_delete')->default(false);
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_uuid')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('blogs', function (Blueprint $table): void {
            $table->id();
            $table->longText('description')->nullable();
            $table->text('title')->nullable();
            $table->string('image')->nullable();
            $table->string('slug')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('category_label_ar', 191)->nullable();
            $table->string('author', 191)->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->integer('is_active')->default(0);
            $table->string('status')->default('draft');
            $table->timestamp('publish_at')->nullable();
            $table->timestamps();
        });
        Schema::create('marketing_service_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('path', 191)->unique();
            $table->string('target_keyword', 191)->nullable();
            $table->string('status', 32)->default('draft');
            $table->longText('body')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title_ar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('section')->nullable();
            $table->string('action')->nullable();
            $table->string('action_label_ar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
        });
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('role_id')->nullable();
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
