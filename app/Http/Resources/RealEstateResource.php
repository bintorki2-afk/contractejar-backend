<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Alkoumi\LaravelHijriDate\Hijri;

class RealEstateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
 
        return [
            'id'=>$this->id,
            'instrument_type' => $this->instrument_type,
            'instrument_number' => $this->instrument_number,
            'instrument_history' => $this->instrument_history,
            'old_handwritten_photo' => $this->old_handwritten_photo,
            'photo_of_the_electronic' => $this->photo_of_the_electronic,
            'strong_argument_photo' => $this->strong_argument_photo,
            'real_estate_registry_number' => $this->real_estate_registry_number,
            'date_first_registration' => $this->date_first_registration,
            'name_owner' => $this->name_owner,
            'national_num' => $this->national_num,
            // QA-F PROPS-9: الخادم يخزّن تاريخ ميلاد المالك في dob_hijri أياً كان نوعه — نفصله حسب النوع.
            'DOB' => $this->DOB ?: ($this->ownerDobType() === 'gregorian' ? $this->dob_hijri : null),
            'dob_hijri' => $this->ownerDobType() === 'gregorian' && ! $this->DOB ? null : $this->dob_hijri,
            'type_dob_property_owner' => $this->ownerDobType(),
            'owner_dob' => $this->dob_hijri ?: $this->DOB,
            'mobile' => $this->mobile,
            // QA-F PROPS-12: رقم دولي جاهز لرابط واتساب (966…).
            'mobile_international' => self::international($this->mobile),
            'iban_bank' => $this->iban_bank,
            'Count_Units' => $this->units()->count(), 
            'name_real_estate' => $this->name_real_estate,
            'number_of_units_in_realestate' => $this->number_of_units_in_realestate,
            'property_type_name' => optional($this->propertyType)->name_ar,
            'property_usages_name' => optional($this->propertyUsages)->name_ar,
            'property_type_id' => $this->property_type_id,
            'type_real_estate_other'=>$this->type_real_estate_other,
            'property_usages_id' => $this->property_usages_id,
            'property_place_name' => optional($this->tenantEntityRegion)->name_ar,
            'property_city_name' => optional($this->tenantEntityCity)->name_ar,
            'property_place_id' => $this->property_place_id,
            'property_city_id' => $this->property_city_id,
            'contract_type' => $this->contract_type,
            'street' => $this->street,
            'postal_code' => $this->postal_code,
            'extra_figure' => $this->extra_figure,
            'neighborhood' => $this->neighborhood,
            'number_of_floors' => $this->number_of_floors,
            'building_number' => $this->building_number,
            'address_url' => $this->address_url,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            // QA-F PROPS-7: مرفقات العقار الحقيقية (الأعمدة الثلاثة أعلاه من جدول العقود ودائماً null).
            ...$this->attachmentUrls(),
            'attachments' => $this->attachmentList(),
            // QA-F PROPS-11: اسم نوع الوثيقة بالعربية.
            'instrument_type_label' => $this->instrument_type ? \App\Models\Contract::instrumentTypeLabel((string) $this->instrument_type, 'ar') : null,
            // QA-F PROPS-17: تمييز المسوّدات (الموقع يُخفي العقار بلا اسم).
            'step' => (int) $this->step,
            'is_complete' => filled($this->name_real_estate),
            'contract_type_label' => $this->contract_type ? \App\Models\Contract::contractTypeLabel((string) $this->contract_type, 'ar') : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    private function ownerDobType(): string
    {
        $type = (string) ($this->getAttributes()['type_dob_property_owner'] ?? '');

        return $type === 'gregorian' ? 'gregorian' : 'hijri';
    }

    /** @return array<string, string|null> */
    private function attachmentUrls(): array
    {
        $out = [];
        foreach (\App\Support\RealEstateImage::FIELDS as $field) {
            $out[$field] = \App\Support\RealEstateImage::signedUrl($this->resource, $field);
        }

        return $out;
    }

    /** @return list<array{key: string, label: string, url: string, is_pdf: bool}> */
    private function attachmentList(): array
    {
        $labels = [
            'image_instrument' => 'صورة الصك',
            'image_address' => 'صورة العنوان الوطني',
            'copy_of_the_endowment_registration_certificate' => 'شهادة تسجيل الوقف',
            'copy_of_the_trusteeship_deed' => 'صك النظارة',
            'copy_of_guardians_power_of_attorney_for_agent' => 'وكالة الولي/الناظر للوكيل',
            'copy_of_the_authorization_or_agency' => 'صورة الوكالة أو التفويض',
        ];
        $list = [];
        foreach ($labels as $field => $label) {
            $url = \App\Support\RealEstateImage::signedUrl($this->resource, $field);
            if ($url === null) {
                continue;
            }
            $raw = (string) ($this->getAttributes()[$field] ?? '');
            $list[] = ['key' => $field, 'label' => $label, 'url' => $url, 'is_pdf' => str_ends_with(strtolower($raw), '.pdf')];
        }

        return $list;
    }

    private static function international(?string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $mobile) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00966')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '966')) {
            return $digits;
        }
        if (str_starts_with($digits, '05')) {
            return '966'.substr($digits, 1);
        }
        if (str_starts_with($digits, '5') && strlen($digits) === 9) {
            return '966'.$digits;
        }

        return $digits;
    }
}
