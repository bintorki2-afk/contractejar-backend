<?php

namespace App\Console\Commands;

use App\Support\FieldMapping;
use Illuminate\Console\Command;

/** دفعة (د) — ب24: يولّد docs/field-mapping.md من FieldMapping. */
class FieldMappingDocCommand extends Command
{
    protected $signature = 'docs:field-mapping';

    protected $description = 'Regenerate docs/field-mapping.md from App\\Support\\FieldMapping';

    public function handle(): int
    {
        @mkdir(base_path('docs'), 0775, true);
        file_put_contents(base_path('docs/field-mapping.md'), FieldMapping::markdown());
        $this->info('docs/field-mapping.md written');

        return self::SUCCESS;
    }
}
