<?php

namespace App\Services\Blog;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * قراءة جدول blogs القديم من: تصدير phpMyAdmin بصيغة JSON أو CSV، أو من اتصال قاعدة بيانات
 * (مثلاً بعد تحميل ملف SQL في قاعدة مؤقتة). لا يعدّل أي نص.
 */
class LegacyBlogSource
{
    /** @return list<array<string, mixed>> */
    public function fromFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read file: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'json' => $this->fromJson((string) file_get_contents($path)),
            'csv' => $this->fromCsv($path),
            'sql' => throw new RuntimeException('SQL dumps are not parsed directly. Load the dump into a temporary database and use --connection (see docs/blog-migration/README.md).'),
            default => throw new RuntimeException("Unsupported file type [.{$ext}] — use .json or .csv"),
        };
    }

    /** @return list<array<string, mixed>> */
    public function fromConnection(string $connection, string $table = 'blogs'): array
    {
        return DB::connection($connection)->table($table)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function fromJson(string $json): array
    {
        $data = json_decode($this->stripBom($json), true, 512, JSON_THROW_ON_ERROR);

        // phpMyAdmin: [{"type":"header"},{"type":"database"},{"type":"table","name":"blogs","data":[...]}]
        if (is_array($data) && array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item) && ($item['type'] ?? null) === 'table' && ($item['name'] ?? null) === 'blogs') {
                    return array_values($item['data'] ?? []);
                }
            }
            if ($data === [] || isset($data[0]['slug']) || isset($data[0]['title'])) {
                return $data;
            }
        }

        if (is_array($data) && isset($data['blogs']) && is_array($data['blogs'])) {
            return array_values($data['blogs']);
        }

        throw new RuntimeException('JSON does not contain a blogs table');
    }

    /** @return list<array<string, mixed>> */
    public function fromCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        $first = fgetcsv($handle, 0, $this->detectDelimiter($path), '"', '');
        if ($first === false) {
            fclose($handle);

            return [];
        }
        $first[0] = $this->stripBom((string) $first[0]);
        $delimiter = $this->detectDelimiter($path);

        $hasHeader = in_array('slug', array_map(fn ($v) => strtolower(trim((string) $v)), $first), true);
        $columns = $hasHeader ? array_map(fn ($v) => strtolower(trim((string) $v)), $first) : LegacyBlogImporter::LEGACY_COLUMNS;

        $rows = [];
        if (! $hasHeader) {
            $rows[] = $this->combine($columns, $first);
        }
        while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($line === [null]) {
                continue; // سطر فاضي
            }
            $rows[] = $this->combine($columns, $line);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string|null>  $values
     * @return array<string, mixed>
     */
    private function combine(array $columns, array $values): array
    {
        if (count($values) !== count($columns)) {
            throw new RuntimeException('CSV row has '.count($values).' columns, expected '.count($columns).' — re-export with "Put columns names in the first row".');
        }

        return array_combine($columns, $values);
    }

    private function detectDelimiter(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        $line = strtok($head, "\n") ?: '';

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
