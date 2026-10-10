<?php

namespace App\Models\Concerns;

use App\Support\SchemaCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * دفعة (و) — D6: SoftDeletes على عمود `trashed_at` (سلة 30 يوماً) — آمن مع قواعد بيانات لم يُرحَّل
 * فيها العمود بعد (الاستعلامات تعمل كالسابق والحذف يبقى نهائياً حتى يُضاف العمود).
 */
trait SoftTrashes
{
    use SoftDeletes {
        runSoftDelete as protected baseRunSoftDelete;
    }

    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new class extends SoftDeletingScope
        {
            public function apply(Builder $builder, Model $model): void
            {
                if (SchemaCache::hasColumn($model->getTable(), $model->getDeletedAtColumn())) {
                    parent::apply($builder, $model);
                }
            }
        });
    }

    public function getDeletedAtColumn(): string
    {
        return 'trashed_at';
    }

    public static function trashColumnExists(): bool
    {
        $model = new static;

        return SchemaCache::hasColumn($model->getTable(), $model->getDeletedAtColumn());
    }

    protected function runSoftDelete()
    {
        if (! static::trashColumnExists()) {
            $this->setKeysForSaveQuery($this->newModelQuery())->delete();

            return;
        }

        $this->baseRunSoftDelete();
    }
}
