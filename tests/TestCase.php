<?php

namespace Tests;

use App\Support\Marketing\AttributionSchema;
use App\Support\SchemaCache;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        SchemaCache::flush();
        AttributionSchema::flush();

        // ترحيل Telescope يثبّت اتصاله على DB_CONNECTION (mysql في CI) بينما اختبارات كثيرة
        // تبدّل الاتصال الافتراضي إلى sqlite في الذاكرة ثم تشغّل migrate — فيحاول إنشاء
        // telescope_entries على mysql (موجود مسبقاً) ويفشل. في الاختبارات: يتبع الاتصال الافتراضي.
        config(['telescope.storage.database.connection' => null]);
    }
}
