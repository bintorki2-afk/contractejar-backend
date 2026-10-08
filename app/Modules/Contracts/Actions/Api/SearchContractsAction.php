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
        $orderNumber = ltrim(trim($searchTerm), '#');

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
