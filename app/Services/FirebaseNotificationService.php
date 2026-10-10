<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Employee;
use App\Support\ContractFrontendStatus;
use Exception;
use Google_Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

/**
 * Sends FCM notifications via Firebase HTTP v1 API using a service-account JSON.
 */
class FirebaseNotificationService
{
    protected string $credentialsPath;

    protected string $projectId;

    public function __construct()
    {
        $credentials = (string) config(
            'services.firebase.credentials',
            storage_path('app/aqdi-test-34027147e050.json')
        );

        $this->credentialsPath = $this->resolveCredentialsPath($credentials);
        $this->projectId = (string) config('services.firebase.project_id', 'aqdi-3d3ee');
    }

    private function resolveCredentialsPath(string $path): string
    {
        if ($path !== '' && (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path))) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @throws Exception
     */
    public function sendNotification($title, $body)
    {
        $this->sendToTopic('all', (string) $title, (string) $body);
    }

    /**
     * Notify employees that a contract was received by a specific employee.
     */
    public function notifyContractReceivedByEmployee(Contract $contract, Employee $employee): void
    {
        $contract->loadMissing(['contractStatus', 'draftContractStatus', 'receivedContract']);

        $contractNo = str_pad((string) $contract->id, 6, '0', STR_PAD_LEFT);
        $employeeName = (string) ($employee->name ?? 'موظف');
        $title = 'تم استلام عقد';
        $body = "تم استلام العقد رقم {$contractNo} من الموظف {$employeeName}";
        $statusPayload = ContractFrontendStatus::firebaseData($contract);
        $data = array_merge($statusPayload, [
            'type' => 'contract_received',
            'employee_id' => (string) $employee->id,
            'employee_name' => $employeeName,
            'contract_status_id' => (string) ($contract->contract_status_id ?? ''),
        ]);

        $this->sendToAllEmployees($title, $body, $data);

        // دفعة (د) — ب10: إشعار العميل بالاستلام يمر عبر CustomerNotificationService (صندوق + Push + سجل)
        // من OrderFlowService::receive — لا push مباشر مكرر هنا.
    }

    /**
     * Push contract/draft status change to the contract owner (matches API v2 status fields).
     */
    public function notifyContractStatusChanged(Contract $contract): void
    {
        $contract->loadMissing(['contractStatus', 'draftContractStatus', 'receivedContract', 'user']);

        if ((int) $contract->user_id <= 0) {
            return;
        }

        // ف8: الصياغة حسب الحالة (توثيق / تحديث عام)، تخزين في صندوق الإشعارات
        // مرتبطاً بالطلب + Push (إن وُجد توكن) + سجل إرسال يمنع التكرار.
        try {
            app(CustomerNotificationService::class)->contractStatusChanged($contract);
        } catch (\Throwable $e) {
            Log::warning('Firebase notify contract status change failed', [
                'contract_id' => $contract->id,
                'user_id' => $contract->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify active employees about a draft or completed/paid contract.
     * Sends to topic `employees` + each employee FCM token when available.
     *
     * @param  float|null  $paidAmount  Paid amount in SAR when the contract was paid.
     */
    public function notifyEmployeesOfNewContract(Contract $contract, ?float $paidAmount = null): void
    {
        $contract->loadMissing('user');

        $contractNo = str_pad((string) $contract->id, 6, '0', STR_PAD_LEFT);
        $userName = trim((string) ($contract->user?->name ?? ''));
        $userName = $userName !== '' ? $userName : 'مستخدم';

        $isDraft = (bool) $contract->is_draft;
        $hasPaidAmount = $paidAmount !== null && $paidAmount > 0;

        if ($isDraft) {
            $title = 'مسودة عقد جديدة';
            $body = "عمل {$userName} عقد برقم {$contractNo} معاه مسودة";
            $type = 'new_contract_draft';
        } elseif ($hasPaidAmount || (bool) $contract->is_completed) {
            $amountText = $hasPaidAmount
                ? rtrim(rtrim(number_format($paidAmount, 2, '.', ''), '0'), '.')
                : null;
            $title = 'عقد جديد مدفوع';
            $body = $amountText !== null
                ? "عمل {$userName} عقد برقم {$contractNo} دفع قيمة {$amountText}"
                : "عمل {$userName} عقد برقم {$contractNo} ودفع قيمته";
            $type = 'new_contract_paid';
        } else {
            $title = 'عقد جديد';
            $body = "عمل {$userName} عقد برقم {$contractNo}";
            $type = 'new_contract';
        }

        $data = [
            'type' => $type,
            'contract_id' => (string) $contract->id,
            'contract_uuid' => (string) ($contract->uuid ?? ''),
            'contract_type' => (string) ($contract->contract_type ?? ''),
            'is_draft' => $isDraft ? '1' : '0',
            'is_paid' => ($hasPaidAmount || (bool) $contract->is_completed) ? '1' : '0',
            'paid_amount' => $hasPaidAmount ? (string) $paidAmount : '',
            'user_id' => (string) ($contract->user_id ?? ''),
            'user_name' => $userName,
        ];

        $topic = (string) config('services.firebase.employees_topic', 'employees');

        try {
            $this->sendToTopic($topic, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('Firebase employee topic notification failed', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }

        $tokens = Employee::query()
            ->where('is_active', true)
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->pluck('fcm_token')
            ->unique()
            ->filter()
            ->values();

        foreach ($tokens as $token) {
            try {
                $this->sendToToken((string) $token, $title, $body, $data);
            } catch (\Throwable $e) {
                Log::warning('Firebase employee token notification failed', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, topic_sent: bool, missing_token: bool}
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): array
    {
        $user = \App\Models\User::query()->find($userId);
        if (! $user) {
            throw new \InvalidArgumentException(trans('api.not_found'));
        }

        if (! filled($user->fcm_token)) {
            return ['sent' => 0, 'failed' => 0, 'topic_sent' => false, 'missing_token' => true];
        }

        try {
            $this->sendToToken((string) $user->fcm_token, $title, $body, array_merge([
                'type' => 'custom_user',
                'user_id' => (string) $user->id,
            ], $data));

            return ['sent' => 1, 'failed' => 0, 'topic_sent' => false, 'missing_token' => false];
        } catch (\Throwable $e) {
            Log::warning('Firebase send to user failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return ['sent' => 0, 'failed' => 1, 'topic_sent' => false, 'missing_token' => false];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, topic_sent: bool, missing_token: bool}
     */
    public function sendToEmployee(int $employeeId, string $title, string $body, array $data = []): array
    {
        $employee = Employee::query()->find($employeeId);
        if (! $employee) {
            throw new \InvalidArgumentException(trans('api.not_found'));
        }

        if (! filled($employee->fcm_token)) {
            return ['sent' => 0, 'failed' => 0, 'topic_sent' => false, 'missing_token' => true];
        }

        try {
            $this->sendToToken((string) $employee->fcm_token, $title, $body, array_merge([
                'type' => 'custom_employee',
                'employee_id' => (string) $employee->id,
            ], $data));

            return ['sent' => 1, 'failed' => 0, 'topic_sent' => false, 'missing_token' => false];
        } catch (\Throwable $e) {
            Log::warning('Firebase send to employee failed', ['employee_id' => $employeeId, 'error' => $e->getMessage()]);

            return ['sent' => 0, 'failed' => 1, 'topic_sent' => false, 'missing_token' => false];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, topic_sent: bool, missing_token: bool}
     */
    public function sendToAllUsers(string $title, string $body, array $data = []): array
    {
        $payload = array_merge(['type' => 'all_users'], $data);
        $topicSent = false;

        try {
            $this->sendToTopic((string) config('services.firebase.users_topic', 'users'), $title, $body, $payload);
            $topicSent = $this->isConfigured(); // متابعة (د): بلا إعدادات لا يُرسل فعلاً
        } catch (\Throwable $e) {
            Log::warning('Firebase users topic failed', ['error' => $e->getMessage()]);
        }

        $tokens = \App\Models\User::query()
            ->where('is_active', 1)
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->pluck('fcm_token')
            ->unique()
            ->filter()
            ->values();

        return array_merge($this->sendToTokens($tokens->all(), $title, $body, $payload), [
            'topic_sent' => $topicSent,
            'missing_token' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, topic_sent: bool, missing_token: bool}
     */
    public function sendToAllEmployees(string $title, string $body, array $data = []): array
    {
        $payload = array_merge(['type' => 'all_employees'], $data);
        $topicSent = false;

        try {
            $this->sendToTopic((string) config('services.firebase.employees_topic', 'employees'), $title, $body, $payload);
            $topicSent = $this->isConfigured(); // متابعة (د): بلا إعدادات لا يُرسل فعلاً
        } catch (\Throwable $e) {
            Log::warning('Firebase employees topic failed', ['error' => $e->getMessage()]);
        }

        $tokens = Employee::query()
            ->where('is_active', true)
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->pluck('fcm_token')
            ->unique()
            ->filter()
            ->values();

        return array_merge($this->sendToTokens($tokens->all(), $title, $body, $payload), [
            'topic_sent' => $topicSent,
            'missing_token' => false,
        ]);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int}
     */
    private function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            try {
                $this->sendToToken((string) $token, $title, $body, $data);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Firebase token send failed', ['error' => $e->getMessage()]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @param  array<string, string>  $data
     *
     * @throws Exception
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): void
    {
        $this->sendMessage([
            'topic' => $topic,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $this->stringifyData($data),
            'android' => [
                'priority' => 'high',
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'category' => 'new_contract',
                    ],
                ],
            ],
        ], $title, $body);
    }

    /**
     * @param  array<string, string>  $data
     *
     * @throws Exception
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        $this->sendMessage([
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $this->stringifyData($data),
            'android' => [
                'priority' => 'high',
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'category' => 'new_contract',
                    ],
                ],
            ],
        ], $title, $body);
    }

    /**
     * @param  array<string, mixed>  $message
     *
     * @throws Exception
     */
    /**
     * Firebase is active only when it has not been explicitly disabled and the
     * service-account credentials file actually exists.
     */
    public function isConfigured(): bool
    {
        if (filter_var(config('services.firebase.disabled'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return $this->credentialsPath !== '' && file_exists($this->credentialsPath);
    }

    private function sendMessage(array $message, string $title, string $body): void
    {
        // دفعة (هـ) — A-3: حارس أخير — كل قيم data نصوص مهما كان المصدر.
        if (isset($message['data']) && is_array($message['data'])) {
            $message['data'] = $this->stringifyData($message['data']);
        }

        // No keys / disabled → safe no-op so a fresh install never crashes.
        if (! $this->isConfigured()) {
            Log::info('Firebase notification skipped (not configured)', [
                'title' => $title,
                'target' => $message['topic'] ?? 'token',
            ]);

            return;
        }

        try {
            $client = new GoogleClient();
            $client->setAuthConfig($this->credentialsPath);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $client->refreshTokenWithAssertion();
            $accessToken = $client->getAccessToken();

            if (empty($accessToken['access_token'])) {
                throw new Exception(trans('dashboard.firebase_token_failed'));
            }

            $httpClient = new GuzzleClient();
            $response = $httpClient->post(
                "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$accessToken['access_token'],
                        'Content-Type' => 'application/json',
                    ],
                    'json' => ['message' => $message],
                ]
            );

            Log::info('Firebase Notification Sent Successfully', [
                'title' => $title,
                'project_id' => $this->projectId,
                'target' => $message['topic'] ?? 'token',
                'response' => json_decode($response->getBody()->getContents(), true),
            ]);
        } catch (Exception $e) {
            Log::error('Firebase Notification Error: '.$e->getMessage(), [
                'title' => $title,
                'body' => $body,
                'project_id' => $this->projectId,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    /**
     * FCM يقبل نصوصاً فقط في `data`: المصفوفات/الكائنات تُرمَّز JSON، والمنطقي 1/0، وnull نص فارغ.
     * (دفعة هـ — A-3: حارس موحّد لكل مرسلات الـ push.)
     */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $out[(string) $key] = $value ? '1' : '0';
            } elseif (is_scalar($value) || $value === null) {
                $out[(string) $key] = (string) $value;
            } elseif ($value instanceof \JsonSerializable || is_array($value) || is_object($value)) {
                $out[(string) $key] = (string) json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }
}
