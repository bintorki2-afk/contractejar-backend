<?php

namespace App\Modules\Content\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Http\Traits\Responser;
use App\Models\Contract;
use App\Models\Page;
use App\Models\PopupContract;
use App\Models\PaymentMessage;
use App\Models\Question;
use App\Models\Setting;
use App\Http\Resources\Api\V2\PopupContractResource;
use App\Http\Resources\Api\V2\PaymentMessageResource;
use App\Services\AppStatusService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GeneralController extends Controller
{
    use Responser;

    public function cover()
    {
        $cover = Setting::value('cover');

        return $this->apiResponse([
            'cover' => $cover ? url("storage/{$cover}") : null
        ], trans('api.success'));
    }

    public function instrumentTypes()
    {
        return $this->apiResponse(Contract::instrumentTypeOptions(), trans('api.success'));
    }

    public function contractTypes()
    {
        return $this->apiResponse(Contract::contractTypeOptions(), trans('api.success'));
    }

    public function termsAndConditions()
    {
        $termsConditions = Page::where('page', 'term_and_condition')->first();

        $data = [
            'description' => $termsConditions ? $termsConditions['description_trans'] : '',
        ];

        return $this->apiResponse($data, trans('api.success'));
    }

    public function privacy()
    {
        $privacyPolicy = Page::where('page', 'privacy')->first();

        $data = [
            'description' => $privacyPolicy ? $privacyPolicy->description_trans : '',
        ];

        return $this->apiResponse($data, trans('api.success'));
    }

    public function commonQuestions()
    {
        $questions = Question::get();

        return $this->apiResponse(QuestionResource::collection($questions), trans('api.success'));
    }

    public function popupContracts(Request $request)
    {
        if ($request->filled('instrument_type')) {
            $request->merge([
                'instrument_type' => Contract::normalizeInstrumentType($request->input('instrument_type')),
            ]);
        }

        $this->validate($request, [
            'instrument_type' => ['nullable', Rule::in(Contract::instrumentTypes())],
            'context' => 'nullable|in:contract,realestate',
        ]);

        $query = PopupContract::query();

        if ($request->filled('instrument_type')) {
            $query->where('instrument_type', $request->string('instrument_type'));
        }

        if ($request->input('context') === 'contract') {
            $query->where('popup_status_contract', true);
        } elseif ($request->input('context') === 'realestate') {
            $query->where('popup_status_realestate', true);
        }

        $popups = $query->latest('id')->get();

        return $this->apiResponse(
            PopupContractResource::collection($popups),
            trans('api.success')
        );
    }

    public function paymentContent(Request $request)
    {
        $this->validate($request, [
            'type' => 'nullable|in:success,failed',
        ]);

        if ($request->filled('type')) {
            $type = $request->string('type')->toString();
            $message = PaymentMessage::query()->where('type', $type)->first();

            return $this->apiResponse(
                $message ? (new PaymentMessageResource($message))->resolve() : null,
                trans('api.success')
            );
        }

        $success = PaymentMessage::query()->where('type', 'success')->first();
        $failed = PaymentMessage::query()->where('type', 'failed')->first();

        return $this->apiResponse([
            'success' => $success ? (new PaymentMessageResource($success))->resolve() : null,
            'failed' => $failed ? (new PaymentMessageResource($failed))->resolve() : null,
            'items' => PaymentMessageResource::collection(
                collect([$success, $failed])->filter()->values()
            ),
        ], trans('api.success'));
    }

    public function settings()
    {
        $payload = \App\Support\PublicCache::remember(\App\Support\PublicCache::KEY_SETTINGS, fn () => $this->settingsPayload());

        return $this->apiResponse($payload, trans('api.success'))
            ->header('Cache-Control', \App\Support\PublicCache::CACHE_CONTROL);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(): array
    {
        $setting = Setting::query()->first();
        $terms = Page::query()->where('page', 'term_and_condition')->first();
        $privacy = Page::query()->where('page', 'privacy')->first();

        // رقم الدعم (ف18): دولي بالأرقام فقط، مع الرقم الرسمي كقيمة احتياطية.
        $supportWhatsapp = \App\Support\SupportContact::whatsapp($setting);

        $payload = [
            'whatsapp' => $supportWhatsapp,
            'instagram' => $setting->instagram ?? '',
            'twitter' => $setting->twitter ?? '',
            'snapchat' => $setting->snapchat ?? '',
            'facebook' => $setting->facebook ?? '',
            'tiktok' => $setting->tiktok ?? '',
            'linkedIn' => $setting->linkedIn ?? '',
            'whatsapp_contact' => $supportWhatsapp,
            'support_phone' => $supportWhatsapp,
            'support_phone_local' => \App\Support\SupportContact::whatsappLocal($setting),
            'support_whatsapp_url' => \App\Support\SupportContact::whatsappLink($setting),
            'social' => [
                'instagram' => $setting->instagram ?? '',
                'twitter' => $setting->twitter ?? '',
                'snapchat' => $setting->snapchat ?? '',
                'facebook' => $setting->facebook ?? '',
                'tiktok' => $setting->tiktok ?? '',
                'linkedin' => $setting->linkedIn ?? '',
            ],
            'version' => $setting->version ?? null,
            'time_to_documentation_contract' => $setting->time_to_documentation_contract ?? null,
            'open_payment' => $setting->open_payment ?? null,
            'is_open' => $setting->is_open ?? null,
            // دفعة (و) — D8: ساعات العمل (نص من الإعدادات بافتراضي قرار المالك) + صيغة منظّمة لـ Schema.org.
            'working_hours' => Setting::workingHoursText($setting),
            'working_hours_text' => Setting::workingHoursText($setting),
            'working_hours_en' => Setting::workingHoursTextEn($setting),
            'opening_hours' => Setting::openingHours(),
            // دفعة (و) — D9: زر «إرسال الطلب والدفع بعد مشاهدة المسودة».
            'pay_after_draft_enabled' => Setting::payAfterDraftEnabled($setting),
            // دفعة (و) — D7: ملخص التقييمات («4.7 من 3000»).
            'reviews_summary' => Setting::reviewsSummary($setting),
            'sms_user' => $setting?->sms_user,
            'sms_owner' => $setting?->sms_owner,
            'sms_employee' => $setting?->sms_employee,
            'electricity_meter_fee_commercial_tenant' => $setting?->electricity_meter_fee_commercial_tenant !== null
                ? (float) $setting->electricity_meter_fee_commercial_tenant
                : null,
            'electricity_meter_fee_housing_tenant' => $setting?->electricity_meter_fee_housing_tenant !== null
                ? (float) $setting->electricity_meter_fee_housing_tenant
                : null,
            'water_meter_fee_commercial_tenant' => $setting?->water_meter_fee_commercial_tenant !== null
                ? (float) $setting->water_meter_fee_commercial_tenant
                : null,
            'water_meter_fee_housing_tenant' => $setting?->water_meter_fee_housing_tenant !== null
                ? (float) $setting->water_meter_fee_housing_tenant
                : null,
            'document_surcharge_fee' => \App\Support\DocumentSurcharge::fee($setting),
            'document_surcharge_instrument_types' => \App\Support\DocumentSurcharge::INSTRUMENT_TYPES,
            'lessor_change_fee' => $setting && is_numeric($setting->lessor_change_fee) ? (float) $setting->lessor_change_fee : 400.0,
            'terms' => [
                'description' => $terms ? $terms->description_trans : '',
            ],
            'privacy' => [
                'description' => $privacy ? $privacy->description_trans : '',
            ],
            'image_banner' => $this->settingImageUrl($setting?->banner),
            'app_status' => app(AppStatusService::class)->publicPayload(),
        ];

        return $payload;
    }

    private function settingImageUrl(?string $path): ?string
    {
        return $path ? url("storage/{$path}") : null;
    }
}
