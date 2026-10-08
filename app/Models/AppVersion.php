<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppVersion extends Model
{
    use \App\Models\Concerns\FlushesPublicCache;

    public const PLATFORM_IOS = 'ios';

    public const PLATFORM_ANDROID = 'android';

    public const PLATFORMS = [
        self::PLATFORM_IOS,
        self::PLATFORM_ANDROID,
    ];

    protected $fillable = [
        'platform',
        'latest_version',
        'min_version',
        'force_update',
        'store_url',
        'message_ar',
        'message_en',
    ];

    protected $casts = [
        'force_update' => 'boolean',
    ];
}
