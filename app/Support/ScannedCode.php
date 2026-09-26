<?php

namespace App\Support;

use Illuminate\Support\Str;

class ScannedCode
{
    /**
     * A QR code in this app usually encodes a full page URL (so a phone
     * camera scan opens it directly), but a handheld/HID barcode scanner
     * just types out whatever it decodes into whichever field currently
     * has focus — including a manual "type/paste the code" box that
     * expects a bare code. Pull the trailing code segment back out of a
     * scanned URL; anything that isn't URL-shaped is returned unchanged.
     */
    public static function extract(string $raw): string
    {
        $raw = trim($raw);

        if (! str_contains($raw, '/')) {
            return $raw;
        }

        $path = trim(parse_url($raw, PHP_URL_PATH) ?? '', '/');

        return Str::afterLast($path, '/');
    }
}
