<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use MyBeast\Sessions\Laravel\ErasureFailed;
use MyBeast\Sessions\Laravel\Facades\Sessions;

/**
 * A closed account (a workspace, team or organisation - the token's `acct`): its recordings and its journey are erased
 * whoever they were about, each a call that answers how many went, null while the SDK is off or has no secret, and
 * throws when the service does not confirm it - so a queued job retries. An id no token could carry is never asked
 * about, here or in any other erase call: the service would answer 404 forever.
 */
final class AccountErasureTest extends JourneyTestCase
{
    public function test_an_accounts_recordings_and_journey_are_erased_with_the_secret(): void
    {
        Http::fake([
            'sessions.example.test/api/v1/accounts/*/recordings' => Http::response(['erased' => 4]),
            'sessions.example.test/api/v1/accounts/*/journey' => Http::response(['erased' => 120]),
        ]);

        $this->assertSame(4, Sessions::eraseAccount('org:7'));
        $this->assertSame(120, Sessions::eraseAccountJourney(7));

        Http::assertSent(fn (ClientRequest $r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://sessions.example.test/api/v1/accounts/org%3A7/recordings'
            && $r->hasHeader('Authorization', 'Bearer '.self::SECRET));
        Http::assertSent(fn (ClientRequest $r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://sessions.example.test/api/v1/accounts/7/journey'
            && $r->hasHeader('Authorization', 'Bearer '.self::SECRET));
        Http::assertSentCount(2);
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
            foreach ([fn () => Sessions::eraseAccount('ws-1'), fn () => Sessions::eraseAccountJourney('ws-1')] as $call) {
                try {
                    $call();
                    $this->fail("Confirmed: {$case}");
                } catch (ErasureFailed) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_an_id_no_token_could_carry_is_never_asked_about(): void
    {
        Http::fake();

        foreach (['user/1', 'ws 1', '', str_repeat('a', 65), 'äiti'] as $id) {
            $this->assertSame(0, Sessions::eraseSubject($id), $id);
            $this->assertSame(0, Sessions::eraseJourney($id), $id);
            $this->assertSame(0, Sessions::objected($id), $id);
            $this->assertSame(0, Sessions::eraseAccount($id), $id);
            $this->assertSame(0, Sessions::eraseAccountJourney($id), $id);
            $this->assertSame([], Sessions::testRecordings($id), $id);
            Sessions::objectionLifted($id);
        }

        Http::assertNothingSent();
    }

    public function test_without_a_secret_nothing_is_asked(): void
    {
        Http::fake();
        config(['mybeast-sessions.secret' => null]);
        $this->forgetSessions(); // The manager reads its config once, at first use.

        $this->assertNull(Sessions::eraseAccount('ws-1'));
        $this->assertNull(Sessions::eraseAccountJourney('ws-1'));
        // Off comes first: an id it would never ask about is still "nothing could be asked".
        $this->assertNull(Sessions::eraseAccount('user/1'));

        Http::assertNothingSent();
    }
}
