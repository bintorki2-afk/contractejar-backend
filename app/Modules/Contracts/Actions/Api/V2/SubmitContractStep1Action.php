<?php

namespace App\Modules\Contracts\Actions\Api\V2;

use App\Http\Requests\Api\V2\Contract\Step1Request;
use App\Models\Contract;
use App\Modules\Contracts\Services\ContractPropertyAddressService;

class SubmitContractStep1Action
{
    public function __construct(
        private readonly ContractPropertyAddressService $address,
    ) {}

    /**
     * @return array{ok: true, contract: Contract}|array{ok: false, message: string, code?: int}
     */
    public function execute(Contract $contract, Step1Request $request): array
    {
        if ($contract->lockedForCustomerStep(1)) {
            return ['ok' => false, 'message' => trans('api.completed_contract'), 'code' => 422];
        }

        $validated = $request->validated();

        $step1Data = [
            'is_multiple_trusteeship_deed_copy' => array_key_exists('is_multiple_trusteeship_deed_copy', $validated)
                ? (bool) $validated['is_multiple_trusteeship_deed_copy']
                : (bool) $contract->is_multiple_trusteeship_deed_copy,
        ];

        foreach ([
            'property_type_id',
            'property_usages_id',
            'age_of_the_property',
            'number_of_floors',
            'number_of_units_per_floor',
            'number_of_units_in_realestate',
            'instrument_number',
            'type_instrument_history',
            'real_estate_registry_number',
            'type_date_first_registration',
        ] as $optionalField) {
            if (array_key_exists($optionalField, $validated)) {
                $step1Data[$optionalField] = $validated[$optionalField];
            }
        }

        $instrumentHistory = $request->resolvedInstrumentHistory();
        if ($instrumentHistory !== null) {
            $step1Data['instrument_history'] = $instrumentHistory;
            if (! array_key_exists('type_instrument_history', $step1Data)) {
                $step1Data['type_instrument_history'] = $request->input('type_instrument_history', 'hijri');
            }
        }

        $dateFirstRegistration = $request->resolvedDateFirstRegistration();
        if ($dateFirstRegistration !== null) {
            $step1Data['date_first_registration'] = $dateFirstRegistration;
            if (! array_key_exists('type_date_first_registration', $step1Data)) {
                $step1Data['type_date_first_registration'] = $request->input('type_date_first_registration', 'hijri');
            }
        }

        if ($request->filled('instrument_type')) {
            $step1Data['instrument_type'] = $validated['instrument_type'];
        }

        $this->address->applyCoordinatesIfPresent($step1Data, $request, $validated);
        $this->address->applyAddressUrlIfPresent($step1Data, $request, $validated);

        if ($addressError = $this->address->applyPropertyAddressIfPresent($step1Data, $request, $validated, $contract)) {
            return ['ok' => false, 'message' => $addressError];
        }

        $effectiveInstrumentType = $step1Data['instrument_type'] ?? $contract->instrument_type;
        $step1Data['step'] = Contract::shouldSkipInitialSteps($effectiveInstrumentType) ? 3 : 2;

        if ($contract->real_id) {
            $contract->loadMissing('realEstate');
            $fromReal = $contract->realEstate?->number_of_units_in_realestate;
            if ($fromReal !== null && $fromReal !== '') {
                $step1Data['number_of_units_in_realestate'] = $fromReal;
            }
        }

        // Deed/instrument images go to the PRIVATE disk and are served via a signed route.
        $imageInstrumentFile = $request->file('image_instrument');
        if ($imageInstrumentFile instanceof \Illuminate\Http\UploadedFile && $imageInstrumentFile->isValid()) {
            $step1Data['image_instrument'] = $imageInstrumentFile->store(\App\Support\DeedImage::DIR, \App\Support\DeedImage::DISK);
        } elseif (array_key_exists('image_instrument', $validated) && is_string($validated['image_instrument']) && $validated['image_instrument'] !== '') {
            $step1Data['image_instrument'] = $validated['image_instrument'];
        }

        // صفحات الصك الإضافية: تُستبدل القائمة كاملة عند إرسالها (العميل يرسل كل الصفحات).
        if ($request->hasFile('image_instrument_pages')) {
            $pages = [];
            foreach ((array) $request->file('image_instrument_pages') as $page) {
                if ($page instanceof \Illuminate\Http\UploadedFile && $page->isValid()) {
                    $pages[] = $page->store(\App\Support\DeedImage::DIR, \App\Support\DeedImage::DISK);
                }
            }
            $step1Data['image_instrument_pages'] = $pages;
        }

        foreach (['image_instrument_from_the_front', 'image_instrument_from_the_back'] as $deedImageField) {
            if ($request->hasFile($deedImageField)) {
                $step1Data[$deedImageField] = $request->file($deedImageField)->store(\App\Support\DeedImage::DIR, \App\Support\DeedImage::DISK);
            } elseif (
                array_key_exists($deedImageField, $validated)
                && is_string($validated[$deedImageField])
                && $validated[$deedImageField] !== ''
            ) {
                $step1Data[$deedImageField] = $validated[$deedImageField];
            }
        }

        // Sensitive legal/identity documents go to the PRIVATE disk (like deed
        // images) and are served only through signed URLs — never world-readable.
        $privateDisk = \App\Support\DeedImage::DISK;

        if ($request->hasFile('copy_of_the_endowment_registration_certificate')) {
            $step1Data['copy_of_the_endowment_registration_certificate'] = $request->file('copy_of_the_endowment_registration_certificate')
                ->store('contracts/endowment-registration-certificates', $privateDisk);
        }

        if ($request->hasFile('copy_of_the_trusteeship_deed')) {
            $step1Data['copy_of_the_trusteeship_deed'] = $request->file('copy_of_the_trusteeship_deed')
                ->store('contracts/trusteeship-deeds', $privateDisk);
        }

        foreach ([
            'Image_inheritance_certificate' => 'contracts/inheritance-certificates',
            'copy_power_of_attorney_from_heirs_to_agent' => 'contracts/heirs-powers-of-attorney',
            'copy_of_guardians_power_of_attorney_for_agent' => 'contracts/guardians-powers-of-attorney',
        ] as $instrumentFileField => $storageDir) {
            if ($request->hasFile($instrumentFileField)) {
                $step1Data[$instrumentFileField] = $request->file($instrumentFileField)->store($storageDir, $privateDisk);
            }
        }

        if ($request->hasFile('image_address')) {
            $step1Data['image_address'] = $request->file('image_address')->store('images/contracts', $privateDisk);
        }

        $contract->update($step1Data);

        return ['ok' => true, 'contract' => $contract->fresh(['realEstate', 'contractStatus'])];
    }
}
