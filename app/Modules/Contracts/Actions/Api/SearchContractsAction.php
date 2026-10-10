<?php

namespace App\Modules\Contracts\Actions\Api;

use App\Models\Contract;
use Illuminate\Support\Collection;

class SearchContractsAction
{
    public function execute(string $searchTerm, ?int $userId = null): Collection
    {
        // رقم الطلب (uuid) قد يصل مسبوقاً بـ #. مطابقة تامة له حتى يفتح الرابط الذكي
        // /r/{رقم الطلب} الطلبَ الصحيح في التطبيق. (APP-3)
        // QA-F W-27: الأرقام العربية-الهندية/الفارسية ⇒ لاتينية (العميل السعودي قد يكتب ١٢٣).
        $searchTerm = strtr(trim($searchTerm), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $orderNumber = ltrim($searchTerm, '#');

        return Contract::query()
            ->ownedBy($userId ?? Contract::requireApiUserId())
            ->where(function ($query) use ($searchTerm, $orderNumber) {
                $query->where('tenant_id_num', 'like', '%'.$searchTerm.'%')
                    ->orWhere('property_owner_id_num', 'like', '%'.$searchTerm.'%')
                    ->orWhere('uuid', $orderNumber);
            })
            ->get();
    }
}
