<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/** Apple Pay config is public but must stay disabled until the merchant id + key exist. */
class ApplePayConfigTest extends TestCase
{
    public function test_apple_pay_is_disabled_when_not_configured(): void
    {
        config([
            'services.moyasar.publishable_key' => '',
            'services.moyasar.apple_merchant_id' => '',
        ]);

        $this->getJson('/api/v2/payment/apple-pay/123456')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.reason', 'not_configured');
    }
}
