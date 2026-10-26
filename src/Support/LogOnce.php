<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A warning said once per process and reason: the journey runs on every request, so a broken spool or a malformed
 * subject would otherwise write the same line thousands of times.
 */
final class LogOnce
{
    /** @var array<string, true> */
    private static array $said = [];

    public static function warning(string $reason, string $message, ?Throwable $e = null): void
    {
        if (isset(self::$said[$reason])) {
            return;
        }

        self::$said[$reason] = true;

        try {
            Log::warning($message, $e === null ? [] : ['exception' => $e]);
        } catch (Throwable) {
            // Nothing left to tell it to.
        }
    }

    /** Each app starts with nothing said (a test suite boots thousands). */
    public static function forget(): void
    {
        self::$said = [];
    }
}
