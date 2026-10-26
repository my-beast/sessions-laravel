<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel;

use Closure;
use DateTimeInterface;
use Illuminate\Container\Container as GlobalContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use MyBeast\Sessions\Laravel\Flows\FlowsPayload;
use MyBeast\Sessions\Laravel\Flows\FlowSyncFailed;
use MyBeast\Sessions\Laravel\Flows\InvalidFlows;
use MyBeast\Sessions\Laravel\Flows\KeyFlows;
use MyBeast\Sessions\Laravel\Journey\Journey;
use MyBeast\Sessions\Laravel\Journey\JourneyHooks;
use MyBeast\Sessions\Laravel\Journey\JourneyRequest;
use MyBeast\Sessions\Laravel\Journey\StepSink;
use MyBeast\Sessions\Laravel\Support\LogOnce;
use MyBeast\Sessions\Laravel\Support\Settings;
use MyBeast\Sessions\Laravel\Testing\JourneyFake;
use MyBeast\Sessions\Laravel\Tokens\ClientTokenClaims;
use MyBeast\Sessions\Laravel\Tokens\SigningKey;
use MyBeast\Sessions\Laravel\Tokens\TokenSigner;
use MyBeast\Sessions\Laravel\Transport\Flusher;
use MyBeast\Sessions\Laravel\Transport\ServiceClient;
use MyBeast\Sessions\Laravel\Transport\TransportFailed;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The SDK's front door (facade `MyBeast\Sessions\Laravel\Facades\Sessions`): what a page needs to be recorded, the
 * journey this server keeps of its signed-in people, the key flows, a person's objection and erasure, a closed
 * account's erasure, and the masking canary's read-back of a test person's recordings.
 *
 * A page is recorded only with the person's yes: clientConfig() is given the moment they said it (or null), and the
 * token says `rec` only then. The service refuses a recording without it, and an unmasked one where the project
 * requires masking.
 *
 * Off while the config is incomplete or malformed (or MYBEAST_SESSIONS_ENABLED=false): clientConfig() returns null,
 * the journey keeps nothing, the service is never asked - a host without the service, or with a typo in .env, keeps
 * working.
 */
final class Sessions
{
    private const PUBLIC_KEY = '/^pk_[a-z0-9]{24}$/';

    /** A person or an account as the contract carries them (`sub`, `acct`): the service keeps nothing under another. */
    private const ID = '/^[A-Za-z0-9._:-]{1,64}$/';

    /** Seconds for an account's erasure: it may hold many recordings and a long journey, all erased in one call. */
    private const ACCOUNT_TIMEOUT = 60;

    /** Seconds for the masking canary's export: up to the service's caps (200 recordings, 32 MiB of events). */
    private const EXPORT_TIMEOUT = 120;

    private readonly Settings $settings;

    private ?TokenSigner $signer = null;

    private bool $resolved = false;

    /** @param  array<string, mixed>  $config  config('mybeast-sessions') */
    public function __construct(
        private readonly array $config,
        private readonly ?Container $app = null,
    ) {
        $this->settings = new Settings($config);
    }

    public function enabled(): bool
    {
        return $this->signer() !== null;
    }

    /**
     * For an Inertia shared prop or a Blade layout: where to send recordings and a signed token saying whose they
     * are (ids only) and whether this page may be recorded. Null while the SDK is off.
     *
     * @param  DateTimeInterface|null  $consentAt  when the person said yes to being recorded; null records nothing
     * @param  bool  $mask  false only where the project allows unmasked recordings (never production by default)
     * @return array{url: string, key: string, env: string, token: string, expires_at: int}|null
     */
    public function clientConfig(
        string|int $subject,
        string|int|null $account = null,
        ?DateTimeInterface $consentAt = null,
        bool $mask = true,
        bool $internal = false,
        bool $test = false,
    ): ?array {
        $signer = $this->signer();

        if ($signer === null) {
            return null;
        }

        $now = Date::now()->getTimestamp();
        $claims = new ClientTokenClaims(
            subject: (string) $subject,
            account: $account === null ? null : (string) $account,
            environment: $this->environment(),
            record: $consentAt !== null,
            mask: $mask,
            consentAt: $consentAt === null ? null : (int) $consentAt->format('Uv'),
            internal: $internal,
            test: $test,
            issuedAt: $now,
            expiresAt: $now + $this->ttl(),
        );

        return [
            'url' => $this->settings->url(),
            'key' => (string) $this->config['public_key'],
            'env' => $this->environment(),
            'token' => $signer->sign($claims),
            'expires_at' => $claims->expiresAt,
        ];
    }

    /**
     * Erases every recording of one of the host's people (their account was closed, or they asked): the service
     * deletes the rows and the files and answers how many went. Null while the SDK is off or has no server secret
     * (MYBEAST_SESSIONS_SECRET) - nothing can be asked then. 0 without asking for an id no token could carry (1-64 of
     * letters, digits and `. _ : -`): nothing is kept under it.
     *
     * @throws ErasureFailed when the service does not confirm it: try again (a queued job with retries does)
     */
    public function eraseSubject(string|int $subject): ?int
    {
        return $this->erase('DELETE', 'subjects', $subject, 'recordings');
    }

    /**
     * Erases every recording made in one of the host's accounts (a workspace, team or organisation - the token's
     * `acct`), whoever it was of: the account was closed. The service deletes the rows and the files and answers how
     * many went. Null while off or without a secret; 0 without asking for an id no token could carry.
     *
     * @throws ErasureFailed when the service does not confirm it: try again (a queued job with retries does)
     */
    public function eraseAccount(string|int $account): ?int
    {
        return $this->erase('DELETE', 'accounts', $account, 'recordings', self::ACCOUNT_TIMEOUT);
    }

    /** The journey is kept here: MYBEAST_SESSIONS_JOURNEY=true with the URL and the server secret (or a fake). */
    public function journeyEnabled(): bool
    {
        return $this->container()->make(Journey::class)->enabled();
    }

    /**
     * How the journey knows the host's people - each hook optional (named arguments), the others keep what they
     * were. Call it from a service provider's boot().
     *
     * @param  (Closure(Request): (string|int|null))|null  $subject  the person (default: the signed-in user's id)
     * @param  (Closure(Request): (string|int|null))|null  $account  their account or team (default: none)
     * @param  (Closure(Request): (DateTimeInterface|int|null))|null  $subjectSince  when their account was made, or unix ms (default: the user's created_at)
     * @param  (Closure(string, Request): bool)|null  $allows  false for a person who objected to the journey (default: everyone allows it)
     * @param  (Closure(string): bool)|null  $serverError  whether an error toast stands for a server error rather than a refusal (default: config journey.server_error_toasts)
     * @param  (Closure(Request, Response): ?string)|null  $page  the host's name of the page answered (default: an Inertia answer's component)
     */
    public function journeyUsing(
        ?Closure $subject = null,
        ?Closure $account = null,
        ?Closure $subjectSince = null,
        ?Closure $allows = null,
        ?Closure $serverError = null,
        ?Closure $page = null,
    ): void {
        $this->container()->make(JourneyHooks::class)->using($subject, $account, $subjectSince, $allows, $serverError, $page);
    }

    /**
     * For the host's own "the browser showed a page from its cache" route (a client-side visit the server never saw):
     * one visit step of that page's route for the current request's person and visit. The report itself is no step -
     * also with no route (null: the address was none of the app's pages, routeForAddress()). Never throws.
     *
     * @return bool whether a step was kept (false: the journey is off, no route, a guest, an objector, a skipped route)
     */
    public function pageShown(?string $route, ?string $page = null): bool
    {
        try {
            $app = $this->container();

            return $app->make(Journey::class)->pageShown($app->make('request'), $route, $page);
        } catch (Throwable $e) {
            LogOnce::warning('journey.page', 'MyBeast sessions: a page shown was not kept: '.$e->getMessage(), $e);

            return false;
        }
    }

    /**
     * The name of this app's GET route a browser address belongs to (only its path is read), for the page-shown
     * route: null when the address is none of the app's pages.
     */
    public function routeForAddress(string $address): ?string
    {
        $app = $this->container();

        return JourneyRequest::pageRoute($app->make('router'), $app->make('request'), $address);
    }

    /** Sends the steps waiting in the spool now. @return int how many left it */
    public function flush(): int
    {
        return $this->container()->make(Flusher::class)->flush();
    }

    /** The host's key flows (config `journey.flows`), or null when it names none. @throws InvalidFlows */
    public function keyFlows(): ?KeyFlows
    {
        $class = $this->settings->journeyValue('flows');

        if ($class === null || $class === '') {
            return null;
        }

        $flows = is_string($class) && class_exists($class) ? $this->container()->make($class) : null;

        return $flows instanceof KeyFlows
            ? $flows
            : throw new InvalidFlows(['config mybeast-sessions.journey.flows names no class implementing '.KeyFlows::class.'.']);
    }

    /**
     * Pushes the key flows to the service (PUT /api/v1/flows), replacing the ones there - a deploy step
     * (mybeast:sessions-flows). They are checked first, also while the SDK is off.
     *
     * @return int|null how many flows the service has now; null while off, without a secret, or with no flows named
     *
     * @throws InvalidFlows the definitions do not fit: nothing was sent
     * @throws FlowSyncFailed the service did not take them
     */
    public function syncFlows(?KeyFlows $flows = null): ?int
    {
        $flows ??= $this->keyFlows();

        if ($flows === null) {
            return null;
        }

        $payload = FlowsPayload::build($flows->flows());
        $client = $this->client();

        if ($client === null) {
            return null;
        }

        try {
            $response = $client->request('PUT', '/api/v1/flows', $payload, 30);
        } catch (TransportFailed $e) {
            throw new FlowSyncFailed($e->getMessage(), previous: $e);
        }

        $count = $response->json('flows');

        return $response->successful() && is_int($count)
            ? $count
            : throw new FlowSyncFailed('The sessions service answered '.$response->status().': '.mb_substr($response->body(), 0, 300));
    }

    /**
     * The person objects to the journey: the service erases theirs and keeps none from now on (server steps dropped,
     * a recording's presses and dialogs not added). Say no in Sessions::journeyUsing(allows: …) too - then their
     * steps are not even sent. Null while off or without a secret; 0 without asking for an id no token could carry (no
     * step is ever kept under it).
     *
     * @return int|null how many of their journey steps went
     *
     * @throws ErasureFailed when the service does not confirm it: try again (a queued job with retries does)
     */
    public function objected(string|int $subject): ?int
    {
        return $this->erase('PUT', 'subjects', $subject, 'objection');
    }

    /**
     * The person's objection is lifted: the service keeps their journey again. Does nothing while off or without a
     * secret, or for an id no token could carry.
     *
     * @throws ObjectionNotLifted when the service does not confirm it: try again (a queued job with retries does)
     */
    public function objectionLifted(string|int $subject): void
    {
        $client = $this->client();

        if ($client === null || ! self::takes($subject)) {
            return;
        }

        try {
            $response = $client->request('DELETE', self::path('subjects', $subject, 'objection'));
        } catch (TransportFailed $e) {
            throw new ObjectionNotLifted($e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new ObjectionNotLifted('The sessions service answered '.$response->status().'.');
        }
    }

    /**
     * Erases the person's journey (steps, flow runs, stuck occurrences): their account was closed, or they asked.
     * Null while off or without a secret; 0 without asking for an id no token could carry.
     *
     * @return int|null how many of their journey steps went
     *
     * @throws ErasureFailed when the service does not confirm it: try again (a queued job with retries does)
     */
    public function eraseJourney(string|int $subject): ?int
    {
        return $this->erase('DELETE', 'subjects', $subject, 'journey');
    }

    /**
     * Erases the journey kept in one of the host's accounts (steps, flow runs, stuck occurrences - the steps'
     * `account`), whoever it was about: the account was closed. The people's own records there stay (an objection is
     * theirs). Null while off or without a secret; 0 without asking for an id no token could carry.
     *
     * @return int|null how many journey steps went
     *
     * @throws ErasureFailed when the service does not confirm it: try again (a queued job with retries does)
     */
    public function eraseAccountJourney(string|int $account): ?int
    {
        return $this->erase('DELETE', 'accounts', $account, 'journey', self::ACCOUNT_TIMEOUT);
    }

    /**
     * The masking canary's read-back: every recording of one of the host's people made under a token that said
     * `test: true` (clientConfig(test: true) - a synthetic account), never another of theirs and never anyone else's,
     * newest first; each chunk the JSON text of its rrweb events as the browser sent it (unsealed, unzipped).
     * `started_at` is unix milliseconds; `unreadable_chunks` counts stored chunks that could not be opened (left out
     * of `chunks` - a canary should fail on them). The service writes every export into its audit log. Null while
     * off or without a secret; [] for an id no token could carry (nothing asked).
     *
     * @return list<array{id: string, started_at: int, masked: bool, chunks: list<string>, unreadable_chunks: int}>|null
     *
     * @throws ExportFailed when the service does not hand them over whole - down, refused the secret, or past its caps
     *                      (200 recordings, 32 MiB of events): then nothing is returned, never part of them
     */
    public function testRecordings(string|int $subject): ?array
    {
        $client = $this->client();

        if ($client === null) {
            return null;
        }

        if (! self::takes($subject)) {
            return [];
        }

        try {
            $response = $client->request('GET', self::path('subjects', $subject, 'recordings/test-export'), timeout: self::EXPORT_TIMEOUT, headers: ['Accept-Encoding' => 'gzip']);
        } catch (TransportFailed $e) {
            throw new ExportFailed($e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new ExportFailed('The sessions service answered '.$response->status().'.');
        }

        if ($response->json('truncated') === true) {
            throw new ExportFailed('The sessions service cut the export at its caps (by default 200 recordings, 32 MiB of events): erase the test person\'s recordings and record again.');
        }

        $recordings = $response->json('recordings');

        if ($response->json('truncated') !== false || ! is_array($recordings) || ! array_is_list($recordings) || array_filter($recordings, fn (mixed $recording): bool => ! self::exported($recording)) !== []) {
            throw new ExportFailed('The sessions service did not answer with an export.');
        }

        return $recordings;
    }

    /**
     * Tests: the journey on, its steps kept in memory instead of the spool - nothing written, nothing sent. Assert on
     * the fake it returns.
     */
    public function fake(): JourneyFake
    {
        $fake = new JourneyFake;
        $this->container()->instance(StepSink::class, $fake);

        return $fake;
    }

    public function environment(): string
    {
        return $this->settings->environment();
    }

    /**
     * One of the service's erasing calls: its answer says how many rows went. An id the service never takes (outside
     * the contract's pattern) has nothing kept there: 0, and nothing is asked - the service would answer 404 forever,
     * and a queued job would try again forever.
     *
     * @param  'subjects'|'accounts'  $kind
     *
     * @throws ErasureFailed
     */
    private function erase(string $method, string $kind, string|int $id, string $what, int $timeout = 10): ?int
    {
        $client = $this->client();

        if ($client === null) {
            return null;
        }

        if (! self::takes($id)) {
            return 0;
        }

        try {
            $response = $client->request($method, self::path($kind, $id, $what), timeout: $timeout);
        } catch (TransportFailed $e) {
            throw new ErasureFailed($e->getMessage(), previous: $e);
        }

        $erased = $response->json('erased');

        return $response->successful() && is_int($erased) ? $erased : throw new ErasureFailed('The sessions service answered '.$response->status().'.');
    }

    /** A person or an account the service could keep something under: an id as the contract's tokens carry them. */
    private static function takes(string|int $id): bool
    {
        return preg_match(self::ID, (string) $id) === 1;
    }

    /** @param  'subjects'|'accounts'  $kind */
    private static function path(string $kind, string|int $id, string $what): string
    {
        return '/api/v1/'.$kind.'/'.rawurlencode((string) $id).'/'.$what;
    }

    /** One recording of the export as the service writes it (Recordings\Support\TestExport). */
    private static function exported(mixed $recording): bool
    {
        return is_array($recording)
            && is_string($recording['id'] ?? null)
            && is_int($recording['started_at'] ?? null)
            && is_bool($recording['masked'] ?? null)
            && is_int($recording['unreadable_chunks'] ?? null)
            && is_array($recording['chunks'] ?? null)
            && array_is_list($recording['chunks'])
            && array_filter($recording['chunks'], fn (mixed $chunk): bool => ! is_string($chunk)) === [];
    }

    /** The service, asked with the server secret: null while off or without one. */
    private function client(): ?ServiceClient
    {
        $secret = $this->settings->secret();

        return $secret === null ? null : new ServiceClient($this->settings->url(), $secret);
    }

    private function container(): Container
    {
        return $this->app ?? GlobalContainer::getInstance();
    }

    private function ttl(): int
    {
        return max(60, min(3600, (int) ($this->config['token_ttl'] ?? 1800)));
    }

    private function signer(): ?TokenSigner
    {
        if ($this->resolved) {
            return $this->signer;
        }

        $this->resolved = true;
        $publicKey = (string) ($this->config['public_key'] ?? '');
        $signingKey = (string) ($this->config['signing_key'] ?? '');
        if (! $this->settings->on() || preg_match(self::PUBLIC_KEY, $publicKey) !== 1 || $signingKey === '') {
            return null;
        }

        try {
            $this->signer = new TokenSigner(SigningKey::parse($signingKey), $publicKey);
        } catch (InvalidArgumentException) {
            $this->signer = null;
        }

        return $this->signer;
    }
}
