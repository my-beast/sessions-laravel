<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use MyBeast\Sessions\Laravel\ErasureFailed;
use MyBeast\Sessions\Laravel\Facades\Sessions;
use MyBeast\Sessions\Laravel\ObjectionNotLifted;

/**
 * Every signed-in person can object to the journey: the host's own record says so (Sessions::journeyUsing(allows:)),
 * so their steps are not even sent, and the service is told (Sessions::objected) - it erases theirs and drops any
 * that still come. Lifting it, and erasing a person's journey, are calls too: each answers null while off, and throws
 * when the service does not confirm, so a queued job retries.
 */
final class JourneyObjectionTest extends JourneyTestCase
{
    public function test_an_objector_is_not_followed_and_everyone_else_still_is(): void
    {
        $objectors = ['42'];
        $asked = 0;
        Sessions::journeyUsing(allows: function (string $subject, Request $request) use (&$objectors, &$asked): bool {
            $asked++;

            return ! in_array($subject, $objectors, true);
        });

        $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();
        $this->actingAs($this->user)->from('/dashboard')->post('/save', [], $this->inertia());
        $this->assertSame([], $this->spooled(), 'an objector is not followed');

        $this->actingAs(new GenericUser(['id' => 43]))->get('/dashboard', $this->inertia())->assertOk();
        $this->assertSame(['43'], array_column($this->spooled(), 'subject'));

        // Asked last: a request that would not be kept anyway (a prefetch, a JSON read) costs no lookup.
        $before = $asked;
        $this->actingAs($this->user)->get('/dashboard', $this->inertia(['Purpose' => 'prefetch']));
        $this->actingAs($this->user)->getJson('/api/todos');
        $this->assertSame($before, $asked);

        // Lifted: followed again.
        $objectors = [];
        $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();
        $this->assertSame(['43', '42'], array_column($this->spooled(), 'subject'));
    }

    public function test_an_objection_is_told_to_the_service_which_erases_the_persons_journey(): void
    {
        Http::fake(['sessions.example.test/*' => Http::response(['erased' => 12])]);

        $this->assertSame(12, Sessions::objected('org:42'));

        Http::assertSent(fn (ClientRequest $r): bool => $r->method() === 'PUT'
            && $r->url() === 'https://sessions.example.test/api/v1/subjects/org%3A42/objection'
            && $r->hasHeader('Authorization', 'Bearer '.self::SECRET));
    }

    public function test_lifting_an_objection(): void
    {
        Http::fake(['sessions.example.test/*' => Http::response(null, 204)]);

        Sessions::objectionLifted(42);

        Http::assertSent(fn (ClientRequest $r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://sessions.example.test/api/v1/subjects/42/objection'
            && $r->hasHeader('Authorization', 'Bearer '.self::SECRET));
    }

    public function test_erasing_a_persons_journey(): void
    {
        Http::fake(['sessions.example.test/*' => Http::response(['erased' => 3])]);

        $this->assertSame(3, Sessions::eraseJourney(42));

        Http::assertSent(fn (ClientRequest $r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://sessions.example.test/api/v1/subjects/42/journey'
            && $r->hasHeader('Authorization', 'Bearer '.self::SECRET));
    }

    public function test_what_the_service_does_not_confirm_is_an_error_to_try_again(): void
    {
        $answer = null;
        Http::fake(['sessions.example.test/*' => function (ClientRequest $request) use (&$answer) {
            return $answer($request);
        }]);

        foreach ([
            'down' => fn () => Http::response('down', 503),
            'refused the secret' => fn () => Http::response(['message' => 'no'], 401),
            'no count' => fn () => Http::response(['ok' => true]),
            'no answer' => Http::failedConnection(),
        ] as $case => $answer) {
            foreach ([fn () => Sessions::objected(42), fn () => Sessions::eraseJourney(42)] as $call) {
                try {
                    $call();
                    $this->fail("Confirmed: {$case}");
                } catch (ErasureFailed) {
                    $this->addToAssertionCount(1);
                }
            }

            if ($case === 'no count') {
                continue; // Lifting answers nothing to count.
            }

            try {
                Sessions::objectionLifted(42);
                $this->fail("Lifted: {$case}");
            } catch (ObjectionNotLifted) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_without_a_secret_nothing_is_asked(): void
    {
        Http::fake();
        config(['mybeast-sessions.secret' => null]);
        $this->forgetSessions(); // The manager reads its config once, at first use.

        $this->assertNull(Sessions::objected(42));
        $this->assertNull(Sessions::eraseJourney(42));
        Sessions::objectionLifted(42);

        Http::assertNothingSent();
    }
}
