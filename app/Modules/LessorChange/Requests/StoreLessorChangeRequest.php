<?php

namespace App\Modules\LessorChange\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLessorChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $digits = static fn ($v) => is_string($v)
            ? preg_replace('/\D+/', '', strtr($v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']))
            : $v;

        $this->merge([
            'new_owner_id_number' => $digits($this->input('new_owner_id_number')),
            'mobile' => $this->filled('mobile') ? $digits($this->input('mobile')) : null,
            'new_owner_dob_type' => $this->input('new_owner_dob_type', 'hijri'),
        ]);
    }

    public function rules(): array
    {
        return [
            'old_deed_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'new_deed_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'new_owner_id_number' => ['required', 'digits:10'],
            'new_owner_dob_day' => ['required', 'integer', 'min:1', 'max:31'],
            'new_owner_dob_month' => ['required', 'integer', 'min:1', 'max:12'],
            'new_owner_dob_year' => ['required', 'integer', 'min:1300', 'max:2100'],
            'new_owner_dob_type' => ['required', 'in:hijri,gregorian'],
            'mobile' => ['nullable', 'regex:/^(00966|966|0)?5\d{8}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'acknowledged' => ['accepted'], // تنبيه انتقال العقود المرتبطة بالصك القديم
            'platform' => ['nullable', 'in:web,app'],
        ];
    }

    public function messages(): array
    {
        return [
            'old_deed_image.required' => 'أرفق صك المالك القديم.',
            'new_deed_image.required' => 'أرفق صك المالك الجديد.',
            'new_owner_id_number.required' => 'رقم هوية المالك الجديد مطلوب.',
            'new_owner_id_number.digits' => 'رقم الهوية يجب أن يكون 10 أرقام.',
            'new_owner_dob_day.required' => 'تاريخ ميلاد المالك الجديد مطلوب.',
            'new_owner_dob_month.required' => 'تاريخ ميلاد المالك الجديد مطلوب.',
            'new_owner_dob_year.required' => 'تاريخ ميلاد المالك الجديد مطلوب.',
            'mobile.regex' => 'رقم الجوال غير صحيح.',
            'acknowledged.accepted' => 'يجب الإقرار بأن جميع العقود المرتبطة بالصك القديم ستنتقل إلى الصك الجديد.',
            // متابعة دفعة (د) — QA: كل رسائل التحقق بالعربية.
            'old_deed_image.file' => 'صك المالك القديم يجب أن يكون ملفاً.',
            'new_deed_image.file' => 'صك المالك الجديد يجب أن يكون ملفاً.',
            'old_deed_image.mimes' => 'صك المالك القديم يجب أن يكون صورة أو PDF.',
            'new_deed_image.mimes' => 'صك المالك الجديد يجب أن يكون صورة أو PDF.',
            'old_deed_image.max' => 'حجم صك المالك القديم يتجاوز الحد المسموح (10MB).',
            'new_deed_image.max' => 'حجم صك المالك الجديد يتجاوز الحد المسموح (10MB).',
            'new_owner_dob_day.integer' => 'يوم الميلاد غير صحيح.',
            'new_owner_dob_day.min' => 'يوم الميلاد غير صحيح.',
            'new_owner_dob_day.max' => 'يوم الميلاد غير صحيح.',
            'new_owner_dob_month.integer' => 'شهر الميلاد غير صحيح.',
            'new_owner_dob_month.min' => 'شهر الميلاد غير صحيح.',
            'new_owner_dob_month.max' => 'شهر الميلاد غير صحيح.',
            'new_owner_dob_year.integer' => 'سنة الميلاد غير صحيحة.',
            'new_owner_dob_year.min' => 'سنة الميلاد غير صحيحة.',
            'new_owner_dob_year.max' => 'سنة الميلاد غير صحيحة.',
            'new_owner_dob_type.required' => 'نوع تاريخ الميلاد (هجري/ميلادي) مطلوب.',
            'new_owner_dob_type.in' => 'نوع تاريخ الميلاد يجب أن يكون هجرياً أو ميلادياً.',
            'notes.string' => 'الملاحظات يجب أن تكون نصاً.',
            'notes.max' => 'الملاحظات طويلة جداً (الحد 2000 حرف).',
            'acknowledged.required' => 'يجب الإقرار بأن جميع العقود المرتبطة بالصك القديم ستنتقل إلى الصك الجديد.',
            'platform.in' => 'المنصة غير صحيحة.',
        ];
    }
}
