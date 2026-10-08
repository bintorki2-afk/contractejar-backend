<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractStatusRequest extends FormRequest
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
        $statusId = (int) $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('contract_statuses', 'name')->ignore($statusId)],
            'color' => ['sometimes', 'required', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'color_text' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'description' => ['nullable', 'string'],
            'client_explanation' => ['nullable', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'status_key' => ['nullable', 'string', Rule::in(\App\Models\ContractStatus::KEYS), Rule::unique('contract_statuses', 'status_key')->ignore($statusId)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => trans('validation.required', ['attribute' => 'name']),
            'name.unique' => trans('validation.unique', ['attribute' => 'name']),
            'color.required' => trans('validation.required', ['attribute' => 'color']),
            'color.regex' => trans('validation.regex', ['attribute' => 'color']),
            'color_text.regex' => trans('validation.regex', ['attribute' => 'color_text']),
        ];
    }
}
