<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    protected $table='offers';
    use HasFactory;

    protected $fillable = ['title', 'body', 'is_active', 'start_date', 'end_date', 'is_read', 'user_id', 'contract_id'];

    public function contract()
    {
        return $this->belongsTo(\App\Models\Contract::class, 'contract_id', 'id');
    }

    public function User()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
