<?php

namespace App\Modules\Contracts\Actions\Api\V2;

use App\Http\Requests\Api\V2\Contract\Step2Request;
use App\Models\Contract;
use App\Modules\Contracts\Services\ContractPropertyAddressService;

class SubmitContractStep2Action
{
    public function __construct(
        private readonly ContractPropertyAddressService $address,
    ) {}

    /**
     * @return array{ok: true, contract: Contract}|array{ok: false, message: string, code?: int}
     */
    public function execute(Contract $contract, Step2Request $request): array
    {
        $validated = $request->validated();

        if (Contract::shouldSkipInitialSteps($contract->instrument_type)) {
            $skipData = ['step' => 3];
            $this->address->applyCoordinatesIfPresent($skipData, $request, $validated);
            $this->address->applyAddressUrlIfPresent($skipData, $request, $validated);
            if ($addressError = $this->address->applyPropertyAddressIfPresent($skipData, $request, $validated, $contract)) {
                return ['ok' => false, 'message' => $addressError];
            }
            $contract->update($skipData);

            return ['ok' => true, 'contract' => $contract->fresh(['contractStatus'])];
        }

        if ($contract->is_completed) {
            return ['ok' => false, 'message' => trans('api.completed_contract')];
        }

        $data = ['step' => 3];
        $this->address->mergeRequired($data, $validated);
        if ($addressError = $this->address->applyPropertyAddressIfPresent($data, $request, $validated, $contract)) {
            return ['ok' => false, 'message' => $addressError];
        }

        $this->address->applyCoordinatesIfPresent($data, $request, $validated);
        $this->address->applyAddressUrlIfPresent($data, $request, $validated);

        if ($request->hasFile('image_address')) {
            // Sensitive field (DeedImage::FIELDS): private disk + signed URL, never public.
            $data['image_address'] = $request->file('image_address')->store('images/contracts', \App\Support\DeedImage::DISK);
        }

        $contract->update($data);

        return ['ok' => true, 'contract' => $contract->fresh(['contractStatus'])];
    }
}
