<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\ContractWhatsApp;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\RealEstate;
use App\Enums\ReceivedContractStatus;
use App\Models\ReceivedContract;
use App\Models\RefundableContract;
use App\Models\Region;
use App\Models\UnitsReal;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class AnalysisSeeder extends Seeder
{
    private const PAYMENT_MARKER = 'بيانات تحليلات';

    /**
     * Seed dashboard analytics: financial periods, users, employees, contract statuses, locations.
     *
     * Requires: RegionSeeder, CitySeeder, ContractStatusSeeder, UserSeeder, EmployeeSeeder.
     */
    public function run(): void
    {
        $users = User::all();
        $employees = Employee::all();
        $cities = City::all()->keyBy('name_ar');

        $riyadhId = $cities->get('الرياض')?->id ?? City::query()->value('id');
        $jeddahId = $cities->get('جدة')?->id ?? $riyadhId;
        $dammamId = $cities->get('الدمام')?->id ?? $riyadhId;
        $easternRegionId = Region::query()->where('name_ar', 'الشرقية')->value('id')
            ?? Region::query()->value('id');

        if ($users->isEmpty() || $employees->isEmpty()) {
            $this->command?->warn('AnalysisSeeder: run UserSeeder and EmployeeSeeder first.');

            return;
        }

        $this->seedExtraUsers();
        $users = User::all();
        $userIds = $users->pluck('id')->all();
        $employeeIds = $employees->pluck('id')->all();

        $statusIds = $this->resolveContractStatusIds();
        $primaryStatusId = $statusIds['review'] ?? $statusIds['default'] ?? 2;

        $this->command?->info('Seeding analytics data (contract_status_id='.$primaryStatusId.' for status cards)...');

        $this->removePreviousAnalyticsPayments();
        $this->seedVisitors();
        $contracts = $this->seedContracts($userIds, $riyadhId, $jeddahId, $dammamId, $easternRegionId, $statusIds);
        $this->seedFinancialPayments($contracts, $riyadhId, $jeddahId, $dammamId, $easternRegionId);
        $this->seedContractWhatsapp();
        $this->seedReceivedContracts($contracts, $employees);
        $this->seedRefundableContracts($contracts, $employeeIds);
        $this->seedExpenses($employeeIds);
        $this->seedRealEstatesAndUnits($userIds, $riyadhId, $jeddahId, $dammamId);
        $this->seedUnpaidReceivedContracts($contracts, $employeeIds, $userIds);

        $this->command?->info('AnalysisSeeder finished: '.count($contracts).' contracts, payments & related records.');
    }

    /**
     * @return array<string, int>
     */
    private function resolveContractStatusIds(): array
    {
        $map = ContractStatus::query()->pluck('id', 'name')->all();

        return [
            'new' => $map['جديد'] ?? null,
            'review' => $map['قيد المراجعة'] ?? ContractStatus::query()->orderBy('id')->skip(1)->value('id'),
            'completed' => $map['مكتمل'] ?? null,
            'cancelled' => $map['ملغى'] ?? null,
            'pending' => $map['معلق'] ?? null,
            'received' => $map['مستلم'] ?? null,
            'default' => ContractStatus::query()->whereKey(2)->value('id')
                ?? ContractStatus::query()->orderBy('id')->skip(1)->value('id'),
        ];
    }

    private function removePreviousAnalyticsPayments(): void
    {
        Payment::query()->where('name', self::PAYMENT_MARKER)->delete();
    }

    private function seedExtraUsers(): void
    {
        $extra = [
            ['fname' => 'عبدالرحمن', 'lname' => 'القحطاني', 'email' => 'analytics.user1@example.com', 'mobile' => '00966509990001', 'is_active' => true],
            ['fname' => 'ريم', 'lname' => 'الشهري', 'email' => 'analytics.user2@example.com', 'mobile' => '00966509990002', 'is_active' => true],
            ['fname' => 'فهد', 'lname' => 'العنزي', 'email' => 'analytics.user3@example.com', 'mobile' => '00966509990003', 'is_active' => true],
            ['fname' => 'منى', 'lname' => 'الخالدي', 'email' => 'analytics.inactive@example.com', 'mobile' => '00966509990004', 'is_active' => false],
        ];

        foreach ($extra as $row) {
            User::updateOrCreate(
                ['email' => $row['email']],
                array_merge($row, [
                    'password' => bcrypt('User@123'),
                    'email_verified_at' => Carbon::now(),
                ])
            );
        }
    }

    private function seedVisitors(): void
    {
        foreach (range(1, 80) as $i) {
            Visitor::create([
                'ip_address' => '10.0.0.'.($i % 254 + 1),
                'time_visit' => rand(30, 7200),
                'created_at' => $this->randomDateInPeriods(),
            ]);
        }
    }

    /**
     * @param  array<string, int|null>  $statusIds
     * @return array<int, Contract>
     */
    private function seedContracts(
        array $userIds,
        int $riyadhId,
        int $jeddahId,
        int $dammamId,
        int $easternRegionId,
        array $statusIds
    ): array {
        $cityIds = [$riyadhId, $jeddahId, $dammamId];
        $contracts = [];

        $stepConfigs = [
            ['step' => 0, 'is_completed' => 0],
            ['step' => 1, 'is_completed' => 0],
            ['step' => 2, 'is_completed' => 0],
            ['step' => 3, 'is_completed' => 0],
            ['step' => 4, 'is_completed' => 0],
            ['step' => 5, 'is_completed' => 0],
            ['step' => 6, 'is_completed' => 1],
        ];

        $periodBuckets = [
            'today' => fn () => Carbon::today()->addHours(rand(0, 20)),
            'week' => fn () => Carbon::now()->startOfWeek()->addDays(rand(0, 6))->addHours(rand(0, 23)),
            'month' => fn () => Carbon::now()->startOfMonth()->addDays(rand(0, 27))->addHours(rand(0, 23)),
            'year' => fn () => Carbon::now()->startOfYear()->addDays(rand(0, 300))->addHours(rand(0, 23)),
        ];

        $hasStatusColumn = Schema::hasColumn('contracts', 'contract_status_id');

        foreach ($stepConfigs as $config) {
            foreach ($periodBuckets as $period => $dateFactory) {
                $count = match ($period) {
                    'today' => rand(3, 8),
                    'week' => rand(4, 10),
                    'month' => rand(5, 12),
                    'year' => rand(6, 15),
                };

                for ($i = 0; $i < $count; $i++) {
                    $cityId = $cityIds[array_rand($cityIds)];
                    $regionId = $cityId === $dammamId ? $easternRegionId : ($cityId === $riyadhId ? 1 : 2);
                    $createdAt = $dateFactory();

                    $statusId = $this->pickContractStatusId($statusIds, $config['is_completed'], $period);

                    $payload = [
                        'contract_type' => ['housing', 'commercial'][rand(0, 1)],
                        'user_id' => $userIds[array_rand($userIds)],
                        'app_or_web' => ['app', 'web'][rand(0, 1)],
                        'step' => $config['step'],
                        'is_completed' => $config['is_completed'],
                        'is_delete' => 0,
                        'property_city_id' => $cityId,
                        'property_place_id' => $regionId,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];

                    if ($hasStatusColumn && $statusId) {
                        $payload['contract_status_id'] = $statusId;
                    }

                    $contracts[] = Contract::create($payload);
                }
            }
        }

        // Extra batch for contract_status_id = 2 (dashboard status analytics)
        if ($hasStatusColumn && ! empty($statusIds['default'])) {
            foreach (['today', 'week', 'month', 'year'] as $period) {
                for ($i = 0; $i < rand(2, 6); $i++) {
                    $createdAt = $periodBuckets[$period]();
                    $cityId = $cityIds[array_rand($cityIds)];
                    $contracts[] = Contract::create([
                        'contract_type' => 'housing',
                        'user_id' => $userIds[array_rand($userIds)],
                        'app_or_web' => 'web',
                        'step' => rand(2, 5),
                        'is_completed' => 0,
                        'is_delete' => 0,
                        'property_city_id' => $cityId,
                        'property_place_id' => $cityId === $dammamId ? $easternRegionId : 1,
                        'contract_status_id' => $statusIds['default'],
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                }
            }
        }

        return $contracts;
    }

    private function pickContractStatusId(array $statusIds, int $isCompleted, string $period): ?int
    {
        if ($isCompleted && $statusIds['completed']) {
            return $statusIds['completed'];
        }

        if ($period === 'today' && $statusIds['review']) {
            return $statusIds['review'];
        }

        $pool = array_filter([
            $statusIds['review'] ?? null,
            $statusIds['new'] ?? null,
            $statusIds['pending'] ?? null,
            $statusIds['received'] ?? null,
        ]);

        if ($pool === []) {
            return $statusIds['default'] ?? null;
        }

        return $pool[array_rand($pool)];
    }

    /**
     * Income, refunds (failed), and per-city success payments for location analytics.
     *
     * @param  array<int, Contract>  $contracts
     */
    private function seedFinancialPayments(
        array $contracts,
        int $riyadhId,
        int $jeddahId,
        int $dammamId,
        int $easternRegionId
    ): void {
        $amountsByPeriod = [
            'today' => [450, 1200],
            'week' => [800, 2500],
            'month' => [1500, 6000],
            'year' => [3000, 12000],
            'older' => [500, 2000],
        ];

        $periodDates = [
            'today' => Carbon::today()->addHours(rand(1, 10)),
            'week' => Carbon::now()->startOfWeek()->addDays(rand(0, 5)),
            'month' => Carbon::now()->startOfMonth()->addDays(rand(0, 20)),
            'year' => Carbon::now()->startOfYear()->addMonths(rand(0, 10)),
            'older' => Carbon::now()->subYear()->subMonths(rand(1, 6)),
        ];

        foreach ($amountsByPeriod as $period => $range) {
            $createdAt = $periodDates[$period];
            $count = $period === 'today' ? 8 : 5;

            for ($i = 0; $i < $count; $i++) {
                $contract = $contracts[array_rand($contracts)] ?? null;
                if (! $contract) {
                    continue;
                }

                Payment::create([
                    'name' => self::PAYMENT_MARKER,
                    'amount' => rand($range[0], $range[1]),
                    'payment_date' => $createdAt->toDateString(),
                    'contract_uuid' => $contract->uuid,
                    'tran_currency' => 'SAR',
                    'payment_method' => 'mada',
                    'status' => 'success',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            // Refunds (failed) — drives "مسترجع" financial cards
            for ($i = 0; $i < ($period === 'today' ? 2 : 3); $i++) {
                $contract = $contracts[array_rand($contracts)] ?? null;
                if (! $contract) {
                    continue;
                }

                Payment::create([
                    'name' => self::PAYMENT_MARKER,
                    'amount' => rand(200, 1800),
                    'payment_date' => $createdAt->toDateString(),
                    'contract_uuid' => $contract->uuid,
                    'tran_currency' => 'SAR',
                    'payment_method' => 'mada',
                    'status' => 'failed',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
        }

        // City-weighted success payments (الرياض، جدة، الدمام / الشرقية)
        $cityTargets = [
            ['city_id' => $riyadhId, 'amount' => [2000, 8000]],
            ['city_id' => $jeddahId, 'amount' => [1500, 6000]],
            ['city_id' => $dammamId, 'amount' => [1000, 4500]],
        ];

        foreach ($cityTargets as $target) {
            $cityContracts = array_values(array_filter(
                $contracts,
                fn (Contract $c) => (int) $c->property_city_id === (int) $target['city_id']
            ));
            if ($cityContracts === []) {
                continue;
            }

            for ($i = 0; $i < 4; $i++) {
                $contract = $cityContracts[array_rand($cityContracts)];
                $at = $this->randomDateInPeriods();

                Payment::create([
                    'name' => self::PAYMENT_MARKER,
                    'amount' => rand($target['amount'][0], $target['amount'][1]),
                    'payment_date' => $at->toDateString(),
                    'contract_uuid' => $contract->uuid,
                    'tran_currency' => 'SAR',
                    'payment_method' => 'mada',
                    'status' => 'success',
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }

        unset($easternRegionId);
    }

    private function seedContractWhatsapp(): void
    {
        foreach (range(1, 25) as $i) {
            $complete = $i % 3 !== 0;
            ContractWhatsApp::create([
                'mobile_number' => '9665'.rand(10000000, 59999999),
                'addition_date' => $this->randomDateInPeriods(),
                'contract_type' => ['commercial', 'residential'][rand(0, 1)],
                'is_complete' => $complete,
                'amount_paid_by_client' => rand(100, 2500),
                'rental_fees' => rand(500, 8000),
            ]);
        }
    }

    /**
     * @param  array<int, Contract>  $contracts
     */
    private function seedReceivedContracts(array $contracts, $employees): void
    {
        $topEmployee = Employee::query()->where('name', 'محمد العلي')->first()
            ?? $employees->first();

        $weights = [];
        foreach ($employees as $employee) {
            $weights[$employee->id] = $employee->id === $topEmployee?->id ? 12 : 4;
        }

        $alreadyReceivedIds = ReceivedContract::query()->pluck('contract_id')->all();
        $eligible = [];
        foreach ($contracts as $contract) {
            if (in_array($contract->id, $alreadyReceivedIds, true)) {
                continue;
            }
            $eligible[$contract->id] = $contract;
        }

        $sample = array_values($eligible);
        shuffle($sample);
        $sample = array_slice($sample, 0, min(45, count($sample)));

        foreach ($sample as $contract) {
            $employeeId = $this->weightedEmployeeId($weights);
            $isCompleted = (bool) $contract->is_completed;

            $this->upsertReceivedContract(
                $contract,
                $employeeId,
                $isCompleted ? ReceivedContractStatus::Finish : ReceivedContractStatus::Pending
            );
        }
    }

    private function upsertReceivedContract(
        Contract $contract,
        int $employeeId,
        ReceivedContractStatus $status
    ): void {
        $receivedAt = $contract->created_at ?? Carbon::now();

        ReceivedContract::updateOrCreate(
            ['contract_id' => $contract->id],
            [
                'employee_id' => $employeeId,
                'status' => $status,
                'date_of_received' => $receivedAt->toDateString(),
                'created_at' => $receivedAt,
                'updated_at' => Carbon::now(),
            ]
        );
    }

    /**
     * @param  array<int, int>  $weights
     */
    private function weightedEmployeeId(array $weights): int
    {
        $pool = [];
        foreach ($weights as $id => $weight) {
            for ($i = 0; $i < $weight; $i++) {
                $pool[] = $id;
            }
        }

        return $pool[array_rand($pool)];
    }

    /**
     * @param  array<int, Contract>  $contracts
     */
    private function seedRefundableContracts(array $contracts, array $employeeIds): void
    {
        // The return-orders page only lists refund requests whose contract is in the
        // «مسترجع» status (status_key = refunded) — the same set the orders report counts
        // as "returned". We move a sample of PAID contracts to that status and attach the
        // refund requests to them so both views agree. (دفعة د: «قيد المراجعة» لم تعد حالة استرجاع.)
        $refundedId = ContractStatus::refundedId();
        if ($refundedId === null) {
            return;
        }
        $returnable = array_values(array_filter(
            $contracts,
            fn (Contract $c) => (bool) $c->is_completed
        ));
        $sample = array_slice($returnable, 0, min(24, count($returnable)));
        foreach ($sample as $contract) {
            $contract->forceFill(['contract_status_id' => $refundedId])->saveQuietly();
        }

        // Spread refund requests across the four workflow states the return-orders
        // page buckets by, and date several of them today so the page shows real
        // data under its default today filter instead of an empty list.
        $states = [
            [null, false],  // pending    — بانتظار الموافقة
            [true, false],  // processing — قيد المعالجة
            [true, true],   // completed  — تم الاسترجاع
            [false, false], // rejected   — مرفوضة
        ];
        $dates = [
            Carbon::today()->addHours(9),
            Carbon::today()->addHours(13),
            Carbon::now()->subDay(),
            Carbon::now()->startOfWeek()->addDay(),
            Carbon::now()->startOfMonth()->addDays(3),
            Carbon::now()->startOfYear()->addMonths(2),
        ];

        foreach ($sample as $i => $contract) {
            [$adminConfirmed, $isRefunded] = $states[$i % count($states)];

            RefundableContract::create([
                'user_id' => $contract->user_id,
                'contract_id' => $contract->id,
                'employee_id' => $employeeIds[array_rand($employeeIds)],
                'refund_amount' => rand(250, 3200),
                'notes' => 'استرجاع طلب — بيانات تحليلات',
                'admin_confirmed' => $adminConfirmed,
                'is_refunded' => $isRefunded,
                'created_at' => $dates[$i % count($dates)],
            ]);
        }
    }

    private function seedExpenses(array $employeeIds): void
    {
        $templates = [
            ['notes' => 'مصروفات اليوم', 'amount' => [200, 900], 'at' => Carbon::today()],
            ['notes' => 'مصروفات الأسبوع', 'amount' => [500, 1800], 'at' => Carbon::now()->startOfWeek()->addDays(rand(0, 5))],
            ['notes' => 'مصروفات الشهر', 'amount' => [1200, 4500], 'at' => Carbon::now()->startOfMonth()->addDays(rand(0, 20))],
            ['notes' => 'مصروفات العام', 'amount' => [4000, 12000], 'at' => Carbon::now()->startOfYear()->addMonths(rand(0, 8))],
            ['notes' => 'مصروفات إضافية', 'amount' => [300, 1500], 'at' => $this->randomDateInPeriods()],
        ];

        foreach ($templates as $tpl) {
            for ($i = 0; $i < rand(2, 4); $i++) {
                Expense::create([
                    'employee_id' => $employeeIds[array_rand($employeeIds)],
                    'amount' => rand($tpl['amount'][0], $tpl['amount'][1]),
                    'notes' => $tpl['notes'],
                    'created_at' => $tpl['at'] instanceof Carbon ? $tpl['at'] : Carbon::parse($tpl['at']),
                ]);
            }
        }
    }

    private function seedRealEstatesAndUnits(array $userIds, int $riyadhId, int $jeddahId, int $dammamId): void
    {
        $cityIds = [$riyadhId, $jeddahId, $dammamId];

        foreach (array_slice($userIds, 0, min(12, count($userIds))) as $index => $userId) {
            $createdAt = $this->randomDateInPeriods();

            $estate = RealEstate::create([
                'user_id' => $userId,
                'property_city_id' => $cityIds[$index % count($cityIds)],
                'contract_type' => ['housing', 'commercial'][$index % 2],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            if ($index % 2 === 0) {
                UnitsReal::create([
                    'real_estates_units_id' => $estate->id,
                    'user_id' => $userId,
                    'unit_area' => (string) rand(90, 350),
                    'Services' => 0,
                    'created_at' => $createdAt->copy()->addHours(2),
                    'updated_at' => $createdAt->copy()->addHours(2),
                ]);
            }
        }
    }

    /**
     * Contracts received by employee but without successful payment (unpaid orders analytics).
     *
     * @param  array<int, Contract>  $contracts
     */
    private function seedUnpaidReceivedContracts(array $contracts, array $employeeIds, array $userIds): void
    {
        $paidUuids = Payment::query()->where('status', 'success')->pluck('contract_uuid')->all();

        for ($i = 0; $i < 8; $i++) {
            $createdAt = $this->randomDateInPeriods();
            $cityId = $contracts[0]->property_city_id ?? 1;

            $contract = Contract::create([
                'contract_type' => 'commercial',
                'user_id' => $userIds[array_rand($userIds)],
                'app_or_web' => 'app',
                'step' => rand(1, 4),
                'is_completed' => 0,
                'is_delete' => 0,
                'property_city_id' => $cityId,
                'property_place_id' => 1,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $this->upsertReceivedContract(
                $contract,
                $employeeIds[array_rand($employeeIds)],
                ReceivedContractStatus::Pending
            );

            if (! in_array($contract->uuid, $paidUuids, true) && rand(0, 1)) {
                Payment::create([
                    'name' => self::PAYMENT_MARKER,
                    'amount' => rand(100, 800),
                    'payment_date' => $createdAt->toDateString(),
                    'contract_uuid' => $contract->uuid,
                    'tran_currency' => 'SAR',
                    'payment_method' => 'mada',
                    'status' => 'pending',
                    'created_at' => $createdAt,
                ]);
            }
        }
    }

    private function randomDateInPeriods(): Carbon
    {
        $pick = rand(1, 100);

        if ($pick <= 15) {
            return Carbon::today()->addHours(rand(0, 22));
        }
        if ($pick <= 40) {
            return Carbon::now()->startOfWeek()->addDays(rand(0, 6))->addHours(rand(0, 23));
        }
        if ($pick <= 75) {
            return Carbon::now()->startOfMonth()->addDays(rand(0, 28))->addHours(rand(0, 23));
        }

        return Carbon::now()->startOfYear()->addDays(rand(0, 300))->addHours(rand(0, 23));
    }
}
