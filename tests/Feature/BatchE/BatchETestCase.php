<?php

namespace Tests\Feature\BatchE;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ContractPricing;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\BatchD\BatchDTestCase;

/**
 * قاعدة اختبارات دفعة (هـ): نفس قاعدة دفعة (د) + أدوات للدفع والرسوم والحوالة.
 */
abstract class BatchETestCase extends BatchDTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.moyasar.driver' => 'moyasar', 'app.frontend_url' => 'https://contractejar.com']);
    }

    /** دفعة ناجحة بقيمة السعر الحي (Moyasar). */
    protected function payFull(Contract $contract, string $gatewayId = 'pay_e_1'): Payment
    {
        $p = $this->payment($contract, ContractPricing::total($contract->fresh()), $gatewayId);
        $p->forceFill(['gateway_payment_id' => $gatewayId, 'kind' => Payment::KIND_ORIGINAL, 'contract_id' => $contract->id])->save();
        $contract->forceFill(['is_completed' => 1])->save();
        app(\App\Services\ContractInvoiceService::class)->forContract($contract->fresh());

        return $p;
    }

    /** موظف بدور محدود + صلاحيات محددة (بدون كامل الوصول). */
    protected function limitedEmployee(array $permissions, bool $actAs = true): Employee
    {
        $role = Role::query()->create(['name' => 'limited_'.uniqid(), 'title_ar' => 'محدود', 'title_en' => 'Limited']);
        $ids = Permission::query()->whereIn('name', $permissions)->pluck('id')->all();
        $role->permissions()->sync($ids);
        $employee = Employee::query()->create([
            'name' => 'موظف محدود', 'email' => 'limited'.uniqid().'@test.local', 'password' => Hash::make('secret'),
            'is_active' => true, 'role_id' => $role->id, 'role' => $role->name,
        ]);
        if ($actAs) {
            Sanctum::actingAs($employee);
        }

        return $employee;
    }

    /** @return array<string, mixed> */
    protected function moyasarInvoiceFake(string $invoiceId = 'inv_e_1'): array
    {
        return ['https://api.moyasar.com/v1/invoices' => \Illuminate\Support\Facades\Http::response([
            'id' => $invoiceId, 'url' => 'https://moyasar.test/pay/'.$invoiceId, 'status' => 'initiated',
        ], 201)];
    }

    protected function fakePng(string $name = 'receipt.png'): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()->image($name, 40, 40);
    }
}
