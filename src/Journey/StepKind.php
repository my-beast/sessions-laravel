<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Journey;

/** What one server step was (journey-steps.schema.json `kind`), told from the answer the request got. */
enum StepKind: string
{
    /** A page shown: an Inertia visit or a full page load (a redirect included), or a key flow's data read. */
    case Visit = 'visit';

    /** A write the server took: anything but GET, answered 2xx/3xx, nothing refused. */
    case Save = 'save';

    /** A write the server sent back: the form's errors, a 422 (or another 4xx), or the error toast of a refusal. */
    case Refused = 'refused';

    /** The server failed (5xx, or the toast that stands for one where the host shows a toast instead). */
    case Error = 'error';

    /** Not let in: not allowed, not found, the page expired, too many tries. */
    case Blocked = 'blocked';

    /** Statuses that say "not let in" rather than "not accepted". */
    private const BLOCKED_STATUSES = [401, 403, 404, 405, 419, 429];

    /**
     * What a request the server answered was, from the answer alone.
     *
     * @param  bool  $read  a GET (a page asked for), else a write
     * @param  int  $status  the answer's status - a redirect for an Inertia "go to this address" (409 + location)
     * @param  bool  $refused  the answer refused the write: the form's errors, or the error toast of a refusal
     * @param  bool  $serverError  the toast that stands for a 5xx (the request went back to its page with it)
     */
    public static function forAnswer(bool $read, int $status, bool $refused, bool $serverError): self
    {
        if ($status >= 500 || $serverError) {
            return self::Error;
        }

        if (in_array($status, self::BLOCKED_STATUSES, true)) {
            return self::Blocked;
        }

        if ($read) {
            return $status >= 400 ? self::Blocked : self::Visit;
        }

        return $refused || $status >= 400 ? self::Refused : self::Save;
    }
}
