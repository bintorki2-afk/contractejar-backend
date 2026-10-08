<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * صندوق إشعارات العميل (الاسم التاريخي للجدول: offers).
 */
class Offer extends Model
{
    protected $table='offers';
    use HasFactory;

    protected $fillable = [
        'title', 'body', 'kind', 'url', 'data', 'is_active', 'start_date', 'end_date',
        'is_read', 'read_at', 'user_id', 'contract_id', 'lessor_change_request_id',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'is_active' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(\App\Models\Contract::class, 'contract_id', 'id');
    }

    public function lessorChangeRequest()
    {
        return $this->belongsTo(LessorChangeRequest::class, 'lessor_change_request_id', 'id');
    }

    public function User()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function markRead(): void
    {
        if ((bool) $this->is_read && $this->read_at !== null) {
            return;
        }

        $this->forceFill(['is_read' => true, 'read_at' => $this->read_at ?? now()])->save();
    }
}
