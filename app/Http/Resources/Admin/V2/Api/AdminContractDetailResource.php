<?php

namespace App\Http\Resources\Admin\V2\Api;

use App\Enums\ReceivedContractStatus;
use App\Http\Resources\Admin\V2\Api\Concerns\ResolvesContractPaymentForAdmin;
use App\Http\Resources\Admin\V2\Api\Concerns\ResolvesContractReturnAcceptance;
use App\Http\Resources\Admin\V2\Api\Concerns\ResolvesContractReturnOrderFields;
use App\Http\Resources\Admin\V2\Api\ContractCommentResource;
use App\Http\Resources\Api\V2\UnitResource;
use App\Models\Account;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractPeriod;
use App\Models\ContractStatus;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\RealEstate;
use App\Models\ReceivedContract;
use App\Models\ReaEstatType;
use App\Models\ReaEstatUsage;
use App\Models\RefundableContract;
use App\Models\Region;
use App\Models\TenantRole;
use App\Models\UnitType;
use App\Models\UnitsReal;
use App\Models\UsageUnit;
use App\Models\User;
use App\Support\ContractFrontendStatus;
use App\Support\ContractReceivedTiming;
use App\Support\HijriDobParts;
use App\Services\ContractStatusCaseService;
use App\Services\ContractStatusHistoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Full contract for admin: scalar columns + relations with readable names (city, region, types, etc.).
 */
class AdminContractDetailResource extends JsonResource
{
    use ResolvesContractPaymentForAdmin;
    use ResolvesContractReturnAcceptance;
    use ResolvesContractReturnOrderFields;
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $full = $c->toArray();

        foreach (array_keys($c->getRelations()) as $relationName) {
            unset($full[Str::snake($relationName)]);
        }

        // Normalize file columns (paths from `store(..., 'public')`, e.g. images/contracts/….png).
        $attrs = $c->getAttributes();
        foreach ($this->contractStoragePathColumns() as $column) {
            $raw = array_key_exists($column, $attrs)
                ? $attrs[$column]
                : ($full[$column] ?? null);
            if ($raw === null && ! array_key_exists($column, $full)) {
                continue;
            }

            // Deed/instrument images are private: expose a temporary signed URL, never a public path.
            if (\App\Support\DeedImage::isField($column)) {
                $full[$column] = \App\Support\DeedImage::signedUrl($c, $column);

                continue;
            }

            $full[$column] = $this->publicStorageUrl($raw);
        }

        $enriched = $this->enrichedRelations($c);
        $frontend = ContractFrontendStatus::for($c);
        $receivedTiming = ContractReceivedTiming::for($c, $c->receivedContract);

        return array_merge($full, $enriched, $this->step4TenantFields($c), $this->ownerAndDateSplitFields($c, $full), $this->returnOrderFields(), $this->returnAcceptanceFields(), $this->contractPaymentFields(), $this->displayAliases($c, $frontend), $receivedTiming, [
            'image_instrument_pages' => \App\Support\DeedImage::signedPageUrls($c),
            'smart_link' => \App\Support\SmartLink::for($c),
            'relation_labels' => $this->relationLabels($c),
            'documentation_deadline_at' => $c->documentationDeadlineAt()?->format('Y-m-d H:i:s'),
            'status_case' => $this->statusCasePayload($c, $full),
            'deed_type' => $c->deed_addition_method,
            'ejar_contract_draft_number' => $c->ejar_contract_draft_number,
            'contact_number_mode' => $c->draft_contact_number_mode,
            'contact_number' => $c->draft_contact_number,
            'comments' => $this->commentsPayload($c, $request),
            'comments_count' => $c->relationLoaded('comments') ? $c->comments->count() : 0,
            'status_timeline' => app(ContractStatusHistoryService::class)->timeline($c),
            'invoice' => $this->invoiceSummary($c),
            // دفعة (د) — ب9: سجل النشاط (من/ماذا/متى + قبل/بعد).
            'activities' => app(\App\Services\Orders\ContractActivityLogger::class)->forAdmin($c),
            // دفعة (د) — ب10: الإشعارات المرسلة + الخصم المطبّق.
            'notifications_sent' => app(\App\Services\CustomerNotificationService::class)->sentForContract($c),
            'applied_discount' => \App\Services\Admin\UserCustomDiscountService::appliedFor($c),
            // ب8: الدفعات (لزر «استرجاع») وسجل الاسترجاعات.
            'payments' => \App\Services\Payments\PaymentRefundService::paymentsFor($c),
            'refunds' => \App\Services\Payments\PaymentRefundService::refundsFor($c),
            'refunded_amount' => \App\Services\Payments\PaymentRefundService::refundedTotalFor($c),
            // ب11: علامات التأخير (محسوبة الآن، لا تنتظر المجدول).
            'delay_flags' => app(\App\Services\Orders\OrderAttentionService::class)->flagsFor($c),
            'status_key' => \App\Models\ContractStatus::keyForId($c->contract_status_id ? (int) $c->contract_status_id : null),
            // متابعة دفعة (د): الموظف المستلم (يملأ «المستلم» في رأس التفاصيل).
            ...$this->receiverFields($c, $full),
            // متابعة دفعة (د) — QA: نتيجة الخادم للعدادات (العداد المشترك × أشهر العقد) وأشهر المدة، وتاريخ البداية بالتقويمين.
            ...$this->durationAndMeterFields($c, $full),
        ]);
    }

    /**
     * @param  array<string, mixed>  $full
     * @return array<string, mixed>
     */
    private function durationAndMeterFields($c, array $full): array
    {
        $months = \App\Support\DocFee::contractMonths($c);
        $row = $c->relationLoaded('contractTermInYears') ? $c->contractTermInYears : $c->contractTermInYears()->first();
        $term = null;
        if ($row !== null) {
            $term = $row->toArray();
            $term['months'] = $row->months !== null ? (int) $row->months : \App\Support\DocFee::monthsFromContractPeriod($c);
        }

        $meterFees = \App\Support\MeterFees::forContract($c);

        return [
            'contract_term_in_years' => $term ?? ($full['contract_term_in_years'] ?? null),
            'contract_months' => $months,
            'meter_fees' => $meterFees,
            'shared_meters' => $meterFees['shared_meters'] ?? null,
            ...\App\Support\ContractStartingDateInput::bothCalendars($c->contract_starting_date, $c->type_contract_starting_date),
        ];
    }

    /**
     * @param  array<string, mixed>  $full
     * @return array<string, mixed>
     */
    private function receiverFields($c, array $full): array
    {
        $received = $c->relationLoaded('receivedContract') ? $c->receivedContract : $c->receivedContract()->with('employee')->first();
        $received?->loadMissing('employee');
        $name = $received?->employee?->name;

        $receivedArray = $full['received_contract'] ?? null;
        if (is_array($receivedArray)) {
            $receivedArray['employee_name'] = $name;
        } elseif ($received !== null) {
            $receivedArray = array_merge($received->toArray(), ['employee_name' => $name]);
        }

        return [
            'employee_id' => $received?->employee_id,
            'employee_name' => $name,
            'received_contract' => $receivedArray,
        ];
    }

    /**
     * Extra fields collected when changing contract / draft status (IDs 9, 10, 2, send-draft).
     *
     * @param  array<string, mixed>  $full
     * @return array<string, mixed>
     */
    private function statusCasePayload($c, array $full): array
    {
        $attachment = $full['status_attachment'] ?? $this->publicStorageUrl($c->status_attachment);

        return [
            'deed_type' => $c->deed_addition_method,
            'deed_addition_method' => $c->deed_addition_method,
            'deed_number' => $c->deed_number,
            'ejar_contract_number' => $c->ejar_contract_number,
            'notes' => $c->ejar_status_notes,
            'ejar_status_notes' => $c->ejar_status_notes,
            'attachment' => $attachment,
            'status_attachment' => $attachment,
            'ejar_contract_draft_number' => $c->ejar_contract_draft_number,
            'contact_number_mode' => $c->draft_contact_number_mode,
            'contact_number' => $c->draft_contact_number,
            'current_contact_number' => app(ContractStatusCaseService::class)->currentContactNumber($c),
        ];
    }

    /**
     * Day/month/year splits for owner, owner agent and contract start date
     * (mirrors the parts the frontend sends in steps 3 and 6), plus aliases
     * for legacy/alternate column names used by the create-contract steps.
     *
     * @param  array<string, mixed>  $full
     * @return array<string, mixed>
     */
    private function ownerAndDateSplitFields($c, array $full): array
    {
        $ownerDob = $this->splitDateParts($c->property_owner_dob);
        $ownerAgentDob = $this->splitDateParts($c->dob_of_property_owner_agent);
        $startDate = $this->splitDateParts($c->contract_starting_date);

        return [
            'property_owner_dob_day' => $ownerDob['day'],
            'property_owner_dob_month' => $ownerDob['month'],
            'property_owner_dob_year' => $ownerDob['year'],
            'dob_of_property_owner_agent_day' => $ownerAgentDob['day'],
            'dob_of_property_owner_agent_month' => $ownerAgentDob['month'],
            'dob_of_property_owner_agent_year' => $ownerAgentDob['year'],
            'contract_starting_date_day' => $startDate['day'],
            'contract_starting_date_month' => $startDate['month'],
            'contract_starting_date_year' => $startDate['year'],
            // Frontend step-5 aliases (canonical columns: tootal_rooms / The_number_of_the_toilet).
            'number_of_rooms' => $full['number_of_rooms'] ?? $c->tootal_rooms,
            'The_number_of_toilets' => $full['The_number_of_toilets'] ?? $c->The_number_of_the_toilet,
            // Step-6 sends `conditions`; other_conditions_list is canonical (multi).
            'conditions' => $full['conditions']
                ?? (
                    (is_array($c->other_conditions_list) && $c->other_conditions_list !== [])
                    || ($c->other_conditions !== null && trim((string) $c->other_conditions) !== '')
                ),
            'other_conditions_list' => $this->resolvedOtherConditionsList($c),
            'other_conditions_count' => count($this->resolvedOtherConditionsList($c)),
        ];
    }

    /**
     * @return list<string>
     */
    private function resolvedOtherConditionsList($c): array
    {
        $list = $c->other_conditions_list;
        if (is_array($list) && $list !== []) {
            return array_values(array_filter(array_map(
                static fn ($v) => is_scalar($v) ? trim((string) $v) : '',
                $list
            )));
        }

        if ($c->other_conditions !== null && trim((string) $c->other_conditions) !== '') {
            return [trim((string) $c->other_conditions)];
        }

        return [];
    }

    /**
     * Split DD-MM-YYYY (hijri parts) or YYYY-MM-DD (gregorian) into day/month/year.
     *
     * @return array{day: ?string, month: ?string, year: ?string}
     */
    private function splitDateParts(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return ['day' => null, 'month' => null, 'year' => null];
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', trim($value), $m)) {
            return [
                'day' => str_pad($m[3], 2, '0', STR_PAD_LEFT),
                'month' => str_pad($m[2], 2, '0', STR_PAD_LEFT),
                'year' => $m[1],
            ];
        }

        return HijriDobParts::split($value);
    }

    /**
     * Same tenant / Step4 payload shape as API V2 Step4Resource.
     *
     * @return array<string, mixed>
     */
    private function step4TenantFields($c): array
    {
        $tenantDob = HijriDobParts::split($c->tenant_dob);
        $tenantAgentDob = HijriDobParts::split($c->dob_of_property_tenant_agent);

        return [
            'tenant_entity' => $c->tenant_entity,
            'tenant_id_num' => $c->tenant_id_num,
            'tenant_dob' => $c->tenant_dob,
            'tenant_dob_day' => $tenantDob['day'],
            'tenant_dob_month' => $tenantDob['month'],
            'tenant_dob_year' => $tenantDob['year'],
            'type_tenant_dob' => $c->type_tenant_dob ?? 'hijri',
            'type_dob_tenant_agent' => $c->type_dob_tenant_agent ?? 'hijri',
            'tenant_mobile' => $c->tenant_mobile,
            'region_of_the_tenant_legal_agent' => $c->region_of_the_tenant_legal_agent,
            'city_of_the_tenant_legal_agent' => $c->city_of_the_tenant_legal_agent,
            'tenant_entity_unified_registry_number' => $c->tenant_entity_unified_registry_number,
            'authorization_type' => $c->authorization_type,
            'copy_of_the_owner_record' => $this->publicStorageUrl(
                $c->getAttributes()['copy_of_the_owner_record'] ?? $c->copy_of_the_owner_record
            ),
            'id_num_of_property_tenant_agent' => $c->id_num_of_property_tenant_agent,
            'mobile_of_property_tenant_agent' => $c->mobile_of_property_tenant_agent,
            'dob_of_property_tenant_agent' => $c->dob_of_property_tenant_agent,
            'dob_of_property_tenant_agent_day' => $tenantAgentDob['day'],
            'dob_of_property_tenant_agent_month' => $tenantAgentDob['month'],
            'dob_of_property_tenant_agent_year' => $tenantAgentDob['year'],
            // Aliases matching the exact keys the frontend sends in step 4.
            'dobof_property_tenant_agent_day' => $tenantAgentDob['day'],
            'dobof_property_tenant_agent_month' => $tenantAgentDob['month'],
            'dobof_property_tenant_agent_year' => $tenantAgentDob['year'],
        ];
    }

    /**
     * Contract columns that store paths under public disk (same convention as API V2 Step resources).
     *
     * @return list<string>
     */
    private function contractStoragePathColumns(): array
    {
        return [
            'image_instrument',
            'image_instrument_from_the_back',
            'image_instrument_from_the_front',
            'image_address',
            'copy_of_the_authorization_or_agency',
            'copy_of_the_owner_record',
            'copy_of_the_endowment_registration_certificate',
            'copy_of_the_trusteeship_deed',
            'draft_before_paid',
            'draft_after_paid',
            'file',
            'status_attachment',
            'strong_argument_photo',
            'photo_of_the_electronic',
            'Image_from_the_agency',
            'copy_power_of_attorney_from_heirs_to_agent',
            'Image_inheritance_certificate',
            'copy_of_guardians_power_of_attorney_for_agent',
        ];
    }

    /**
     * Full URL for paths stored via `store(..., 'public')` (relative to the public disk root).
     */
    private function publicStorageUrl(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (! is_string($raw)) {
            if (! is_scalar($raw)) {
                return null;
            }
            $raw = (string) $raw;
        }

        $path = trim($raw);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (Str::startsWith($path, '/storage/')) {
            return url($path);
        }

        $path = ltrim($path, '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return asset('storage/' . $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function enrichedRelations($c): array
    {
        return [
            'user' => $this->userSummary($c->user),
            'real_estate' => $this->realEstateSummary($c->realEstate),
            'unit' => $this->unitSummary($c->unit),
            'units' => $c->relationLoaded('units')
                ? $c->units->map(fn ($u) => $this->unitSummary($u))->filter()->values()->all()
                : [],
            'units_count' => $c->relationLoaded('units') ? $c->units->count() : 0,
            'property_type' => $this->reaEstatTypeSummary($c->propertyType),
            'property_usages' => $this->reaEstatUsageSummary($c->propertyUsages),
            'property_region' => $this->regionSummary($c->propertyRegion),
            'property_city' => $this->citySummary($c->propertyCity),
            // Model links City (column region_of_the_tenant_legal_agent); name kept for API compatibility.
            'tenant_entity_legal_region' => $this->citySummary($c->tenantEntityLegalRegion),
            'tenant_entity_legal_city' => $this->citySummary($c->tenantEntityLegalCity),
            'tenant_entity_city' => $this->citySummary($c->tenantEntityCity),
            'tenant_entity_region' => $this->regionSummary($c->tenantEntityRegion),
            'unit_type' => $this->unitTypeSummary($c->unitType),
            'unit_usage' => $this->usageUnitSummary($c->unitUsage),
            'contract_term_in_years' => $this->contractPeriodSummary($c->contractTermInYears),
            'payment_type' => $this->paymentTypeSummary($c->paymentType),
            'account' => $this->accountSummary($c->account),
            'received_contract' => $this->receivedContractSummary($c->receivedContract),
            'contract_status' => $this->contractStatusSummary($c->contractStatus),
            'draft_contract_status' => $this->contractStatusSummary($c->draftContractStatus),
            'draft_contract_status_id' => $c->draft_contract_status_id,
            'contract_payments' => $this->paymentsSummary($c->contractPayments),
            'tenant_role' => $this->tenantRoleSummary($c->tenantRole),
            'tenant_roles_details' => $this->tenantRolesDetails($c),
            'accept_retrun_contract_employee' => $this->employeeSummary($c->acceptRetrunContractEmployee),
            'refundable_contract' => $this->refundableContractSummary($c->refundableContract),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tenantRolesDetails($c): array
    {
        $ids = $c->tenant_role_ids ?? [];
        if (! is_array($ids) || $ids === []) {
            return [];
        }

        $values = is_array($c->tenant_role_values) ? $c->tenant_role_values : [];
        $roles = TenantRole::query()->whereIn('id', $ids)->orderBy('id')->get();

        return $roles->map(function (TenantRole $role) use ($values) {
            $key = (string) $role->id;
            $summary = $this->tenantRoleSummary($role);

            return array_merge($summary ?? [], [
                'value' => $values[$key] ?? $values[$role->id] ?? null,
            ]);
        })->values()->all();
    }

    /**
     * Flat human-readable labels for FKs (quick display in admin UI).
     *
     * @return array<string, string|null>
     */
    private function relationLabels($c): array
    {
        return [
            'user_name' => $this->translatedName($c->user, 'name'),
            'property_city' => $this->translatedName($c->propertyCity),
            'property_region' => $this->translatedName($c->propertyRegion),
            'property_type' => $this->translatedName($c->propertyType, 'name'),
            'property_usages' => $this->translatedName($c->propertyUsages, 'name'),
            'tenant_entity_city' => $this->translatedName($c->tenantEntityCity),
            'tenant_entity_region' => $this->translatedName($c->tenantEntityRegion),
            'tenant_entity_legal_city' => $this->translatedName($c->tenantEntityLegalCity),
            'tenant_entity_legal_region' => $this->translatedName($c->tenantEntityLegalRegion),
            'unit_type' => $this->translatedName($c->unitType, 'name'),
            'unit_usage' => $this->translatedName($c->unitUsage, 'name'),
            'contract_term' => $this->translatedName($c->contractTermInYears, 'note'),
            'payment_type' => $this->translatedName($c->paymentType, 'name'),
            'contract_status' => $c->contractStatus?->name,
            'draft_contract_status' => $c->draftContractStatus?->name,
            'tenant_role' => $c->tenantRole?->text_of_reason,
            'instrument_type' => $this->instrumentTypeFields($c->instrument_type)['instrument_type_trans'],
        ];
    }

    private function translatedName(?object $model, string $prefix = 'name'): ?string
    {
        if ($model === null) {
            return null;
        }
        $transKey = "{$prefix}_trans";
        if (isset($model->{$transKey}) && $model->{$transKey} !== null && $model->{$transKey} !== '') {
            return (string) $model->{$transKey};
        }
        foreach (["{$prefix}_ar", "{$prefix}_en", $prefix] as $attr) {
            if (isset($model->{$attr}) && $model->{$attr} !== null && $model->{$attr} !== '') {
                return (string) $model->{$attr};
            }
        }

        return null;
    }

    private function citySummary(?City $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'name' => $this->translatedName($m),
            'name_ar' => $m->name_ar ?? null,
            'name_en' => $m->name_en ?? null,
            'name_trans' => $m->name_trans ?? null,
            'region_id' => $m->region_id ?? null,
        ];
    }

    private function regionSummary(?Region $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'name' => $this->translatedName($m),
            'name_ar' => $m->name_ar ?? null,
            'name_en' => $m->name_en ?? null,
            'name_trans' => $m->name_trans ?? null,
        ];
    }

    private function reaEstatTypeSummary(?ReaEstatType $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'name' => $this->translatedName($m, 'name'),
            'name_ar' => $m->name_ar ?? null,
            'name_en' => $m->name_en ?? null,
            'name_trans' => $m->name_trans ?? null,
            'contract_type' => $m->contract_type ?? null,
        ];
    }

    private function reaEstatUsageSummary(?ReaEstatUsage $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'name' => $this->translatedName($m, 'name'),
            'name_ar' => $m->name_ar ?? null,
            'name_en' => $m->name_en ?? null,
            'name_trans' => $m->name_trans ?? null,
            'contract_type' => $m->contract_type ?? null,
        ];
    }

    private function unitTypeSummary(?UnitType $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'name' => $this->translatedName($m, 'name'),
            'name_ar' => $m->name_ar ?? null,
            'name_en' => $m->name_en ?? null,
            'name_trans' => $m->name_trans ?? null,
        ];
    }

    private function usageUnitSummary(?UsageUnit $m): ?array
    {
        if ($m === null) {
            return null;
        }

        $attrs = $m->getAttributes();

        return array_merge($attrs, [
            'id' => $m->id,
            'name' => $this->translatedName($m, 'name')
                ?? ($attrs['name_ar'] ?? $attrs['name_en'] ?? $attrs['name'] ?? null),
        ]);
    }

    private function contractPeriodSummary(?ContractPeriod $m): ?array
    {
        if ($m === null) {
            return null;
        }

        $payment = $this->contractPaymentFields();
        $price = is_numeric($payment['amount_payment'])
            ? (float) $payment['amount_payment']
            : ($m->price ?? null);

        return [
            'id' => $m->id,
            'period' => $m->period ?? null,
            'name' => $this->translatedName($m, 'note'),
            'note_ar' => $m->note_ar ?? null,
            'note_en' => $m->note_en ?? null,
            'note_trans' => $m->note_trans ?? null,
            'price' => $price,
            'contract_type' => $m->contract_type ?? null,
        ];
    }

    private function paymentTypeSummary(?PaymentType $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'name' => $this->translatedName($m, 'name'),
            'name_ar' => $m->name_ar ?? null,
            'name_en' => $m->name_en ?? null,
            'name_trans' => $m->name_trans ?? null,
            'contract_type' => $m->contract_type ?? null,
            'notes' => $m->notes ?? null,
        ];
    }

    private function userSummary(?User $u): ?array
    {
        if ($u === null) {
            return null;
        }

        return [
            'id' => $u->id,
            'name' => $u->name ?? null,
            'email' => $u->email ?? null,
            // زائر الموقع: جوال الواتساب الذي كتبه في contact_mobile (mobile فارغ). (DASHBOARD-8)
            'mobile' => $u->mobile ?: ($u->contact_mobile ?? null),
            'contact_mobile' => $u->contact_mobile ?? null,
            'is_guest' => (bool) ($u->is_guest ?? false),
        ];
    }

    private function realEstateSummary(?RealEstate $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return array_merge(
            ['id' => $m->id],
            $m->only([
                'name_real_estate',
                'name_owner',
                'contract_type',
                'street',
                'neighborhood',
                'property_city_id',
                'property_place_id',
                'building_number',
                'postal_code',
                'real_estate_registry_number',
                'address_url',
                'electricity_meter_ownership',
                'water_meter_ownership',
            ]),
            $this->instrumentTypeFields($m->instrument_type),
            [
                // RealEstate sensitive docs live on the private disk — signed URL, never a public path.
                'copy_of_guardians_power_of_attorney_for_agent' => \App\Support\RealEstateImage::signedUrl(
                    $m, 'copy_of_guardians_power_of_attorney_for_agent'
                ),
            ]
        );
    }

    /**
     * Full unit payload for admin (same fields as API V2 UnitResource).
     *
     * @return array<string, mixed>|null
     */
    private function unitSummary(?UnitsReal $m): ?array
    {
        if ($m === null) {
            return null;
        }

        if (! $m->relationLoaded('unitType')) {
            $m->loadMissing(['unitType', 'unitUsage', 'realEstate']);
        }

        return (new UnitResource($m))->resolve();
    }

    private function accountSummary(?Account $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'value_contract' => $m->valueContract ?? null,
        ];
    }

    private function receivedContractSummary(?ReceivedContract $r): ?array
    {
        if ($r === null) {
            return null;
        }

        $timing = ContractReceivedTiming::for($this->resource, $r);

        return [
            'id' => $r->id,
            'contract_id' => $r->contract_id,
            'employee_id' => $r->employee_id,
            'status' => $r->status instanceof ReceivedContractStatus
                ? $r->status->value
                : $r->status,
            'notes' => $r->notes,
            'date_of_received' => $r->date_of_received?->format('Y-m-d'),
            'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
            'received_at' => $timing['received_at'],
            'received_since' => $timing['received_since'],
            'received_since_label_ar' => $timing['received_since_label_ar'],
            'receive_speed' => $timing['receive_speed'],
            'receive_speed_label_ar' => $timing['receive_speed_label_ar'],
            'employee' => $this->employeeSummary($r->employee),
        ];
    }

    private function employeeSummary(?Employee $e): ?array
    {
        if ($e === null) {
            return null;
        }

        return [
            'id' => $e->id,
            'name' => $e->name,
            'email' => $e->email,
            'phone' => $e->phone,
        ];
    }

    private function contractStatusSummary($m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'name' => $m->name,
            'color' => $m->color ?? null,
            'color_text' => $m->color_text ?? null,
            'description' => $m->description ?? null,
            'client_explanation' => $m->client_explanation ?? null,
            'is_active' => (bool) ($m->is_active ?? false),
            'status_case' => $m->status_case,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Payment>|null  $collection
     * @return list<array<string, mixed>>|null
     */
    private function paymentsSummary($collection): ?array
    {
        if ($collection === null || $collection->isEmpty()) {
            return $collection === null ? null : [];
        }

        return $collection->map(static function (Payment $p) {
            return [
                'id' => $p->id,
                'amount' => $p->amount ?? null,
                'status' => $p->status ?? null,
                'payment_date' => $p->payment_date ?? null,
                'payment_method' => $p->payment_method ?? null,
                'contract_uuid' => $p->contract_uuid ?? null,
                'name' => $p->name ?? null,
            ];
        })->values()->all();
    }

    private function tenantRoleSummary(?TenantRole $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'name' => $m->text_of_reason,
            'text_of_reason' => $m->text_of_reason,
            'service_definition' => $m->service_definition,
            'input_field_label' => $m->input_field_label,
            'input_field_type' => $m->input_field_type,
            'has_user_input' => $m->requiresUserInput(),
            'icon' => $m->icon,
            'input_icon' => $m->input_icon,
            'pop' => (bool) $m->pop,
        ];
    }

    /**
     * Admin dashboard is Arabic; always expose the Arabic deed label next to the stored key.
     *
     * @return array{
     *     instrument_type: ?string,
     *     instrument_type_key: ?string,
     *     instrument_type_trans: ?string,
     *     instrument_type_label: ?string
     * }
     */
    private function instrumentTypeFields(mixed $instrumentType): array
    {
        $key = is_string($instrumentType) ? trim($instrumentType) : '';
        if ($key === '') {
            return [
                'instrument_type' => null,
                'instrument_type_key' => null,
                'instrument_type_trans' => null,
                'instrument_type_label' => null,
            ];
        }

        $canonical = Contract::normalizeInstrumentType($key) ?? $key;
        $label = Contract::instrumentTypeLabel($canonical, 'ar');

        return [
            'instrument_type' => $label,
            'instrument_type_key' => $canonical,
            'instrument_type_trans' => $label,
            'instrument_type_label' => $label,
        ];
    }

    /**
     * Flat display names so the admin UI does not need to assemble step objects.
     *
     * @param  array{status: string, status_label: string, status_type: string, status_id: int|null, status_color: string|null, status_description: string|null, status_client_explanation: string|null}  $frontend
     * @return array<string, mixed>
     */
    private function displayAliases($c, array $frontend): array
    {
        $labels = $this->relationLabels($c);
        $tenantRoleNames = collect($this->tenantRolesDetails($c))
            ->pluck('name')
            ->filter(static fn ($v) => is_string($v) && trim($v) !== '')
            ->map(static fn ($v) => trim((string) $v))
            ->values()
            ->all();

        if ($tenantRoleNames === [] && is_string($c->tenantRole?->text_of_reason) && trim($c->tenantRole->text_of_reason) !== '') {
            $tenantRoleNames = [trim($c->tenantRole->text_of_reason)];
        }

        return [
            'status' => $frontend['status'],
            'status_label' => $frontend['status_label'],
            'status_type' => $frontend['status_type'],
            'status_id' => $frontend['status_id'],
            'status_color' => $frontend['status_color'],
            'status_description' => $frontend['status_description'],
            'status_client_explanation' => $frontend['status_client_explanation'],
            'property_place_name' => $labels['property_region'] ?? null,
            'city_name' => $labels['property_city'] ?? null,
            'property_type_name' => $labels['property_type'] ?? null,
            'property_usages_name' => $labels['property_usages'] ?? null,
            'unit_type_name' => $labels['unit_type'] ?? null,
            'unit_usage_name' => $labels['unit_usage'] ?? null,
            'contract_term_name' => $labels['contract_term'] ?? null,
            'payment_type_name' => $labels['payment_type'] ?? null,
            'contract_status_name' => $c->contractStatus?->name,
            'contract_status_color' => $c->contractStatus?->color,
            'tenant_role_names' => $tenantRoleNames,
            'contract_type_key' => $c->contract_type,
            'contract_type_trans' => $c->contract_type_trans,
            ...$this->instrumentTypeFields($c->instrument_type),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commentsPayload($c, Request $request): array
    {
        if (! $c->relationLoaded('comments')) {
            return [];
        }

        return ContractCommentResource::collection(
            $c->comments->sortByDesc('id')->values()
        )->toArray($request);
    }

    /**
     * الفاتورة للوحة التحكم: نفس بنود فاتورة العميل (items / subtotal / discount / vat / total)
     * من {@see \App\Services\ContractInvoiceService}. للطلبات المدفوعة يُصدر سجل الفاتورة إن لم يوجد؛
     * وغير المدفوعة تُعرض معاينة حيّة بدون إنشاء سجل.
     */
    private function invoiceSummary($c): ?array
    {
        $invoice = $c->relationLoaded('invoices')
            ? $c->invoices->sortByDesc('id')->first()
            : null;

        $legacy = $invoice instanceof Invoice ? [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'order_number' => $invoice->order_number,
            'date' => optional($invoice->date)?->format('Y-m-d'),
            'customer_phone' => $invoice->customer_phone,
            'description' => $invoice->description,
            'rental_fees' => $invoice->rental_fees,
            'service_fees' => $invoice->service_fees,
            'total_amount' => $invoice->total_amount,
        ] : null;

        if (! $invoice instanceof Invoice && ! (bool) $c->is_completed) {
            return null;
        }

        try {
            $payload = app(\App\Services\ContractInvoiceService::class)
                ->forContract($c, persist: (bool) $c->is_completed);
        } catch (\Throwable $e) {
            report($e);

            return $legacy;
        }

        return array_merge($legacy ?? [], $payload, [
            'customer_phone' => $legacy['customer_phone'] ?? ($c->user?->mobile ?? null),
        ]);
    }

    private function refundableContractSummary(?RefundableContract $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'contract_id' => $m->contract_id,
            'employee_id' => $m->employee_id,
            'has_draft_contract' => (bool) $m->has_draft_contract,
            'refund_amount' => $m->refund_amount,
            'notes' => $m->notes,
            'admin_confirmed' => $m->admin_confirmed,
            'is_refunded' => (bool) $m->is_refunded,
            'created_at' => optional($m->created_at)?->format('Y-m-d H:i:s'),
        ];
    }
}
