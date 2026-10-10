<?php

namespace Tests\Feature\BatchE;

use App\Models\Permission;

/**
 * دفعة (هـ) — 2.8: إعدادات الحوالة البنكية + القوالب الأربعة + الصلاحيتان الجديدتان + رسالة تعليمات الحوالة.
 */
class SettingsTemplatesPermissionsTest extends BatchETestCase
{
    public function test_bank_settings_round_trip_and_instructions_message(): void
    {
        $this->employee('admin');
        $before = $this->getJson('/api/admin/settings')->assertOk()->json('data.bank_transfer');
        $this->assertFalse($before['is_configured']);

        $this->postJson('/api/admin/settings', ['bank_name' => 'مصرف الراجحي', 'bank_iban' => 'sa03 8000 0000 6080 1016 7519', 'bank_account_name' => 'مؤسسة عقدي العقارية'])->assertOk();
        $after = $this->getJson('/api/admin/settings')->json('data.bank_transfer');
        $this->assertTrue($after['is_configured']);
        $this->assertSame('SA0380000000608010167519', $after['bank_iban']);
        $this->assertSame('مصرف الراجحي', $after['bank_name']);

        $contract = $this->contract(['tenant_mobile' => '0551234567']);
        $msg = $this->getJson('/api/admin/orders/'.$contract->id.'/bank-transfer-message')->assertOk()->json('data');
        $this->assertEquals(249, $msg['amount']);
        $this->assertStringContainsString('SA0380000000608010167519', $msg['message']);
        $this->assertStringContainsString('مصرف الراجحي', $msg['message']);
        $this->assertStringContainsString('249', $msg['message']);
        $this->assertStringStartsWith('https://wa.me/966551234567?text=', $msg['whatsapp_url']);

        // الآيبان لا يصل للعميل.
        \Laravel\Sanctum\Sanctum::actingAs($contract->user, ['*']);
        $json = json_encode($this->getJson('/api/v2/contracts/'.$contract->id)->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SA0380000000608010167519', $json);
        $this->assertStringNotContainsString('bank_iban', json_encode($this->getJson('/api/v2/settings')->json()));
    }

    public function test_templates_and_permissions_catalogue(): void
    {
        $this->employee('admin');
        $keys = collect($this->getJson('/api/admin/message-templates')->json('data.items'))->map(fn ($r) => $r['channel'].':'.$r['key'])->all();
        foreach (['whatsapp:data_request', 'whatsapp:data_request_reminder', 'whatsapp:charge_payment_request', 'whatsapp:bank_transfer_instructions'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->assertNotContains('whatsapp:stage_draft_sent', $keys);
        $this->assertNotContains('push:draft_sent', $keys);
        $tokens = array_column($this->getJson('/api/admin/message-templates')->json('data.placeholders'), 'token');
        foreach (['{items}', '{reason}', '{payment_url}', '{bank}', '{iban}', '{account_name}'] as $t) {
            $this->assertContains($t, $tokens);
        }
        $preview = $this->postJson('/api/admin/message-templates/preview', ['body' => 'حوّل {amount} إلى {bank} — {iban}'])->json('data.body');
        $this->assertStringContainsString('مصرف الراجحي', $preview);

        $this->assertTrue(Permission::query()->where('name', 'payments.record_transfer')->exists());
        $this->assertTrue(Permission::query()->where('name', 'payments.add_fee')->exists());
        $this->assertFalse(Permission::query()->where('name', 'analytics.add_fee')->exists());
    }
}
