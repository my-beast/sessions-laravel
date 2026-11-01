<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Journey;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MyBeast\Sessions\Laravel\Support\LogOnce;
use Stringable;
use Symfony\Component\HttpFoundation\Response;

/**
 * How the host tells the journey about its people (Sessions::journeyUsing). Every hook has a default that needs no
 * code: the signed-in user's id and `created_at`, no account, everyone allows it, no page name but Inertia's.
 *
 * The defaults never look anyone up: they read the user some part of the request already signed in (a guard that
 * has one), so a guest's request costs nothing.
 */
final class JourneyHooks
{
    /** The schema's pattern for a subject or an account id. */
    private const ID = '/^[A-Za-z0-9._:-]{1,64}$/';

    /** @var (Closure(Request): (string|int|null))|null */
    private ?Closure $subject = null;

    /** @var (Closure(Request): (string|int|null))|null */
    private ?Closure $account = null;

    /** @var (Closure(Request): (DateTimeInterface|int|null))|null */
    private ?Closure $subjectSince = null;

    /** @var (Closure(string, Request): bool)|null */
    private ?Closure $allows = null;

    /** @var (Closure(string): bool)|null */
    private ?Closure $serverError = null;

    /** @var (Closure(Request, Response): ?string)|null */
    private ?Closure $page = null;

    public function __construct(private readonly Container $app) {}

    /**
     * Replaces the hooks given (named arguments); the others keep what they were.
     *
     * @param  (Closure(Request): (string|int|null))|null  $subject  the person (default: the signed-in user's id)
     * @param  (Closure(Request): (string|int|null))|null  $account  their account or team (default: none)
     * @param  (Closure(Request): (DateTimeInterface|int|null))|null  $subjectSince  when their account was made, or unix ms (default: the user's created_at)
     * @param  (Closure(string, Request): bool)|null  $allows  false for a person who objected to the journey (default: everyone allows it)
     * @param  (Closure(string): bool)|null  $serverError  whether an error toast stands for a server error rather than a refusal (default: config server_error_toasts)
     * @param  (Closure(Request, Response): ?string)|null  $page  the host's name of the page answered (default: an Inertia answer's component)
     */
    public function using(
        ?Closure $subject = null,
        ?Closure $account = null,
        ?Closure $subjectSince = null,
        ?Closure $allows = null,
        ?Closure $serverError = null,
        ?Closure $page = null,
    ): void {
        $this->subject = $subject ?? $this->subject;
        $this->account = $account ?? $this->account;
        $this->subjectSince = $subjectSince ?? $this->subjectSince;
        $this->allows = $allows ?? $this->allows;
        $this->serverError = $serverError ?? $this->serverError;
        $this->page = $page ?? $this->page;
    }

    /** The person, as an id the service takes; null for a guest, or for an id it would refuse (said once in the log). */
    public function subject(Request $request): ?string
    {
        $id = self::id($this->subject !== null ? ($this->subject)($request) : $this->user()?->getAuthIdentifier());

        if ($id !== null && preg_match(self::ID, $id) !== 1) {
            LogOnce::warning('journey.subject', 'My Beast sessions: a journey subject is not an id of 1-64 letters, digits and . _ : - so its steps are not kept (Sessions::journeyUsing(subject: …)).');

            return null;
        }

        return $id;
    }

    /** The person's account; null when none, or for an id the service would refuse (the step is kept without it). */
    public function account(Request $request): ?string
    {
        $id = $this->account === null ? null : self::id(($this->account)($request));

        if ($id !== null && preg_match(self::ID, $id) !== 1) {
            LogOnce::warning('journey.account', 'My Beast sessions: a journey account is not an id of 1-64 letters, digits and . _ : - so steps go without it (Sessions::journeyUsing(account: …)).');

            return null;
        }

        return $id;
    }

    /** When the person's account was made, unix ms (a key flow for new accounts starts only for people that new). */
    public function subjectSince(Request $request): ?int
    {
        if ($this->subjectSince !== null) {
            $since = ($this->subjectSince)($request);
        } else {
            $user = $this->user();
            $since = $user !== null && isset($user->created_at) ? $user->created_at : null;
        }

        if ($since instanceof DateTimeInterface) {
            $since = (int) $since->format('Uv');
        }

        return is_int($since) && $since >= 0 ? $since : null;
    }

    /** Whether the person allows the journey: false for someone who objected, whose steps are never kept. */
    public function allows(string $subject, Request $request): bool
    {
        return $this->allows === null || ($this->allows)($subject, $request) === true;
    }

    /** @return bool|null the host's word on whether a toast stands for a server error; null: the config decides */
    public function serverError(string $toast): ?bool
    {
        return $this->serverError === null ? null : ($this->serverError)($toast) === true;
    }

    /**
     * The host's name of the page answered. By default an Inertia answer's component (the response says so with its
     * own X-Inertia header; the component is read off the data it was made from, the body is never parsed).
     */
    public function page(Request $request, Response $response): ?string
    {
        if ($this->page !== null) {
            $page = ($this->page)($request, $response);

            return is_string($page) && $page !== '' ? $page : null;
        }

        if ($response->headers->get('X-Inertia') !== 'true' || ! $response instanceof JsonResponse) {
            return null;
        }

        $data = $response->getOriginalContent();
        $component = is_array($data) ? ($data['component'] ?? null) : null;

        return is_string($component) && $component !== '' ? $component : null;
    }

    /** The user some part of the request already signed in - never a lookup of one. */
    private function user(): ?Authenticatable
    {
        $guard = $this->app->make('auth')->guard();

        return $guard->hasUser() ? $guard->user() : null;
    }

    private static function id(mixed $value): ?string
    {
        if (is_int($value) || $value instanceof Stringable || (is_string($value) && $value !== '')) {
            return (string) $value;
        }

        return null;
    }
}
