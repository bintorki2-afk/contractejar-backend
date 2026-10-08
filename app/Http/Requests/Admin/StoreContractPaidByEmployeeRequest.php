<?php

namespace App\Http\Requests\Admin;

use App\Models\ContractPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContractPaidByEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('employee_id');
        $this->request->remove('is_paid');
        $this->request->remove('contract_uuid');

        if ($this->has('draft_contract_number')) {
            $this->merge([
                'draft_contract_number' => trim((string) $this->input('draft_contract_number')),
            ]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_mobile' => ['required', 'string', 'max:32'],
            'contract_type' => ['required', 'in:housing,commercial'],
            'contract_period_id' => ['required', 'integer', 'exists:contract_periods,id'],
            'draft_contract_number' => ['required', 'string', 'max:32'],
            'draft_contract_id' => ['nullable', 'integer', 'exists:contracts,id'],
            // مبلغ مخصّص مسموح (ميزة تجارية) لكن ضمن حدود معقولة. (CROSS-7)
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.(float) config('services.moyasar.employee_link_max_amount', 10000)],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('contract_period_id') || ! $this->filled('contract_type')) {
                return;
            }

            $periodType = ContractPeriod::query()
                ->whereKey($this->integer('contract_period_id'))
                ->value('contract_type');

            if ($periodType && $periodType !== $this->input('contract_type')) {
                $validator->errors()->add(
                    'contract_period_id',
                    'مدة العقد لا تطابق نوع العقد المختار.'
                );
            }
        });
    }
}
