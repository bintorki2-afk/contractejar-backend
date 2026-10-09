<?php

namespace App\Support;

/**
 * Extra fields required when an admin changes a contract / draft status.
 *
 * يُحدَّد بالمفتاح الثابت للحالة (`status_key`) أولاً ثم بالاسم العربي.
 * دفعة (د): لا أرقام ثابتة لـ «مسترجع» (كانت 2) أو «بانتظار المشرف» (كانت 10) — الرقم 2 في الكتالوج
 * المزروع هو «قيد المراجعة». يبقى 8/9 احتياطاً فقط للمسودة/التوثيق.
 */
class ContractStatusCase
{
    public const EJAR_AUTHENTICATION = 'ejar_authentication';

    public const WAITING_SUPERVISOR = 'waiting_supervisor';

    public const RETURN = 'return';

    public const SEND_DRAFT = 'send_draft';

    public const EJAR_AUTHENTICATION_ID = 9;

    public const SEND_DRAFT_ID = 8;

    public const DEED_TYPES = ['paper', 'electronic', 'other'];

    public const CONTACT_MODES = ['same', 'another'];

    public static function resolve(?int $statusId, ?string $statusName, ?string $statusKey = null): ?string
    {
        $normalized = self::normalizeName($statusName);

        if ($statusKey !== null && $statusKey !== '') {
            $byKey = match ($statusKey) {
                'ejar_authenticated' => self::EJAR_AUTHENTICATION,
                'waiting_supervisor' => self::WAITING_SUPERVISOR,
                'whatsapp_draft' => self::SEND_DRAFT,
                'refunded' => self::RETURN,
                default => null,
            };
            if ($byKey !== null) {
                return $byKey;
            }
            // حالة بمفتاح معروف آخر (جديد/قيد المراجعة/…) لا تحتاج حقولاً إضافية — لا نعود للرقم.
            if (in_array($statusKey, \App\Models\ContractStatus::KEYS, true)) {
                return null;
            }
        }

        $hasName = $normalized !== '';

        if (str_contains($normalized, 'توثيق العقد في ايجار') || (! $hasName && $statusId === self::EJAR_AUTHENTICATION_ID)) {
            return self::EJAR_AUTHENTICATION;
        }

        if (str_contains($normalized, 'بانتظار المشرف')) {
            return self::WAITING_SUPERVISOR;
        }

        if (
            (str_contains($normalized, 'مسودة') && str_contains($normalized, 'واتساب'))
            || (! $hasName && $statusId === self::SEND_DRAFT_ID)
        ) {
            return self::SEND_DRAFT;
        }

        if (str_contains($normalized, 'استرجاع') || str_contains($normalized, 'مسترجع')) {
            return self::RETURN;
        }

        return null;
    }

    /**
     * Form schema for the admin UI (also appended on status list rows).
     *
     * @return array{key: string, fields: list<array<string, mixed>>}|null
     */
    public static function schemaFor(?int $statusId, ?string $statusName, ?string $statusKey = null): ?array
    {
        $key = self::resolve($statusId, $statusName, $statusKey);
        if ($key === null) {
            return null;
        }

        return [
            'key' => $key,
            'fields' => self::fields($key),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fields(string $key): array
    {
        return match ($key) {
            self::EJAR_AUTHENTICATION => [
                [
                    'name' => 'deed_type',
                    'aliases' => ['deed_addition_method', 'addition_method'],
                    'type' => 'select',
                    'required' => true,
                    'label_ar' => 'نوع الصك / طريقة الإضافة',
                    'label_en' => 'Deed Type / Addition Method',
                    'options' => [
                        ['value' => 'paper', 'label_ar' => 'ورقي', 'label_en' => 'Paper'],
                        ['value' => 'electronic', 'label_ar' => 'إلكتروني', 'label_en' => 'Electronic'],
                        ['value' => 'other', 'label_ar' => 'أخرى', 'label_en' => 'Other'],
                    ],
                ],
                [
                    'name' => 'deed_number',
                    'type' => 'string',
                    'required' => true,
                    'label_ar' => 'رقم الصك',
                    'label_en' => 'Deed Number',
                ],
            ],
            self::WAITING_SUPERVISOR => [
                [
                    'name' => 'ejar_contract_number',
                    'type' => 'string',
                    'required' => true,
                    'label_ar' => 'رقم عقد إيجار',
                    'label_en' => 'Ejar Contract Number',
                ],
                [
                    'name' => 'notes',
                    'aliases' => ['ejar_status_notes'],
                    'type' => 'text',
                    'required' => false,
                    'label_ar' => 'ملاحظات',
                    'label_en' => 'Notes',
                ],
            ],
            self::RETURN => [
                [
                    'name' => 'attachment',
                    'aliases' => ['file', 'status_attachment'],
                    'type' => 'file',
                    'required' => false,
                    'label_ar' => 'مرفق',
                    'label_en' => 'File Attachment',
                ],
            ],
            self::SEND_DRAFT => [
                [
                    'name' => 'ejar_contract_draft_number',
                    'aliases' => ['draft_number'],
                    'type' => 'string',
                    'required' => true,
                    'label_ar' => 'رقم مسودة عقد إيجار',
                    'label_en' => 'Ejar Contract Draft Number',
                ],
                [
                    'name' => 'contact_number_mode',
                    'aliases' => ['contact_choice'],
                    'type' => 'select',
                    'required' => true,
                    'label_ar' => 'رقم التواصل',
                    'label_en' => 'Contact Number',
                    'options' => [
                        ['value' => 'same', 'label_ar' => 'نفس الرقم', 'label_en' => 'Same number'],
                        ['value' => 'another', 'label_ar' => 'رقم آخر', 'label_en' => 'Another number'],
                    ],
                ],
                [
                    'name' => 'contact_number',
                    'aliases' => ['new_contact_number'],
                    'type' => 'string',
                    'required' => false,
                    'required_if' => ['contact_number_mode', 'another'],
                    'label_ar' => 'رقم التواصل الجديد',
                    'label_en' => 'New contact number',
                ],
            ],
            default => [],
        };
    }

    public static function normalizeDeedType(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = self::normalizeName($value);

        return match ($normalized) {
            'paper', 'ورقي', 'paper_deed' => 'paper',
            'electronic', 'الكتروني', 'صك الكتروني', 'electronic_deed' => 'electronic',
            'other', 'اخرى', 'other_deed' => 'other',
            default => in_array($normalized, self::DEED_TYPES, true) ? $normalized : null,
        };
    }

    public static function normalizeContactMode(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = self::normalizeName($value);

        return match ($normalized) {
            'same', 'same_number', 'same number', 'نفس الرقم', 'نفس' => 'same',
            'another', 'another_number', 'another number', 'other', 'رقم اخر' => 'another',
            default => in_array($normalized, self::CONTACT_MODES, true) ? $normalized : null,
        };
    }

    public static function normalizeName(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $trimmed = trim($name);
        $trimmed = str_replace(['أ', 'إ', 'آ', 'ى'], ['ا', 'ا', 'ا', 'ي'], $trimmed);

        return preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;
    }
}
