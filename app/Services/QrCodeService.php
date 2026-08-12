<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Thin wrapper around bacon/bacon-qr-code. SVG output prints crisp at any size
 * (table tents, badge sheets) without needing a GD/Imagick round trip.
 */
class QrCodeService
{
    public function svg(string $content, int $size = 320): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($content);
    }
}
