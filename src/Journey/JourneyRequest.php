<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Journey;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the journey reads off a request: the recording it came from, the visit key to file its step under, and the
 * name of the host's page route a browser address belongs to.
 */
final class JourneyRequest
{
    /** Longest page address read (only its path is ever used). */
    private const MAX_ADDRESS = 2048;

    /** Header `X-Recording-Id` while the browser records (the browser SDK sends it): a UUID, else nothing. */
    public static function recordingId(Request $request): ?string
    {
        $header = $request->headers->get('X-Recording-Id');

        return is_string($header) && Str::isUuid($header) ? strtolower($header) : null;
    }

    /**
     * The recording's id while one runs; else the session's: `srv-` and 16 hex of the sha256 of its id (the id
     * itself never leaves). Null without a session - such a request is nobody's visit.
     */
    public static function visitKey(Request $request, ?string $recordingId): ?string
    {
        if ($recordingId !== null) {
            return $recordingId;
        }

        if (! $request->hasSession() || (string) $request->session()->getId() === '') {
            return null;
        }

        return 'srv-'.substr(hash('sha256', (string) $request->session()->getId()), 0, 16);
    }

    /**
     * The name of the host's GET route a page address belongs to, matched on this host: null when it is none of its
     * pages (no route, only the not-found fallback, an unnamed route). Only the path is read - no query value, no
     * other host.
     */
    public static function pageRoute(Router $router, Request $request, string $address): ?string
    {
        $path = strlen($address) > self::MAX_ADDRESS ? null : parse_url($address, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $route = $router->getRoutes()->match(Request::create($request->getSchemeAndHttpHost().'/'.ltrim($path, '/'), 'GET'));
        } catch (Throwable) {
            return null; // Not found, or not a page (no GET route there).
        }

        return $route->isFallback ? null : $route->getName();
    }
}
