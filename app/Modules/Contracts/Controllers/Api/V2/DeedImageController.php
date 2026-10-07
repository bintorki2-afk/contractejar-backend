<?php

namespace App\Modules\Contracts\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Support\DeedImage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves deed/instrument images behind a temporary signed URL (see {@see DeedImage}).
 * The `signed` middleware on the route guarantees the URL was minted by the backend
 * and has not expired, so the file is never exposed from a public storage path.
 */
class DeedImageController extends Controller
{
    public function show(Contract $contract, string $field): StreamedResponse
    {
        abort_unless(DeedImage::isField($field), 404);

        $raw = $contract->getAttributes()[$field] ?? null;
        $resolved = DeedImage::resolveStored(is_string($raw) ? $raw : null);

        abort_if($resolved === null, 404);

        [$disk, $path] = $resolved;

        return Storage::disk($disk)->response($path);
    }

    /** صفحة إضافية من صفحات الصك (بالترتيب). */
    public function page(Contract $contract, int $index): StreamedResponse
    {
        $pages = $contract->image_instrument_pages;
        abort_unless(is_array($pages) && array_key_exists($index, array_values($pages)), 404);

        $resolved = DeedImage::resolveStored(array_values($pages)[$index]);
        abort_if($resolved === null, 404);

        [$disk, $path] = $resolved;

        return Storage::disk($disk)->response($path);
    }
}
