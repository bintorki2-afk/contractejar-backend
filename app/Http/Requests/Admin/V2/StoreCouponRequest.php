<?php

namespace App\Http\Requests\Admin\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code_coupon' => ['required', 'string', 'max:255', Rule::unique('coupons', 'code_coupon')],
            'type_coupon' => ['required', 'in:ratio,value'],
            // نسبة ≤ 100، والمبلغ الثابت ≥ 0 (DASHBOARD-10).
            'value_coupon' => array_merge(['required', 'numeric', 'min:0'], $this->input('type_coupon') === 'ratio' ? ['max:100'] : []),
            'date_start' => ['required', 'date'],
            'date_end' => ['required', 'date', 'after_or_equal:date_start'],
            'usage' => ['required', 'integer', 'min:0'],
            'usage_of_user' => ['required', 'integer', 'min:0'],
            'is_review' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value_coupon.max' => 'نسبة الخصم يجب ألا تتجاوز 100%.',
            'value_coupon.min' => 'قيمة الخصم يجب ألا تكون سالبة.',
            'date_end.after_or_equal' => 'تاريخ نهاية الكوبون يجب أن يكون في تاريخ البداية أو بعده.',
        ];
    }
}
