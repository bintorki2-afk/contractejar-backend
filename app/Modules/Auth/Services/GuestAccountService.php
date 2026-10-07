<?php

namespace App\Modules\Auth\Services;

use App\Models\Contract;
use App\Models\Offer;
use App\Models\User;
use App\Modules\Auth\Support\AuthMobile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * جلسات الزوّار على الموقع (بدون حساب).
 *
 * الزائر يحصل على مستخدم «ضيف» + توكن Sanctum حتى يعمل معالج العقد والدفع
 * بنفس مسارات التطبيق تماماً. عند تحقق OTP لاحقاً برقم الجوال نفسه
 * (contact_mobile)، تُنقل طلبات الضيف إلى الحساب الموثّق.
 */
class GuestAccountService
{
    public function create(?string $platform = null): User
    {
        return User::create([
            'is_guest' => true,
            'platform' => $platform ? User::normalizePlatform($platform) : User::PLATFORM_WEBSITE,
            'password' => bcrypt(Str::random(40)),
        ]);
    }

    /** يحفظ رقم تواصل الزائر (واتساب) بصيغة موحّدة. */
    public function setContactMobile(User $guest, string $mobile): void
    {
        $guest->contact_mobile = AuthMobile::normalizeSaudiMobile(trim($mobile));
        $guest->save();
    }

    /**
     * دمج كل الضيوف الذين كتبوا هذا الرقم في الحساب الموثّق.
     * يُستدعى بعد نجاح تحقق OTP فقط (الرقم مُثبت ملكيته).
     *
     * @return int عدد الطلبات المنقولة
     */
    public function mergeGuestsInto(User $verified, string $mobile): int
    {
        if ($verified->isGuest()) {
            return 0;
        }

        $variants = AuthMobile::lookupVariants($mobile);

        $guests = User::query()
            ->where('is_guest', true)
            ->whereNull('merged_into_user_id')
            ->where('id', '!=', $verified->id)
            ->whereIn('contact_mobile', $variants)
            ->get();

        if ($guests->isEmpty()) {
            return 0;
        }

        $moved = 0;
        DB::transaction(function () use ($guests, $verified, &$moved) {
            $guestIds = $guests->pluck('id')->all();

            $moved = Contract::query()->whereIn('user_id', $guestIds)->update(['user_id' => $verified->id]);
            Offer::query()->whereIn('user_id', $guestIds)->update(['user_id' => $verified->id]);

            foreach ($guests as $guest) {
                $guest->merged_into_user_id = $verified->id;
                $guest->save();
                // توكنات الضيف تنتهي: الجلسة الحقيقية الآن هي الحساب الموثّق.
                $guest->tokens()->delete();
            }
        });

        return (int) $moved;
    }
}
