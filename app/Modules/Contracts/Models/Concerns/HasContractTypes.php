<?php

namespace App\Modules\Contracts\Models\Concerns;

use App\Models\RealEstate;

trait HasContractTypes
{
    public const SKIP_INITIAL_STEPS_INSTRUMENT_TYPES = [
        'lease_renewal',
        'sublease_agreement',
    ];

    public const CONTRACT_TYPES = [
        'housing',
        'commercial',
    ];

    public static function contractTypes(): array
    {
        return self::CONTRACT_TYPES;
    }

    /**
     * @return list<array{key: string, name: string, name_ar: string, name_en: string}>
     */
    public static function contractTypeOptions(): array
    {
        return array_map(
            static fn (string $key): array => [
                'key' => $key,
                'name' => self::contractTypeLabel($key),
                'name_ar' => self::contractTypeLabel($key, 'ar'),
                'name_en' => self::contractTypeLabel($key, 'en'),
            ],
            self::contractTypes()
        );
    }

    public static function contractTypeLabel(string $contractType, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if ($locale === 'en') {
            return match ($contractType) {
                'housing' => 'Housing',
                'commercial' => 'Commercial',
                default => $contractType,
            };
        }

        return match ($contractType) {
            'housing' => 'سكني',
            'commercial' => 'تجاري',
            default => $contractType,
        };
    }

    public static function instrumentTypes(): array
    {
        return RealEstate::instrumentTypes();
    }

    public static function shouldSkipInitialSteps(?string $instrumentType): bool
    {
        return in_array((string) $instrumentType, self::SKIP_INITIAL_STEPS_INSTRUMENT_TYPES, true);
    }

    /**
     * Map API / UI aliases to canonical enum values stored on contracts.instrument_type.
     */
    public static function normalizeInstrumentType(?string $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = strtolower(trim($value));

        $aliases = [
            'electronic_deed_from_the_ministry_of_justice' => 'electronic',
            'electronic_deed' => 'electronic',
            'electronic_deed_from_ministry_of_justice' => 'electronic',
        ];

        $canonical = $aliases[$normalized] ?? $normalized;

        return in_array($canonical, self::instrumentTypes(), true) ? $canonical : $value;
    }

    public static function instrumentTypeLabel(string $instrumentType, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $instrumentType = self::normalizeInstrumentType($instrumentType) ?? $instrumentType;

        if ($locale === 'en') {
            return match ($instrumentType) {
                'electronic' => 'Electronic deed',
                'electronic_tax_register' => 'Electronic deed from the Real Estate Registry',
                'property_ownership_owner_are_deceased_endowment' => 'Owner deceased endowment ownership deed',
                'property_ownership_owner_is_endowment' => 'Ownership deed; property owner is endowment (waqf)',
                'sale_agreement' => 'Sale agreement',
                'electronic_deed_from_the_ministry_of_justice' => 'Electronic deed from Ministry of Justice',
                'economic_cities_authority_suspended' => 'Economic Cities Authority (suspended)',
                'property_ownership_owner_are_deceased' => 'Owner deceased property ownership deed',
                'property_ownership_owner_are_suspended' => 'Ownership deed (owner suspended)',
                'old_handwritten' => 'Old handwritten deed',
                'strong_argument' => 'Adverse possession deed (Hujjat Istihkam)',
                'sublease_agreement' => 'Sublease agreement',
                'lease_renewal' => 'Lease renewal',
                default => $instrumentType,
            };
        }

        // QA-F ORDERS-COM-5 / W-29: نفس التسميات التي يختارها العميل في الموقع/التطبيق
        // (كانت electronic_tax_register ⇒ «سجل ضريبي» و strong_argument ⇒ «السجل العقاري» خطأً).
        return match ($instrumentType) {
            'electronic' => 'صك ملكية إلكتروني من وزارة العدل',
            'electronic_tax_register' => 'صك ملكية إلكتروني من السجل العقاري',
            'property_ownership_owner_are_deceased_endowment' => 'صك ملكية والمالك متوفى',
            'property_ownership_owner_is_endowment' => 'صك ملكية والمالك وقف',
            'sale_agreement' => 'ورقة مبايعة مختومة من مكتب عقاري',
            'electronic_deed_from_the_ministry_of_justice' => 'صك ملكية إلكتروني من وزارة العدل',
            'economic_cities_authority_suspended' => 'وثيقة هيئة المدن الاقتصادية',
            'property_ownership_owner_are_deceased' => 'صك ملكية والمالك متوفى (حصر ورثة)',
            'property_ownership_owner_are_suspended' => 'صك ملكية والمالك وقف',
            'old_handwritten' => 'صك ملكية ورقي',
            'strong_argument' => 'حجة استحكام',
            'sublease_agreement' => 'عقد إيجار من الباطن',
            'lease_renewal' => 'تجديد عقد إيجار',
            default => $instrumentType,
        };
    }

    /**
     * @return list<array{type: string, key: string, name_ar: string, images: list<array{key: string, name_ar: string, name_en: string}>, conditional_images: list<array{key: string, name_ar: string, name_en: string, when: array<string, mixed>}>, required_images: list<array{key: string, name_ar: string, name_en: string}>, conditional_required_images: list<array{key: string, name_ar: string, name_en: string, when: array<string, mixed>}>}>
     */
    public static function instrumentTypeOptions(): array
    {
        return array_map(static function (string $key): array {
            $requirements = self::instrumentTypeImageRequirements($key);

            return [
                'type' => $key,
                'key' => $key,
                'name_ar' => self::instrumentTypeLabel($key, 'ar'),
                'images' => $requirements['required_images'],
                'conditional_images' => $requirements['conditional_required_images'],
                'required_images' => $requirements['required_images'],
                'conditional_required_images' => $requirements['conditional_required_images'],
            ];
        }, self::instrumentTypes());
    }

    /**
     * @return array{
     *     required_images: list<array{key: string, name_ar: string, name_en: string}>,
     *     conditional_required_images: list<array{key: string, name_ar: string, name_en: string, when: array<string, mixed>}>
     * }
     */
    public static function instrumentTypeImageRequirements(?string $instrumentType): array
    {
        $requirements = [
            'required_images' => self::requiredImagesForInstrumentType($instrumentType),
            'conditional_required_images' => self::conditionalRequiredImagesForInstrumentType($instrumentType),
        ];

        return array_merge($requirements, [
            'type' => $instrumentType,
            'images' => $requirements['required_images'],
            'conditional_images' => $requirements['conditional_required_images'],
        ]);
    }

    /**
     * @return list<string>
     */
    public static function deedFrontBackImageFields(): array
    {
        return [
            'image_instrument_from_the_front',
            'image_instrument_from_the_back',
        ];
    }

    /**
     * Deed image fields required for a given instrument type.
     *
     * @return list<string>
     */
    public static function deedImageFieldsForInstrumentType(?string $instrumentType): array
    {
        return [];
    }

    /**
     * @return list<array{key: string, name_ar: string, name_en: string}>
     */
    public static function requiredImagesForInstrumentType(?string $instrumentType): array
    {
        return [];
    }

    /**
     * @return list<array{key: string, name_ar: string, name_en: string, when: array<string, mixed>}>
     */
    public static function conditionalRequiredImagesForInstrumentType(?string $instrumentType): array
    {
        return [];
    }

    public static function instrumentImageFieldLabel(string $field, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $labels = [
            'image_instrument' => ['ar' => 'صورة الصك', 'en' => 'Deed image'],
            'image_instrument_from_the_front' => ['ar' => 'صورة الصك من الأمام', 'en' => 'Deed front image'],
            'image_instrument_from_the_back' => ['ar' => 'صورة الصك من الخلف', 'en' => 'Deed back image'],
            'copy_of_the_endowment_registration_certificate' => ['ar' => 'شهادة تسجيل الوقف', 'en' => 'Endowment registration certificate'],
            'copy_of_the_trusteeship_deed' => ['ar' => 'صك النظارة', 'en' => 'Trusteeship deed'],
            'copy_of_guardians_power_of_attorney_for_agent' => ['ar' => 'وكالة النظار للوكيل', 'en' => 'Guardians power of attorney'],
            'image_address' => ['ar' => 'صورة العنوان', 'en' => 'Address image'],
        ];

        $lang = $locale === 'en' ? 'en' : 'ar';

        return $labels[$field][$lang] ?? $field;
    }
}
