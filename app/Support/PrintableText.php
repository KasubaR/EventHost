<?php

namespace App\Support;

/**
 * What the PDFs and the pass PNG can actually draw. Both use DejaVu Sans, which covers Latin (with every accent), Greek, Cyrillic,
 * Armenian, Georgian, Hebrew and Arabic letters, but nothing for Chinese, Japanese, Korean, Thai, the Indic scripts or emoji: those
 * come out as empty boxes. `forPrint()` replaces each such run with a single "?" so a printed name says plainly that it could not be
 * drawn, instead of showing boxes. Hebrew and Arabic are drawn but neither shaped nor reordered (GD and DomPDF have no bidirectional
 * engine), so `mayNotPrint()` flags them too, for the host's guest list. The web pages are unaffected: the browser draws everything.
 * plans/guest-adding-gaps.md Phase 4.
 */
final class PrintableText
{
    /** Scripts DejaVu Sans has glyphs for, plus punctuation, digits and combining marks. */
    private const DRAWN = '\p{Latin}\p{Greek}\p{Cyrillic}\p{Armenian}\p{Georgian}\p{Hebrew}\p{Arabic}\p{Common}\p{Inherited}';

    /** Emoji and pictographs (Common in Unicode, but DejaVu has almost none of them). */
    private const EMOJI = '\x{1F000}-\x{1FAFF}\x{FE0F}';

    /** $text with every run DejaVu cannot draw replaced by one "?". */
    public static function forPrint(?string $text): ?string
    {
        if (! is_string($text) || $text === '') {
            return $text;
        }

        return preg_replace('/[^'.self::DRAWN.']+|['.self::EMOJI.']+/u', '?', $text) ?? $text;
    }

    /** True when printing $text would lose characters or draw right-to-left text unshaped. */
    public static function mayNotPrint(?string $text): bool
    {
        if (! is_string($text) || $text === '') {
            return false;
        }

        return self::forPrint($text) !== $text || preg_match('/[\p{Hebrew}\p{Arabic}]/u', $text) === 1;
    }
}
