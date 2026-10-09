<?php

namespace App\Modules\Notifications\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\NotificationDispatch;
use App\Models\User;
use App\Services\CustomerNotificationService;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class NotificationController extends Controller
{
    use Responser;

    public function __construct(
        protected FirebaseNotificationService $firebase,
        protected CustomerNotificationService $customers,
    ) {}

    /**
     * POST /api/admin/notifications/send
     *
     * audience: user | custom_user | employee | custom_employee | all_users | all_employees
     * kind (للعملاء): offer | announcement — يُخزَّن الإشعار في صندوق العميل مع رابط اختياري `url`.
     */
    public function send(Request $request)
    {
        return $this->dispatchNotification($request, null);
    }

    /** POST /api/admin/notifications/user */
    public function sendToUser(Request $request)
    {
        return $this->dispatchNotification($request, 'user');
    }

    /** POST /api/admin/notifications/custom-user */
    public function sendToCustomUser(Request $request)
    {
        return $this->dispatchNotification($request, 'custom_user');
    }

    /** POST /api/admin/notifications/employee */
    public function sendToEmployee(Request $request)
    {
        return $this->dispatchNotification($request, 'employee');
    }

    /** POST /api/admin/notifications/custom-employee */
    public function sendToCustomEmployee(Request $request)
    {
        return $this->dispatchNotification($request, 'custom_employee');
    }

    /** POST /api/admin/notifications/all-users */
    public function sendToAllUsers(Request $request)
    {
        return $this->dispatchNotification($request, 'all_users');
    }

    /** POST /api/admin/notifications/all-employees */
    public function sendToAllEmployees(Request $request)
    {
        return $this->dispatchNotification($request, 'all_employees');
    }

    /**
     * GET /api/admin/notification-dispatches?kind=&date=&from=&to=&user_id=&contract_id=&search=&per_page=
     *
     * سجل إرسال إشعارات العملاء (ف8): المجدولة والفورية واليدوية.
     */
    public function dispatches(Request $request)
    {
        try {
            $validated = $request->validate([
                'kind' => ['nullable', 'string', 'max:64'],
                'date' => ['nullable', 'date'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'user_id' => ['nullable', 'integer'],
                'contract_id' => ['nullable', 'integer'],
                'search' => ['nullable', 'string', 'max:64'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $query = NotificationDispatch::query()
                ->with(['user:id,fname,lname,mobile,contact_mobile', 'contract:id,uuid,contract_type', 'lessorChangeRequest:id,uuid'])
                ->latest('id');

            if (! empty($validated['kind'])) {
                $query->where('kind', $validated['kind']);
            }
            if (! empty($validated['date'])) {
                $query->whereDate('created_at', $validated['date']);
            }
            if (! empty($validated['from'])) {
                $query->whereDate('created_at', '>=', $validated['from']);
            }
            if (! empty($validated['to'])) {
                $query->whereDate('created_at', '<=', $validated['to']);
            }
            if (! empty($validated['user_id'])) {
                $query->where('user_id', (int) $validated['user_id']);
            }
            if (! empty($validated['contract_id'])) {
                $query->where('contract_id', (int) $validated['contract_id']);
            }
            if (! empty($validated['search'])) {
                $term = trim((string) $validated['search']);
                $query->where(function ($q) use ($term) {
                    $q->whereHas('contract', fn ($c) => $c->where('uuid', 'like', $term.'%'))
                        ->orWhereHas('lessorChangeRequest', fn ($c) => $c->where('uuid', 'like', $term.'%'))
                        ->orWhereHas('user', fn ($u) => $u->where('mobile', 'like', '%'.$term.'%')->orWhere('contact_mobile', 'like', '%'.$term.'%'))
                        ->orWhere('title', 'like', '%'.$term.'%');
                });
            }

            $paginator = $query->paginate($this->perPageFromRequest($request, 25));

            $items = collect($paginator->items())->map(fn (NotificationDispatch $row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'kind_label' => CustomerNotificationService::KIND_LABELS[$row->kind] ?? $row->kind,
                'channel' => $row->channel ?? 'push',
                'title' => $row->title,
                'body' => $row->body,
                'url' => $row->url,
                'push_result' => $row->push_result,
                'recipients_count' => (int) $row->recipients_count,
                'is_broadcast' => $row->user_id === null,
                'user' => $row->user ? [
                    'id' => $row->user->id,
                    'name' => $row->user->name,
                    'mobile' => $row->user->contact_mobile ?? $row->user->mobile,
                ] : null,
                'contract_id' => $row->contract_id,
                'order_number' => $row->contract?->uuid ?? $row->lessorChangeRequest?->uuid,
                'lessor_change_request_id' => $row->lessor_change_request_id,
                'sent_at' => optional($row->sent_at)?->format('Y-m-d H:i'),
                'created_at' => optional($row->created_at)?->format('Y-m-d H:i'),
            ])->values();

            $kinds = collect(CustomerNotificationService::KINDS)->map(fn (string $kind) => [
                'value' => $kind,
                'label' => CustomerNotificationService::KIND_LABELS[$kind] ?? $kind,
            ])->values();

            return $this->paginatedApiResponse($paginator, $items, trans('api.success'), [
                'kinds' => $kinds,
                'last_run' => \Illuminate\Support\Facades\Cache::get('notifications.dispatch.last_run'),
            ]);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    private function dispatchNotification(Request $request, ?string $forcedAudience)
    {
        try {
            if ($forcedAudience !== null) {
                $request->merge(['audience' => $forcedAudience]);
            }

            $validated = $request->validate([
                'audience' => ['required', Rule::in([
                    'user',
                    'custom_user',
                    'employee',
                    'custom_employee',
                    'all_users',
                    'all_employees',
                ])],
                'user_id' => [
                    Rule::requiredIf(fn () => in_array($request->input('audience'), ['user', 'custom_user'], true)),
                    'nullable',
                    'integer',
                    'exists:users,id',
                ],
                'employee_id' => [
                    Rule::requiredIf(fn () => in_array($request->input('audience'), ['employee', 'custom_employee'], true)),
                    'nullable',
                    'integer',
                    'exists:employees,id',
                ],
                'title' => ['required', 'string', 'max:255'],
                'body' => ['required', 'string', 'max:5000'],
                'kind' => ['nullable', Rule::in([CustomerNotificationService::KIND_OFFER, CustomerNotificationService::KIND_ANNOUNCEMENT])],
                'url' => ['nullable', 'string', 'max:500', 'url'],
                'data' => ['nullable', 'array'],
                // دفعة (د) — ب10: شريحة الإرسال الجماعي + كوبون اختياري بصلاحية.
                'segment' => ['nullable', Rule::in(CustomerNotificationService::SEGMENTS)],
                'city_id' => ['nullable', 'integer', Rule::requiredIf(fn () => $request->input('segment') === 'city')],
                'coupon_code' => ['nullable', 'string', 'max:64'],
                'valid_until' => ['nullable', 'date'],
            ]);

            $audience = (string) $validated['audience'];
            $title = (string) $validated['title'];
            $body = (string) $validated['body'];
            $kind = (string) ($validated['kind'] ?? CustomerNotificationService::KIND_OFFER);
            $url = isset($validated['url']) && $validated['url'] !== '' ? (string) $validated['url'] : null;
            $data = is_array($validated['data'] ?? null) ? $validated['data'] : [];
            if (in_array($audience, ['user', 'custom_user'], true)) {
                if (filled($validated['coupon_code'] ?? null)) {
                    $data['coupon_code'] = (string) $validated['coupon_code'];
                }
                if (filled($validated['valid_until'] ?? null)) {
                    $data['valid_until'] = \Illuminate\Support\Carbon::parse($validated['valid_until'])->toDateString();
                }
            }

            $result = match ($audience) {
                'user', 'custom_user' => $this->sendToCustomer((int) $validated['user_id'], $kind, $title, $body, $url, $data),
                'employee', 'custom_employee' => $this->firebase->sendToEmployee(
                    (int) $validated['employee_id'],
                    $title,
                    $body,
                    $data
                ),
                'all_users' => $this->broadcastToCustomers($kind, $title, $body, $url, $data, [
                    'segment' => $validated['segment'] ?? 'all',
                    'city_id' => $validated['city_id'] ?? null,
                    'coupon_code' => $validated['coupon_code'] ?? null,
                    'valid_until' => isset($validated['valid_until']) ? \Illuminate\Support\Carbon::parse($validated['valid_until'])->toDateString() : null,
                ]),
                'all_employees' => $this->firebase->sendToAllEmployees($title, $body, $data),
            };

            if (! empty($result['missing_token']) && empty($result['stored'])) {
                return $this->errorMessage('لا يوجد FCM token للمستلم.', 422);
            }

            return $this->apiResponse([
                'audience' => $audience,
                'kind' => $kind,
                'url' => $url,
                'title' => $title,
                'body' => $body,
                'result' => $result,
            ], trans('api.success'));
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (\InvalidArgumentException $e) {
            return $this->errorMessage($e->getMessage(), 404);
        } catch (Throwable $e) {
            return $this->errorMessage(trans('api.error_occurred').': '.$e->getMessage(), 500);
        }
    }

    /**
     * عميل واحد: يُخزَّن في صندوق إشعاراته + Push إن وُجد توكن.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sendToCustomer(int $userId, string $kind, string $title, string $body, ?string $url, array $data): array
    {
        $user = User::query()->find($userId);
        if (! $user) {
            throw new \InvalidArgumentException(trans('api.not_found'));
        }

        $offer = $this->customers->notify(
            $user,
            $kind,
            $title,
            $body,
            array_merge($data, $url !== null ? ['url' => $url] : []),
            dedupe: false,
        );

        $hasToken = filled($user->fcm_token);

        return [
            'stored' => $offer !== null,
            'notification_id' => $offer?->id,
            'sent' => $hasToken ? 1 : 0,
            'failed' => 0,
            'topic_sent' => false,
            'missing_token' => ! $hasToken,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function broadcastToCustomers(string $kind, string $title, string $body, ?string $url, array $data, array $options = []): array
    {
        $outcome = $this->customers->broadcast($kind, $title, $body, $url, $data, $options);

        return array_merge($outcome['push'], [
            'stored' => $outcome['recipients'] > 0,
            'recipients' => $outcome['recipients'],
            'segment' => $outcome['segment'] ?? 'all',
            'missing_token' => false,
        ]);
    }

    /**
     * POST /api/admin/notifications/broadcast/preview — عدد المستلمين للشريحة + شكل الإشعار كما يظهر للعميل.
     */
    public function broadcastPreview(Request $request)
    {
        try {
            $validated = $request->validate([
                'segment' => ['nullable', Rule::in(CustomerNotificationService::SEGMENTS)],
                'city_id' => ['nullable', 'integer', Rule::requiredIf(fn () => $request->input('segment') === 'city')],
                'title' => ['nullable', 'string', 'max:255'],
                'body' => ['nullable', 'string', 'max:5000'],
                'kind' => ['nullable', Rule::in([CustomerNotificationService::KIND_OFFER, CustomerNotificationService::KIND_ANNOUNCEMENT])],
                'coupon_code' => ['nullable', 'string', 'max:64'],
                'valid_until' => ['nullable', 'date'],
            ]);

            $segment = (string) ($validated['segment'] ?? 'all');

            return $this->apiResponse([
                'segment' => $segment,
                'segments' => [
                    ['value' => 'all', 'label' => 'كل العملاء'],
                    ['value' => 'has_active_contract', 'label' => 'عملاء لديهم عقد مدفوع نشط'],
                    ['value' => 'city', 'label' => 'عملاء مدينة محددة'],
                ],
                'recipients_count' => $this->customers->segmentCount(['segment' => $segment, 'city_id' => $validated['city_id'] ?? null]),
                'preview' => [
                    'kind' => $validated['kind'] ?? CustomerNotificationService::KIND_OFFER,
                    'title' => $validated['title'] ?? '',
                    'body' => $validated['body'] ?? '',
                    'coupon_code' => $validated['coupon_code'] ?? null,
                    'valid_until' => isset($validated['valid_until']) ? \Illuminate\Support\Carbon::parse($validated['valid_until'])->toDateString() : null,
                ],
            ], trans('api.success'));
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        }
    }
}
