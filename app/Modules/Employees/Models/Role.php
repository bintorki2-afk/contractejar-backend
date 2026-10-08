<?php

namespace App\Modules\Employees\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'title_ar',
        'title_en',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['title_trans', 'created_at_label', 'permissions_count'];

    /**
     * Get translated title based on current locale
     */
    public function getTitleTransAttribute()
    {
        return \getTransAttribute($this, 'title');
    }

    /**
     * Get formatted created at label
     */
    public function getCreatedAtLabelAttribute()
    {
        return date('Y-m-d H:i A', strtotime($this->created_at));
    }

    /**
     * Get permissions count
     */
    public function getPermissionsCountAttribute()
    {
        if (array_key_exists('permissions_count', $this->attributes)) {
            return (int) $this->attributes['permissions_count'];
        }

        if ($this->relationLoaded('permissions')) {
            return $this->permissions->count();
        }

        return $this->permissions()->count();
    }

    /**
     * Relationship: Role has many permissions
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }

    /**
     * Relationship: Role has many employees
     */
    public function employees()
    {
        return $this->hasMany(Employee::class, 'role_id');
    }

    /**
     * Whether this role bypasses individual permission grants.
     */
    public function isFullAccess(): bool
    {
        return self::grantsFullAccess($this->name, $this->title_en, $this->title_ar);
    }

    /**
     * True when a role slug or display title is a system admin (full dashboard access).
     */
    public static function grantsFullAccess(?string $name, ?string $titleEn = null, ?string $titleAr = null): bool
    {
        $normalizedName = self::normalizeAccessKey($name);
        $configuredNames = array_map(
            [self::class, 'normalizeAccessKey'],
            (array) config('permissions.full_access_roles', ['admin'])
        );

        if ($normalizedName !== '' && in_array($normalizedName, $configuredNames, true)) {
            return true;
        }

        $titles = array_map(
            [self::class, 'normalizeAccessTitle'],
            (array) config('permissions.full_access_titles', ['admin', 'مدير النظام'])
        );
        $candidates = [
            self::normalizeAccessTitle($titleEn),
            self::normalizeAccessTitle($titleAr),
            self::normalizeAccessTitle($name),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && in_array($candidate, $titles, true)) {
                return true;
            }
        }

        // مطابقة تامة فقط للأسماء/العناوين المعتمدة في config/permissions.php — لا مطابقة جزئية
        // (كان «content_admin» أو «مساعد الأدمن» يحصل على صلاحيات مدير النظام). (CROSS-6)
        return false;
    }

    public static function normalizeAccessKey(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }

        $value = str_replace(['-', ' '], '_', $value);

        return preg_replace('/_+/', '_', $value) ?? $value;
    }

    public static function normalizeAccessTitle(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/[A-Za-z]/', $value) === 1) {
            return strtolower($value);
        }

        return $value;
    }

    public static function looksLikeAdminTitle(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $latin = strtolower(str_replace(['-', ' '], '_', $value));
        if (str_contains($latin, 'super_admin')
            || str_contains($latin, 'superadmin')
            || preg_match('/(^|_)admin(istrator)?($|_)/', $latin) === 1
            || $latin === 'admin'
            || str_ends_with($latin, '_admin')
            || str_starts_with($latin, 'admin_')
        ) {
            return true;
        }

        return str_contains($value, 'أدمن')
            || str_contains($value, 'ادمن')
            || str_contains($value, 'الأدمن')
            || str_contains($value, 'الادمن')
            || str_contains($value, 'مدير النظام');
    }
}
