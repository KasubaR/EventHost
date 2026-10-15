<?php

namespace Tests\Unit;

use App\Support\ShortText;
use PHPUnit\Framework\TestCase;

class ShortTextTest extends TestCase
{
    public function test_short_text_is_left_alone_on_one_line(): void
    {
        $this->assertSame('Mary & Tom 🎉', ShortText::limit("  Mary &\n Tom   🎉 ", 20));
    }

    public function test_a_flag_at_the_cut_point_is_dropped_whole(): void
    {
        $result = ShortText::limit(str_repeat('a', 8).'🇿🇲bbb', 10);

        $this->assertSame(str_repeat('a', 8).'…', $result);
        $this->assertLessThanOrEqual(10, mb_strlen($result));
    }

    public function test_a_family_emoji_that_fits_is_kept_whole(): void
    {
        $this->assertSame('ab👨‍👩‍👧…', ShortText::limit('ab👨‍👩‍👧cdefgh', 8));
    }

    public function test_a_family_emoji_that_does_not_fit_is_not_split(): void
    {
        $this->assertSame('ab…', ShortText::limit('ab👨‍👩‍👧cdefgh', 6));
    }

    public function test_a_skin_tone_is_never_left_without_its_emoji(): void
    {
        $result = ShortText::limit('abc👍🏽defg', 5);

        $this->assertSame('abc…', $result);
    }
}
