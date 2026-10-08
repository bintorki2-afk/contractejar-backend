<?php

namespace App\Modules\Users\Actions;

use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * حذف الحساب من داخل التطبيق (متطلب App Store 5.1.1(v)).
 *
 * لا نحذف السجلات المالية/العقود (مطلوبة محاسبياً ونظامياً)، بل:
 *  - نلغّي كل توكنات الجلسة (خروج فوري من كل الأجهزة).
 *  - نُخفي/نُجهّل بيانات التعريف الشخصية (الاسم/البريد/الجوال/الصورة/رمز الإشعارات/معرّف جوجل).
 *  - نمسح أكواد التحقق المخزّنة.
 *  - نُعطّل الحساب ونحذفه حذفاً ناعماً (deleted_at) فلا يمكن الدخول به بعدها.
 * العقود والفواتير والمدفوعات تبقى مرتبطة بحساب مُجهّل للأغراض المحاسبية.
 */
class DeleteOwnAccountAction
{
    /**
     * @return array{ok: true, message: string}|array{ok: false, message: string}
     */
    public function execute(?User $user): array
    {
        if (! $user) {
            return ['ok' => false, 'message' => trans('api.profile_not_exist')];
        }

        DB::transaction(function () use ($user) {
            // إلغاء كل التوكنات (خروج من جميع الأجهزة).
            $user->tokens()->delete();

            $anonymizedEmail = 'deleted+'.$user->id.'@removed.invalid';

            $user->forceFill([
                'fname' => null,
                'lname' => null,
                'email' => $anonymizedEmail,
                'mobile' => null,
                'contact_mobile' => null,
                'photo' => null,
                'fcm_token' => null,
                'google_id' => null,
                'remember_token' => null,
                'is_active' => false,
                // مسح أكواد التحقق واستعادة كلمة المرور.
                'verification_code' => null,
                'verification_code_expires_at' => null,
                'verification_attempts' => 0,
                'verification_locked_until' => null,
                'reset_password_code' => null,
                'reset_password_code_expires_at' => null,
                'reset_password_attempts' => 0,
                'reset_password_locked_until' => null,
            ])->save();

            // حذف ناعم: لا يمكن الدخول بالحساب بعد ذلك، والعقود/الفواتير تبقى مرتبطة به مُجهّلاً.
            $user->delete();
        });

        return [
            'ok' => true,
            'message' => trans('api.account_deleted'),
        ];
    }
}
