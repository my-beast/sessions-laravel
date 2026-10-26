<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Journey;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use MyBeast\Sessions\Laravel\Support\LogOnce;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The journey: one step per meaningful request of a signed-in person, kept after the answer has been sent
 * (terminate) - so it never slows or breaks a request: one small file written, every failure swallowed (and logged
 * once per process). At the end of the `web` group (the SDK puts it there unless config `journey.middleware` is
 * false).
 *
 * Kept: an Inertia visit, a full page load answered with HTML, a data read that answers JSON (config
 * `journey.data_reads`), and every write (not GET) - an Inertia form, an XHR call, a plain form. Not kept: guests;
 * HEAD and OPTIONS; prefetches (Inertia's `Purpose: prefetch`, the browser's `Sec-Purpose`); partial reloads (a
 * page's live refresh, its deferred props - never a new page); Inertia's version check (the full load that follows
 * is the step); other JSON reads (polling, badges); broadcasting's sign-in; the SDK's own routes (mybeast.*), a
 * request that reported a page itself (Sessions::pageShown) and config `journey.skip_routes`.
 *
 * Of a request it keeps the route's name, the method, the status, the time it took and, for a refused write, the KEYS
 * of its errors - the session's error bag (an Inertia or a plain form) or a 422 JSON answer's `errors` - never a
 * message, never a value.
 */
final class RecordJourneyStep
{
    /** Request attribute: when the request came through here (unix ms), and when its answer came back. */
    private const STAMP = 'mybeast.sessions.journey.stamp';

    /** Request attribute: its step was decided (the middleware listed twice still keeps one). */
    private const DONE = 'mybeast.sessions.journey.done';

    /** A JSON answer longer than this is not read for its error keys. */
    private const MAX_JSON_BYTES = 65_536;

    public function __construct(
        private readonly Journey $journey,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->journey->enabled()) {
            return $next($request);
        }

        $arrivedAt = (int) Date::now()->format('Uv');
        $response = $next($request);
        $request->attributes->set(self::STAMP, [$arrivedAt, microtime(true)]);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($request->attributes->has(self::DONE) || ! $this->journey->enabled()) {
                return;
            }

            $request->attributes->set(self::DONE, true);
            $step = $this->step($request, $response);

            if ($step !== null) {
                $this->journey->record($step);
            }
        } catch (Throwable $e) {
            LogOnce::warning('journey.step', 'MyBeast sessions: a journey step was not kept: '.$e->getMessage(), $e);
        }
    }

    private function step(Request $request, Response $response): ?JourneyStep
    {
        $method = strtoupper($request->getMethod());

        if ($method === 'HEAD' || $method === 'OPTIONS') {
            return null;
        }

        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        if ($this->skipped($request, $name)) {
            return null;
        }

        // Only a person some part of the request already signed in (the default hook looks no one up).
        $hooks = $this->journey->hooks();
        $subject = $hooks->subject($request);

        if ($subject === null) {
            return null;
        }

        $read = $method === 'GET';
        $status = $response->getStatusCode();
        // Inertia's "go to this address" (409 + X-Inertia-Location) is a redirect by another name.
        $relocated = $status === 409 && $response->headers->has('X-Inertia-Location');
        $asked = $read ? $this->pageAsked($request, $response, $name, $relocated) : null;

        if ($read && $asked === null) {
            return null;
        }

        $fields = $read ? null : $this->refusedFields($request, $response);
        $toast = $this->errorToast($request);
        $serverError = $toast !== null && $this->journey->serverErrorToast($toast);
        [$arrivedAt, $durationMs] = $this->timing($request);

        return $this->journey->step(
            $request,
            $subject,
            at: $arrivedAt,
            kind: StepKind::forAnswer(
                read: $read,
                status: $relocated ? 303 : $status,
                refused: $fields !== null || ($toast !== null && ! $serverError),
                serverError: $serverError,
            ),
            route: $name,
            method: $method,
            status: $status,
            durationMs: $durationMs,
            fields: $fields,
            page: $hooks->page($request, $response),
            dataRead: $asked === 'data',
        );
    }

    /** Broadcasting's sign-in, the SDK's own routes, a page report, the host's skip list. */
    private function skipped(Request $request, ?string $name): bool
    {
        return $request->attributes->has(Journey::REPORTED)
            || $request->is('broadcasting/*')
            || $this->journey->skips($name);
    }

    /**
     * What a GET asked for: 'page' (an Inertia visit, or a full page load answered with HTML - a redirect included),
     * 'data' (a data read's JSON), or null - a prefetch, a partial reload, Inertia's version check, another JSON read.
     */
    private function pageAsked(Request $request, Response $response, ?string $name, bool $relocated): ?string
    {
        if ($relocated || self::prefetch($request) || self::partialReload($request)) {
            return null;
        }

        if ($request->headers->has('X-Inertia')) {
            return 'page';
        }

        if (! $request->expectsJson() && str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return 'page';
        }

        return $this->journey->dataRead($name) ? 'data' : null;
    }

    private static function prefetch(Request $request): bool
    {
        return strtolower((string) $request->headers->get('Purpose')) === 'prefetch'
            || str_contains(strtolower((string) $request->headers->get('Sec-Purpose')), 'prefetch')
            || strtolower((string) $request->headers->get('X-Moz')) === 'prefetch';
    }

    private static function partialReload(Request $request): bool
    {
        foreach (['X-Inertia-Partial-Component', 'X-Inertia-Partial-Data', 'X-Inertia-Partial-Except'] as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The KEYS of a refused write's errors: the session's error bag (flashed by this request; the session is saved,
     * and its flash aged, before terminate), else a 422 JSON answer's `errors`. Never a message or a value.
     *
     * @return list<string>|null
     */
    private function refusedFields(Request $request, Response $response): ?array
    {
        $keys = $this->flashedNow($request, 'errors') ? self::bagKeys($request->session()->get('errors')) : [];

        if ($keys === [] && $response->getStatusCode() === 422 && $response instanceof JsonResponse) {
            $keys = self::jsonErrorKeys($response);
        }

        return $keys === [] ? null : $keys;
    }

    /** The error toast this request flashed (config `journey.error_toast`): a refusal's, or a server error's. */
    private function errorToast(Request $request): ?string
    {
        $key = $this->journey->toastKey();

        if ($key === null || ! $this->flashedNow($request, $key)) {
            return null;
        }

        $toast = $request->session()->get($key);

        return is_string($toast) && $toast !== '' ? $toast : null;
    }

    /** Whether this request flashed the key (once the session is saved, its own flash is the `_flash.old` list). */
    private function flashedNow(Request $request, string $key): bool
    {
        return $request->hasSession() && in_array($key, (array) $request->session()->get('_flash.old', []), true);
    }

    /**
     * Keys of the session's `errors`: a ViewErrorBag, or its JSON-serialised form.
     *
     * @return list<string>
     */
    private static function bagKeys(mixed $errors): array
    {
        $keys = [];

        if ($errors instanceof ViewErrorBag) {
            foreach ($errors->getBags() as $bag) {
                array_push($keys, ...$bag->keys());
            }
        } elseif ($errors instanceof MessageBag) {
            $keys = $errors->keys();
        } elseif (is_array($errors)) {
            foreach ($errors as $bag) {
                if (is_array($bag) && is_array($bag['messages'] ?? null)) {
                    array_push($keys, ...array_keys($bag['messages']));
                }
            }
        }

        return array_values(array_map('strval', array_filter($keys, fn (mixed $key): bool => is_string($key) || is_int($key))));
    }

    /** @return list<string> the keys of a 422 JSON answer's `errors` */
    private static function jsonErrorKeys(JsonResponse $response): array
    {
        $content = (string) $response->getContent();

        if (strlen($content) > self::MAX_JSON_BYTES) {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) && is_array($data['errors'] ?? null) ? array_map('strval', array_keys($data['errors'])) : [];
    }

    /**
     * When the request came in (the app's clock: tests travel), and how long it took to answer - from the PHP
     * request's start (LARAVEL_START, else REQUEST_TIME_FLOAT) to the answer coming back through this middleware
     * (its sending not included).
     *
     * @return array{0: int, 1: int|null} unix ms, ms
     */
    private function timing(Request $request): array
    {
        $stamp = $request->attributes->get(self::STAMP);
        $answeredAt = is_array($stamp) && is_float($stamp[1] ?? null) ? $stamp[1] : microtime(true);
        $startedAt = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', 0);
        $durationMs = $startedAt > 0 && $answeredAt >= $startedAt && $answeredAt - $startedAt < 86_400
            ? (int) round(($answeredAt - $startedAt) * 1000)
            : null;

        $arrivedAt = is_array($stamp) && is_int($stamp[0] ?? null)
            ? $stamp[0]
            // Refused before it got here (a stale form token): its start, as near as can be told.
            : (int) Date::now()->format('Uv') - ($durationMs ?? 0);

        return [$arrivedAt, $durationMs];
    }
}
