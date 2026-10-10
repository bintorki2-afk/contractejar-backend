<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractUnit extends Model
{
    use HasFactory;

    protected $table = 'contract_units';

    protected $fillable = [
        'contract_id',
        'real_unit_id',
        'real_estate_id',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitsReal::class, 'real_unit_id')->withTrashed();
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class, 'real_estate_id')->withTrashed();
    }
}
