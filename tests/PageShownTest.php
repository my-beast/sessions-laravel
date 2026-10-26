<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use MyBeast\Sessions\Laravel\Facades\Sessions;

/**
 * Sessions::pageShown (the host's own route, POST /journey/page-shown here): the browser reports a page it showed
 * from its cache, which the server never saw. One visit step of the page's route - the host finds it from the
 * address's path with Sessions::routeForAddress, no query value kept - in the request's visit; nothing for an address
 * that is no page of the app or a skipped one, for a guest, an objector, or while the journey is off. The report is
 * itself no step.
 */
final class PageShownTest extends JourneyTestCase
{
    public function test_a_reported_page_is_a_visit_step_of_its_route_and_nothing_of_its_address_is_kept(): void
    {
        $recording = (string) Str::uuid();

        $this->report(['url' => '/properties?search=Aino+Virtanen&page=2', 'component' => 'Property/Index'], ['X-Recording-Id' => $recording])
            ->assertOk()->assertJson(['kept' => true]);

        $step = $this->soleStep();
        $this->assertSame('visit', $step['kind']);
        $this->assertSame('properties.index', $step['route']);
        $this->assertSame('GET', $step['method']);
        $this->assertNull($step['status'], 'The server answered nothing.');
        $this->assertNull($step['duration_ms']);
        $this->assertSame('Property/Index', $step['page']);
        $this->assertSame([$recording, $recording], [$step['visit'], $step['recording']]);
        $this->assertSame('42', $step['subject']);
        $this->assertStringNotContainsString('Aino', json_encode($this->spooled()), 'No query value is kept.');

        // A full address and a page with parameters; without a recording the session names the visit.
        $this->report(['url' => 'http://localhost/properties/0199f5a0-0000-7000-8000-000000000001/money#rent'])->assertJson(['kept' => true]);

        $show = $this->spooled()[1];
        $this->assertSame('properties.money', $show['route']);
        $this->assertNull($show['page']);
        $this->assertMatchesRegularExpression('/^srv-[0-9a-f]{16}$/', $show['visit']);
        $this->assertNull($show['recording']);
        $this->assertSame([], Fixtures::violations(['steps' => $this->spooled()], 'journey-steps.schema.json'));
    }

    public function test_an_address_that_is_no_page_of_the_app_or_is_skipped_keeps_nothing(): void
    {
        foreach ([
            'no such page (the fallback answers it)' => '/no-such-page-here',
            'a write-only address' => '/journey/page-shown',
            'not an address' => 'javascript:alert(1)',
            'too long' => '/properties/'.str_repeat('a', 2048),
            'a skipped page' => '/settings/security',
            'the SDK\'s own' => '/mybeast/sessions/ping',
        ] as $case => $url) {
            $this->report(['url' => $url, 'component' => 'Whatever'])->assertJson(['kept' => false]);
            $this->assertSame([], $this->spooled(), $case);
        }

        // The control: a page of the app is kept.
        $this->report(['url' => '/dashboard'])->assertJson(['kept' => true]);
        $this->assertSame(['dashboard'], array_column($this->spooled(), 'route'));
    }

    public function test_while_the_journey_is_off_nothing_is_kept(): void
    {
        config(['mybeast-sessions.journey.enabled' => false]);

        $this->report(['url' => '/dashboard'])->assertJson(['kept' => false]);

        $this->assertSame([], $this->spooled());
    }

    public function test_a_guest_and_an_objector_report_nothing(): void
    {
        $this->postJson('/journey/page-shown', ['url' => '/dashboard'])->assertJson(['kept' => false]);

        Sessions::journeyUsing(allows: fn (string $subject): bool => $subject !== '42');
        $this->report(['url' => '/dashboard'])->assertJson(['kept' => false]);

        $this->assertSame([], $this->spooled());
    }

    public function test_page_shown_never_throws_at_the_host(): void
    {
        Sessions::journeyUsing(allows: fn () => throw new \RuntimeException('the host broke'));

        $this->report(['url' => '/dashboard'])->assertOk()->assertJson(['kept' => false]);
    }

    public function test_called_outside_a_request_it_keeps_nothing(): void
    {
        $this->app->instance('request', Request::create('/'));

        $this->assertFalse(Sessions::pageShown('dashboard'));
        $this->assertSame([], $this->spooled());
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function report(array $body, array $headers = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/journey/page-shown', $body, $headers);
    }
}
