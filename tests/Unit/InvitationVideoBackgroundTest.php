<?php

namespace Tests\Unit;

use App\Support\InvitationVideoBackground;
use PHPUnit\Framework\TestCase;

class InvitationVideoBackgroundTest extends TestCase
{
    public function test_parse_watch_url(): void
    {
        $this->assertSame(
            'dQw4w9WgXcQ',
            InvitationVideoBackground::parseVideoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        );
    }

    public function test_parse_short_url_and_normalize(): void
    {
        $this->assertSame(
            'youtube:dQw4w9WgXcQ',
            InvitationVideoBackground::normalizeUserInput('https://youtu.be/dQw4w9WgXcQ')
        );
    }

    public function test_embed_url_contains_loop_playlist(): void
    {
        $url = InvitationVideoBackground::embedUrl('dQw4w9WgXcQ');
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $url);
        $this->assertStringContainsString('playlist=dQw4w9WgXcQ', $url);
    }
}
