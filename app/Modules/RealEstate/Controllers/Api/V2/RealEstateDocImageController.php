<?php

namespace App\Modules\RealEstate\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\RealEstate;
use App\Support\RealEstateImage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves sensitive real-estate documents behind a temporary signed URL
 * (see {@see RealEstateImage}). The `signed` middleware on the route guarantees
 * the URL was minted by the backend and has not expired, so the file is never
 * exposed from a public storage path.
 */
class RealEstateDocImageController extends Controller
{
    public function show(RealEstate $realEstate, string $field): StreamedResponse
    {
        abort_unless(RealEstateImage::isField($field), 404);

        $raw = $realEstate->getAttributes()[$field] ?? null;
        $resolved = RealEstateImage::resolveStored(is_string($raw) ? $raw : null);

        abort_if($resolved === null, 404);

        [$disk, $path] = $resolved;

        return Storage::disk($disk)->response($path);
    }
}
