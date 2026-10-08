<?php

namespace Tests\Feature\BatchD;

use App\Models\ContractStatusHistory;
use App\Models\ReceivedContract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * دفعة (د) — ب18: reports:weekly-owner يرسل ملخصاً لتيليجرام.
 */
class WeeklyOwnerReportTest extends BatchDTestCase
{
    public function test_weekly_report_content_and_telegram_send(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '123']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $employee = $this->employee('manager', false);
        $c = $this->paidContract(['contract_status_id' => $this->statusId('ejar_authenticated')]);
        $this->payment($c, 349);
        ReceivedContract::query()->create(['contract_id' => $c->id, 'employee_id' => $employee->id, 'status' => 'finish', 'date_of_received' => now()->toDateString()]);
        $p = ContractStatusHistory::query()->create(['contract_id' => $c->id, 'status_type' => 'system', 'status' => 'paid', 'status_label' => 'تم الدفع', 'source' => 'payment']);
        DB::table('contract_status_histories')->where('id', $p->id)->update(['created_at' => now()->subHours(20)]);
        ContractStatusHistory::query()->create(['contract_id' => $c->id, 'status_type' => 'contract', 'status' => 'ejar_authenticated', 'status_label' => 'توثيق', 'source' => 'admin']);

        Artisan::call('reports:weekly-owner');

        Http::assertSent(function ($request) use ($c, $employee) {
            $text = $request->data()['text'] ?? '';

            return str_contains($request->url(), '/sendMessage')
                && ($request->data()['chat_id'] ?? null) === '123'
                && str_contains($text, 'الطلبات: 1')
                && str_contains($text, '349')
                && str_contains($text, '20 ساعة')
                && str_contains($text, '#'.$c->uuid)
                && str_contains($text, $employee->name);
        });
    }

    public function test_skips_quietly_without_config(): void
    {
        config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
        Http::fake();
        $this->assertSame(0, Artisan::call('reports:weekly-owner'));
        Http::assertNothingSent();
    }
}
