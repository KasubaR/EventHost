<?php

namespace App\Enums;

enum ReviewMediaType: string
{
    case Text = 'text';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Written review',
            self::Video => 'Video review',
        };
    }
}
