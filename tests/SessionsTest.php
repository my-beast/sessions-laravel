<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MyBeast\Sessions\Laravel\ErasureFailed;
use MyBeast\Sessions\Laravel\Facades\Sessions;
use MyBeast\Sessions\Laravel\SessionsServiceProvider;
use Orchestra\Testbench\TestCase;

final class SessionsTest extends TestCase
{
    private const SIGNING_KEY = 'sig_abcdefghijklmnop.0123456789abcdef0123456789abcdef0123456';

    protected function getPackageProviders($app): array
    {
        return [SessionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mybeast-sessions', [
            'url' => 'https://sessions.example.test/',
            'public_key' => 'pk_'.str_repeat('a', 24),
            'signing_key' => self::SIGNING_KEY,
            'environment' => 'Staging',
            'token_ttl' => 1800,
        ]);
    }

    public function test_with_the_persons_yes_the_token_lets_the_page_be_recorded_masked(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        $yes = Carbon::createFromTimestampMs(1_799_990_000_123);

        $config = Sessions::clientConfig(subject: 42, account: 'ws-1', consentAt: $yes);

        $this->assertSame('https://sessions.example.test', $config['url']);
        $this->assertSame('staging', $config['env']);
        $this->assertSame(1_800_001_800, $config['expires_at']);

        $claims = $this->claims($config['token']);
        $this->assertSame('42', $claims['sub']);
        $this->assertTrue($claims['rec']);
        $this->assertTrue($claims['mask']);
        $this->assertSame(1_799_990_000_123, $claims['consent_at']);
        $this->assertSame([], Fixtures::violations($claims, 'client-token.schema.json'));
    }

    public function test_without_a_yes_nothing_is_recorded(): void
    {
        $claims = $this->claims(Sessions::clientConfig(subject: 'u-1')['token']);

        $this->assertFalse($claims['rec']);
        $this->assertNull($claims['consent_at']);
        $this->assertSame([], Fixtures::violations($claims, 'client-token.schema.json'));
    }

    public function test_it_is_off_without_complete_config(): void
    {
        foreach ([
            ['url' => null],
            ['url' => 'not a url'],
            ['public_key' => 'pk_short'],
            ['signing_key' => ''],
            ['signing_key' => 'sig_bad'],
            ['enabled' => false],
        ] as $broken) {
            $this->app['config']->set('mybeast-sessions', array_merge((array) $this->app['config']->get('mybeast-sessions'), $broken));
            $this->app->forgetInstance(\MyBeast\Sessions\Laravel\Sessions::class);
            Sessions::clearResolvedInstances();

            $this->assertNull(Sessions::clientConfig(subject: 1), json_encode($broken));
            $this->assertFalse(Sessions::enabled());
            $this->defineEnvironment($this->app);
        }
    }

    public function test_erasing_a_person_asks_the_service_with_the_secret_and_says_how_many_went(): void
    {
        $this->app['config']->set('mybeast-sessions.secret', 'sk_abcdefghijklmnop.'.str_repeat('s', 43));
        Http::fake(['sessions.example.test/*' => Http::response(['erased' => 3])]);

        $this->assertSame(3, Sessions::eraseSubject('org:7.user_1'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://sessions.example.test/api/v1/subjects/org%3A7.user_1/recordings'
            && $r->hasHeader('Authorization', 'Bearer sk_abcdefghijklmnop.'.str_repeat('s', 43)));
    }

    public function test_an_erasure_the_service_does_not_confirm_is_an_error_and_without_a_secret_nothing_is_asked(): void
    {
        Http::fake(['*' => Http::response(['message' => 'refused'], 401)]);
        $this->assertNull(Sessions::eraseSubject(1));
        Http::assertNothingSent();

        $this->app['config']->set('mybeast-sessions.secret', 'sk_abcdefghijklmnop.'.str_repeat('s', 43));
        $this->app->forgetInstance(\MyBeast\Sessions\Laravel\Sessions::class); // the config is read once, at the first use
        Sessions::clearResolvedInstances();
        $this->expectException(ErasureFailed::class);
        Sessions::eraseSubject(1);
    }

    /** @return array<string, mixed> */
    private function claims(string $token): array
    {
        [, $body] = explode('.', $token);

        return (array) JWT::jsonDecode(JWT::urlsafeB64Decode($body));
    }
}
