<?php

namespace App\Services;

use App\Models\Guest;
use App\Support\GuestPassCard;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Draws the guest's invitation pass as a PNG — the phone-gallery / WhatsApp
 * counterpart of the PDF. Pure GD (already a hard requirement, see CLAUDE.md
 * "PHP Extensions Required"): no headless browser, nothing extra to install on
 * cPanel. See plans/invitation-pass-card.md §4.
 *
 * Everything is laid out in logical units (a 1080-wide canvas), drawn at 2x and
 * downsampled so shape edges are antialiased — GD antialiases text but not
 * filled shapes. The QR is pasted after the downsample, at its exact final
 * pixel size, so its modules stay razor sharp and scannable.
 *
 * Throws RuntimeException when it cannot draw (no FreeType, font missing);
 * callers fall back to the plain QR PNG rather than failing a delivery.
 */
class GuestPassImageService
{
    private const WIDTH = 1080;

    /** Supersampling factor. */
    private const S = 2;

    private const CARD_X = 40;

    private const CARD_W = 1000;

    private const PAD = 64;

    private const RADIUS = 48;

    /** GD's TTF size is in points at 96 dpi; the layout is in pixels. */
    private const PT = 0.75;

    private const QR_SIZE = 440;

    private const QR_FRAME = 476;

    private const OUTER_BG = '#f4f5f9';

    private const BORDER = '#dcdce4';

    private const INK = '#1a1a2e';

    private const MUTED = '#8e8ea8';

    public function __construct(
        private readonly QrCodeService $qrCodeService,
        private readonly GuestPassFileCache $cache,
    ) {}

    public function render(Guest $guest, GuestPassCard $card): string
    {
        return $this->cache->remember(
            GuestPassFileCache::IMAGE,
            $guest,
            $card,
            'png',
            fn (): string => $this->generate($guest, $card),
        );
    }

    private function generate(Guest $guest, GuestPassCard $card): string
    {
        if (! function_exists('imagettftext')) {
            throw new RuntimeException('GD was built without FreeType; cannot draw the pass image.');
        }

        $regular = resource_path('fonts/DejaVuSans.ttf');
        $bold = resource_path('fonts/DejaVuSans-Bold.ttf');
        foreach ([$regular, $bold] as $font) {
            if (! is_readable($font)) {
                throw new RuntimeException("Pass image font missing: {$font}");
            }
        }

        $qrUrl = $guest->checkInQrUrl();
        abort_if($qrUrl === null, 404);

        $textX = self::CARD_X + self::PAD;
        $textW = self::CARD_W - 2 * self::PAD;

        // ── Measure first: the canvas height depends on how much text wraps. ──
        $titleLines = $this->wrap($card->eventName, $bold, 68, $textW, 3);
        $venueLines = $card->venue !== null ? $this->wrap($card->venue, $regular, 36, $textW - 40, 2) : [];
        $guestLines = $this->wrap($card->guestName, $bold, 44, $textW, 2);

        $brandTop = self::CARD_X + 52;
        $kickerBase = $brandTop + 56 + 64;
        $titleBase = $kickerBase + 24 + 68;
        $titleLast = $titleBase + (count($titleLines) - 1) * 84;
        $headerBottom = $titleLast + 56;

        $y = $headerBottom;
        $dateBase = $card->dateLine !== null ? ($y += 80) : null;
        $venueBase = null;
        if ($venueLines !== []) {
            $venueBase = $y + ($dateBase !== null ? 66 : 80);
            $y = $venueBase + (count($venueLines) - 1) * 50;
        }

        $detTop = $y + 70;
        $guestBase = $detTop + 56;
        $lastBase = $guestBase + (count($guestLines) - 1) * 54;
        $rowLabel = $rowBase = null;
        if ($card->partyLabel() !== null || $card->table !== null) {
            $rowLabel = $lastBase + 78;
            $rowBase = $rowLabel + 56;
            $lastBase = $rowBase;
        }

        $tearY = $lastBase + 60;
        $qrTop = $tearY + 50;
        $stateLabel = $card->stateLabel();
        $frameTop = $stateLabel !== null ? $qrTop + 56 + 30 : $qrTop;
        $hintBase = $frameTop + self::QR_FRAME + 56;
        $cardBottom = ($card->isValid() ? $hintBase : $frameTop + self::QR_FRAME) + 50;
        $height = $cardBottom + self::CARD_X;

        // ── Draw at 2x. ──
        $k = fn (int|float $v): int => (int) round($v * self::S);
        $big = imagecreatetruecolor($k(self::WIDTH), $k($height));
        imagealphablending($big, true);
        imagefill($big, 0, 0, $this->color($big, self::OUTER_BG));

        $primary = $card->theme['primary'];
        $x1 = $k(self::CARD_X);
        $x2 = $k(self::CARD_X + self::CARD_W);
        $r = $k(self::RADIUS);

        // Card body with a hairline border (a slightly larger rounded rect beneath).
        $this->roundedRect($big, $x1 - 2, $k(self::CARD_X) - 2, $x2 + 2, $k($cardBottom) + 2, $r + 2, $this->color($big, self::BORDER));
        $this->roundedRect($big, $x1, $k(self::CARD_X), $x2, $k($cardBottom), $r, $this->color($big, $card->theme['background']));

        $this->drawHeader($big, $card, $guest, $k, $headerBottom, $primary);

        // Header text.
        $white = $this->color($big, '#ffffff');
        $icon = $this->loadIcon();
        if ($icon !== null) {
            imagecopyresampled($big, $icon, $k($textX), $k($brandTop), 0, 0, $k(56), $k(56), imagesx($icon), imagesy($icon));
        }
        $this->text($big, $k(34), $k($textX + 56 + 18), $k($brandTop + 39), $white, $bold, (string) config('app.name'));
        $this->text($big, $k(26), $k($textX), $k($kickerBase), $this->color($big, $this->mix('#ffffff', $primary, 0.85)), $bold, mb_strtoupper($card->eventTypeLabel.' · Invitation'));
        foreach ($titleLines as $i => $line) {
            $this->text($big, $k(68), $k($textX), $k($titleBase + $i * 84), $white, $bold, $line);
        }

        // Meta rows.
        $ink = $this->color($big, self::INK);
        $accent = $this->color($big, $card->theme['accent']);
        if ($dateBase !== null) {
            imagefilledellipse($big, $k($textX + 9), $k($dateBase - 13), $k(18), $k(18), $accent);
            $this->text($big, $k(38), $k($textX + 40), $k($dateBase), $ink, $regular, $card->dateLine.($card->timeLine !== null ? ' · '.$card->timeLine : ''));
        }
        if ($venueBase !== null) {
            imagefilledellipse($big, $k($textX + 9), $k($venueBase - 13), $k(18), $k(18), $accent);
            foreach ($venueLines as $i => $line) {
                $this->text($big, $k(36), $k($textX + 40), $k($venueBase + $i * 50), $ink, $regular, $line);
            }
        }

        // Details.
        $muted = $this->color($big, self::MUTED);
        $this->text($big, $k(24), $k($textX), $k($detTop), $muted, $bold, 'GUEST');
        foreach ($guestLines as $i => $line) {
            $this->text($big, $k(44), $k($textX), $k($guestBase + $i * 54), $ink, $bold, $line);
        }
        if ($rowBase !== null) {
            $colB = $textX + 436;
            $labelBase = $rowLabel;
            $col = $textX;
            foreach ([['PLUS ONE', $card->partyLabel()], ['TABLE', $card->table]] as [$label, $value]) {
                if ($value === null) {
                    continue;
                }
                $this->text($big, $k(24), $k($col), $k($labelBase), $muted, $bold, $label);
                $this->text($big, $k(44), $k($col), $k($rowBase), $ink, $bold, $this->ellipsize($value, $bold, 44, 400));
                $col = $colB;
            }
        }

        // Perforation: dashed line plus two edge notches.
        $tear = $this->color($big, '#b9b9cc');
        for ($dx = self::CARD_X + 24; $dx < self::CARD_X + self::CARD_W - 24; $dx += 20) {
            imagefilledrectangle($big, $k($dx), $k($tearY) - 2, $k($dx + 10), $k($tearY) + 1, $tear);
        }
        // Notches punch out to the page colour; only the half inside the card gets an outline.
        $border = $this->color($big, self::BORDER);
        imagesetthickness($big, 3);
        foreach ([[self::CARD_X, 270, 90], [self::CARD_X + self::CARD_W, 90, 270]] as [$notchX, $from, $to]) {
            imagefilledellipse($big, $k($notchX), $k($tearY), $k(44), $k(44), $this->color($big, self::OUTER_BG));
            imagearc($big, $k($notchX), $k($tearY), $k(44), $k(44), $from, $to, $border);
        }
        imagesetthickness($big, 1);

        // State pill, centred above the QR.
        if ($stateLabel !== null) {
            $valid = $card->state === GuestPassCard::STATE_CHECKED_IN;
            $pillW = $this->width($stateLabel, $bold, 26) + 56;
            $px = (int) round((self::WIDTH - $pillW) / 2);
            $this->roundedRect($big, $k($px), $k($qrTop), $k($px + $pillW), $k($qrTop + 56), $k(28), $this->color($big, $valid ? '#e6f6ec' : '#fdeaea'));
            $this->text($big, $k(26), $k($px + 28), $k($qrTop + 37), $this->color($big, $valid ? '#1e7a3f' : '#b3261e'), $bold, $stateLabel);
        }

        // QR frame (white rounded card with a soft edge); the QR itself goes on after downsampling.
        $fx = (int) round((self::WIDTH - self::QR_FRAME) / 2);
        $this->roundedRect($big, $k($fx) - 2, $k($frameTop) - 2, $k($fx + self::QR_FRAME) + 2, $k($frameTop + self::QR_FRAME) + 2, $k(28) + 2, $this->color($big, '#e6e6ee'));
        $this->roundedRect($big, $k($fx), $k($frameTop), $k($fx + self::QR_FRAME), $k($frameTop + self::QR_FRAME), $k(28), $this->color($big, '#ffffff'));

        if ($card->isValid()) {
            $hint = 'Show this QR code at the door';
            $this->text($big, $k(26), $k((self::WIDTH - $this->width($hint, $regular, 26)) / 2), $k($hintBase), $muted, $regular, $hint);
        }

        // ── Downsample, then paste the QR at its exact final size. ──
        $final = imagecreatetruecolor(self::WIDTH, $height);
        imagecopyresampled($final, $big, 0, 0, 0, 0, self::WIDTH, $height, $k(self::WIDTH), $k($height));

        $qr = imagecreatefromstring($this->qrCodeService->png($qrUrl, self::QR_SIZE));
        if ($qr === false) {
            throw new RuntimeException('Could not decode the generated QR code.');
        }
        if (! $card->isValid() && $card->state !== GuestPassCard::STATE_CHECKED_IN) {
            // Cancelled / ended: dim the code the same way the web card does.
            imagefilter($qr, IMG_FILTER_GRAYSCALE);
            imagealphablending($qr, true);
            imagefilledrectangle($qr, 0, 0, imagesx($qr), imagesy($qr), imagecolorallocatealpha($qr, 255, 255, 255, 38));
        }
        $inset = (int) round((self::QR_FRAME - self::QR_SIZE) / 2);
        imagecopy($final, $qr, $fx + $inset, $frameTop + $inset, 0, 0, imagesx($qr), imagesy($qr));

        ob_start();
        imagepng($final, null, 6);
        $png = (string) ob_get_clean();

        return $png;
    }

    /**
     * Header block: theme colour, optional cover under a tint, top corners
     * rounded to match the card. Built as its own image and pasted row by row
     * for the rounded rows, because GD has no clipping.
     */
    private function drawHeader(GdImage $big, GuestPassCard $card, Guest $guest, \Closure $k, int|float $headerBottom, string $primary): void
    {
        $w = $k(self::CARD_W);
        $h = $k($headerBottom - self::CARD_X);
        $header = imagecreatetruecolor($w, $h);
        imagefill($header, 0, 0, $this->color($header, $primary));

        $cover = $this->loadCover($guest);
        if ($cover !== null) {
            $sw = imagesx($cover);
            $sh = imagesy($cover);
            $scale = max($w / $sw, $h / $sh);
            $cw = (int) round($w / $scale);
            $ch = (int) round($h / $scale);
            imagecopyresampled($header, $cover, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $w, $h, $cw, $ch);

            // Tint so white text stays readable whatever the photo is.
            imagealphablending($header, true);
            [$pr, $pg, $pb] = $this->rgb($primary);
            imagefilledrectangle($header, 0, 0, $w, $h, imagecolorallocatealpha($header, $pr, $pg, $pb, 30));
        }

        $r = $k(self::RADIUS);
        $x = $k(self::CARD_X);
        $y = $k(self::CARD_X);
        for ($row = 0; $row < $r; $row++) {
            $inset = $r - (int) floor(sqrt(max(0, $r * $r - ($r - $row - 0.5) ** 2)));
            imagecopy($big, $header, $x + $inset, $y + $row, $inset, $row, $w - 2 * $inset, 1);
        }
        imagecopy($big, $header, $x, $y + $r, 0, $r, $w, $h - $r);
    }

    private function loadCover(Guest $guest): ?GdImage
    {
        $path = $guest->event?->cover_image;
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path) || $disk->size($path) > 8 * 1024 * 1024) {
            return null;
        }

        $image = @imagecreatefromstring((string) $disk->get($path));

        return $image === false ? null : $image;
    }

    private function loadIcon(): ?GdImage
    {
        $path = resource_path('images/eventhost-icon.png');
        if (! is_readable($path)) {
            return null;
        }

        $icon = @imagecreatefromstring((string) file_get_contents($path));
        if ($icon === false) {
            return null;
        }
        imagealphablending($icon, false);
        imagesavealpha($icon, true);

        return $icon;
    }

    /**
     * Greedy word wrap by measured width. A word wider than the line is broken by
     * character; overflow past $maxLines is cut with an ellipsis.
     *
     * @return list<string>
     */
    private function wrap(string $text, string $font, int $size, int $maxWidth, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($this->width($candidate, $font, $size) <= $maxWidth) {
                $line = $candidate;

                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            while ($this->width($word, $font, $size) > $maxWidth && mb_strlen($word) > 1) {
                $cut = mb_strlen($word) - 1;
                while ($cut > 1 && $this->width(mb_substr($word, 0, $cut), $font, $size) > $maxWidth) {
                    $cut--;
                }
                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }
            $line = $word;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->ellipsize($lines[$maxLines - 1].' …', $font, $size, $maxWidth);
        }

        return $lines === [] ? [''] : $lines;
    }

    private function ellipsize(string $text, string $font, int $size, int $maxWidth): string
    {
        if ($this->width($text, $font, $size) <= $maxWidth) {
            return $text;
        }

        $text = rtrim($text, ' …');
        while ($text !== '' && $this->width($text.'…', $font, $size) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size * self::PT, 0, $font, $text);

        return $box === false ? 0 : abs($box[2] - $box[0]);
    }

    private function text(GdImage $im, int $size, int $x, int $baseline, int $color, string $font, string $text): void
    {
        imagettftext($im, $size * self::PT, 0, $x, $baseline, $color, $font, $text);
    }

    private function roundedRect(GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }

    private function color(GdImage $im, string $hex): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return (int) imagecolorallocate($im, $r, $g, $b);
    }

    /** @return array{int, int, int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** Blend $a over $b at $alpha (0–1). */
    private function mix(string $a, string $b, float $alpha): string
    {
        $ca = $this->rgb($a);
        $cb = $this->rgb($b);

        return sprintf('#%02x%02x%02x', ...array_map(
            fn (int $i): int => (int) round($ca[$i] * $alpha + $cb[$i] * (1 - $alpha)),
            [0, 1, 2],
        ));
    }
}
