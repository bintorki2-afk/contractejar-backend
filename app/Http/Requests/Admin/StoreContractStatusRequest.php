<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreContractStatusRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:contract_statuses,name'],
            'color' => ['required', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'color_text' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'description' => ['nullable', 'string'],
            'client_explanation' => ['nullable', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'status_key' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Models\ContractStatus::KEYS), 'unique:contract_statuses,status_key'],
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
