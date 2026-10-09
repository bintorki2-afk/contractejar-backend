<?php

namespace Tests\Feature\Admin;

use App\Models\Payment;
use App\Models\Setting;
use App\Services\Admin\ReportsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportsPerformanceTest extends TestCase
{
    private const HOUSING_PAYMENT = 249;

    private const COMMERCIAL_PAYMENT = 349;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createMinimalSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'sms_logs',
            'operating_expenses',
            'refundable_contracts',
            'received_contracts',
            'coupon_usages',
            'payments',
            'contracts',
            'contract_statuses',
            'contract_periods',
            'employees',
            'settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_performance_returns_every_documented_section(): void
    {
        $this->seedPerformanceFixture();

        $result = $this->performance();

        foreach ([
            'period_label',
            'kpis',
            'conversion_funnel',
            'conversion_leakage',
            'conversion_rates',
            'daily_orders',
            'orders_by_status',
            'by_contract_type',
            'by_employee',
            'operational_metrics',
            'revenue_by_payment_method',
            'pnl',
            'unit_economics',
            'unit_economics_note',
            'financial_summary',
            'by_document_type',
            'correction_errors',
            'refund_requests_by_status',
            'refund_requests_total',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "missing section: {$key}");
        }

        $this->assertSame([
            'total_count' => 4,
            'total' => 4,
            'documented_count' => 2,
            'working_count' => 2,
            'active_count' => 2,
            // دفعة (د) — ب6: «ملغى» = حالة cancelled لطلب غير محذوف؛ الطلب المحذوف لا يُعد إلغاءً.
            'canceled_count' => 0,
            // متابعة دفعة (د): «مسترجعة» = حالة مسترجع أو استرجاع Moyasar؛ طلبات الاسترجاع المؤكدة القديمة منفصلة.
            'refunded_count' => 0,
            'refunded_orders' => 0,
            'refund_requests_confirmed' => 2,
            'revenue' => self::HOUSING_PAYMENT + self::COMMERCIAL_PAYMENT,
            'paid' => 2,
            'delayed_count' => 0,
        ], $result['kpis']);

        $this->assertSame([
            ['label' => 'بداية طلب', 'value' => 5, 'from_previous_pct' => null],
            ['label' => 'طلب مكتمل البيانات', 'value' => 4, 'from_previous_pct' => 80],
            ['label' => 'مسودة عقد', 'value' => 3, 'from_previous_pct' => 75],
            ['label' => 'مدفوع', 'value' => 2, 'from_previous_pct' => 67],
            ['label' => 'موثّق', 'value' => 2, 'from_previous_pct' => 100],
        ], $result['conversion_funnel']);

        $this->assertSame(['count' => 3, 'percent' => 60], $result['conversion_leakage']);

        $rates = collect($result['conversion_rates'])->pluck('value', 'label');
        $this->assertSame(60, $rates['نسبة عدم الإكمال (تسرّب)']);
        $this->assertSame(67, $rates['تحويل المسودة إلى دفع']);
        $this->assertSame(75, $rates['نسبة استلام الطلبات']);
        $this->assertSame(50, $rates['نسبة التوثيق']);
        $this->assertSame(0, $rates['نسبة الإلغاء']); // ب6: ملغى ÷ البداية (لا طلب بحالة «ملغى» هنا)
        $this->assertSame(0, $rates['نسبة الاسترجاع']); // متابعة (د): الطلبات المسترجعة فعلاً ÷ المدفوع (لا طلب مسترجع هنا)

        $this->assertSame([
            ['label' => 'سكني', 'value' => 3, 'revenue' => self::HOUSING_PAYMENT],
            ['label' => 'تجاري', 'value' => 1, 'revenue' => self::COMMERCIAL_PAYMENT],
        ], $result['by_contract_type']);

        $this->assertSame([
            ['employee_id' => 1, 'label' => 'ريان', 'value' => 2],
            ['employee_id' => 2, 'label' => 'سارة', 'value' => 1],
        ], $result['by_employee']);

        $this->assertSame([
            ['label' => 'صك إلكتروني', 'value' => 2, 'revenue' => self::HOUSING_PAYMENT],
            ['label' => 'تجديد عقد إيجار', 'value' => 1, 'revenue' => self::COMMERCIAL_PAYMENT],
        ], $result['by_document_type']);

        // Statuses nobody is sitting in are dropped so the chart isn't padded with zeros.
        $this->assertSame([
            ['stage' => 'status_1', 'label' => 'جديد', 'value' => 2],
            ['stage' => 'status_10', 'label' => 'بانتظار المشرف', 'value' => 2],
        ], $result['orders_by_status']);

        $this->assertSame([
            ['method' => 'creditcard', 'label' => 'بطاقة ائتمان', 'value' => self::HOUSING_PAYMENT],
            ['method' => 'mada', 'label' => 'مدى', 'value' => self::COMMERCIAL_PAYMENT],
        ], collect($result['revenue_by_payment_method'])->sortBy('method')->values()->all());

        $this->assertSame([
            ['label' => 'قيد المراجعة', 'value' => 1],
            ['label' => 'موافق عليه', 'value' => 1],
            ['label' => 'منفّذ', 'value' => 1],
        ], $result['refund_requests_by_status']);
        $this->assertSame(149, $result['refund_requests_total']);

        $this->assertSame([], $result['correction_errors']);

        $pnlByLabel = collect($result['pnl'])->keyBy('label');
        $this->assertSame(598, $pnlByLabel['دخل العملاء (المحصّل)']['value']);
        $this->assertSame(-149, $pnlByLabel['مبالغ مسترجعة']['value']);
        $this->assertSame(449, $pnlByLabel['صافي الإيراد']['value']);
        $this->assertSame(-325, $pnlByLabel['رسوم منصة إيجار']['value']);

        $summary = collect($result['financial_summary'])->pluck('value', 'label');
        $this->assertSame(self::HOUSING_PAYMENT, $summary['توثيق سكني - سنة أولى']);
        $this->assertSame(self::COMMERCIAL_PAYMENT, $summary['توثيق تجاري - سنة أولى']);
        $this->assertSame(449, $summary['الإجمالي']);
        $this->assertTrue(collect($result['financial_summary'])->last()['is_total']);

        $housingUnit = collect($result['unit_economics'])->firstWhere('label', 'توثيق سكني - سنة أولى');
        $this->assertSame(249, $housingUnit['customer_pays']);
        $this->assertSame(125, $housingUnit['ejar_fee']);
        $this->assertSame(7.23, $housingUnit['moyasar_fee']);
        $this->assertSame(116.77, $housingUnit['margin']);
        $this->assertSame(47, $housingUnit['margin_percent']);

        $this->assertSame('كل الفترات', $result['period_label']);
    }

    public function test_contract_type_and_employee_filters_scope_the_aggregates(): void
    {
        $this->seedPerformanceFixture();

        $housing = $this->performance(contractType: 'housing');

        $this->assertSame(3, $housing['kpis']['total_count']);
        $this->assertSame(1, $housing['kpis']['documented_count']);
        $this->assertSame(self::HOUSING_PAYMENT, $housing['kpis']['revenue']);
        $this->assertSame([
            ['label' => 'سكني', 'value' => 3, 'revenue' => self::HOUSING_PAYMENT],
        ], $housing['by_contract_type']);
        $this->assertSame([
            ['label' => 'صك إلكتروني', 'value' => 2, 'revenue' => self::HOUSING_PAYMENT],
        ], $housing['by_document_type']);

        $employee = $this->performance(employeeId: 2);

        $this->assertSame(1, $employee['kpis']['total_count']);
        $this->assertSame(self::COMMERCIAL_PAYMENT, $employee['kpis']['revenue']);
        $this->assertSame([
            ['employee_id' => 2, 'label' => 'سارة', 'value' => 1],
        ], $employee['by_employee']);
        $this->assertSame(1, $employee['operational_metrics']['total_orders']);
    }

    public function test_empty_period_returns_zeros_instead_of_failing(): void
    {
        $this->seedPerformanceFixture();

        $from = Carbon::today()->subMonths(6)->startOfDay();
        $result = $this->performance(range: [$from, $from->copy()->endOfDay()]);

        $this->assertSame(0, $result['kpis']['total_count']);
        $this->assertSame(0, $result['kpis']['revenue']);
        $this->assertSame(['count' => 0, 'percent' => 0], $result['conversion_leakage']);
        $this->assertSame([], $result['orders_by_status']);
        $this->assertSame([], $result['by_document_type']);
        $this->assertSame([], $result['by_employee']);
        $this->assertSame([], $result['revenue_by_payment_method']);
        $this->assertSame([], $result['correction_errors']);
        $this->assertSame(0, $result['refund_requests_total']);
        $this->assertSame(0, $result['operational_metrics']['waiting_count']);
        // دفعة (د) — ب6: لا طلبات ⇒ null («—») بدل 100%.
        $this->assertNull($result['operational_metrics']['sla_percent']);
        $this->assertSame([0, 0, 0, 0, 0], array_column($result['conversion_funnel'], 'value'));

        // A single-day window labels the bar with its weekday name.
        $this->assertCount(1, $result['daily_orders']);
        $this->assertSame(0, $result['daily_orders'][0]['value']);
        $this->assertNotSame('', $result['daily_orders'][0]['label']);
    }

    public function test_receive_latency_lands_in_the_sla_buckets(): void
    {
        $this->seedPerformanceFixture();

        $metrics = $this->performance()['operational_metrics'];

        // Receive latencies seeded at 10, 20 and 45 minutes.
        $this->assertSame(1500, $metrics['avg_wait_seconds']);
        $this->assertSame(2700, $metrics['longest_receive_seconds']);
        $this->assertSame(2, $metrics['late_over_15m']);
        $this->assertSame(1, $metrics['late_over_30m']);
        $this->assertSame(33, $metrics['sla_percent']);
        $this->assertSame(15, $metrics['sla_minutes']);
        $this->assertSame(1, $metrics['waiting_count']);
        $this->assertGreaterThan(0, $metrics['longest_wait_seconds']);
        $this->assertSame(0, $metrics['unclaim_count']);
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array<string, mixed>
     */
    private function performance(
        ?string $contractType = null,
        ?int $employeeId = null,
        ?array $range = null
    ): array {
        return app(ReportsService::class)->performance([
            'key' => $range === null ? 'all' : 'custom',
            'label_ar' => $range === null ? 'كل الفترات' : 'مدة محددة',
            'range' => $range,
            'date_from' => $range === null ? null : $range[0]->toDateString(),
            'date_to' => $range === null ? null : $range[1]->toDateString(),
        ], $contractType, $employeeId, false)->resolve();
    }

    private function seedPerformanceFixture(): void
    {
        Setting::query()->create([
            'moyasar_mada_percent' => 1.75,
            'moyasar_credit_percent' => 2.50,
            'moyasar_fixed_fee' => 1.00,
            'marketing_budget' => 0,
            'operating_budget' => 0,
            'monthly_salaries' => 0,
            'meter_transfer_fee' => 0,
        ]);

        DB::table('employees')->insert([
            ['id' => 1, 'name' => 'ريان', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'سارة', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('contract_statuses')->insert([
            ['id' => 1, 'name' => 'جديد', 'order' => 1, 'is_active' => 1],
            ['id' => 2, 'name' => 'قيد المراجعة', 'order' => 2, 'is_active' => 1],
            ['id' => 4, 'name' => 'ملغى', 'order' => 4, 'is_active' => 1],
            ['id' => 10, 'name' => 'بانتظار المشرف', 'order' => 10, 'is_active' => 1],
        ]);

        $createdAt = Carbon::today()->addHours(9);

        $contracts = [
            // documented + paid, received after 10 minutes
            [100001, 'housing', 6, 1, 0, 10, 'electronic'],
            // documented + paid draft, received after 20 minutes
            [100002, 'commercial', 6, 1, 1, 10, 'lease_renewal'],
            // draft still open, received after 45 minutes
            [100003, 'housing', 6, 0, 1, 1, 'electronic'],
            // never reached the admin queue
            [100004, 'housing', 1, 0, 0, null, 'electronic'],
            // open and still unreceived
            [100005, 'housing', 6, 0, 0, 1, null],
        ];

        foreach ($contracts as [$uuid, $type, $step, $completed, $draft, $statusId, $instrument]) {
            DB::table('contracts')->insert([
                'uuid' => $uuid,
                'user_id' => 1,
                'contract_type' => $type,
                'instrument_type' => $instrument,
                'is_delete' => 0,
                'step' => $step,
                'is_completed' => $completed,
                'is_draft' => $draft,
                'contract_status_id' => $statusId,
                'total_months' => 12,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        DB::table('contracts')->insert([
            'uuid' => 100006,
            'user_id' => 1,
            'contract_type' => 'housing',
            'is_delete' => 1,
            'step' => 6,
            'is_completed' => 0,
            'is_draft' => 0,
            'contract_status_id' => 4,
            'total_months' => 12,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $contractIdByUuid = DB::table('contracts')->pluck('id', 'uuid');

        foreach ([
            [100001, 1, 10],
            [100002, 2, 20],
            [100003, 1, 45],
        ] as [$uuid, $employeeId, $minutes]) {
            $receivedAt = $createdAt->copy()->addMinutes($minutes);

            DB::table('received_contracts')->insert([
                'contract_id' => $contractIdByUuid[$uuid],
                'employee_id' => $employeeId,
                'status' => 'finish',
                'date_of_received' => $receivedAt->toDateString(),
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
        }

        Payment::query()->create([
            'name' => 'housing',
            'amount' => self::HOUSING_PAYMENT,
            'payment_date' => $createdAt->toDateString(),
            'contract_uuid' => '100001',
            'tran_currency' => 'SAR',
            'payment_method' => 'creditcard',
            'payment_brand' => 'visa',
            'status' => 'success',
        ]);

        Payment::query()->create([
            'name' => 'commercial',
            'amount' => self::COMMERCIAL_PAYMENT,
            'payment_date' => $createdAt->toDateString(),
            'contract_uuid' => '100002',
            'tran_currency' => 'SAR',
            'payment_method' => 'mada',
            'payment_brand' => 'mada',
            'status' => 'success',
        ]);

        foreach ([
            ['admin_confirmed' => 0, 'is_refunded' => 0, 'refund_amount' => 200],
            ['admin_confirmed' => 1, 'is_refunded' => 0, 'refund_amount' => 100],
            ['admin_confirmed' => 1, 'is_refunded' => 1, 'refund_amount' => 49],
        ] as $refund) {
            DB::table('refundable_contracts')->insert(array_merge($refund, [
                'contract_id' => $contractIdByUuid[100001],
                'employee_id' => 1,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]));
        }
    }

    private function createMinimalSchema(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('moyasar_fee_percent', 5, 2)->nullable();
            $table->decimal('moyasar_mada_percent', 5, 2)->nullable();
            $table->decimal('moyasar_credit_percent', 5, 2)->nullable();
            $table->decimal('moyasar_fixed_fee', 8, 2)->nullable();
            $table->decimal('marketing_budget', 12, 2)->nullable();
            $table->decimal('monthly_salaries', 12, 2)->nullable();
            $table->decimal('operating_budget', 12, 2)->nullable();
            $table->decimal('meter_transfer_fee', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('contract_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('period')->nullable();
            $table->string('note_ar')->nullable();
            $table->string('contract_type')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('contract_type')->nullable();
            $table->string('instrument_type')->nullable();
            $table->unsignedTinyInteger('is_delete')->default(0);
            $table->unsignedInteger('step')->default(6);
            $table->unsignedTinyInteger('is_completed')->default(1);
            $table->boolean('is_draft')->default(false);
            $table->unsignedBigInteger('contract_status_id')->nullable();
            $table->string('ejar_contract_draft_number')->nullable();
            $table->unsignedBigInteger('contract_term_in_years')->nullable();
            $table->string('duration_preset')->nullable();
            $table->unsignedTinyInteger('duration_years')->nullable();
            $table->unsignedTinyInteger('duration_months')->nullable();
            $table->unsignedSmallInteger('total_months')->nullable();
            $table->string('electricity_meter_ownership')->nullable();
            $table->string('water_meter_ownership')->nullable();
            $table->timestamps();
        });

        Schema::create('received_contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->date('date_of_received')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('payment_date');
            $table->string('contract_uuid');
            $table->string('tran_currency')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_brand')->nullable();
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('sms_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('phone_number');
            $table->text('message');
            $table->string('sms_id')->nullable();
            $table->string('type');
            $table->decimal('cost', 10, 4)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('operating_expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('expense')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('refundable_contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->boolean('admin_confirmed')->default(false);
            $table->boolean('is_refunded')->default(false);
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('coupon_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('contract_uuid')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }
}
