<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Journey;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use MyBeast\Sessions\Laravel\Support\LogOnce;
use MyBeast\Sessions\Laravel\Support\Settings;

/**
 * Keeps the journey: the steps the middleware makes of requests (RecordJourneyStep) and the pages the host's own
 * route reports the browser showed from its cache (Sessions::pageShown), into the sink - the spool, or a test's fake.
 *
 * Made per use from the config as it is then (cheap), so switching the journey on or off takes effect at once.
 */
final readonly class Journey
{
    /**
     * Never steps: the SDK's own routes (and a host's route named under mybeast.*, such as its page-shown report).
     * Broadcasting's channel sign-in is skipped by its path.
     */
    private const ALWAYS_SKIPPED = ['mybeast.*'];

    /** Request attribute: this request reported a page itself (Sessions::pageShown), so it is no step of its own. */
    public const REPORTED = 'mybeast.sessions.journey.reported';

    public function __construct(
        private Settings $settings,
        private JourneyHooks $hooks,
        private StepSink $sink,
        private bool $faked,
    ) {}

    /** Recording steps: switched on with the URL and the secret, or faked in a test. */
    public function enabled(): bool
    {
        return $this->faked || $this->settings->journey();
    }

    public function hooks(): JourneyHooks
    {
        return $this->hooks;
    }

    /** Routes never recorded (names, `*` wildcards): the SDK's own and config `journey.skip_routes`. */
    public function skips(?string $route): bool
    {
        return $route !== null && Str::is([...self::ALWAYS_SKIPPED, ...$this->settings->journeyList('skip_routes')], $route);
    }

    /** JSON reads that are steps (config `journey.data_reads`): a sheet's or a list's data a key flow counts. */
    public function dataRead(?string $route): bool
    {
        $reads = $this->settings->journeyList('data_reads');

        return $route !== null && $reads !== [] && Str::is($reads, $route);
    }

    /** The flash key of the error toast a refused write shows (config `journey.error_toast`), or none. */
    public function toastKey(): ?string
    {
        $key = $this->settings->journeyValue('error_toast', 'error');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** Whether an error toast stands for a server error: the host's hook, else config `journey.server_error_toasts`. */
    public function serverErrorToast(string $toast): bool
    {
        return $this->hooks->serverError($toast) ?? Str::startsWith($toast, $this->settings->journeyList('server_error_toasts'));
    }

    /**
     * A step of the request's person in its visit, or null when it is nobody's visit (no session, no recording) or
     * the person objects - asked last, so only a request that would be kept costs the host's lookup.
     *
     * @param  list<string>|null  $fields
     */
    public function step(
        Request $request,
        string $subject,
        int $at,
        StepKind $kind,
        ?string $route,
        ?string $method,
        ?int $status = null,
        ?int $durationMs = null,
        ?array $fields = null,
        ?string $page = null,
        bool $dataRead = false,
        ?string $requestId = null,
    ): ?JourneyStep {
        $recording = JourneyRequest::recordingId($request);
        $visit = JourneyRequest::visitKey($request, $recording);

        if ($visit === null || ! $this->hooks->allows($subject, $request)) {
            return null;
        }

        if ($route !== null && JourneyStep::routeName($route) === null) {
            LogOnce::warning('journey.route', "My Beast sessions: the route name \"{$route}\" is not 1-120 letters, digits and . _ : - so its steps go without it.");
        }

        if ($page !== null && JourneyStep::pageName($page) === null) {
            LogOnce::warning('journey.page-name', 'My Beast sessions: a page name is not 1-120 letters, digits and . _ : / - so steps go without it (Sessions::journeyUsing(page: …)).');
        }

        return new JourneyStep(
            subject: $subject,
            account: $this->hooks->account($request),
            subjectSince: $this->hooks->subjectSince($request),
            environment: $this->environment(),
            visit: $visit,
            recording: $recording,
            at: $at,
            kind: $kind,
            route: $route,
            method: $method,
            status: $status,
            durationMs: $durationMs,
            fields: $fields,
            page: $page,
            dataRead: $dataRead,
            request: $requestId,
        );
    }

    /** The header that carries a request's id (config `journey.request_id_header`; its log lines' id), or none. */
    public function requestIdHeader(): ?string
    {
        $header = $this->settings->journeyValue('request_id_header', 'X-Request-Id');

        return is_string($header) && $header !== '' ? $header : null;
    }

    public function record(JourneyStep $step): void
    {
        $this->sink->put($step->toArray());
    }

    /**
     * A page the browser showed without asking the server (a client-side cache, e.g. Inertia's prefetch), as the
     * host's own route reports it: one visit step - the route and the page, no status or time of its own - for the
     * request's person and visit. That report is itself no step, also when it names no page of the app (null).
     *
     * @return bool whether a step was kept
     */
    public function pageShown(Request $request, ?string $route, ?string $page): bool
    {
        $request->attributes->set(self::REPORTED, true);

        if ($route === null || $route === '' || ! $this->enabled() || $this->skips($route)) {
            return false;
        }

        $subject = $this->hooks->subject($request);
        $step = $subject === null ? null : $this->step(
            $request,
            $subject,
            at: (int) Date::now()->format('Uv'),
            kind: StepKind::Visit,
            route: $route,
            method: 'GET',
            page: $page,
        );

        if ($step === null) {
            return false;
        }

        $this->record($step);

        return true;
    }

    public function environment(): string
    {
        return $this->settings->environment();
    }
}
