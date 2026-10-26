<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static array{url: string, key: string, env: string, token: string, expires_at: int}|null clientConfig(string|int $subject, string|int|null $account = null, ?\DateTimeInterface $consentAt = null, bool $mask = true, bool $internal = false, bool $test = false)
 * @method static int|null eraseSubject(string|int $subject)
 * @method static int|null eraseAccount(string|int $account)
 * @method static bool journeyEnabled()
 * @method static void journeyUsing(?\Closure $subject = null, ?\Closure $account = null, ?\Closure $subjectSince = null, ?\Closure $allows = null, ?\Closure $serverError = null, ?\Closure $page = null)
 * @method static bool pageShown(?string $route, ?string $page = null)
 * @method static string|null routeForAddress(string $address)
 * @method static int flush()
 * @method static \MyBeast\Sessions\Laravel\Flows\KeyFlows|null keyFlows()
 * @method static int|null syncFlows(?\MyBeast\Sessions\Laravel\Flows\KeyFlows $flows = null)
 * @method static int|null objected(string|int $subject)
 * @method static void objectionLifted(string|int $subject)
 * @method static int|null eraseJourney(string|int $subject)
 * @method static int|null eraseAccountJourney(string|int $account)
 * @method static list<array{id: string, started_at: int, masked: bool, chunks: list<string>, unreadable_chunks: int}>|null testRecordings(string|int $subject)
 * @method static \MyBeast\Sessions\Laravel\Testing\JourneyFake fake()
 * @method static string environment()
 *
 * @see \MyBeast\Sessions\Laravel\Sessions
 */
final class Sessions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MyBeast\Sessions\Laravel\Sessions::class;
    }
}
