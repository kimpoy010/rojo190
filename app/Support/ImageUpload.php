<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared upload/replace logic for the event thumbnail and game banner image
 * fields — both just need "store this file publicly, hand back a URL" and
 * "clean up the old file when it's replaced".
 */
class ImageUpload
{
    public static function store(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, 'public');

        return Storage::disk('public')->url($path);
    }

    /**
     * No-ops on an empty value or on a URL that isn't one of our own local
     * uploads (e.g. a link pasted before this feature switched to uploads).
     */
    public static function deleteIfLocal(?string $url): void
    {
        if (! $url) {
            return;
        }

        $publicBase = rtrim(Storage::disk('public')->url(''), '/');

        if (! str_starts_with($url, $publicBase)) {
            return;
        }

        $relativePath = ltrim(Str::after($url, $publicBase), '/');

        Storage::disk('public')->delete($relativePath);
    }
}
