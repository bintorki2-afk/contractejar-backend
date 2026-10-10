<?php

namespace App\Modules\Catalog\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\BankAccount;
use App\Modules\Catalog\Models\City;
use App\Modules\Catalog\Models\ContractPeriod;
use App\Modules\Catalog\Models\Paperwork;
use App\Modules\Catalog\Models\PaymentType;
use App\Modules\Catalog\Models\ReaEstatType;
use App\Modules\Catalog\Models\ReaEstatUsage;
use App\Modules\Catalog\Models\Region;
use App\Modules\Catalog\Models\ServicesPricing;
use App\Modules\Catalog\Models\UnitType;
use App\Modules\Catalog\Models\UsageUnit;
use App\Modules\Catalog\Requests\Api\FilterByContractTypeRequest;
use App\Modules\Catalog\Requests\Api\ListCitiesRequest;
use App\Modules\Catalog\Resources\BankAccountResource;
use App\Modules\Catalog\Resources\CityResource;
use App\Modules\Catalog\Resources\ContractPeriodResource;
use App\Modules\Catalog\Resources\PaperworkResource;
use App\Modules\Catalog\Resources\PaymentTypeResource;
use App\Modules\Catalog\Resources\ReaEstatTypeResource;
use App\Modules\Catalog\Resources\ReaEstatUsageResource;
use App\Modules\Catalog\Resources\RegionResource;
use App\Modules\Catalog\Resources\ServicePricingResource;
use App\Modules\Catalog\Resources\UnitTypeResource;
use App\Modules\Catalog\Resources\UnitUsageResource;
use App\Shared\Responses\Responser;
use Illuminate\Support\Facades\Cache;

class CatalogLookupController extends Controller
{
    use Responser;

    private const CACHE_TTL_SECONDS = 600;

    public function cities(ListCitiesRequest $request)
    {
        $regionId = (int) $request->region_id;
        $cities = $this->rememberCatalog('cities.'.$regionId, fn () => City::where('region_id', $regionId)->get());

        return $this->apiResponse(CityResource::collection($cities), trans('api.success'));
    }

    public function regions()
    {
        $regions = $this->rememberCatalog('regions', fn () => Region::orderBy('id', 'desc')->get());

        return $this->apiResponse(RegionResource::collection($regions), trans('api.success'));
    }

    public function bankAccounts()
    {
        // QA-F C5 / قرار E2: أرقام حسابات المؤسسة والآيبان لا تظهر للعموم (الموظف يرسلها بنفسه).
        // النقطة باقية للتوافق مع الإصدارات القديمة من التطبيق لكنها ترجع قائمة فارغة.
        return $this->apiResponse(BankAccountResource::collection(collect()), trans('api.success'));
    }

    public function servicesPricing(FilterByContractTypeRequest $request)
    {
        $type = (string) $request->contract_type;
        $servicesPricing = $this->rememberCatalog('services-pricing.'.$type, fn () => ServicesPricing::where('contract_type', $type)->get());

        return $this->apiResponse(ServicePricingResource::collection($servicesPricing), trans('api.success'));
    }

    public function paperwork(FilterByContractTypeRequest $request)
    {
        $type = (string) $request->contract_type;
        $paperwork = $this->rememberCatalog('paperwork.'.$type, fn () => Paperwork::where('contract_type', $type)->get());

        return $this->apiResponse(PaperworkResource::collection($paperwork), trans('api.success'));
    }

    public function realEstatType(FilterByContractTypeRequest $request)
    {
        $type = (string) $request->contract_type;
        $realEstatTypes = $this->rememberCatalog('real-estat-type.'.$type, fn () => ReaEstatType::where('contract_type', $type)->get());

        return $this->apiResponse(ReaEstatTypeResource::collection($realEstatTypes), trans('api.success'));
    }

    public function realEstatUsage(FilterByContractTypeRequest $request)
    {
        $type = (string) $request->contract_type;
        $realEstatUsage = $this->rememberCatalog('real-estat-usage.'.$type, fn () => ReaEstatUsage::where('contract_type', $type)->get());

        return $this->apiResponse(ReaEstatUsageResource::collection($realEstatUsage), trans('api.success'));
    }

    public function unitsTypes(FilterByContractTypeRequest $request)
    {
        $type = (string) $request->contract_type;
        $unitsTypes = $this->rememberCatalog('units-types.'.$type, fn () => UnitType::where('contract_type', $type)->get());

        return $this->apiResponse(UnitTypeResource::collection($unitsTypes), trans('api.success'));
    }

    public function unitsUsages(FilterByContractTypeRequest $request)
    {
        $type = (string) $request->contract_type;
        $unitsUsages = $this->rememberCatalog('units-usage.'.$type, fn () => UsageUnit::where('contract_type', $type)->get());

        return $this->apiResponse(UnitUsageResource::collection($unitsUsages), trans('api.success'));
    }

    public function paymentsTypes(FilterByContractTypeRequest $request)
    {
        $type = (string) $request->contract_type;
        $paymentsTypes = $this->rememberCatalog('payments-types.'.$type, fn () => PaymentType::where('contract_type', $type)->get());

        return $this->apiResponse(PaymentTypeResource::collection($paymentsTypes), trans('api.success'));
    }

    public function contractPeriods(FilterByContractTypeRequest $request)
    {
        $type = (string) $request->contract_type;
        $contractPeriods = $this->rememberCatalog('contract-periods.v2.'.$type, fn () => ContractPeriod::where('contract_type', $type)->where('is_active', true)->orderBy('months')->get());

        return $this->apiResponse(ContractPeriodResource::collection($contractPeriods), trans('api.success'))
            ->header('Cache-Control', \App\Support\PublicCache::CACHE_CONTROL);
    }

    /**
     * @template TValue
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    private function rememberCatalog(string $key, callable $callback): mixed
    {
        return Cache::remember(
            'catalog.lookup.'.$key.'.'.app()->getLocale(),
            self::CACHE_TTL_SECONDS,
            $callback
        );
    }
}
