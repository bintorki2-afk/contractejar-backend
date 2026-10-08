<?php

namespace App\Modules\Users\Models;

use App\Models\AuthHistory;
use App\Models\Contract;
use App\Models\Device_token;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\RealEstate;
use App\Models\UnitsReal;
use App\Models\UserCoupon;
use App\Services\Marketing\AttributionService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const PLATFORM_WEBSITE = 'website';

    public const PLATFORM_GOOGLE_PLAY = 'google_play';

    public const PLATFORM_APPLE_STORE = 'apple_store';

    protected $table = 'users';

    protected $fillable = [
        'fname',
        'lname',
        'email',
        'mobile',
        'password',
        'photo',
        'fcm_token',
        'platform',
        'is_active',
        'is_guest',
        'contact_mobile',
        'merged_into_user_id',
    ];

    protected $appends = ['name', 'photo_path', 'status', 'fcm_token', 'created_at_label', 'mobile', 'email'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'is_guest' => 'boolean',
        'attributed_at' => 'datetime',
        'verification_code_expires_at' => 'datetime',
        'verification_locked_until' => 'datetime',
        'reset_password_code_expires_at' => 'datetime',
        'reset_password_locked_until' => 'datetime',
        'verification_attempts' => 'integer',
        'reset_password_attempts' => 'integer',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
        'reset_password_code',
        'verification_code_expires_at',
        'verification_attempts',
        'verification_locked_until',
        'reset_password_code_expires_at',
        'reset_password_attempts',
        'reset_password_locked_until',
    ];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            app(AttributionService::class)->stampOnCreating($model);
        });
    }

    public function getCreatedAtLabelAttribute()
    {
        return date('Y-m-d H:i A', strtotime($this->created_at));
    }

    public function getMobileAttribute($value)
    {
        return $this->attributes['mobile'] ?? null;
    }

    public function getEmailAttribute($value)
    {
        return $this->attributes['email'] ?? null;
    }

    public function isVerified()
    {
        return $this->email_verified_at != null;
    }

    public function isActive()
    {
        return $this->is_active == 1;
    }

    /** جلسة زائر من الموقع (بدون رقم دخول) — تُدمج لاحقاً في حساب موثّق. */
    public function isGuest(): bool
    {
        return (bool) $this->is_guest;
    }

    public static function generateVerificationCode(): string
    {
        return app(\App\Modules\Auth\Services\UserOtpService::class)->generatePlain();
    }

    public static function generateResetPasswordCode(): string
    {
        return app(\App\Modules\Auth\Services\UserOtpService::class)->generatePlain();
    }

    public function getSingle($id)
    {
        return self::find($id);
    }

    public function realEstate()
    {
        return $this->hasMany(RealEstate::class);
    }

    public function unitReal()
    {
        return $this->hasMany(UnitsReal::class);
    }

    public function devicesToken()
    {
        return $this->hasMany(Device_token::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function notifications()
    {
        return $this->hasMany(Offer::class, 'user_id', 'id');
    }

    public function userCoupons()
    {
        return $this->hasMany(UserCoupon::class);
    }

    public function getNameAttribute()
    {
        return $this->fname.' '.$this->lname;
    }

    public function getPhotoPathAttribute()
    {
        return isset($this->photo) ? getFilePath($this->photo) : '';
    }

    public function getStatusAttribute()
    {
        return $this->is_active == 1 ? 'active' : 'inactive';
    }

    public function customerNumber(): string
    {
        return 'C-'.$this->id;
    }

    public function resolvedPlatform(): string
    {
        $platform = (string) ($this->platform ?? '');

        return match ($platform) {
            self::PLATFORM_APPLE_STORE, 'ios', 'apple', 'appstore' => self::PLATFORM_APPLE_STORE,
            self::PLATFORM_GOOGLE_PLAY, 'android', 'google' => self::PLATFORM_GOOGLE_PLAY,
            default => self::PLATFORM_WEBSITE,
        };
    }

    public function platformLabelAr(): string
    {
        return match ($this->resolvedPlatform()) {
            self::PLATFORM_APPLE_STORE => 'عملاء أبل ستور',
            self::PLATFORM_GOOGLE_PLAY => 'عملاء قوقل بلاي',
            default => 'عملاء الموقع',
        };
    }

    public static function normalizePlatform(?string $platform): ?string
    {
        if ($platform === null || trim($platform) === '') {
            return null;
        }

        return match (strtolower(trim($platform))) {
            'apple_store', 'ios', 'apple', 'appstore', 'app_store' => self::PLATFORM_APPLE_STORE,
            'google_play', 'android', 'google', 'googleplay' => self::PLATFORM_GOOGLE_PLAY,
            'website', 'web' => self::PLATFORM_WEBSITE,
            default => self::PLATFORM_WEBSITE,
        };
    }

    /**
     * «عميل» (دفعة د — ب6) — نطاق واحد لصفحة العملاء والتقارير والرئيسية:
     * حساب غير مدموج في حساب آخر، وإما مسجّل (ليس زائراً) أو زائر لديه طلب (الخطوة ≥ 4).
     * جلسات الزوار الفارغة ليست عملاء.
     */
    public function scopeCustomers($query)
    {
        if (\App\Support\SchemaCache::hasColumn('users', 'merged_into_user_id')) {
            $query->whereNull('users.merged_into_user_id');
        }
        if (! \App\Support\SchemaCache::hasColumn('users', 'is_guest')) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->where('users.is_guest', false)
                ->orWhereNull('users.is_guest')
                ->orWhereHas('contracts', fn ($c) => $c->where('is_delete', 0)->where('step', '>=', \App\Models\Contract::CUSTOMER_VISIBLE_MIN_STEP));
        });
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function getFcmTokenAttribute()
    {
        return $this->attributes['fcm_token'] ?? null;
    }

    public function authHistory()
    {
        return $this->hasMany(AuthHistory::class);
    }

    public function fullname()
    {
        return $this->fname.' '.$this->lname;
    }
}
