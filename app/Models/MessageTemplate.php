<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * قالب رسالة (دفعة د — ب16).
 */
class MessageTemplate extends Model
{
    protected $fillable = ['key', 'channel', 'title', 'body', 'description', 'is_active', 'updated_by'];

    protected $casts = ['is_active' => 'boolean'];
}
