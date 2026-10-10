<?php

namespace App\Services\RealEstate;

use App\Models\Contract;
use App\Models\ContractUnit;
use App\Models\RealEstate;
use App\Models\UnitsReal;
use App\Services\Orders\TrashService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * دفعة (و) — D6: سلة محذوفات العقارات والوحدات (30 يوماً، نفس نمط سلة الطلبات {@see TrashService}).
 *  - حذف العميل = نقل للمحذوفات (trashed_at) لا حذف نهائي؛ حذف العقار ينقل وحداته معه بنفس الوقت.
 *  - الاسترجاع خلال 30 يوماً (العميل أو اللوحة). استرجاع وحدة عقارها محذوف يسترجع العقار.
 *  - التنظيف النهائي `trash:purge` — لا يُحذف نهائياً ما زال مرتبطاً بطلب.
 */
class PropertyTrashService
{
    public const RETENTION_DAYS = TrashService::RETENTION_DAYS;

    public function trashRealEstate(RealEstate $realEstate): RealEstate
    {
        $at = now();
        DB::transaction(function () use ($realEstate, $at) {
            UnitsReal::query()->where('real_estates_units_id', $realEstate->id)->update(['trashed_at' => $at]);
            $realEstate->forceFill(['trashed_at' => $at])->save();
        });

        return $realEstate;
    }

    public function trashUnit(UnitsReal $unit): UnitsReal
    {
        $property = $unit->realEstate;
        $countBefore = $property ? $property->units()->count() : 0;
        $unit->forceFill(['trashed_at' => now()])->save();
        // QA-F PROPS-13: عدّاد وحدات العقار يتبع الحذف.
        if ($property && ! $property->trashed()) {
            $property->syncUnitsCountAfterRemoval($countBefore);
        }

        return $unit;
    }

    /** @throws \InvalidArgumentException */
    public function restoreRealEstate(RealEstate $realEstate): RealEstate
    {
        $this->assertRestorable($realEstate->trashed_at);
        $at = Carbon::parse($realEstate->trashed_at);
        DB::transaction(function () use ($realEstate, $at) {
            // وحداته التي نُقلت معه (نفس الوقت تقريباً) تعود معه؛ الوحدات المحذوفة قبله منفردةً تبقى في السلة.
            UnitsReal::onlyTrashed()->where('real_estates_units_id', $realEstate->id)
                ->whereBetween('trashed_at', [$at->copy()->subSeconds(5), $at->copy()->addSeconds(5)])
                ->update(['trashed_at' => null]);
            $realEstate->forceFill(['trashed_at' => null])->save();
        });

        return $realEstate->fresh();
    }

    /** @throws \InvalidArgumentException */
    public function restoreUnit(UnitsReal $unit): UnitsReal
    {
        $this->assertRestorable($unit->trashed_at);
        DB::transaction(function () use ($unit) {
            $property = RealEstate::withTrashed()->find($unit->real_estates_units_id);
            if ($property !== null && $property->trashed()) {
                $property->forceFill(['trashed_at' => null])->save();
            }
            $unit->forceFill(['trashed_at' => null])->save();
        });

        return $unit->fresh();
    }

    /**
     * @return array{trashed: bool, trashed_at: string|null, purge_at: string|null, days_left: int|null, restorable: bool}
     */
    public function meta(mixed $trashedAt): array
    {
        $at = $trashedAt ? Carbon::parse($trashedAt) : null;
        $purgeAt = $at?->copy()->addDays(self::RETENTION_DAYS);

        return [
            'trashed' => $at !== null,
            'trashed_at' => $at?->toIso8601String(),
            'purge_at' => $purgeAt?->toIso8601String(),
            'days_left' => $purgeAt ? max(0, (int) ceil(now()->diffInSeconds($purgeAt, false) / 86400)) : null,
            'restorable' => $at !== null && $at->gt(now()->subDays(self::RETENTION_DAYS)),
        ];
    }

    /**
     * محذوفات مستخدم (أو الكل للوحة).
     *
     * @return array{real_estates: list<array<string, mixed>>, units: list<array<string, mixed>>}
     */
    public function listing(?int $userId = null, bool $withUser = false): array
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $properties = RealEstate::onlyTrashed()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->where('trashed_at', '>', $cutoff)
            ->with($withUser ? ['user'] : [])
            ->latest('trashed_at')->limit(500)->get();

        $units = UnitsReal::onlyTrashed()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->where('trashed_at', '>', $cutoff)
            ->with(array_merge(['realEstate'], $withUser ? ['user'] : []))
            ->latest('trashed_at')->limit(1000)->get();

        $userArray = static fn ($u) => $u ? [
            'id' => $u->id,
            'name' => trim(($u->fname ?? '').' '.($u->lname ?? '')) ?: ($u->name ?? null),
            'mobile' => $u->mobile ?? null,
        ] : null;

        return [
            'real_estates' => $properties->map(fn (RealEstate $r) => array_merge([
                'id' => $r->id,
                'name_real_estate' => $r->name_real_estate,
                'contract_type' => $r->contract_type,
                'units_count' => UnitsReal::withTrashed()->where('real_estates_units_id', $r->id)->count(),
            ], $this->meta($r->trashed_at), $withUser ? ['user' => $userArray($r->user)] : []))->values()->all(),
            'units' => $units->map(fn (UnitsReal $u) => array_merge([
                'id' => $u->id,
                'real_estate_id' => $u->real_estates_units_id,
                'real_estate_name' => $u->realEstate?->name_real_estate,
                'real_estate_trashed' => (bool) $u->realEstate?->trashed(),
                'unit_number' => $u->unit_number ?? null,
            ], $this->meta($u->trashed_at), $withUser ? ['user' => $userArray($u->user)] : []))->values()->all(),
        ];
    }

    /**
     * حذف نهائي لما مضى عليه 30 يوماً — يتجاوز ما زال مرتبطاً بطلب (حماية).
     *
     * @return array{real_estates: int, units: int}
     */
    public function purge(): array
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $units = 0;
        $properties = 0;

        UnitsReal::onlyTrashed()->where('trashed_at', '<', $cutoff)->orderBy('id')->chunkById(200, function ($rows) use (&$units) {
            foreach ($rows as $unit) {
                if (Contract::query()->where('real_units_id', $unit->id)->exists() || ContractUnit::query()->where('real_unit_id', $unit->id)->exists()) {
                    continue;
                }
                try {
                    $unit->forceDelete();
                    $units++;
                } catch (\Throwable $e) {
                    Log::warning('property trash purge failed for unit', ['unit_id' => $unit->id, 'error' => $e->getMessage()]);
                }
            }
        });

        RealEstate::onlyTrashed()->where('trashed_at', '<', $cutoff)->orderBy('id')->chunkById(200, function ($rows) use (&$properties) {
            foreach ($rows as $property) {
                $unitIds = UnitsReal::withTrashed()->where('real_estates_units_id', $property->id)->pluck('id')->all();
                if (Contract::query()->where('real_id', $property->id)->exists()
                    || ContractUnit::query()->where('real_estate_id', $property->id)->exists()
                    || ($unitIds !== [] && (Contract::query()->whereIn('real_units_id', $unitIds)->exists()
                        || ContractUnit::query()->whereIn('real_unit_id', $unitIds)->exists()))) {
                    continue;
                }
                try {
                    DB::transaction(function () use ($property, $unitIds) {
                        if ($unitIds !== []) {
                            UnitsReal::withTrashed()->whereIn('id', $unitIds)->forceDelete();
                        }
                        $property->forceDelete();
                    });
                    $properties++;
                } catch (\Throwable $e) {
                    Log::warning('property trash purge failed', ['real_estate_id' => $property->id, 'error' => $e->getMessage()]);
                }
            }
        });

        return ['real_estates' => $properties, 'units' => $units];
    }

    private function assertRestorable(mixed $trashedAt): void
    {
        if ($trashedAt === null) {
            throw new \InvalidArgumentException('العنصر ليس في المحذوفات.');
        }
        if (Carbon::parse($trashedAt)->lte(now()->subDays(self::RETENTION_DAYS))) {
            throw new \InvalidArgumentException('انتهت مدة الاسترجاع (30 يوماً).');
        }
    }
}
