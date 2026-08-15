<?php

namespace App\Http\Controllers;

use App\Support\GoogleMapsLinkParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Resolves a Google Maps *short* link (maps.app.goo.gl, goo.gl/maps/...) to coordinates.
 *
 * Short links carry no coordinates in the text itself — they only appear once Google's redirect
 * has been followed — and the browser can't follow it itself (Google sends no CORS headers on
 * that redirect). Full links are parsed client-side with no server round-trip at all; see
 * parseGoogleMapsCoords() in public/js/events-form.js.
 *
 * This is the app's one endpoint that fetches a URL the user supplies, so the SSRF guard is the
 * point of the class, not an afterthought: every hop's host is checked against
 * {@see GoogleMapsLinkParser::isAllowedHop()} *before* it is requested, and only the redirect
 * `Location` header is ever read — the destination page's body is never fetched or returned.
 */
class MapLinkController extends Controller
{
    private const MAX_HOPS = 5;

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $url = $validated['url'];

        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return response()->json(['message' => 'That link could not be resolved.'], 422);
        }

        if (! GoogleMapsLinkParser::isShortLink($url)) {
            return response()->json(['message' => 'That link could not be resolved.'], 422);
        }

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if (! GoogleMapsLinkParser::isAllowedHop($url)) {
                return response()->json(['message' => 'That link could not be resolved.'], 422);
            }

            try {
                $response = Http::withOptions(['allow_redirects' => false])
                    ->timeout(5)
                    ->get($url);
            } catch (\Throwable) {
                return response()->json(['message' => 'That link could not be resolved.'], 422);
            }

            if (! $response->redirect()) {
                $coordinates = GoogleMapsLinkParser::extractCoordinates($url);

                if ($coordinates === null) {
                    return response()->json(['message' => 'No coordinates found in that link.'], 422);
                }

                return response()->json([
                    'latitude' => $coordinates['lat'],
                    'longitude' => $coordinates['lng'],
                ]);
            }

            $location = $response->header('Location');

            if (! is_string($location) || $location === '') {
                return response()->json(['message' => 'That link could not be resolved.'], 422);
            }

            $url = $this->resolveLocation($url, $location);
        }

        return response()->json(['message' => 'That link took too many redirects to resolve.'], 422);
    }

    /** A `Location` header is sometimes relative — resolve it against the request it answered. */
    private function resolveLocation(string $requestedUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($requestedUrl);
        $origin = ($base['scheme'] ?? 'https').'://'.($base['host'] ?? '');

        return $origin.'/'.ltrim($location, '/');
    }
}
