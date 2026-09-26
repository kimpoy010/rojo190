<?php

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

class QrCodeGenerator
{
    /**
     * Render $data as an inline SVG string. SVG needs no GD/Imagick
     * extension, so it works in any PHP environment.
     */
    public static function svg(string $data, int $size = 280): string
    {
        $result = (new Builder(
            writer: new SvgWriter(),
            data: $data,
            size: $size,
            margin: 10,
        ))->build();

        return $result->getString();
    }
}
