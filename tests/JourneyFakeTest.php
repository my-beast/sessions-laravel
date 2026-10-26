<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use MyBeast\Sessions\Laravel\Facades\Sessions;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Sessions::fake() for a host's own tests: the journey on, whatever the config says, its steps in memory - no spool,
 * no service - with assertions.
 */
final class JourneyFakeTest extends JourneyTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A host's test suite: no service, the journey not switched on.
        $app['config']->set('mybeast-sessions.url', null);
        $app['config']->set('mybeast-sessions.secret', null);
        $app['config']->set('mybeast-sessions.journey.enabled', false);
    }

    public function test_a_fake_keeps_the_steps_in_memory(): void
    {
        Http::fake();
        $this->assertFalse(Sessions::journeyEnabled());

        $journey = Sessions::fake();
        $this->assertTrue(Sessions::journeyEnabled());

        $this->actingAs($this->user)->get('/dashboard', $this->inertia())->assertOk();
        $this->actingAs($this->user)->from('/dashboard')->post('/form', [], $this->inertia());
        $this->actingAs($this->user)->postJson('/journey/page-shown', ['url' => '/properties']);

        $journey->assertRecordedCount(3);
        $journey->assertRecordedRoute('dashboard', 'visit');
        $journey->assertRecordedRoute('test.form', 'refused');
        $journey->assertRecorded(fn (array $step): bool => $step['route'] === 'properties.index' && $step['status'] === null);
        $journey->assertNotRecorded(fn (array $step): bool => $step['route'] === 'journey.page-shown');
        $this->assertSame(['name', 'email'], $journey->recorded(fn (array $step): bool => $step['kind'] === 'refused')[0]['fields']);
        $this->assertSame('properties.index', $journey->last()['route']);
        $this->assertSame([], Fixtures::violations(['steps' => $journey->steps], 'journey-steps.schema.json'));

        $this->assertSame([], $this->spooled(), 'Nothing written.');
        $this->assertSame(0, Sessions::flush());
        Http::assertNothingSent();
    }

    public function test_its_assertions_fail_when_they_should(): void
    {
        $journey = Sessions::fake();
        $journey->assertNothingRecorded();

        $this->expectException(AssertionFailedError::class);
        $journey->assertRecordedRoute('dashboard');
    }
}
