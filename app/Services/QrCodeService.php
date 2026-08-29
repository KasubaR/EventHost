<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
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
    /** bacon-qr-code's own default — ~7% of the code can be lost and still decode. */
    public const ECC_STANDARD = 'L';

    /**
     * ~30% recovery. Costs a denser code for the same payload, and buys the
     * room to lay something over the modules without breaking the decode —
     * which is why ticket QRs use it (see tickets/show.blade.php's "Used"
     * badge). Don't lower it for tickets without removing that badge first.
     */
    public const ECC_HIGH = 'H';

    public function svg(string $content, int $size = 320, string $ecLevel = self::ECC_STANDARD): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($content, ecLevel: $this->level($ecLevel));
    }

    /**
     * Raster PNG, for contexts SVG doesn't work in — namely email attachments,
     * which most mail clients strip or refuse to render inline. Uses
     * bacon-qr-code's bundled GD renderer rather than its Imagick back end: GD
     * is this app's one guaranteed image extension (see CLAUDE.md "PHP
     * Extensions Required"), Imagick is only optional.
     */
    public function png(string $content, int $size = 320, string $ecLevel = self::ECC_STANDARD): string
    {
        return (new Writer(new GDLibRenderer($size)))->writeString($content, ecLevel: $this->level($ecLevel));
    }

    private function level(string $ecLevel): ErrorCorrectionLevel
    {
        return $ecLevel === self::ECC_HIGH
            ? ErrorCorrectionLevel::H()
            : ErrorCorrectionLevel::L();
    }
}
