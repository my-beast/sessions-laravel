<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MyBeast\Sessions\Laravel\Facades\Sessions;
use MyBeast\Sessions\Laravel\Journey\JourneyStep;
use MyBeast\Sessions\Laravel\Journey\Spool;
use MyBeast\Sessions\Laravel\Journey\StepKind;

/**
 * The spool to the service (POST /v1/steps): gzip, with the secret, in batches of at most 500. A 202 takes them (a
 * step it refused is dropped and said), a 413 halves the batch, no answer or a 5xx keeps them for later. Sent after a
 * response once due, after a queued job, every minute, and by the command.
 */
final class FlushTest extends JourneyTestCase
{
    private const TAKEN = ['accepted' => 1, 'dropped' => 0, 'refused' => []];

    public function test_the_steps_go_out_gzip_with_the_secret_and_fit_the_contract(): void
    {
        $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();
        $this->actingAs($this->user)->from('/dashboard')->post('/form', [], $this->inertia());
        $this->assertCount(2, $this->spooled());
        Http::fake(['sessions.example.test/v1/steps' => Http::response(['accepted' => 2, 'dropped' => 0, 'refused' => []], 202)]);

        $this->assertSame(2, Sessions::flush());

        $this->assertSame([], $this->spooled());
        Http::assertSent(function (ClientRequest $request): bool {
            $body = json_decode((string) gzdecode($request->body()), true);

            return $request->method() === 'POST'
                && $request->url() === 'https://sessions.example.test/v1/steps'
                && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
                && $request->hasHeader('Content-Encoding', 'gzip')
                && array_column($body['steps'], 'kind') === ['visit', 'refused']
                && Fixtures::violations($body, 'journey-steps.schema.json') === [];
        });
    }

    public function test_batches_hold_at_most_500_steps(): void
    {
        $this->spoolSteps(501);
        Http::fake(['sessions.example.test/*' => Http::response(self::TAKEN, 202)]);

        $this->assertSame(501, Sessions::flush());

        $this->assertSame([500, 1], $this->batchSizes());
        $this->assertSame([], $this->spooled());
    }

    public function test_a_step_the_service_refused_is_dropped_and_said(): void
    {
        Log::spy();
        $this->spoolSteps(3);
        Http::fake(['sessions.example.test/*' => Http::response(['accepted' => 2, 'dropped' => 0, 'refused' => [['index' => 1, 'reason' => 'subject: does not match']]], 202)]);

        $this->assertSame(3, Sessions::flush());

        $this->assertSame([], $this->spooled(), 'It will never be taken: it does not wait.');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'refused 1 journey step')
            && $context['refused'] === [['index' => 1, 'reason' => 'subject: does not match', 'kind' => 'visit', 'route' => 'dashboard']])->once();
    }

    public function test_past_the_months_limit_steps_are_dropped_and_said_once_a_month(): void
    {
        Log::spy();
        $over = fn (int ...$indexes): array => ['accepted' => 0, 'dropped' => 0, 'refused' => array_map(fn (int $i): array => ['index' => $i, 'reason' => 'monthly-limit'], $indexes)];
        Http::fakeSequence('sessions.example.test/*')->push($over(0, 1, 2), 202)->push($over(0, 1), 202)->push(self::TAKEN, 202);

        $this->spoolSteps(3);
        $this->assertSame(3, Sessions::flush());
        $this->spoolSteps(2);
        $this->assertSame(2, Sessions::flush());

        // Dropped, not kept: the service would refuse them again until the 1st - and said once, not on every flush.
        $this->assertSame([], $this->spooled());
        Log::shouldHaveReceived('warning')->with("My Beast sessions: the project has used up this month's journey steps; the service drops the rest until the 1st (UTC).", ['dropped' => 3])->once();
        Log::shouldNotHaveReceived('warning', fn (string $message): bool => str_contains($message, 'journey step(s); they were dropped'));

        $this->spoolSteps(1);
        $this->assertSame(1, Sessions::flush());
        Log::shouldHaveReceived('info')->with('My Beast sessions: the service takes journey steps again.')->once();
    }

    public function test_too_big_halves_the_batch_and_one_step_too_big_is_dropped(): void
    {
        $this->spoolSteps(4);
        Http::fake(['sessions.example.test/*' => Http::sequence()
            ->push('too big', 413)
            ->push(self::TAKEN, 202)
            ->push(self::TAKEN, 202)
            ->push('too big', 413)]);

        $this->assertSame(4, Sessions::flush());
        $this->assertSame([4, 2, 2], $this->batchSizes());

        // One step alone too big can never go.
        $this->spoolSteps(1);
        $this->assertSame(1, Sessions::flush());
        $this->assertSame([], $this->spooled());
    }

    public function test_while_the_service_is_away_the_steps_wait(): void
    {
        $this->spoolSteps(2);
        $answer = null;
        Http::fake(['sessions.example.test/*' => function (ClientRequest $request) use (&$answer) {
            return $answer($request);
        }]);

        foreach ([
            'down' => fn () => Http::response('down', 503),
            'failing' => fn () => Http::response('oops', 500),
            'busy' => fn () => Http::response('slow down', 429),
            'a wrong secret' => fn () => Http::response(['message' => 'no'], 401),
            'no answer' => Http::failedConnection(),
        ] as $case => $answer) {
            $this->assertSame(0, Sessions::flush(), $case);
            $this->assertCount(2, $this->spooled(), $case);
        }
    }

    public function test_a_body_the_service_calls_no_batch_is_dropped(): void
    {
        $this->spoolSteps(2);
        Http::fake(['sessions.example.test/*' => Http::response(['message' => 'not a batch'], 422)]);

        $this->assertSame(2, Sessions::flush());
        $this->assertSame([], $this->spooled(), 'Sent again it would be refused again.');
    }

    public function test_the_response_sends_the_spool_once_it_is_due(): void
    {
        Http::fake(['sessions.example.test/*' => Http::response(self::TAKEN, 202)]);

        // Not due yet (the tests' flush_after is an hour): the step waits.
        $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();
        Http::assertNothingSent();
        $this->assertCount(1, $this->spooled());

        // Due: the next request that keeps a step takes everything along.
        config(['mybeast-sessions.journey.flush_after' => 0]);
        $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();

        $this->assertSame([2], $this->batchSizes());
        $this->assertSame([], $this->spooled());
    }

    public function test_after_a_queued_job_the_spool_goes_when_due(): void
    {
        Http::fake(['sessions.example.test/*' => Http::response(self::TAKEN, 202)]);
        $this->spoolSteps(1);
        config(['mybeast-sessions.journey.flush_after' => 0]);

        event(new JobProcessed('sync', $this->createStub(Job::class)));

        $this->assertSame([1], $this->batchSizes());
    }

    public function test_one_drain_at_a_time(): void
    {
        $this->spoolSteps(1);
        Http::fake();
        $lock = fopen($this->spool.'/.drain.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            $this->assertSame(0, Sessions::flush());
            Http::assertNothingSent();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_the_command_sends_and_is_safe_while_off(): void
    {
        $this->spoolSteps(2);
        Http::fake(['sessions.example.test/*' => Http::response(self::TAKEN, 202)]);

        $this->artisan('mybeast:sessions-flush')->expectsOutput('Sent 2 journey step(s).')->assertSuccessful();

        config(['mybeast-sessions.secret' => null]);
        $this->artisan('mybeast:sessions-flush')->expectsOutputToContain('off')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_every_minute_on_the_schedule(): void
    {
        $flush = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($event): bool => str_contains((string) $event->command, 'mybeast:sessions-flush'),
        ));

        $this->assertCount(1, $flush);
        $this->assertSame('* * * * *', $flush[0]->expression);
    }

    private function spoolSteps(int $count): void
    {
        $spool = $this->app->make(Spool::class);

        for ($i = 0; $i < $count; $i++) {
            $spool->write((new JourneyStep(
                subject: '42',
                account: null,
                subjectSince: null,
                environment: 'staging',
                visit: 'srv-0123456789abcdef',
                recording: null,
                at: 1_790_000_000_000 + $i,
                kind: StepKind::Visit,
                route: 'dashboard',
                method: 'GET',
                status: 200,
                durationMs: 5,
            ))->toArray());
        }
    }

    /** @return list<int> how many steps each POST /v1/steps carried */
    private function batchSizes(): array
    {
        return array_values(array_map(
            fn (array $sent): int => count(json_decode((string) gzdecode($sent[0]->body()), true)['steps']),
            Http::recorded(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/v1/steps'))->all(),
        ));
    }
}
