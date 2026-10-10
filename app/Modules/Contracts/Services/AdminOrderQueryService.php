<?php

namespace App\Modules\Contracts\Services;

use App\Models\Contract;
use App\Models\ContractStatus;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AdminOrderQueryService
{
    /**
     * @return array{paginator: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function paginateOrders(Request $request): array
    {
        if ($this->wantsReturnList($request)) {
            return $this->paginateReturnOrders($request);
        }

        if ($this->wantsDraftList($request)) {
            return $this->paginateDraftOrders($request);
        }

        if ($this->wantsCompletedDraftList($request)) {
            return $this->paginateCompletedAndDraft($request);
        }

        $statusId = $this->resolveContractStatusIdFromRequest($request);
        $isCompleted = $this->resolveCompletionFilterFromRequest($request);
        $receivedPresenceFilter = $this->parseReceivedContractQueryFilter($request);

        if ($statusId !== null) {
            $request->merge(['status_id' => $statusId]);
            validator($request->all(), [
                'status_id' => 'required|integer|exists:contract_statuses,id',
            ])->validate();

            $orders = $this->baseOrdersQuery($request)
                ->where('contract_status_id', $statusId)
                ->when($isCompleted !== null, fn ($q) => $q->paymentPaid((bool) $isCompleted)
                )
                ->latest()
                ->paginate($this->perPage($request, 120, 200));

            return [
                'paginator' => $orders,
                'meta' => array_merge(
                    [
                        'contract_status_id' => $statusId,
                        'status_id' => $statusId,
                        'is_completed' => $isCompleted,
                    ],
                    $this->newOrdersListSummaryIfNeeded($request, $statusId)
                ),
            ];
        }

        if ($isCompleted !== null) {
            // متابعة دفعة (د): status_key / tab تُطبَّق مع فلتر الدفع أيضاً.
            $tab = strtolower(trim((string) $request->input('tab', '')));
            if ($tab === 'incomplete' || $tab === 'incomplete_drafts') {
                return $this->paginateIncompleteDrafts($request);
            }
            $statusKeys = $this->resolveStatusKeysFromRequest($request);

            $orders = $this->baseOrdersQuery($request)
                ->paymentPaid((bool) $isCompleted)
                ->tap(fn ($q) => $this->applyStatusKeysFilter($q, $statusKeys))
                ->latest()
                ->paginate($this->perPage($request, 120, 200));

            return [
                'paginator' => $orders,
                'meta' => [
                    'is_completed' => $isCompleted ? 1 : 0,
                    'status_keys' => $statusKeys,
                ],
            ];
        }

        if ($receivedPresenceFilter === false) {
            $orders = $this->awaitingReceiptOrdersQuery($request)
                ->paginate($this->perPage($request, 120, 200));

            return [
                'paginator' => $orders,
                'meta' => array_merge(
                    [
                        'contract_status_id' => ContractStatus::NEW_ID,
                        'is_received' => false,
                    ],
                    $this->newOrdersListSummary($request)
                ),
            ];
        }

        if ($receivedPresenceFilter === true || strtolower(trim((string) $request->input('list', ''))) === 'received') {
            return $this->paginateReceivedOrders($request);
        }

        // دفعة (د) — ب3: «جميع الطلبات» بدون فلتر حالة ضمني (كل الحالات). فلتر صريح عبر
        // status_key / status_case (مفتاح أو أكثر مفصولة بفواصل) أو tab، مع بقاء status_id للتوافق.
        $tab = strtolower(trim((string) $request->input('tab', '')));
        if ($tab === 'incomplete' || $tab === 'incomplete_drafts') {
            return $this->paginateIncompleteDrafts($request);
        }

        $statusKeys = $this->resolveStatusKeysFromRequest($request);

        $orders = Contract::query()
            ->tap(fn ($q) => $this->applySuccessfulPaymentAmountSelect($q))
            ->notDeleted()
            ->reachedAdminOrderStep()
            ->tap(fn ($q) => $this->applyContractStatusFiltersToQuery($q, $request))
            ->tap(fn ($q) => $this->applyStatusKeysFilter($q, $statusKeys))
            // فلتر نوع العقد/العميل كان مفقوداً في هذا الفرع الافتراضي. (DASHBOARD-4)
            ->when($request->filled('contract_type'), fn ($q) => $q->where('contract_type', $request->contract_type)
            )
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id)
            )
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString())
            )
            ->tap(fn ($q) => $this->applyReceivedContractPresenceToQuery($q, $request))
            ->tap(fn ($q) => $this->applyAttentionFilter($q, $request))
            ->with($this->orderListRelations())
            ->latest()
            ->paginate($this->perPage($request, 120, 200));

        return [
            'paginator' => $orders,
            'meta' => [
                'contract_status_id' => null,
                'status_keys' => $statusKeys,
                'tab' => $tab !== '' ? $tab : 'all',
                'is_received' => $receivedPresenceFilter,
                'attention' => $request->filled('attention') ? (string) $request->input('attention') : null,
            ],
        ];
    }

    /**
     * «غير مكتمل»: مسودات لم تتجاوز الخطوة 3 (راجع ARCHITECTURE.md — قاعدة الظهور).
     *
     * @return array{paginator: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function paginateIncompleteDrafts(Request $request): array
    {
        $orders = $this->filteredScope(Contract::query()->incompleteDraft(), $request)
            ->tap(fn ($q) => $this->applySuccessfulPaymentAmountSelect($q))
            ->with($this->orderListRelations())
            ->latest()
            ->paginate($this->perPage($request, 120, 200));

        return [
            'paginator' => $orders,
            'meta' => ['tab' => 'incomplete', 'contract_status_id' => null, 'status_keys' => []],
        ];
    }

    /**
     * عدّادات تبويبات «جميع الطلبات» (ب3): نفس نطاق القائمة تماماً + نفس فلاتر البحث/النوع/العميل.
     *
     * @return array<string, mixed>
     */
    public function statusCounts(Request $request): array
    {
        $listed = $this->filteredScope(Contract::query()->notDeleted()->reachedAdminOrderStep(), $request);

        $byStatusId = (clone $listed)
            ->selectRaw('contract_status_id, COUNT(*) as aggregate')
            ->groupBy('contract_status_id')
            ->pluck('aggregate', 'contract_status_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $all = array_sum($byStatusId);
        $statuses = ContractStatus::query()->orderBy('order')->orderBy('id')->get();
        $rows = [];
        $byKey = array_fill_keys(ContractStatus::KEYS, 0);
        foreach ($statuses as $status) {
            $key = \App\Support\ContractFrontendStatus::keyForStatusRow($status);
            $count = (int) ($byStatusId[$status->id] ?? 0);
            $rows[] = [
                'id' => (int) $status->id,
                'name' => $status->name,
                'status_key' => $key,
                'color' => $status->color,
                'color_text' => $status->color_text,
                'is_active' => (bool) $status->is_active,
                'count' => $count,
            ];
            if ($key !== null) {
                $byKey[$key] = ($byKey[$key] ?? 0) + $count;
            }
        }

        // «تم الدفع» افتراضية: صف «جديد» المدفوع (لم ينتقل بعد). by_key.new = غير المدفوع فقط
        // حتى يطابق ?status_key=new، و by_key.paid = المدفوع — والمجموع = عدد صف «جديد».
        $newId = ContractStatus::idFor(ContractStatus::KEY_NEW);
        $paidNew = $newId !== null ? (clone $listed)->where('contract_status_id', $newId)->paymentPaid(true)->count() : 0;
        $byKey[ContractStatus::KEY_PAID] = ($byKey[ContractStatus::KEY_PAID] ?? 0) + $paidNew;
        $byKey[ContractStatus::KEY_NEW] = max(0, ($byKey[ContractStatus::KEY_NEW] ?? 0) - $paidNew);

        $incomplete = $this->filteredScope(Contract::query()->incompleteDraft(), $request)->count();

        return [
            'all' => $all,
            'paid' => (clone $listed)->paymentPaid(true)->count(),
            'unpaid' => (clone $listed)->paymentPaid(false)->count(),
            'incomplete' => $incomplete,
            'no_status' => (int) ($byStatusId[''] ?? $byStatusId[0] ?? 0),
            'by_key' => $byKey,
            'statuses' => $rows,
            'tabs' => $this->buildTabs($rows, $byKey, $all, $incomplete),
        ];
    }

    /**
     * تبويب لكل حالة؛ صف «جديد» يُقسم إلى «جديد» (غير مدفوع) و«تم الدفع» — عدد كل تبويب = إجمالي ?status_key=<key>.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $byKey
     * @return list<array<string, mixed>>
     */
    private function buildTabs(array $rows, array $byKey, int $all, int $incomplete): array
    {
        $tabs = [['key' => 'all', 'label' => 'جميع الطلبات', 'count' => $all]];
        foreach ($rows as $r) {
            // دفعة (هـ): حالات قديمة (المسودة) لا تُعرض كتبويب.
            if (in_array($r['status_key'], ContractStatus::LEGACY_KEYS, true)) {
                continue;
            }
            if ($r['status_key'] === ContractStatus::KEY_NEW) {
                $tabs[] = ['key' => 'new', 'label' => $r['name'], 'count' => $byKey['new'] ?? 0, 'status_id' => $r['id'], 'status_key' => 'new'];
                $tabs[] = ['key' => 'paid', 'label' => 'تم الدفع', 'count' => $byKey['paid'] ?? 0, 'status_id' => $r['id'], 'status_key' => 'paid'];

                continue;
            }
            $tabs[] = ['key' => $r['status_key'] ?? ('status_'.$r['id']), 'label' => $r['name'], 'count' => $r['count'], 'status_id' => $r['id'], 'status_key' => $r['status_key']];
        }
        $tabs[] = ['key' => 'incomplete', 'label' => 'غير مكتمل', 'count' => $incomplete];

        return $tabs;
    }

    /**
     * فلاتر البحث/النوع/العميل المشتركة بين القائمة والعدّادات.
     */
    private function filteredScope(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('contract_type'), fn ($q) => $q->where('contract_type', $request->contract_type))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString()));
    }

    /**
     * @return list<string>
     */
    public function resolveStatusKeysFromRequest(Request $request): array
    {
        $raw = $request->input('status_key', $request->input('status_case'));
        $tab = strtolower(trim((string) $request->input('tab', '')));
        if (($raw === null || $raw === '') && $tab !== '' && $tab !== 'all') {
            $raw = $tab;
        }
        if ($raw === null || $raw === '') {
            return [];
        }

        $keys = is_array($raw) ? $raw : explode(',', (string) $raw);
        $keys = array_values(array_unique(array_filter(array_map(static fn ($k) => strtolower(trim((string) $k)), $keys))));

        foreach ($keys as $key) {
            if (! in_array($key, ContractStatus::KEYS, true)) {
                throw new InvalidArgumentException('status_key غير معروف: '.$key.' — المسموح: '.implode(', ', ContractStatus::KEYS));
            }
        }

        return $keys;
    }

    /**
     * @param  list<string>  $keys
     */
    private function applyStatusKeysFilter($query, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $newId = ContractStatus::idFor(ContractStatus::KEY_NEW);
        $query->where(function ($q) use ($keys, $newId) {
            $plain = array_values(array_diff($keys, [ContractStatus::KEY_PAID, ContractStatus::KEY_NEW]));
            $ids = ContractStatus::idsFor($plain);
            $q->whereIn('contract_status_id', $ids ?: [-1]);
            // new/paid: نفس الصف «جديد» مفرّقاً بالدفع.
            if ($newId !== null && in_array(ContractStatus::KEY_NEW, $keys, true) && in_array(ContractStatus::KEY_PAID, $keys, true)) {
                $q->orWhere('contract_status_id', $newId);
            } elseif ($newId !== null && in_array(ContractStatus::KEY_NEW, $keys, true)) {
                $q->orWhere(fn ($w) => $w->where('contract_status_id', $newId)->paymentPaid(false));
            } elseif ($newId !== null && in_array(ContractStatus::KEY_PAID, $keys, true)) {
                $q->orWhere(fn ($w) => $w->where('contract_status_id', $newId)->paymentPaid(true));
            }
        });
    }

    /**
     * @return array{paginator: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function paginateReturnOrders(Request $request): array
    {
        $returnStatus = $this->resolveReturnAcceptanceFilter($request);

        $contracts = $this->returnContractsQuery($request)
            ->with($this->orderListRelations())
            ->paginate($this->perPage($request));

        return [
            'paginator' => $contracts,
            'meta' => [
                'contract_status_id' => ContractStatus::RECEIVED_ID,
                'return_status' => $returnStatus,
                'return_status_filters' => ['pending', 'accept', 'reject'],
            ],
        ];
    }

    /**
     * @return array{paginator: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function paginateReceivedOrders(Request $request): array
    {
        $contracts = Contract::query()
            ->tap(fn ($q) => $this->applySuccessfulPaymentAmountSelect($q))
            ->notDeleted()
            ->reachedAdminOrderStep()
            ->whereHas('receivedContract')
            ->tap(fn ($q) => $this->applyContractStatusFiltersToQuery($q, $request))
            ->when($request->filled('contract_type'), fn ($q) => $q->where('contract_type', $request->contract_type)
            )
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id)
            )
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString())
            )
            ->with($this->orderListRelations())
            ->orderBy(
                $request->get('sort_by', 'created_at'),
                $request->get('sort_order', 'desc')
            )
            ->paginate($this->perPage($request, 120, 200));

        return [
            'paginator' => $contracts,
            'meta' => ['is_received' => true],
        ];
    }

    /**
     * @return array{paginator: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function paginateDraftOrders(Request $request): array
    {
        if ($request->filled('status_id') || $request->filled('draft_contract_status_id')) {
            $statusId = (int) ($request->input('draft_contract_status_id') ?? $request->input('status_id'));
            $request->merge(['status_id' => $statusId]);
            validator($request->all(), [
                'status_id' => 'required|integer|exists:draft_contract_statuses,id',
            ])->validate();
        }

        $contracts = $this->draftContractsQuery($request)
            ->with($this->orderListRelations())
            ->paginate($this->perPage($request, 120, 200));

        $meta = ['is_draft' => true];
        if ($request->filled('draft_contract_status_id') || $request->filled('status_id')) {
            $meta['draft_contract_status_id'] = (int) ($request->input('draft_contract_status_id') ?? $request->input('status_id'));
        }

        return [
            'paginator' => $contracts,
            'meta' => $meta,
        ];
    }

    /**
     * @return array{paginator: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function paginateCompletedAndDraft(Request $request): array
    {
        $contracts = $this->completedAndDraftContractsQuery($request)
            ->with($this->orderListRelations())
            ->paginate($this->perPage($request, 120, 200));

        return [
            'paginator' => $contracts,
            'meta' => ['is_completed_or_draft' => true],
        ];
    }

    public function findAdminContract(int $id): Contract
    {
        return Contract::query()->whereKey($id)->firstOrFail();
    }

    /**
     * @return list<string|array>
     */
    public function orderListRelations(): array
    {
        return [
            'user',
            'receivedContract.employee',
            'acceptRetrunContractEmployee:id,name',
            'refundableContract',
            'contractStatus',
            'draftContractStatus',
            'contractPayments' => fn ($q) => $q->where('status', 'success'),
            // دفعة (هـ): شارة الدفع/«بانتظار دفع فرق»/«بانتظار العميل» بلا استعلام لكل صف.
            'paymentRows' => fn ($q) => $q->where('status', 'success'),
            'charges',
            'dataRequests' => fn ($q) => $q->where('status', \App\Models\ContractDataRequest::STATUS_PENDING),
            'refunds' => fn ($q) => $q->where('status', \App\Models\Refund::STATUS_SUCCEEDED),
        ];
    }

    /**
     * @return list<string|array>
     */
    public function contractDetailRelations(): array
    {
        return [
            'user',
            'realEstate',
            'unit',
            'units.unitType',
            'units.unitUsage',
            'propertyType',
            'propertyUsages',
            'propertyRegion',
            'propertyCity',
            'tenantEntityLegalRegion',
            'tenantEntityLegalCity',
            'tenantEntityCity',
            'tenantEntityRegion',
            'unitType',
            'unitUsage',
            'contractTermInYears',
            'paymentType',
            'account',
            'receivedContract.employee',
            'acceptRetrunContractEmployee:id,name',
            'refundableContract',
            'contractStatus',
            'draftContractStatus',
            'contractPayments',
            'tenantRole',
            'comments.employee',
            'invoices',
        ];
    }

    private function baseOrdersQuery(Request $request): Builder
    {
        return Contract::query()
            ->tap(fn ($q) => $this->applySuccessfulPaymentAmountSelect($q))
            ->notDeleted()
            ->reachedAdminOrderStep()
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString())
            )
            ->when($request->filled('contract_type'), fn ($q) => $q->where('contract_type', $request->contract_type)
            )
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id)
            )
            ->tap(fn ($q) => $this->applyReceivedContractPresenceToQuery($q, $request))
            ->tap(fn ($q) => $this->applyAttentionFilter($q, $request))
            ->with($this->orderListRelations());
    }

    private function awaitingReceiptOrdersQuery(Request $request): Builder
    {
        return Contract::query()
            ->tap(fn ($q) => $this->applySuccessfulPaymentAmountSelect($q))
            ->notDeleted()
            // دفعة (د): المدفوع ينتقل تلقائياً إلى «قيد المراجعة» — يبقى بانتظار الاستلام حتى يستلمه موظف.
            ->whereIn('contract_status_id', $this->awaitingReceiptStatusIds())
            ->whereDoesntHave('receivedContract')
            ->when($request->has('is_completed'), fn ($q) => $q->paymentPaid($request->boolean('is_completed'))
            )
            ->when($request->filled('contract_type'), fn ($q) => $q->where('contract_type', $request->contract_type)
            )
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id)
            )
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString())
            )
            ->with($this->orderListRelations())
            ->latest();
    }

    /**
     * @return array<string, mixed>
     */
    /** @return list<int> */
    public function awaitingReceiptStatusIds(): array
    {
        return ContractStatus::idsFor([ContractStatus::KEY_NEW, ContractStatus::KEY_UNDER_REVIEW]) ?: [ContractStatus::NEW_ID];
    }

    private function newOrdersListSummaryIfNeeded(Request $request, int $statusId): array
    {
        if ($statusId !== ContractStatus::NEW_ID) {
            return [];
        }

        return $this->newOrdersListSummary($request);
    }

    /**
     * @return array{summary: array<string, mixed>}
     */
    public function newOrdersListSummary(Request $request): array
    {
        $now = now();
        $over15 = $now->copy()->subMinutes(15);
        $over30 = $now->copy()->subMinutes(30);

        $isCompleted = $this->resolveCompletionFilterFromRequest($request);

        $base = Contract::query()
            ->notDeleted()
            ->reachedAdminOrderStep()
            ->whereIn('contract_status_id', $this->awaitingReceiptStatusIds())
            ->whereDoesntHave('receivedContract')
            ->when($isCompleted !== null, fn ($q) => $q->paymentPaid((bool) $isCompleted)
            )
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString())
            )
            ->when($request->filled('contract_type'), fn ($q) => $q->where('contract_type', $request->contract_type)
            )
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id)
            );

        $total = (clone $base)->count();
        $exceeded15 = (clone $base)->where('created_at', '<=', $over15)->count();
        $exceeded30 = (clone $base)->where('created_at', '<=', $over30)->count();

        return [
            'summary' => [
                'total_new_orders' => $total,
                'total_new_orders_label' => 'إجمالي الطلبات الجديدة',
                'exceeded_15_minutes' => $exceeded15,
                'exceeded_15_minutes_label' => 'تجاوزت 15 دقيقة',
                'exceeded_30_minutes' => $exceeded30,
                'exceeded_30_minutes_label' => 'تجاوزت 30 دقيقة',
                'cards' => [
                    [
                        'key' => 'total_new_orders',
                        'label' => 'إجمالي الطلبات الجديدة',
                        'count' => $total,
                    ],
                    [
                        'key' => 'exceeded_15_minutes',
                        'label' => 'تجاوزت 15 دقيقة',
                        'count' => $exceeded15,
                    ],
                    [
                        'key' => 'exceeded_30_minutes',
                        'label' => 'تجاوزت 30 دقيقة',
                        'count' => $exceeded30,
                    ],
                ],
            ],
        ];
    }

    private function returnContractsQuery(Request $request): Builder
    {
        return Contract::query()
            ->tap(fn ($q) => $this->applySuccessfulPaymentAmountSelect($q))
            ->notDeleted()
            ->reachedAdminOrderStep()
            ->where('contract_status_id', ContractStatus::RECEIVED_ID)
            ->whereHas('receivedContract')
            ->tap(fn ($q) => $this->applyReturnAcceptanceFilterToQuery($q, $request))
            ->when($request->filled('contract_type'), fn ($q) => $q->where('contract_type', $request->contract_type)
            )
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id)
            )
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString())
            )
            ->tap(fn ($q) => $this->applyReceivedContractPresenceToQuery($q, $request))
            ->orderBy(
                $request->get('sort_by', 'created_at'),
                $request->get('sort_order', 'desc')
            );
    }

    private function applyReturnAcceptanceFilterToQuery($query, Request $request): void
    {
        $status = $this->resolveReturnAcceptanceFilter($request);
        if ($status === null) {
            return;
        }

        match ($status) {
            'pending' => $query->whereNull('accept_retrun_contract_employee_id'),
            'accept' => $query->where('accept_retrun_contract', true),
            'reject' => $query
                ->where('accept_retrun_contract', false)
                ->whereNotNull('accept_retrun_contract_employee_id'),
        };
    }

    /**
     * @return 'pending'|'accept'|'reject'|null
     */
    public function resolveReturnAcceptanceFilter(Request $request): ?string
    {
        $raw = $request->input('return_status')
            ?? $request->input('accept_retrun_status')
            ?? $request->input('acceptance_status');

        if ($raw === null || $raw === '') {
            return null;
        }

        $status = strtolower(trim((string) $raw));

        $status = match ($status) {
            'accepted', 'approve', 'approved', '1', 'true' => 'accept',
            'rejected', 'reject', '0', 'false' => 'reject',
            'pending', 'wait', 'waiting' => 'pending',
            default => $status,
        };

        if (! in_array($status, ['pending', 'accept', 'reject'], true)) {
            throw new InvalidArgumentException(
                'return_status يجب أن يكون: pending أو accept أو reject'
            );
        }

        return $status;
    }

    private function draftContractsQuery(Request $request): Builder
    {
        $draftStatusId = null;
        if ($request->filled('draft_contract_status_id')) {
            $draftStatusId = (int) $request->input('draft_contract_status_id');
        } elseif ($request->filled('status_id')) {
            $draftStatusId = (int) $request->input('status_id');
        }

        return Contract::query()
            ->tap(fn ($q) => $this->applySuccessfulPaymentAmountSelect($q))
            ->notDeleted()
            ->reachedAdminOrderStep()
            ->draft()
            ->tap(fn ($q) => $this->applyDraftStatusIdFilter($q, $draftStatusId))
            ->when($request->filled('contract_type'), fn ($q) => $q->where('contract_type', $request->contract_type)
            )
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id)
            )
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString())
            )
            ->tap(fn ($q) => $this->applyReceivedContractPresenceToQuery($q, $request))
            ->orderBy(
                $request->get('sort_by', 'created_at'),
                $request->get('sort_order', 'desc')
            );
    }

    private function applyDraftStatusIdFilter($query, ?int $draftStatusId): void
    {
        if ($draftStatusId === null) {
            return;
        }

        $query->where('draft_contract_status_id', $draftStatusId);
    }

    private function completedAndDraftContractsQuery(Request $request): Builder
    {
        return Contract::query()
            ->tap(fn ($q) => $this->applySuccessfulPaymentAmountSelect($q))
            ->notDeleted()
            ->reachedAdminOrderStep()
            ->where(function ($q) {
                $q->where('is_completed', 1)
                    ->orWhere('is_draft', true);
            })
            ->when($request->filled('contract_type'), fn ($q) => $q->where('contract_type', $request->contract_type)
            )
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id)
            )
            ->when($request->filled('search'), fn ($q) => $q->adminSearch($request->string('search')->toString())
            )
            ->tap(fn ($q) => $this->applyReceivedContractPresenceToQuery($q, $request))
            ->orderBy(
                $request->get('sort_by', 'created_at'),
                $request->get('sort_order', 'desc')
            );
    }

    public function resolveContractStatusIdFromRequest(Request $request): ?int
    {
        foreach (['status_id', 'contract_status_id', 'status'] as $key) {
            if (! $request->filled($key)) {
                continue;
            }

            $value = $request->input($key);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function listQueryName(Request $request): string
    {
        return strtolower(trim((string) $request->input('list', '')));
    }

    public function wantsReturnList(Request $request): bool
    {
        return $this->listQueryName($request) === 'return' || $request->boolean('return');
    }

    public function wantsDraftList(Request $request): bool
    {
        return $this->listQueryName($request) === 'draft' || $request->boolean('is_draft');
    }

    public function wantsCompletedDraftList(Request $request): bool
    {
        return $this->listQueryName($request) === 'completed-draft'
            || $request->boolean('completed_draft');
    }

    public function resolveCompletionFilterFromRequest(Request $request): ?bool
    {
        if ($request->filled('incomplete') && $this->isTruthyQueryFlag($request->input('incomplete'))) {
            return false;
        }

        if ($request->filled('complete') && $this->isTruthyQueryFlag($request->input('complete'))) {
            return true;
        }

        if ($request->has('is_completed') && $request->query('is_completed') !== null && $request->query('is_completed') !== '') {
            return $request->boolean('is_completed');
        }

        $status = strtolower(trim((string) $request->input('status', '')));
        if (in_array($status, ['complete', 'completed'], true)) {
            return true;
        }
        if (in_array($status, ['incomplete', 'uncompleted', 'not_completed'], true)) {
            return false;
        }

        return null;
    }

    private function isTruthyQueryFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function applyContractStatusFiltersToQuery($query, Request $request): void
    {
        $status = $request->get('status');
        $statusId = $this->resolveContractStatusIdFromRequest($request);

        $query
            ->when($request->has('is_completed'), fn ($q) => $q->paymentPaid($request->boolean('is_completed'))
            )
            ->when($request->filled('status'), function ($q) use ($status) {
                if (is_numeric($status)) {
                    $q->where('contract_status_id', (int) $status);
                } elseif (in_array(strtolower((string) $status), ['incomplete', 'uncompleted', 'not_completed'], true)) {
                    $q->incomplete();
                } elseif (in_array(strtolower((string) $status), ['complete', 'completed'], true)) {
                    $q->completed();
                }
            })
            ->when($statusId !== null, fn ($q) => $q->where('contract_status_id', $statusId)
            )
            ->when($request->filled('status_name'), fn ($q) => $q->whereHas('contractStatus', fn ($sq) => $sq->where('name', 'like', '%'.$request->status_name.'%')
            )
            )
            ->when($request->filled('contract_status_id'), fn ($q) => $q->where('contract_status_id', $request->contract_status_id)
            );
    }

    private function applyReceivedContractPresenceToQuery($query, Request $request): void
    {
        $wantReceived = $this->parseReceivedContractQueryFilter($request);
        if ($wantReceived === null) {
            return;
        }

        if ($wantReceived) {
            $query->whereHas('receivedContract');
        } else {
            $query->whereDoesntHave('receivedContract');
        }
    }

    private function applySuccessfulPaymentAmountSelect($query): void
    {
        $query->addSelect([
            'successful_payment_amount' => Payment::query()
                ->select('amount')
                ->successfulMatchingContractUuidColumn('contracts.uuid')
                ->orderByDesc('id')
                ->limit(1),
        ]);
    }

    public function parseReceivedContractQueryFilter(Request $request): ?bool
    {
        if ($request->has('is_received')) {
            $raw = $request->query('is_received');
            if ($raw === null || $raw === '') {
                return false;
            }

            return $request->boolean('is_received');
        }

        if (! $request->has('received_contract')) {
            return null;
        }

        $raw = $request->query('received_contract');
        if ($raw === null || $raw === '') {
            return false;
        }

        return $request->boolean('received_contract');
    }

    private function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        // دفعة (هـ): التصدير يطلب صفحة كبيرة واحدة.
        $exportMax = (int) $request->attributes->get('export_max', 0);
        if ($exportMax > 0) {
            $max = $exportMax;
        }

        return min(max((int) $request->input('per_page', $default), 1), $max);
    }

    /**
     * دفعة (هـ): فلتر «عليك الحين» على القائمة — ?attention=awaiting_customer (طلب مرفق معلّق)
     * أو ?attention=charge_pending (رسوم بانتظار الدفع «بانتظار دفع فرق»).
     */
    private function applyAttentionFilter(Builder $query, Request $request): void
    {
        $attention = strtolower(trim((string) $request->input('attention', '')));
        if ($attention === '') {
            return;
        }
        if ($attention === 'awaiting_customer' && \App\Support\SchemaCache::hasTable('contract_data_requests')) {
            $query->whereIn('contracts.id', \App\Models\ContractDataRequest::query()->select('contract_id')->where('status', \App\Models\ContractDataRequest::STATUS_PENDING));
        } elseif (in_array($attention, ['charge_pending', 'awaiting_charge'], true) && \App\Support\SchemaCache::hasTable('contract_charges')) {
            $query->whereIn('contracts.id', \App\Models\ContractCharge::query()->select('contract_id')->where('status', \App\Models\ContractCharge::STATUS_PENDING));
        } elseif ($attention === 'unpaid') {
            $query->paymentPaid(false);
        }
    }
}
