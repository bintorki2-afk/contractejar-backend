<?php

namespace App\Support;

use App\Models\RealEstate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Sensitive real-estate documents (deed, endowment / trusteeship certificates,
 * powers of attorney, address proof) must NOT be world-readable from the public disk.
 *
 * This mirrors {@see DeedImage} but is keyed to a {@see RealEstate} row. New uploads
 * go to the private disk ({@see self::DISK}); reads happen only through a temporary
 * signed route ({@see routes} `real-estates.doc-image`). Legacy files that still live
 * on the public disk are streamed transparently for backward compatibility.
 */
final class RealEstateImage
{
    /** Private disk for new real-estate document uploads. */
    public const DISK = 'local';

    /** Signed-URL lifetime. */
    public const TTL_MINUTES = 30;

    /**
     * RealEstate columns that hold sensitive legal / identity documents.
     * All are stored on the private disk and served only via signed URLs.
     */
    public const FIELDS = [
        'image_instrument',
        'image_address',
        'copy_of_the_endowment_registration_certificate',
        'copy_of_the_trusteeship_deed',
        'copy_of_guardians_power_of_attorney_for_agent',
        'copy_of_the_authorization_or_agency',
    ];

    public static function isField(string $field): bool
    {
        return in_array($field, self::FIELDS, true);
    }

    /**
     * Temporary signed URL to fetch a real-estate document field, or null.
     */
    public static function signedUrl(RealEstate $realEstate, string $field): ?string
    {
        if (! self::isField($field)) {
            return null;
        }

        $raw = $realEstate->getAttributes()[$field] ?? $realEstate->{$field} ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return URL::temporarySignedRoute(
            'real-estates.doc-image',
            now()->addMinutes(self::TTL_MINUTES),
            ['realEstate' => $realEstate->getKey(), 'field' => $field]
        );
    }

    /**
     * Resolve [disk, path] for a stored value, trying the private disk first and
     * falling back to the legacy public disk. Returns null when nothing is found.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function resolveStored(?string $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $path = ltrim(trim($raw), '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (Storage::disk(self::DISK)->exists($path)) {
            return [self::DISK, $path];
        }

        if (Storage::disk('public')->exists($path)) {
            return ['public', $path];
        }

        return null;
    }
}
