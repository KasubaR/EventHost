<?php

namespace App\Support;

/**
 * Invitation hero background may be an uploaded file path or a YouTube reference ({@see PREFIX}).
 *
 * Also owns the YouTube reference format for admin-authored video reviews, which store the same
 * {@see PREFIX} value but render through {@see playerEmbedUrl()} rather than the muted background embed.
 */
final class InvitationVideoBackground
{
    public const PREFIX = 'youtube:';

    public static function isYoutube(?string $stored): bool
    {
        return self::extractIdFromStored($stored) !== null;
    }

    public static function isFilePath(?string $stored): bool
    {
        return is_string($stored)
            && $stored !== ''
            && ! self::isYoutube($stored);
    }

    public static function extractIdFromStored(?string $stored): ?string
    {
        if (! is_string($stored) || ! str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $id = substr($stored, strlen(self::PREFIX));

        return self::validId($id) ? $id : null;
    }

    /**
     * Accept pasted URLs, bare 11-char IDs, or stored {@see PREFIX} values.
     */
    public static function parseVideoId(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (str_starts_with($input, self::PREFIX)) {
            $id = substr($input, strlen(self::PREFIX));

            return self::validId($id) ? $id : null;
        }

        if (self::validId($input)) {
            return $input;
        }

        if (preg_match('#youtu\.be/([a-zA-Z0-9_-]{11})#', $input, $m)) {
            return $m[1];
        }

        if (preg_match('#[?&]v=([a-zA-Z0-9_-]{11})#', $input, $m)) {
            return $m[1];
        }

        if (preg_match('#youtube\.com/embed/([a-zA-Z0-9_-]{11})#', $input, $m)) {
            return $m[1];
        }

        if (preg_match('#youtube\.com/live/([a-zA-Z0-9_-]{11})#', $input, $m)) {
            return $m[1];
        }

        if (preg_match('#youtube\.com/shorts/([a-zA-Z0-9_-]{11})#', $input, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return non-empty-string|null Normalized persisted value or null when invalid / empty input.
     */
    public static function normalizeUserInput(?string $input): ?string
    {
        $input = $input !== null ? trim($input) : '';
        if ($input === '') {
            return null;
        }

        $id = self::parseVideoId($input);

        return $id !== null ? self::PREFIX.$id : null;
    }

    /** Share URL for admin display when stored reference exists. */
    public static function watchUrlFromStored(?string $stored): ?string
    {
        $id = self::extractIdFromStored($stored);

        return $id !== null ? 'https://www.youtube.com/watch?v='.$id : null;
    }

    public static function embedUrl(string $videoId): string
    {
        $query = http_build_query([
            'autoplay' => '1',
            'mute' => '1',
            'playsinline' => '1',
            'loop' => '1',
            'playlist' => $videoId,
            'controls' => '0',
            'modestbranding' => '1',
            'rel' => '0',
        ]);

        return 'https://www.youtube-nocookie.com/embed/'.$videoId.'?'.$query;
    }

    /**
     * Embed URL for a video the viewer has chosen to play: real controls, sound
     * on, no loop. {@see embedUrl()} is muted and chrome-less because it plays
     * behind an invitation hero — a testimonial nobody can hear is useless.
     */
    public static function playerEmbedUrl(string $videoId): string
    {
        $query = http_build_query([
            'autoplay' => '1',
            'playsinline' => '1',
            'modestbranding' => '1',
            'rel' => '0',
        ]);

        return 'https://www.youtube-nocookie.com/embed/'.$videoId.'?'.$query;
    }

    private static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_-]{11}$/', $id);
    }
}
