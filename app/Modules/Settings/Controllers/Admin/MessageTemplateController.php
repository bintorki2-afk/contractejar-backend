<?php

namespace App\Modules\Settings\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\Responser;
use App\Models\MessageTemplate;
use App\Services\MessageTemplateService;
use App\Support\MessageTemplateDefaults;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * قوالب الرسائل (دفعة د — ب16) — صفحة الإعدادات في اللوحة.
 */
class MessageTemplateController extends Controller
{
    use Responser;

    public function index(Request $request)
    {
        $items = MessageTemplate::query()
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->input('channel')))
            ->orderBy('channel')->orderBy('key')
            ->get()
            ->map(fn (MessageTemplate $t) => $this->row($t))
            ->values();

        return $this->apiResponse([
            'items' => $items,
            'placeholders' => collect(MessageTemplateDefaults::PLACEHOLDERS)->map(fn ($label, $token) => ['token' => $token, 'label' => $label])->values(),
            'channels' => [
                ['value' => 'whatsapp', 'label' => 'واتساب'],
                ['value' => 'sms', 'label' => 'رسالة نصية'],
                ['value' => 'push', 'label' => 'إشعار التطبيق/الموقع'],
            ],
        ], trans('api.success'));
    }

    public function show(int $id)
    {
        return $this->apiResponse($this->row(MessageTemplate::query()->findOrFail($id)), trans('api.success'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'channel' => ['required', Rule::in(MessageTemplateDefaults::CHANNELS)],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (MessageTemplate::query()->where('key', $data['key'])->where('channel', $data['channel'])->exists()) {
            return $this->errorResponse(['key' => ['يوجد قالب بنفس المفتاح والقناة.']], 422);
        }
        $data['updated_by'] = $request->user()?->id;
        $template = MessageTemplate::query()->create($data);

        return $this->apiResponse($this->row($template), trans('api.created_successfully'), true, 201);
    }

    public function update(Request $request, int $id)
    {
        $template = MessageTemplate::query()->findOrFail($id);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['updated_by'] = $request->user()?->id;
        $template->update($data);

        return $this->apiResponse($this->row($template->fresh()), trans('api.success'));
    }

    public function destroy(int $id)
    {
        MessageTemplate::query()->findOrFail($id)->delete();

        return $this->apiResponse(null, trans('api.success'));
    }

    /** POST /message-templates/preview { body, title?, vars? } — معاينة بالقيم التجريبية. */
    public function preview(Request $request, MessageTemplateService $templates)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
            'vars' => ['nullable', 'array'],
        ]);
        $vars = array_merge([
            'order' => '123456', 'name' => 'محمد', 'link' => 'https://contractejar.com/r/123456',
            'amount' => '249', 'support' => '0597500014',
            // دفعة (هـ)
            'items' => "• صورة الصك غير واضحة\n• رقم الصك",
            'reason' => 'تغيير نوع المستند: إلكتروني → ورقي',
            'payment_url' => 'https://contractejar.com/r/123456?charge=1',
            'bank' => 'مصرف الراجحي', 'iban' => 'SA00 0000 0000 0000 0000 0000', 'account_name' => 'مؤسسة عقدي العقارية',
        ], $data['vars'] ?? []);

        return $this->apiResponse([
            'title' => isset($data['title']) ? $templates->fill($data['title'], $vars) : null,
            'body' => $templates->fill($data['body'], $vars),
            'vars' => $vars,
        ], trans('api.success'));
    }

    /** @return array<string, mixed> */
    private function row(MessageTemplate $t): array
    {
        return [
            'id' => $t->id,
            'key' => $t->key,
            'channel' => $t->channel,
            'title' => $t->title,
            'body' => $t->body,
            'description' => $t->description,
            'is_active' => (bool) $t->is_active,
            'placeholders_used' => array_values(array_filter(array_keys(MessageTemplateDefaults::PLACEHOLDERS), fn ($p) => str_contains((string) $t->body.(string) $t->title, $p))),
            'updated_by' => $t->updated_by,
            'updated_at' => optional($t->updated_at)?->toIso8601String(),
        ];
    }
}
