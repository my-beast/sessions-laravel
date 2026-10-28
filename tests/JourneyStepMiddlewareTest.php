<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MyBeast\Sessions\Laravel\Facades\Sessions;
use MyBeast\Sessions\Laravel\Journey\RecordJourneyStep;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * RecordJourneyStep: one step per page visit or save of a signed-in person, kept after the answer - what it keeps
 * (route, method, status, time, refused field KEYS) and what it leaves out (guests, prefetches, partial reloads, JSON
 * reads, the SDK's own calls, skipped routes) - and that every step fits the contract.
 */
final class JourneyStepMiddlewareTest extends JourneyTestCase
{
    public function test_the_middleware_ends_the_web_group(): void
    {
        $this->get('/properties');

        $web = $this->app['router']->getMiddlewareGroups()['web'];
        $this->assertSame(RecordJourneyStep::class, end($web));
    }

    public function test_an_inertia_visit_is_a_step(): void
    {
        $before = (int) now()->format('Uv');
        $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();

        $step = $this->soleStep();
        $this->assertSame('visit', $step['kind']);
        $this->assertSame('dashboard', $step['route']);
        $this->assertSame('GET', $step['method']);
        $this->assertSame(200, $step['status']);
        $this->assertSame('Dashboard/Index', $step['page'], 'An Inertia answer names its page.');
        $this->assertSame('42', $step['subject']);
        $this->assertNull($step['account'], 'No account unless the host says.');
        $this->assertSame(1_790_856_000_000, $step['subject_since'], 'The user\'s created_at.');
        $this->assertSame('staging', $step['environment']);
        $this->assertMatchesRegularExpression('/^srv-[0-9a-f]{16}$/', $step['visit']);
        $this->assertNull($step['recording']);
        $this->assertNull($step['fields']);
        $this->assertFalse($step['data_read']);
        $this->assertIsInt($step['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $step['duration_ms']);
        $this->assertGreaterThanOrEqual($before, $step['at']);
        $this->assertLessThan(2000, abs($step['at'] - (int) now()->format('Uv')));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $step['id']);
    }

    public function test_a_step_names_its_request_as_its_log_lines_do(): void
    {
        // The answer's id (the app's log channel put it there), else the request's own (a proxy's).
        $this->actingAs($this->user)->get('/reports', ['Accept' => 'text/html'])->assertOk();
        $this->actingAs($this->user)->get('/properties', ['Accept' => 'text/html', 'X-Request-Id' => 'lb-7f3a9c21'])->assertOk();
        // One that is not an id, and none at all: the step goes without - a service from before it refuses the field.
        $this->actingAs($this->user)->get('/properties', ['Accept' => 'text/html', 'X-Request-Id' => 'not an id'])->assertOk();
        $this->actingAs($this->user)->get('/dashboard', ['Accept' => 'text/html'])->assertOk();

        [$answered, $proxied, $odd, $none] = $this->spooled();
        $this->assertSame('01JBC4Q8W2V6X3Y9Z5A7B1C3D5', $answered['request']);
        $this->assertSame('lb-7f3a9c21', $proxied['request']);
        $this->assertArrayNotHasKey('request', $odd);
        $this->assertArrayNotHasKey('request', $none);
    }

    #[DefineEnvironment('withoutRequestIds')]
    public function test_a_host_may_name_no_request_header(): void
    {
        $this->actingAs($this->user)->get('/reports', ['Accept' => 'text/html'])->assertOk();

        $this->assertArrayNotHasKey('request', $this->soleStep());
    }

    protected function withoutRequestIds($app): void
    {
        $app['config']->set('mybeast-sessions.journey.request_id_header', null);
    }

    public function test_a_full_page_load_is_a_step_and_the_session_names_its_visit(): void
    {
        $this->actingAs($this->user)->get('/dashboard', ['Accept' => 'text/html'])->assertOk();

        $step = $this->soleStep();
        $this->assertSame('visit', $step['kind']);
        $this->assertSame('dashboard', $step['route']);
        $this->assertNull($step['page']);
        $this->assertSame('srv-'.substr(hash('sha256', session()->getId()), 0, 16), $step['visit'], 'The session, hashed.');
    }

    public function test_a_save_is_a_step(): void
    {
        $this->actingAs($this->user)->from('/dashboard')->post('/save', [], $this->inertia())->assertRedirect('/dashboard');

        $step = $this->soleStep();
        $this->assertSame('save', $step['kind']);
        $this->assertSame('test.save', $step['route']);
        $this->assertSame('POST', $step['method']);
        $this->assertContains($step['status'], [302, 303]);
        $this->assertNull($step['fields']);
    }

    public function test_a_refused_form_keeps_the_keys_of_its_errors_and_nothing_it_was_sent(): void
    {
        $this->actingAs($this->user)->from('/dashboard')->post('/form', [
            'email' => 'not-an-address-secret',
            'rows' => [['amount' => '']],
        ], $this->inertia())->assertRedirect('/dashboard');

        $step = $this->soleStep();
        $this->assertSame('refused', $step['kind']);
        $this->assertSame(['name', 'email', 'rows.0.amount'], $step['fields']);

        $kept = json_encode($this->spooled());
        $this->assertStringNotContainsString('secret', $kept, 'No value is kept.');
        $this->assertStringNotContainsString('required', $kept, 'No message is kept.');

        // The same form sent as JSON (an XHR call): its 422 answer's error keys.
        $this->actingAs($this->user)->postJson('/form', ['name' => 'Aino'])->assertStatus(422);

        $json = $this->spooled()[1];
        $this->assertSame('refused', $json['kind']);
        $this->assertSame(422, $json['status']);
        $this->assertSame(['email'], $json['fields']);

        // The form's errors were that request's: the next save is not refused by them.
        $this->actingAs($this->user)->from('/dashboard')->post('/save', [], $this->inertia());
        $this->assertSame('save', $this->spooled()[2]['kind']);
    }

    public function test_a_refusal_told_with_the_error_toast_is_a_refusal(): void
    {
        $this->actingAs($this->user)->from('/dashboard')->post('/refuse', [], $this->inertia())->assertRedirect('/dashboard');

        $step = $this->soleStep();
        $this->assertSame('refused', $step['kind']);
        $this->assertNull($step['fields']);
    }

    public function test_a_server_error_is_an_error_also_when_the_host_turns_it_into_a_toast(): void
    {
        $this->actingAs($this->user)->post('/fail')->assertStatus(500);

        $step = $this->soleStep();
        $this->assertSame('error', $step['kind']);
        $this->assertSame(500, $step['status']);

        // The host sends an Inertia request back to its page with "it did not go through" (config server_error_toasts).
        $this->actingAs($this->user)->from('/dashboard')->post('/fail-toast', [], $this->inertia())->assertRedirect('/dashboard');

        $toast = $this->spooled()[1];
        $this->assertSame('error', $toast['kind']);
        $this->assertContains($toast['status'], [302, 303]);

        // The host's own word on its toasts, when it gives one.
        Sessions::journeyUsing(serverError: fn (string $toast): bool => $toast === 'flash.notAllowedHere');
        $this->actingAs($this->user)->from('/dashboard')->post('/refuse', [], $this->inertia());
        $this->assertSame('error', $this->spooled()[2]['kind']);
    }

    public function test_not_allowed_and_not_found_are_blocked(): void
    {
        $this->actingAs($this->user)->get('/forbidden', $this->inertia())->assertForbidden();
        $this->actingAs($this->user)->get('/no-such-page-here', $this->inertia())->assertNotFound();

        $steps = $this->spooled();
        $this->assertSame(['blocked', 'blocked'], array_column($steps, 'kind'));
        $this->assertSame([403, 404], array_column($steps, 'status'));
        $this->assertSame('test.forbidden', $steps[0]['route']);
        $this->assertNull($steps[1]['route'], 'No route, no name.');
    }

    public function test_a_guest_is_never_followed(): void
    {
        $this->get('/dashboard', $this->inertia())->assertOk();
        $this->from('/dashboard')->post('/save')->assertRedirect();

        $this->assertSame([], $this->spooled());
        $this->assertControlVisitIsKept();
    }

    public function test_prefetches_partial_reloads_version_checks_and_json_reads_are_no_steps(): void
    {
        $this->actingAs($this->user);

        $this->get('/dashboard', $this->inertia(['Purpose' => 'prefetch']));
        $this->get('/dashboard', ['Sec-Purpose' => 'prefetch;prerender', 'Accept' => 'text/html']);
        $this->get('/dashboard', $this->inertia(['X-Inertia-Partial-Component' => 'Dashboard/Index', 'X-Inertia-Partial-Data' => 'todos']));
        $this->get('/dashboard', $this->inertia(['X-Inertia-Partial-Component' => 'Dashboard/Index', 'X-Inertia-Partial-Except' => 'todos']));
        $this->get('/dashboard', $this->inertia(['X-Inertia-Version' => 'stale']))->assertStatus(409);
        $this->getJson('/api/todos')->assertOk();
        $this->call('HEAD', '/dashboard');
        $this->call('OPTIONS', '/dashboard');

        $this->assertSame([], $this->spooled());
        $this->assertControlVisitIsKept();
    }

    public function test_the_sdks_own_calls_broadcasting_and_skipped_routes_are_no_steps(): void
    {
        $this->actingAs($this->user);

        $this->getJson('/mybeast/sessions/ping')->assertOk();
        $this->postJson('/broadcasting/auth')->assertOk();
        $this->postJson('/drafts', ['payload' => 'typing…'])->assertOk(); // drafts.* - an autosave
        $this->get('/settings/security', ['Accept' => 'text/html'])->assertOk();

        $this->assertSame([], $this->spooled());
        $this->assertControlVisitIsKept();
    }

    public function test_a_recording_names_the_visit(): void
    {
        $id = (string) Str::uuid();

        $this->actingAs($this->user)->get('/dashboard', $this->inertia(['X-Recording-Id' => strtoupper($id)]))->assertOk();
        $this->actingAs($this->user)->get('/dashboard', $this->inertia(['X-Recording-Id' => 'not-a-recording']))->assertOk();

        [$recorded, $forged] = $this->spooled();
        $this->assertSame($id, $recorded['visit']);
        $this->assertSame($id, $recorded['recording']);
        $this->assertStringStartsWith('srv-', $forged['visit']);
        $this->assertNull($forged['recording']);
    }

    public function test_a_data_read_that_answers_json_is_a_visit(): void
    {
        $this->actingAs($this->user)->getJson('/sheets/rent')->assertOk();

        $step = $this->soleStep();
        $this->assertSame('visit', $step['kind']);
        $this->assertSame('sheets.rent', $step['route']);
        $this->assertTrue($step['data_read']);
    }

    public function test_the_hosts_hooks_name_the_person_their_account_and_when_they_joined(): void
    {
        Sessions::journeyUsing(
            subject: fn (Request $request): ?string => $request->user() === null ? null : 'user:'.$request->user()->getAuthIdentifier(),
            account: fn (Request $request): int => 7,
            subjectSince: fn (Request $request): int => 1_700_000_000_000,
            page: fn (Request $request, $response): string => 'Host/'.$request->route()?->getName(),
        );

        $this->actingAs($this->user)->get('/properties', ['Accept' => 'text/html'])->assertOk();

        $step = $this->soleStep();
        $this->assertSame(['user:42', '7', 1_700_000_000_000, 'Host/properties.index'], [$step['subject'], $step['account'], $step['subject_since'], $step['page']]);
    }

    public function test_an_id_the_service_would_refuse_is_never_sent(): void
    {
        Log::spy();

        // An e-mail is no id: the step is not kept (and it is said once).
        $this->actingAs(new GenericUser(['id' => 'jane@example.com']))->get('/properties', ['Accept' => 'text/html'])->assertOk();
        $this->actingAs(new GenericUser(['id' => 'jane@example.com']))->get('/properties', ['Accept' => 'text/html'])->assertOk();
        $this->assertSame([], $this->spooled());
        Log::shouldHaveReceived('warning')->once();

        // A malformed account: the step is kept without it.
        Sessions::journeyUsing(account: fn (): string => 'with space');
        $this->actingAs($this->user)->get('/properties', ['Accept' => 'text/html'])->assertOk();
        $this->assertNull($this->soleStep()['account']);
    }

    public function test_switched_off_or_without_a_secret_nothing_is_kept(): void
    {
        foreach ([
            ['mybeast-sessions.journey.enabled', false],
            ['mybeast-sessions.secret', null],
            ['mybeast-sessions.url', 'not a url'],
            ['mybeast-sessions.enabled', false],
            ['mybeast-sessions.environment', 'Not An Environment'],
        ] as [$key, $value]) {
            $was = config($key);
            config([$key => $value]);

            $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();
            $this->actingAs($this->user)->from('/dashboard')->post('/save', [], $this->inertia());
            $this->assertSame([], $this->spooled(), $key);
            $this->assertFalse(Sessions::journeyEnabled(), $key);

            config([$key => $was]);
        }

        $this->assertControlVisitIsKept();
    }

    public function test_a_step_that_cannot_be_kept_never_breaks_the_request(): void
    {
        Log::spy();
        // The spool's folder cannot be made: a file stands where it should be.
        @mkdir(dirname($this->spool), 0777, true);
        file_put_contents($this->spool, 'in the way');

        try {
            $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();
            $this->actingAs($this->user)->from('/dashboard')->post('/save', [], $this->inertia())->assertRedirect('/dashboard');
            Log::shouldHaveReceived('warning')->once();

            // A hook that throws: swallowed too.
            Sessions::journeyUsing(account: fn () => throw new \RuntimeException('the host broke'));
            $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();
        } finally {
            unlink($this->spool);
        }
    }

    public function test_listed_twice_the_middleware_keeps_one_step(): void
    {
        // A host that also made it global: it runs (and terminates) once as global and once in the `web` group.
        $this->app->make(HttpKernel::class)->pushMiddleware(RecordJourneyStep::class);

        $this->actingAs($this->user)->get('/properties', ['Accept' => 'text/html'])->assertOk();

        $this->assertSame('properties.index', $this->soleStep()['route']);
    }

    public function test_every_step_fits_the_contract(): void
    {
        Sessions::journeyUsing(account: fn (): string => 'ws-1', page: fn (): string => str_repeat('P', 300));
        $this->actingAs($this->user);

        $this->get('/dashboard', $this->inertia(['X-Recording-Id' => (string) Str::uuid()]));
        $this->get('/dashboard', ['Accept' => 'text/html']);
        $this->from('/dashboard')->post('/save', [], $this->inertia());
        $this->from('/dashboard')->post('/form', ['rows' => array_fill(0, 30, ['amount' => ''])], $this->inertia());
        $this->postJson('/form', []);
        $this->from('/dashboard')->post('/refuse', [], $this->inertia());
        $this->from('/dashboard')->post('/fail-toast', [], $this->inertia());
        $this->post('/fail');
        $this->get('/forbidden', $this->inertia());
        $this->get('/no-such-page-here', $this->inertia());
        $this->getJson('/sheets/rent');
        $this->postJson('/journey/page-shown', ['url' => '/properties/12/money?tab=rent', 'component' => 'Property::Money/Show']);

        $steps = $this->spooled();
        $this->assertCount(12, $steps);
        $this->assertSame(['visit', 'save', 'refused', 'error', 'blocked'], array_values(array_unique(array_column($steps, 'kind'))));
        $this->assertCount(20, $steps[3]['fields'], 'At most 20 refused keys.');
        $this->assertSame('Property::Money/Show', $steps[11]['page']);
        $this->assertSame([], Fixtures::violations(['steps' => $steps], 'journey-steps.schema.json'));
    }

    public function test_a_route_or_page_name_the_service_would_refuse_goes_as_null(): void
    {
        Log::spy();
        Sessions::journeyUsing(page: fn (Request $request): string => match ($request->path()) {
            'odd' => 'A sentence, not a page',
            'unnamed' => str_repeat('P', 121),
            default => 'Projects/Show',
        });
        $this->actingAs($this->user);

        $this->get('/odd', ['Accept' => 'text/html'])->assertOk();          // a name with a slash and a space
        $this->get('/unnamed', ['Accept' => 'text/html'])->assertOk();      // no name: never its URL
        $this->get('/properties', ['Accept' => 'text/html'])->assertOk();   // the control

        $steps = $this->spooled();
        $this->assertSame([null, null, 'properties.index'], array_column($steps, 'route'));
        $this->assertSame([null, null, 'Projects/Show'], array_column($steps, 'page'));
        $this->assertSame([], Fixtures::violations(['steps' => $steps], 'journey-steps.schema.json'));
        Log::shouldHaveReceived('warning')->twice(); // once for the route, once for the page - not per step
    }

    #[DefineEnvironment('withoutTheMiddleware')]
    public function test_the_middleware_is_not_added_when_the_host_adds_it_itself(): void
    {
        $this->get('/properties');

        $this->assertNotContains(RecordJourneyStep::class, $this->app['router']->getMiddlewareGroups()['web']);
    }

    protected function withoutTheMiddleware($app): void
    {
        $app['config']->set('mybeast-sessions.journey.middleware', false);
    }

    /** The control: the same test's plain Inertia visit is kept, so the "nothing" above was the rule's doing. */
    private function assertControlVisitIsKept(): void
    {
        $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();

        $this->assertSame(['dashboard'], array_column($this->spooled(), 'route'));
    }
}
