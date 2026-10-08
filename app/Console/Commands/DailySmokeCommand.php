<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\TelegramService;
use App\Support\SmokeFailure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة (د) — ب19: فحص يومي اصطناعي (06:00) لمسار العميل كاملاً عبر الـ API:
 * جلسة زائر → بدء طلب → الخطوات 1..6 ببيانات ثابتة → الأسعار → الملخص المالي → التتبع → حذف الطلب الاصطناعي.
 * يرسل لتيليجرام «✅ كل شي سليم» أو «❌ عطل: …». البيانات تُعلَّم is_synthetic وتُستبعد من التقارير ثم تُحذف.
 *
 * لا يُنشئ فاتورة دفع حقيقية في Moyasar (يتحقق من حساب المبلغ فقط)، إلا مع --with-payment في وضع الاختبار.
 */
class DailySmokeCommand extends Command
{
    protected $signature = 'qa:daily-smoke
        {--in-process : نداء التطبيق داخلياً بدل HTTP (للاختبارات/المحلي)}
        {--base-url= : رابط الـ API (افتراضياً SMOKE_BASE_URL ثم APP_URL)}
        {--with-payment : اطلب رابط الدفع (في وضع اختبار البوابة فقط)}
        {--no-telegram : لا ترسل لتيليجرام}';

    protected $description = 'Daily synthetic end-to-end API check (guest → wizard → pricing → track → cleanup) with a Telegram report';

    private ?string $token = null;

    /** @var list<array{step: string, ok: bool, ms: int, message: string|null}> */
    private array $results = [];

    public function handle(TelegramService $telegram): int
    {
        $started = microtime(true);
        $contractId = null;
        $uuid = null;

        try {
            $ref = $this->references();

            $guest = $this->step('guest_session', 'POST', '/auth/guest');
            $this->token = (string) data_get($guest, 'data.token', '');
            $this->assert($this->token !== '', 'guest_session', 'لا يوجد توكن زائر');

            $start = $this->step('start', 'POST', '/contract/start', ['contract_type' => 'housing', 'instrument_type' => 'electronic']);
            $contractId = (int) data_get($start, 'data.contract_id');
            $uuid = (string) data_get($start, 'data.uuid');
            $this->assert($contractId > 0, 'start', 'لم يُنشأ الطلب');
            $this->markSynthetic($contractId);

            $this->step('step1', 'POST', '/contract/step1', [
                'id' => $contractId, 'instrument_type' => 'electronic', 'instrument_number' => '440000000001',
                'instrument_history' => '10-05-1440', 'type_instrument_history' => 'hijri',
                'property_type_id' => $ref['property_type_id'], 'property_usages_id' => $ref['property_usage_id'],
                'number_of_floors' => 1, 'number_of_units_in_realestate' => '1',
            ]);
            $this->step('step2', 'POST', '/contract/step2', [
                'id' => $contractId, 'property_place_id' => $ref['region_id'], 'property_city_id' => $ref['city_id'],
                'neighborhood' => 'فحص', 'street' => 'فحص', 'building_number' => '1000', 'postal_code' => '12345', 'extra_figure' => '1234',
            ]);
            $this->step('step3', 'POST', '/contract/step3', [
                'id' => $contractId, 'name_owner' => 'فحص آلي', 'property_owner_id_num' => '1000000001',
                'property_owner_dob_day' => 1, 'property_owner_dob_month' => 1, 'property_owner_dob_year' => 1400, 'type_dob_property_owner' => 'hijri',
                'property_owner_mobile' => '500000001', 'property_owner_iban' => 'SA0000000000000000000001',
            ]);
            $this->step('step4', 'POST', '/contract/step4', [
                'id' => $contractId, 'tenant_entity' => 'person', 'tenant_id_num' => '1000000002',
                'tenant_dob_day' => 1, 'tenant_dob_month' => 1, 'tenant_dob_year' => 1990, 'type_tenant_dob' => 'gregorian', 'tenant_mobile' => '500000002',
            ]);
            $this->step('step5', 'POST', '/contract/step5', [
                'id' => $contractId,
                'units' => [['unit_number' => '1', 'floor_number' => 1, 'unit_area' => 100, 'unit_type_id' => $ref['unit_type_id'], 'unit_usage_id' => $ref['unit_usage_id'], 'tootal_rooms' => 1]],
            ]);
            $this->step('step6', 'POST', '/contract/step6', [
                'id' => $contractId, 'contract_starting_date_day' => 1, 'contract_starting_date_month' => 1,
                'contract_starting_date_year' => (int) now()->addYear()->year, 'type_contract_starting_date' => 'gregorian',
                'contract_term_in_years' => $ref['period_id'], 'annual_rent_amount_for_the_unit' => 10000,
                'payment_type_id' => $ref['payment_type_id'], 'conditions' => false,
            ]);

            $pricing = $this->step('pricing', 'GET', '/pricing');
            $this->assert((float) data_get($pricing, 'data.housing.first_year', 0) > 0, 'pricing', 'الأسعار فارغة');

            $financial = $this->step('financial', 'GET', '/financial/'.$uuid);
            $this->assert($this->firstPositive($financial, ['data.total_price', 'data.total', 'data.total_amount', 'data.price', 'data.doc_fee']) > 0, 'financial', 'المبلغ المستحق صفر');

            if ($this->option('with-payment') && app(\App\Services\MoyasarPaymentService::class)->isTestMode()) {
                $payment = $this->step('payment_url', 'GET', '/payment/'.$uuid);
                $this->assert(filled(data_get($payment, 'data.payment_url') ?? data_get($payment, 'payment_url')), 'payment_url', 'لا يوجد رابط دفع');
            }

            $track = $this->step('track', 'POST', '/contract/track', ['order' => $uuid, 'mobile' => '0500000002']);
            $this->assert((string) data_get($track, 'data.uuid') === $uuid, 'track', 'التتبع لم يرجع الطلب');

            $this->step('delete', 'DELETE', '/contracts/'.$contractId);
        } catch (SmokeFailure $e) {
            // سُجّلت في النتائج
        } catch (\Throwable $e) {
            $this->results[] = ['step' => 'exception', 'ok' => false, 'ms' => 0, 'message' => class_basename($e).': '.mb_strimwidth($e->getMessage(), 0, 200, '…')];
        } finally {
            $this->cleanup($contractId);
        }

        $failed = collect($this->results)->firstWhere('ok', false);
        $seconds = round(microtime(true) - $started, 1);
        $text = $failed === null
            ? "✅ كل شي سليم — الفحص اليومي لمسار العميل (".count($this->results)." خطوة، {$seconds} ث)"
            : "❌ عطل: خطوة «{$failed['step']}» — ".($failed['message'] ?? 'فشل غير معروف')."\n(الفحص اليومي لمسار العميل، {$seconds} ث)";

        foreach ($this->results as $r) {
            $this->line(($r['ok'] ? '✓' : '✗').' '.$r['step'].' '.$r['ms'].'ms'.($r['message'] ? ' — '.$r['message'] : ''));
        }
        $this->line($text);

        if (! $this->option('no-telegram')) {
            $telegram->send($text);
        }

        \Illuminate\Support\Facades\Cache::put('qa.daily_smoke.last', ['ok' => $failed === null, 'at' => now()->toIso8601String(), 'text' => $text], now()->addDays(3));

        return $failed === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function step(string $name, string $method, string $uri, array $data = []): array
    {
        $t = microtime(true);
        [$status, $json] = $this->option('in-process') ? $this->kernelCall($method, $uri, $data) : $this->httpCall($method, $uri, $data);
        $ms = (int) round((microtime(true) - $t) * 1000);

        $ok = $status >= 200 && $status < 300 && (data_get($json, 'success') !== false);
        $message = $ok ? null : 'HTTP '.$status.': '.mb_strimwidth((string) (data_get($json, 'message') ?? json_encode(data_get($json, 'errors'), JSON_UNESCAPED_UNICODE)), 0, 200, '…');
        $this->results[] = ['step' => $name, 'ok' => $ok, 'ms' => $ms, 'message' => $message];

        if (! $ok) {
            throw new SmokeFailure($name);
        }

        return $json;
    }

    private function assert(bool $condition, string $step, string $message): void
    {
        if ($condition) {
            return;
        }
        $this->results[] = ['step' => $step.'_check', 'ok' => false, 'ms' => 0, 'message' => $message];

        throw new SmokeFailure($step);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function httpCall(string $method, string $uri, array $data): array
    {
        $base = rtrim((string) ($this->option('base-url') ?: env('SMOKE_BASE_URL') ?: config('app.url')), '/');
        $request = Http::timeout(30)->acceptJson()->withHeaders(['User-Agent' => 'contractejar-daily-smoke'])
            ->when($this->token, fn ($r) => $r->withToken((string) $this->token));
        $url = $base.'/api/v2'.$uri;
        $response = match ($method) {
            'GET' => $request->get($url, $data),
            'DELETE' => $request->delete($url, $data),
            default => $request->asJson()->post($url, $data),
        };

        return [$response->status(), (array) ($response->json() ?? [])];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function kernelCall(string $method, string $uri, array $data): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
        if ($this->token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token;
        }
        $request = $method === 'GET'
            ? Request::create('/api/v2'.$uri, 'GET', $data, [], [], $server)
            : Request::create('/api/v2'.$uri, $method, [], [], [], $server, json_encode($data, JSON_UNESCAPED_UNICODE));

        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        auth()->forgetGuards();
        app()->forgetInstance('request');

        return [$response->getStatusCode(), (array) (json_decode((string) $response->getContent(), true) ?? [])];
    }

    /** @return array<string, int|null> */
    private function references(): array
    {
        $city = DB::table('cities')->whereNotNull('region_id')->orderBy('id')->first(['id', 'region_id']);
        $period = DB::table('contract_periods')
            ->when(Schema::hasColumn('contract_periods', 'is_active'), fn ($q) => $q->where('is_active', 1))
            ->where('contract_type', 'housing')->orderBy('id')->value('id');

        return [
            'city_id' => $city?->id,
            'region_id' => $city?->region_id,
            'property_type_id' => DB::table('rea_estat_types')->orderBy('id')->value('id'),
            'property_usage_id' => DB::table('rea_estat_usages')->orderBy('id')->value('id'),
            'unit_type_id' => DB::table('unit_types')->orderBy('id')->value('id'),
            'unit_usage_id' => DB::table('unit_usages')->orderBy('id')->value('id'),
            'payment_type_id' => DB::table('payment_types')->orderBy('id')->value('id'),
            'period_id' => $period,
        ];
    }

    private function markSynthetic(int $contractId): void
    {
        if (! Schema::hasColumn('contracts', 'is_synthetic')) {
            return;
        }
        DB::table('contracts')->where('id', $contractId)->update(['is_synthetic' => true]);
        $userId = DB::table('contracts')->where('id', $contractId)->value('user_id');
        if ($userId && Schema::hasColumn('users', 'is_synthetic')) {
            DB::table('users')->where('id', $userId)->update(['is_synthetic' => true]);
        }
    }

    /** يحذف الطلب الاصطناعي وكل ما تعلّق به نهائياً. */
    private function cleanup(?int $contractId): void
    {
        if (! $contractId) {
            return;
        }
        try {
            $contract = Contract::query()->find($contractId);
            if ($contract === null || (Schema::hasColumn('contracts', 'is_synthetic') && ! $contract->is_synthetic)) {
                return; // لا نحذف إلا ما علّمناه
            }
            $userId = (int) $contract->user_id;
            $unitIds = DB::table('contract_units')->where('contract_id', $contractId)->pluck('real_unit_id')->all();

            foreach (['contract_units', 'contract_status_histories', 'contract_activities', 'notification_dispatches', 'offers'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'contract_id')) {
                    DB::table($table)->where('contract_id', $contractId)->delete();
                }
            }
            DB::table('contracts')->where('id', $contractId)->delete();
            if ($unitIds !== []) {
                DB::table('real_units')->whereIn('id', $unitIds)->delete();
            }
            if ($userId > 0 && Schema::hasColumn('users', 'is_synthetic')
                && DB::table('users')->where('id', $userId)->where('is_synthetic', true)->exists()
                && ! DB::table('contracts')->where('user_id', $userId)->exists()) {
                DB::table('personal_access_tokens')->where('tokenable_type', 'like', '%User')->where('tokenable_id', $userId)->delete();
                DB::table('users')->where('id', $userId)->delete();
            }
            $this->results[] = ['step' => 'cleanup', 'ok' => true, 'ms' => 0, 'message' => null];
        } catch (\Throwable $e) {
            $this->results[] = ['step' => 'cleanup', 'ok' => false, 'ms' => 0, 'message' => class_basename($e).': '.mb_strimwidth($e->getMessage(), 0, 150, '…')];
        }
    }

    /** @param list<string> $paths */
    private function firstPositive(array $json, array $paths): float
    {
        foreach ($paths as $path) {
            $v = data_get($json, $path);
            if (is_numeric($v) && (float) $v > 0) {
                return (float) $v;
            }
        }

        return 0.0;
    }
}
