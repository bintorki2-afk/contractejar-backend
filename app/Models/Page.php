<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use \App\Models\Concerns\FlushesPublicCache;
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | GLOBAL VARIABLES
    |--------------------------------------------------------------------------
    */
    protected $table = 'pages';

    protected $fillable = [
        'description_ar',
        'description_en',
        'page',
    ];

    protected $appends = ['description_trans', 'page'];

    /*
    |--------------------------------------------------------------------------
    | ACCESORS
    |--------------------------------------------------------------------------
    */

    public function getDescriptionTransAttribute()
    {
        $columnName = 'description_' . app()->getLocale();

        return $this->{$columnName};
    
    }

    private function translateAttribute($attribute)
    {

        return $this->$attribute;
    }
}