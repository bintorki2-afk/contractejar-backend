<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Models\Concerns\HasCreatedAtLabel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractPeriod extends Model
{
    use \App\Models\Concerns\FlushesPublicCache;
    use HasCreatedAtLabel;
    use HasFactory;

    protected $fillable = [
        'period',
        'note_ar',
        'note_en',
        'contract_type',
        'price',
        'months',
        'is_active',
    ];

    protected $appends = ['created_at_label', 'note_trans'];

    public function getNoteTransAttribute()
    {
        return getTransAttribute($this, 'note');
    }
}
