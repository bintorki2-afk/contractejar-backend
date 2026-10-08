<?php

namespace App\Http\Requests\Admin\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCouponRequest extends FormRequest
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
        $id = (int) $this->route('id');
        // النوع المُرسل أو نوع الكوبون الحالي — حتى لا تُعدَّل القيمة وحدها لنسبة > 100%.
        $type = $this->input('type_coupon')
            ?? \App\Models\Coupon::query()->whereKey($id)->value('type_coupon');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code_coupon' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('coupons', 'code_coupon')->ignore($id),
            ],
            'type_coupon' => ['sometimes', 'required', 'in:ratio,value'],
            'value_coupon' => array_merge(['sometimes', 'required', 'numeric', 'min:0'], $type === 'ratio' ? ['max:100'] : []),
            'date_start' => ['sometimes', 'required', 'date'],
            'date_end' => ['sometimes', 'required', 'date', 'after_or_equal:date_start'],
            'usage' => ['sometimes', 'required', 'integer', 'min:0'],
            'usage_of_user' => ['sometimes', 'required', 'integer', 'min:0'],
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
