<?php

namespace App\Support;

/**
 * Recognises Google Maps short links and pulls coordinates out of a (resolved) Google Maps URL.
 *
 * Short links (maps.app.goo.gl, goo.gl/maps/...) carry no coordinates in the text itself — they
 * only appear once Google's redirect has been followed — so {@see MapLinkController} resolves the
 * redirect server-side and hands the final URL to {@see extractCoordinates()}.
 *
 * {@see extractCoordinates()} mirrors parseGoogleMapsCoords() in public/js/events-form.js, which
 * parses the same URL shapes client-side for links that already carry coordinates (no redirect to
 * resolve). Keep the two in sync — there is no shared module boundary between blade/JS and PHP in
 * this codebase, so that has to be done by hand.
 */
final class GoogleMapsLinkParser
{
    /** Hosts a short link may point at directly, and a resolved redirect may pass through. */
    private const ALLOWED_HOSTS = ['maps.app.goo.gl', 'goo.gl'];

    public static function isShortLink(string $url): bool
    {
        $host = self::host($url);
        if ($host === null) {
            return false;
        }

        if ($host === 'maps.app.goo.gl') {
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return $host === 'goo.gl' && str_starts_with($path, '/maps/');
    }

    /**
     * True when $url's host is safe to send the *next* request to while following a short link's
     * redirect chain: either another short-link host (some chain through goo.gl before landing on
     * maps.app.goo.gl) or a Google Maps host. Anything else — including an internal/private address
     * a malicious short link could point at — must stop the chain rather than be fetched.
     */
    public static function isAllowedHop(string $url): bool
    {
        $host = self::host($url);
        if ($host === null) {
            return false;
        }

        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return true;
        }

        return $host === 'google.com' || str_ends_with($host, '.google.com');
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public static function extractCoordinates(string $url): ?array
    {
        // The actual pin on a Google "place" link — can legitimately disagree with the `@lat,lng`
        // viewport center below, so it's checked first.
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $m) === 1) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }

        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m) === 1) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }

        if (preg_match('/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m) === 1) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }

        return null;
    }

    private static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
