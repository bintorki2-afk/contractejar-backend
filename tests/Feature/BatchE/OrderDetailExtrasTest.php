<?php

namespace Tests\Feature\BatchE;

use App\Models\City;
use App\Models\ContractActivity;
use App\Models\ContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\Region;
use App\Models\UnitsReal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * دفعة (هـ) — 2.6: إضافات تفاصيل الطلب (جوال المنشئ، الطلبات السابقة، العنوان، المستند، الوحدات، إيجار) + ترحيل المسودة (E3).
 */
class OrderDetailExtrasTest extends BatchETestCase
{
    public function test_creator_mobile_and_previous_orders_summary(): void
    {
        $this->employee('admin');
        $user = $this->customer('0551234567');
        $user->forceFill(['contact_mobile' => '0551234567'])->save();
        $old1 = $this->paidContract([], $user);
        $this->payFull($old1, 'pay_old1');
        $old2 = $this->contract([], $user);
        $current = $this->paidContract([], $user);
        $this->payFull($current, 'pay_cur');
        // عميل آخر بنفس الجوال (حساب ضيف) — يُحتسب.
        $guest = \App\Modules\Users\Models\User::query()->create(['fname' => 'ضيف', 'lname' => '', 'mobile' => '966551234567', 'email' => 'g'.uniqid().'@t.local', 'password' => bcrypt('x'), 'is_active' => true, 'is_guest' => true]);
        $this->contract([], $guest);

        $d = $this->getJson('/api/admin/orders/'.$current->id)->assertOk()->json('data');
        $this->assertSame('0551234567', $d['creator_mobile']['local']);
        $this->assertSame('966551234567', $d['creator_mobile']['dial']);
        $this->assertSame('https://wa.me/966551234567', $d['creator_mobile']['whatsapp_url']);

        $summary = $d['customer_orders_summary'];
        $this->assertSame(3, $summary['count']);
        $this->assertSame(1, $summary['count_paid']);
        $this->assertSame(2, $summary['count_unpaid']);
        $this->assertSame((string) $old1->uuid, $summary['items'][0]['uuid']); // المدفوع أولاً
        $this->assertSame('paid', $summary['items'][0]['payment_state']['status']);
        $this->assertNotContains((string) $current->uuid, array_column($summary['items'], 'uuid'));
    }

    public function test_address_modes_document_and_units_structure(): void
    {
        Storage::fake('local');
        $this->employee('admin');
        $region = Region::query()->create(['name_ar' => 'الرياض', 'name_en' => 'Riyadh']);
        $city = City::query()->create(['name_ar' => 'الرياض', 'name_en' => 'Riyadh', 'region_id' => $region->id]);
        $user = $this->customer();

        $manual = $this->paidContract([
            'property_place_id' => $region->id, 'property_city_id' => $city->id, 'neighborhood' => 'النرجس', 'street' => 'الأمير', 'building_number' => '1234', 'postal_code' => '12345', 'extra_figure' => '6789',
            'instrument_number' => '440123456789', 'instrument_history' => '1440-05-10', 'type_instrument_history' => 'hijri', 'instrument_type' => 'old_handwritten',
        ], $user);
        $d = $this->getJson('/api/admin/orders/'.$manual->id)->json('data');
        $this->assertSame('manual', $d['address_entry_mode']);
        $this->assertSame(['manual'], $d['address_modes_available']);
        $this->assertSame('النرجس', $d['address']['district']);
        $this->assertSame('1234', $d['address']['building_no']);
        $this->assertSame('6789', $d['address']['additional_no']);
        $this->assertSame('الرياض · الرياض · النرجس · الأمير', $d['address']['line1']);
        $this->assertNull($d['address']['map_url']);
        $this->assertSame('old_handwritten', $d['document']['type_key']);
        $this->assertSame('صك ملكية ورقي', $d['document']['type_label']);
        $this->assertSame('440123456789', $d['document']['deed_number']);
        $this->assertSame('10/05/1440', $d['document']['deed_date_hijri']);
        $this->assertSame('2019-01-16', $d['document']['deed_date_gregorian']);
        $this->assertTrue($d['document']['surcharge_applies']);
        $this->assertSame([], $d['attachments']);

        $map = $this->paidContract(['address_url' => 'https://maps.app.goo.gl/x', 'latitude' => 24.7, 'longitude' => 46.6], $user);
        $d = $this->getJson('/api/admin/orders/'.$map->id)->json('data');
        $this->assertSame('map', $d['address_entry_mode']);
        $this->assertSame('https://maps.app.goo.gl/x', $d['address']['map_url']);
        $this->assertEquals(24.7, $d['address']['lat']);

        $image = $this->paidContract([], $user);
        Storage::disk('local')->put('contracts/deeds/'.$image->id.'/image_address.png', 'x');
        Storage::disk('local')->put('contracts/deeds/'.$image->id.'/image_instrument.png', 'x');
        DB::table('contracts')->where('id', $image->id)->update(['image_address' => 'contracts/deeds/'.$image->id.'/image_address.png', 'image_instrument' => 'contracts/deeds/'.$image->id.'/image_instrument.png']);
        $d = $this->getJson('/api/admin/orders/'.$image->id)->json('data');
        $this->assertSame('image', $d['address_entry_mode']);
        $this->assertSame('image_address', $d['address']['image_key']);
        $this->assertStringContainsString('/deed-image/image_address', $d['address']['image_url']);
        $this->assertSame(['image_instrument', 'image_address'], array_column($d['attachments'], 'key'));
        $this->assertSame(['الصك', 'العنوان الوطني'], array_column($d['attachments'], 'label'));

        // وحدتان مهيكلتان.
        foreach ([1, 2] as $i) {
            $unit = UnitsReal::query()->create(UnitsReal::attributesForApi([
                'user_id' => $user->id, 'unit_number' => (string) (10 + $i), 'floor_number' => (string) $i, 'unit_area' => '150', 'tootal_rooms' => '3', 'The_number_of_halls' => '1',
                'The_number_of_kitchens' => '1', 'The_number_of_toilets' => '2', 'split_ac' => 3, 'window_ac' => 1, 'furnished' => $i === 1, 'type_furnished' => $i === 1 ? 'new' : null,
                'electricity_meter' => true, 'electricity_meter_number' => 'E'.$i, 'electricity_meter_ownership' => 'tenant',
                'water_meter' => true, 'water_meter_number' => 'W'.$i, 'water_meter_ownership' => 'shared', 'water_shared_monthly_fee' => 150,
            ]));
            $image->units()->attach($unit->id, ['real_estate_id' => null]);
        }
        $d = $this->getJson('/api/admin/orders/'.$image->id)->json('data');
        $this->assertSame(2, $d['units_count']);
        $u = $d['units'][0];
        $this->assertSame(1, $u['index']);
        $this->assertSame('11', $u['unit_number']);
        $this->assertSame(4, $u['ac_count']);
        $this->assertSame('سبليت × 3 + شباك × 1', $u['ac_label']);
        $this->assertTrue($u['furnished']);
        $this->assertSame('نعم — أثاث جديد', $u['furnished_label']);
        $this->assertFalse($d['units'][1]['furnished']);
        $this->assertNull($d['units'][1]['furnished_label']);
        $this->assertSame('unit_number', $u['fields_order'][0]);
        $this->assertSame(['electricity', 'water'], array_column($u['meters'], 'kind'));
        $this->assertSame('باسم المستأجر', $u['meters'][0]['ownership_label']);
        $this->assertTrue($u['meters'][1]['shared']);
        $this->assertEquals(150, $u['meters'][1]['monthly_amount']);
        $this->assertSame('مشترك · 150 ر.س/شهر', $u['meters'][1]['summary']);
        // المصدر الوحيد للعدادات: 2 عداد كهرباء باسم المستأجر × 15.
        $this->assertSame(2, $d['meter_fees']['electricity_meter_count']);
    }

    public function test_ejar_entry_progress_persists_per_order(): void
    {
        $employee = $this->employee('manager');
        $contract = $this->paidContract();
        $this->assertFalse($this->getJson('/api/admin/orders/'.$contract->id)->json('data.ejar_entry_progress.lessor.done'));

        $this->putJson('/api/admin/orders/'.$contract->id.'/ejar-entry-progress', ['section' => 'nope', 'done' => true])->assertStatus(422);
        $res = $this->putJson('/api/admin/orders/'.$contract->id.'/ejar-entry-progress', ['section' => 'lessor', 'done' => true])->assertOk()->json('data.ejar_entry_progress');
        $this->assertTrue($res['lessor']['done']);
        $this->assertSame($employee->id, $res['lessor']['by']);
        $this->assertSame($employee->name, $res['lessor']['by_name']);
        $this->assertFalse($res['unit']['done']);

        $this->putJson('/api/admin/orders/'.$contract->id.'/ejar-entry-progress', ['section' => 'unit', 'done' => true])->assertOk();
        $progress = $this->getJson('/api/admin/orders/'.$contract->id)->json('data.ejar_entry_progress');
        $this->assertTrue($progress['lessor']['done']);
        $this->assertTrue($progress['unit']['done']);
        $this->putJson('/api/admin/orders/'.$contract->id.'/ejar-entry-progress', ['section' => 'lessor', 'done' => false])->assertOk()
            ->assertJsonPath('data.ejar_entry_progress.lessor.done', false);
    }

    public function test_draft_status_is_migrated_and_hidden(): void
    {
        $this->employee('admin');
        $draftId = $this->statusId('whatsapp_draft');
        $contract = $this->paidContract(['contract_status_id' => $draftId]);
        DB::table('contract_statuses')->where('id', $draftId)->update(['is_active' => 1]);

        // إعادة تشغيل ترحيل دفعة (هـ) على بيانات قديمة.
        $migration = require base_path('database/migrations/2026_10_10_000100_batch_e_remove_draft_stage.php');
        $migration->up();

        $fresh = $contract->fresh();
        $this->assertSame($this->statusId('received_by_employee'), (int) $fresh->contract_status_id);
        $this->assertTrue(ContractStatusHistory::query()->where('contract_id', $contract->id)->where('status', 'received_by_employee')->where('source', 'migration')->exists());
        $this->assertTrue(ContractActivity::query()->where('contract_id', $contract->id)->where('action', 'status_changed')->exists());
        $this->assertFalse((bool) ContractStatus::query()->find($draftId)->is_active);
        $this->assertFalse(DB::table('message_templates')->whereIn('key', ['draft_sent', 'stage_draft_sent'])->exists());

        $tabs = array_column($this->getJson('/api/admin/orders/status-counts')->json('data.tabs'), 'key');
        $this->assertNotContains('whatsapp_draft', $tabs);
        $this->assertContains('received_by_employee', $tabs);

        // لا يوجد أي مسار يذكر المسودة في رد المراحل.
        $stages = $this->getJson('/api/admin/orders/'.$contract->id.'/stages')->json('data');
        $this->assertSame('received', $stages['current_stage']);
        $this->assertStringNotContainsString('مسودة', json_encode($stages, JSON_UNESCAPED_UNICODE));
    }
}
