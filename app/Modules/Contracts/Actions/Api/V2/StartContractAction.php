<?php

namespace App\Modules\Contracts\Actions\Api\V2;

use App\Http\Requests\Api\V2\Contract\ContractTypeRequest;
use App\Models\Contract;
use App\Models\RealEstate;
use App\Services\ContractUnitsService;
use InvalidArgumentException;

class StartContractAction
{
    public function __construct(
        private readonly ContractUnitsService $units,
    ) {}

    /**
     * @return array{ok: true, contract: Contract}|array{ok: false, message: string, code: int}
     */
    public function execute(ContractTypeRequest $request, int $userId): array
    {
        // منع التكرار (WEBSITE-3): نفس المفتاح من نفس المستخدم خلال 24 ساعة ⇒ نفس العقد،
        // حتى لو ضاع الرد الأول وأعاد العميل المحاولة. المفتاح من ترويسة Idempotency-Key
        // أو الحقل client_reference (رقم المسودة المحلي).
        $idempotencyKey = trim((string) ($request->header('Idempotency-Key') ?: $request->input('client_reference', '')));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 200) {
            return $this->create($request, $userId);
        }

        $cacheKey = 'contract-start:'.$userId.':'.sha1($idempotencyKey);
        $lock = \Illuminate\Support\Facades\Cache::lock($cacheKey.':lock', 15);

        try {
            $lock->block(10);
        } catch (\Throwable) {
            // تعذّر القفل — نتابع بلا ضمان (أفضل من رفض الطلب).
        }

        try {
            $existingId = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($existingId) {
                $existing = Contract::query()->whereKey($existingId)->where('user_id', $userId)->first();
                if ($existing !== null && ! $existing->is_delete) {
                    $existing->load(['units.unitType', 'units.unitUsage']);

                    return ['ok' => true, 'contract' => $existing, 'replayed' => true];
                }
            }

            $outcome = $this->create($request, $userId);
            if ($outcome['ok']) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $outcome['contract']->id, now()->addDay());
            }

            return $outcome;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array{ok: true, contract: Contract}|array{ok: false, message: string, code: int}
     */
    private function create(ContractTypeRequest $request, int $userId): array
    {
        $validated = $request->validated();

        $instrumentType = $request->filled('instrument_type')
            ? ($validated['instrument_type'] ?? null)
            : null;

        if (! $instrumentType && ! empty($validated['real_id'])) {
            $instrumentType = RealEstate::query()
                ->whereKey($validated['real_id'])
                ->value('instrument_type');
        }

        $unitPayloads = $request->unitPayloadsForSync();
        $primaryUnitId = $unitPayloads[0]['unit_id'] ?? (
            ! empty($validated['real_units_id']) ? (int) $validated['real_units_id'] : null
        );

        if (! empty($validated['real_id'])) {
            $realEstate = RealEstate::query()->find($validated['real_id']);
            $realEstate?->syncNumberOfUnitsInRealestate($primaryUnitId);
            // QA-F PROPS-5: عقار محفوظ بلا نوع (الموقع لم يكن يرسل contract_type) يأخذ نوع أول عقد عليه.
            if ($realEstate !== null && blank($realEstate->contract_type)
                && in_array($validated['contract_type'] ?? null, ['housing', 'commercial'], true)) {
                $realEstate->forceFill(['contract_type' => $validated['contract_type']])->save();
            }
        }

        $contract = Contract::create([
            'contract_type' => $validated['contract_type'],
            'instrument_type' => $instrumentType,
            'is_real' => (bool) ($validated['is_real'] ?? false),
            'real_id' => $validated['real_id'] ?? null,
            'real_units_id' => $primaryUnitId,
            'user_id' => $userId,
            // متابعة دفعة (د): القناة الحقيقية (الموقع/التطبيق) — لا تُستبدل في الخطوات اللاحقة.
            'app_or_web' => \App\Support\ClientChannel::fromRequest($request),
            'step' => Contract::shouldSkipInitialSteps($instrumentType) ? 3 : 1,
        ]);

        if ($unitPayloads !== []) {
            try {
                $this->units->syncForContract($contract, $unitPayloads, $userId);
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'message' => $e->getMessage(), 'code' => 422];
            }
        }

        $contract->load(['units.unitType', 'units.unitUsage']);

        return ['ok' => true, 'contract' => $contract];
    }
}
