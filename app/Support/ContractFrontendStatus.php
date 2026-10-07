<?php

namespace App\Support;

use App\Models\Contract;
use App\Services\ContractStatusHistoryService;

/**
 * Unified frontend status payload for API v2 + Firebase.
 *
 * - status / status_label = current dashboard status (Arabic label as stored).
 * - status_client_explanation = شرح الحالة للعميل (from admin statuses).
 * - journey / status_timeline = only statuses that actually happened (history).
 */
class ContractFrontendStatus
{
    /** @var array<string, string> */
    private const NAME_TO_KEY = [
        'جديد' => 'new',
        'قيد المراجعة' => 'under_review',
        'مكتمل' => 'completed',
        'ملغى' => 'cancelled',
        'معلق' => 'on_hold',
        'مستلم' => 'received',
        'مستلم من الموظف' => 'received_by_employee',
        'تم الدفع' => 'paid',
        'إرسال مسودة العقد لكم عبر واتساب' => 'whatsapp_draft',
        'ارسال مسودة العقد لكم عبر واتساب' => 'whatsapp_draft',
        'توثيق العقد في إيجار' => 'ejar_authenticated',
        'توثيق العقد في ايجار' => 'ejar_authenticated',
    ];

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     status_type: string,
     *     status_id: int|null,
     *     status_color: string|null,
     *     status_description: string|null,
     *     status_client_explanation: string|null
     * }
     */
    public static function for(?Contract $contract): array
    {
        if ($contract === null) {
            return self::empty();
        }

        $useDraft = (bool) $contract->is_draft && $contract->draft_contract_status_id;

        if ($useDraft) {
            $row = $contract->relationLoaded('draftContractStatus')
                ? $contract->draftContractStatus
                : $contract->draftContractStatus()->first();

            return self::fromRow(
                statusType: 'draft',
                id: $contract->draft_contract_status_id ? (int) $contract->draft_contract_status_id : null,
                name: $row?->name,
                color: $row?->color,
                description: $row?->description,
                clientExplanation: $row?->client_explanation,
                defaultLabel: 'مسودة',
                defaultKey: 'draft'
            );
        }

        $row = $contract->relationLoaded('contractStatus')
            ? $contract->contractStatus
            : $contract->contractStatus()->first();

        return self::fromRow(
            statusType: 'contract',
            id: $contract->contract_status_id ? (int) $contract->contract_status_id : null,
            name: $row?->name,
            color: $row?->color,
            description: $row?->description,
            clientExplanation: $row?->client_explanation,
            defaultLabel: 'قيد المراجعة',
            defaultKey: 'under_review'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function journey(?Contract $contract): array
    {
        return self::statusTimeline($contract);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function statusTimeline(?Contract $contract): array
    {
        if ($contract === null) {
            return [];
        }

        return app(ContractStatusHistoryService::class)->timeline($contract);
    }

    public static function journeyStatus(?Contract $contract): string
    {
        return (string) (self::for($contract)['status'] ?? 'unknown');
    }

    public static function journeyStatusLabel(?Contract $contract): string
    {
        return (string) (self::for($contract)['status_label'] ?? 'غير محدد');
    }

    /**
     * @return array<string, string>
     */
    public static function firebaseData(Contract $contract): array
    {
        $payload = self::for($contract);
        $timeline = self::statusTimeline($contract);
        $current = $timeline !== [] ? $timeline[array_key_last($timeline)] : null;

        return [
            'type' => 'contract_status_changed',
            'contract_id' => (string) $contract->id,
            'contract_uuid' => (string) ($contract->uuid ?? ''),
            'url' => SmartLink::for($contract),
            'status' => (string) $payload['status'],
            'status_label' => (string) $payload['status_label'],
            'status_type' => (string) $payload['status_type'],
            'status_id' => $payload['status_id'] !== null ? (string) $payload['status_id'] : '',
            'status_color' => (string) ($payload['status_color'] ?? ''),
            'status_description' => (string) ($payload['status_description'] ?? ''),
            'status_client_explanation' => (string) ($payload['status_client_explanation'] ?? ''),
            'is_draft' => $contract->is_draft ? '1' : '0',
            'journey_status' => (string) $payload['status'],
            'journey_status_label' => (string) $payload['status_label'],
            'timeline_count' => (string) count($timeline),
            'timeline_current_label' => (string) ($current['status_label'] ?? $payload['status_label']),
        ];
    }

    public static function keyFromName(?string $name, string $fallback = 'unknown'): string
    {
        if ($name === null || trim($name) === '') {
            return $fallback;
        }

        $trimmed = trim($name);
        if (isset(self::NAME_TO_KEY[$trimmed])) {
            return self::NAME_TO_KEY[$trimmed];
        }

        if ($trimmed === 'تم الدفع') {
            return 'paid';
        }

        return $fallback !== 'unknown'
            ? $fallback
            : 'status_'.substr(md5($trimmed), 0, 8);
    }

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     status_type: string,
     *     status_id: int|null,
     *     status_color: string|null,
     *     status_description: string|null,
     *     status_client_explanation: string|null
     * }
     */
    private static function fromRow(
        string $statusType,
        ?int $id,
        ?string $name,
        ?string $color,
        ?string $description,
        ?string $clientExplanation,
        string $defaultLabel,
        string $defaultKey
    ): array {
        $label = $name !== null && trim($name) !== '' ? trim($name) : $defaultLabel;
        $key = self::keyFromName($name, $id ? "{$statusType}_{$id}" : $defaultKey);
        $client = filled($clientExplanation) ? trim((string) $clientExplanation) : null;

        // Tracking UI prefers client explanation; fall back to internal description.
        $trackingDescription = $client ?? (filled($description) ? trim((string) $description) : null);

        return [
            'status' => $key,
            'status_label' => $label,
            'status_type' => $statusType,
            'status_id' => $id,
            'status_color' => $color,
            'status_description' => $trackingDescription,
            'status_client_explanation' => $client,
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     status_type: string,
     *     status_id: int|null,
     *     status_color: string|null,
     *     status_description: string|null,
     *     status_client_explanation: string|null
     * }
     */
    private static function empty(): array
    {
        return [
            'status' => 'unknown',
            'status_label' => 'غير محدد',
            'status_type' => 'contract',
            'status_id' => null,
            'status_color' => null,
            'status_description' => null,
            'status_client_explanation' => null,
        ];
    }
}
