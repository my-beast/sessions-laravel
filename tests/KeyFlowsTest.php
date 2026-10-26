<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use MyBeast\Sessions\Laravel\Facades\Sessions;
use MyBeast\Sessions\Laravel\Flows\FlowDefinition;
use MyBeast\Sessions\Laravel\Flows\FlowsPayload;
use MyBeast\Sessions\Laravel\Flows\FlowStepDefinition;
use MyBeast\Sessions\Laravel\Flows\FlowSyncFailed;
use MyBeast\Sessions\Laravel\Flows\InvalidFlows;

/**
 * The host's key flows: defined in its code (a KeyFlows class named in config), checked here against the contract and
 * what it cannot say, and pushed with the secret (PUT /api/v1/flows) - by a deploy step, mybeast:sessions-flows.
 */
final class KeyFlowsTest extends JourneyTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mybeast-sessions.journey.flows', HostKeyFlows::class);
    }

    public function test_the_hosts_flows_fit_the_contract(): void
    {
        $payload = FlowsPayload::build((new HostKeyFlows)->flows());

        $this->assertSame([], Fixtures::violations($payload, 'key-flows.schema.json'));
        $this->assertSame(['onboarding', 'add-project', 'import'], array_column($payload['flows'], 'slug'));
        $this->assertSame(
            [
                'slug' => 'onboarding',
                'name' => 'First run',
                'give_up_after_hours' => 168,
                'new_account_days' => 7,
                'steps' => [
                    ['key' => 'signed-up', 'label' => 'Signed up', 'kind' => 'start'],
                    ['key' => 'project-saved', 'label' => 'First project saved', 'kind' => 'save', 'routes' => ['projects.store']],
                    ['key' => 'member-invited', 'label' => 'First member invited', 'kind' => 'save', 'routes' => ['invitations.store']],
                ],
            ],
            $payload['flows'][0],
        );
        $this->assertSame([24, null], [$payload['flows'][1]['give_up_after_hours'], $payload['flows'][1]['new_account_days']], 'The defaults.');
    }

    public function test_they_are_pushed_with_the_secret(): void
    {
        Http::fake(['sessions.example.test/api/v1/flows' => Http::response(['flows' => 3])]);

        $this->assertSame(3, Sessions::syncFlows());

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://sessions.example.test/api/v1/flows'
            && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
            && $request->data() === FlowsPayload::build((new HostKeyFlows)->flows())
            && Fixtures::violations($request->data(), 'key-flows.schema.json') === []);
    }

    public function test_flows_that_do_not_fit_are_refused_before_anything_is_sent(): void
    {
        Http::fake();
        $two = fn (): array => [FlowStepDefinition::visit('opened', 'Opened', ['a.create']), FlowStepDefinition::save('saved', 'Saved', ['a.store'])];

        foreach ([
            'a slug is 1-40' => [new FlowDefinition('Add Property', 'Add', $two())],
            'a flow has 2-20 steps' => [new FlowDefinition('one', 'One', [FlowStepDefinition::save('saved', 'Saved', ['a.store'])])],
            'a second step with the key' => [new FlowDefinition('twice', 'Twice', [...$two(), FlowStepDefinition::save('saved', 'Again', ['a.update'])])],
            'a key is 1-40' => [new FlowDefinition('keys', 'Keys', [FlowStepDefinition::visit('Opened!', 'Opened', ['a.create']), FlowStepDefinition::save('saved', 'Saved', ['a.store'])])],
            'a label is 1-80' => [new FlowDefinition('labels', 'Labels', [FlowStepDefinition::visit('opened', ' ', ['a.create']), FlowStepDefinition::save('saved', 'Saved', ['a.store'])])],
            'a name is 1-80' => [new FlowDefinition('named', str_repeat('n', 81), $two())],
            'a route is a route name of 1-120 letters' => [new FlowDefinition('urls', 'URLs', [FlowStepDefinition::visit('opened', 'Opened', ['/projects/create']), FlowStepDefinition::save('saved', 'Saved', ['a.store'])])],
            'names the routes that reach it' => [new FlowDefinition('routeless', 'Routeless', [FlowStepDefinition::visit('opened', 'Opened', []), FlowStepDefinition::save('saved', 'Saved', ['a.store'])])],
            'a start step is only the first step of a milestone flow' => [new FlowDefinition('started', 'Started', [FlowStepDefinition::start('signed-up', 'Signed up'), FlowStepDefinition::save('saved', 'Saved', ['a.store'])])],
            'starts with a start step' => [new FlowDefinition('first-run', 'First run', $two(), newAccountDays: 7)],
            'giveUpAfterHours is 1-720' => [new FlowDefinition('never', 'Never', $two(), giveUpAfterHours: 0)],
            'newAccountDays is 1-90' => [new FlowDefinition('old', 'Old', [FlowStepDefinition::start('signed-up', 'Signed up'), FlowStepDefinition::save('saved', 'Saved', ['a.store'])], newAccountDays: 91)],
            'a second flow with this slug' => [new FlowDefinition('same', 'Same', $two()), new FlowDefinition('same', 'Same again', $two())],
            'is not a' => ['not a flow'],
            'At most 50 key flows' => array_map(fn (int $i): FlowDefinition => new FlowDefinition("flow-{$i}", "Flow {$i}", $two()), range(1, 51)),
        ] as $problem => $flows) {
            try {
                Sessions::syncFlows(new HostKeyFlows($flows));
                $this->fail("Taken: {$problem}");
            } catch (InvalidFlows $e) {
                $this->assertStringContainsString($problem, $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_the_command_pushes_them_and_names_routes_the_app_does_not_have(): void
    {
        Http::fake(['sessions.example.test/api/v1/flows' => Http::response(['flows' => 3])]);

        $this->artisan('mybeast:sessions-flows')
            ->expectsOutputToContain('no route is named projects.store')
            ->doesntExpectOutputToContain('no route is named properties.index')
            ->expectsOutput('The service has 3 key flow(s).')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_the_command_fails_a_deploy_when_the_flows_do_not_fit_or_are_not_taken(): void
    {
        Http::fake(['sessions.example.test/*' => Http::response('down', 503)]);

        $this->artisan('mybeast:sessions-flows')->expectsOutputToContain('503')->assertFailed();

        $this->app->bind(HostKeyFlows::class, fn (): HostKeyFlows => new HostKeyFlows([new FlowDefinition('one', 'One', [])]));
        $this->artisan('mybeast:sessions-flows')->expectsOutputToContain('a flow has 2-20 steps')->assertFailed();

        config(['mybeast-sessions.journey.flows' => \stdClass::class]);
        $this->forgetSessions();
        $this->artisan('mybeast:sessions-flows')->expectsOutputToContain('names no class implementing')->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_the_command_is_safe_while_off_without_flows_or_only_checking(): void
    {
        Http::fake();

        $this->artisan('mybeast:sessions-flows --check')->expectsOutput('3 key flow(s) fit.')->assertSuccessful();

        config(['mybeast-sessions.secret' => null]);
        $this->forgetSessions();
        $this->assertNull(Sessions::syncFlows());
        $this->artisan('mybeast:sessions-flows')->expectsOutputToContain('nothing sent')->assertSuccessful();

        config(['mybeast-sessions.journey.flows' => null]);
        $this->forgetSessions();
        $this->artisan('mybeast:sessions-flows')->expectsOutputToContain('No key flows defined')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_service_that_does_not_take_them_is_an_error(): void
    {
        Http::fake(['sessions.example.test/*' => Http::response(['message' => 'no'], 422)]);

        $this->expectException(FlowSyncFailed::class);
        Sessions::syncFlows();
    }
}
