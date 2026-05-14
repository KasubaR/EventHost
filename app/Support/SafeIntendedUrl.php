<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SafeIntendedUrl
{
    /**
     * Pull the session "url.intended" value only if it targets this application
     * (relative path, protocol-relative to same host, or absolute http(s) with matching host).
     */
    public static function pullIntendedOrDefault(Request $request, string $default): string
    {
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '') {
            return $default;
        }

        return self::isSafeIntendedTarget($intended, $request) ? $intended : $default;
    }

    public static function redirect(Request $request, string $default, int $status = 302): RedirectResponse
    {
        return redirect()->to(self::pullIntendedOrDefault($request, $default), $status);
    }

    public static function isSafeIntendedTarget(string $url, Request $request): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : null;
        $host = $parts['host'] ?? null;

        if ($host !== null && $host !== '') {
            if ($scheme !== null && ! in_array($scheme, ['http', 'https'], true)) {
                return false;
            }

            return strcasecmp($host, $request->getHost()) === 0;
        }

        if ($scheme !== null && ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if ($scheme !== null && in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return true;
    }
}
