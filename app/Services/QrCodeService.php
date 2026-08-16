<?php

namespace App\Services;

use BaconQrCode\Renderer\GDLibRenderer;
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

    /**
     * Raster PNG, for contexts SVG doesn't work in — namely email attachments,
     * which most mail clients strip or refuse to render inline. Uses
     * bacon-qr-code's bundled GD renderer rather than its Imagick back end: GD
     * is this app's one guaranteed image extension (see CLAUDE.md "PHP
     * Extensions Required"), Imagick is only optional.
     */
    public function png(string $content, int $size = 320): string
    {
        return (new Writer(new GDLibRenderer($size)))->writeString($content);
    }
}
