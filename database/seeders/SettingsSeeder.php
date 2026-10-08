<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'whatsapp' => '966501234567',
            'instagram' => null,
            'twitter' => null,
            'snapchat' => null,
            'facebook' => null,
            'tiktok' => null,
            'linkedIn' => null,
            'whatsapp_contact' => '966501234567',
            'whatsapp_contract' => '966501234567',
            'housing_tax' => 15,
            'electricity_meter_fee_housing_tenant' => 15,
            'water_meter_fee_housing_tenant' => 15,
            'electricity_meter_fee_commercial_tenant' => 25,
            'water_meter_fee_commercial_tenant' => 25,
            'commercial_tax' => 15,
            'application_fees' => 100,
            'open_payment' => true,
            'version' => '1',
            'time_to_documentation_contract' => 7,
            'text_message_user' => 'مرحباً بك في عقدي، نحن هنا لخدمتك في توثيق عقود الإيجار.',
            'text_message_admin' => 'تم استلام طلبك وسيتم معالجته في أقرب وقت.',
        ];

        $setting = Setting::first();
        if ($setting) {
            $setting->update($settings);
        } else {
            Setting::create($settings);
        }
    }
}
