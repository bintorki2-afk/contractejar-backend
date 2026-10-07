<?php

namespace App\Modules\Auth\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Services\GuestAccountService;
use App\Modules\Auth\Support\AuthMobile;
use App\Shared\Responses\Responser;
use Illuminate\Http\Request;

/**
 * جلسة زائر للموقع: توكن بدون حساب حتى يكتمل العقد ويُدفع.
 * نفس شكل استجابة تسجيل الدخول ({user, token}) حتى لا يتغيّر شيء في العميل.
 */
class GuestSessionController extends Controller
{
    use Responser;

    public function __construct(private readonly GuestAccountService $guests) {}

    /** POST /auth/guest — ينشئ ضيفاً ويُرجع توكنه. */
    public function start(Request $request)
    {
        $data = $request->validate([
            'platform' => ['nullable', 'string', 'max:32'],
            'fcm_token' => ['nullable', 'string', 'max:512'],
        ]);

        $guest = $this->guests->create($data['platform'] ?? 'website');

        if (! empty($data['fcm_token'])) {
            $guest->fcm_token = $data['fcm_token'];
            $guest->save();
        }

        // استجابة خفيفة (بدون إحصاءات العقارات/الطلبات التي يحمّلها UserResource).
        return $this->apiResponse([
            'user' => [
                'id' => $guest->id,
                'is_guest' => true,
                'platform' => $guest->platform,
            ],
            'token' => $guest->createToken('guest_token')->plainTextToken,
            'is_guest' => true,
        ], trans('api.success'));
    }

    /** POST /auth/guest/contact — يحفظ رقم الواتساب الذي كتبه الزائر (للتتبع والدمج لاحقاً). */
    public function contact(Request $request)
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'regex:/^(00966|966|0)?5\d{8}$/'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $this->guests->setContactMobile($user, $data['mobile']);

        return $this->apiResponse([
            'contact_mobile' => AuthMobile::normalizeSaudiMobile($data['mobile']),
        ], trans('api.success'));
    }
}
