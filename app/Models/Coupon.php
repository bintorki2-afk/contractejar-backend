<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Coupon extends Model
{
    use \App\Models\Concerns\FlushesPublicCache;
    use HasFactory;

    protected $fillable = [
        'name',
        'code_coupon',
        'type_coupon',
        'is_delete',
        'value_coupon',
        'date_start',
        'date_end',
        'usage',
        'usage_of_user',
        'is_review',
    ];

    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'is_review' => 'boolean',
        'is_delete' => 'boolean',
        'usage' => 'integer',
        'usage_of_user' => 'integer',
        'value_coupon' => 'decimal:2',
    ];

    public function usages()
    {
        return $this->hasMany(CouponUsage::class, 'coupon_id');
    }

    public function userCoupon(): HasOne
    {
        return $this->hasOne(UserCoupon::class);
    }

    public function isValidNow(): bool
    {
        if ($this->is_delete || ! $this->is_review) {
            return false;
        }

        $today = now()->startOfDay();

        return $this->date_start <= $today && $this->date_end >= $today;
    }



    public static function createUsage($userId, $couponId, $contractUuid)
    {
        return self::create([
            'user_id' => $userId,
            'coupon_id' => $couponId,
            'contract_uuid' => $contractUuid,
            'used_at' => now(),
        ]);
    }
   /*
    |--------------------------------------------------------------------------
    | Scope Contract Review
    |--------------------------------------------------------------------------
    */


    public function scopeValid($query)
    {
        return $query->where('date_start', '<=', now())
                     ->where('date_end', '>=', now());
    }

    public function scopeUserUsage($query, $userId, $couponId)
    {
        return $query->where('user_id', $userId)
                     ->where('coupon_id', $couponId);
    }
    
    public function scopeUserContractUsage($query, $userId, $couponId, $contractUuid)
    {
        return $query->where('user_id', $userId)
                     ->where('coupon_id', $couponId)
                     ->where('contract_uuid', $contractUuid);
    }
    
    public function scopeHasRemainingUsage($query)
    {
        return $query->where('usage', '>', 0);
    }


    public function scopdecrementUsage()
    {
        $this->decrement('usage');
    }

 


}
