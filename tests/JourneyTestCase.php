<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use MyBeast\Sessions\Laravel\Facades\Sessions;
use MyBeast\Sessions\Laravel\SessionsServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * A host with the journey on: the service's URL and secret, a spool of its own per test, and a few pages and writes
 * of each kind - an Inertia page that also loads as HTML, forms that save, refuse and fail, a forbidden page, JSON
 * reads, an autosave, a page-shown report route and a not-found fallback.
 */
abstract class JourneyTestCase extends TestCase
{
    protected const SECRET = 'sk_abcdefghijklmnop.0123456789abcdefghijABCDEFGHIJ0123456789abc';

    protected const VERSION = 'v1';

    protected string $spool;

    protected GenericUser $user;

    protected function getPackageProviders($app): array
    {
        return [SessionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->spool = sys_get_temp_dir().'/mybeast-sessions-spool-'.bin2hex(random_bytes(4));

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32))); // The web group encrypts cookies.
        $app['config']->set('mybeast-sessions.url', 'https://sessions.example.test/');
        $app['config']->set('mybeast-sessions.secret', self::SECRET);
        $app['config']->set('mybeast-sessions.environment', 'Staging');
        $app['config']->set('mybeast-sessions.journey.enabled', true);
        $app['config']->set('mybeast-sessions.journey.spool_path', $this->spool);
        $app['config']->set('mybeast-sessions.journey.skip_routes', ['drafts.*', 'settings.security']);
        $app['config']->set('mybeast-sessions.journey.data_reads', ['sheets.*']);
        $app['config']->set('mybeast-sessions.journey.server_error_toasts', ['flash.serverError']);
        // Nothing is sent after a test's requests unless a test says so: the spool is what they look at.
        $app['config']->set('mybeast-sessions.journey.flush_after', 3600);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new GenericUser(['id' => 42, 'created_at' => CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC')]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', [...(glob($this->spool.'/*') ?: []), ...(glob($this->spool.'/.*.lock') ?: []), ...array_filter([$this->spool.'/.monthly-limit'], 'is_file')]);
        @rmdir($this->spool);

        parent::tearDown();
    }

    /** @param  Router  $router */
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function (Router $router): void {
            $router->get('/dashboard', function (Request $request) {
                if (! $request->headers->has('X-Inertia')) {
                    return response('<!doctype html><title>Dashboard</title>');
                }

                if ($request->headers->get('X-Inertia-Version') !== self::VERSION) {
                    return response('', 409, ['X-Inertia-Location' => $request->fullUrl()]);
                }

                return response()->json(['component' => 'Dashboard/Index', 'props' => [], 'url' => '/dashboard', 'version' => self::VERSION], 200, ['X-Inertia' => 'true', 'Vary' => 'X-Inertia']);
            })->name('dashboard');

            $router->get('/properties', fn () => response('<!doctype html><title>Properties</title>'))->name('properties.index');
            // An app whose log channel names each request on its answer (mybeast/logs-laravel's AssignRequestId).
            $router->get('/reports', fn () => response('<!doctype html><title>Reports</title>', 200, ['X-Request-Id' => '01JBC4Q8W2V6X3Y9Z5A7B1C3D5']))->name('reports.index');
            $router->get('/properties/{property}/money', fn () => response('<!doctype html><title>Money</title>'))->name('properties.money');
            $router->get('/settings/security', fn () => response('<!doctype html><title>Security</title>'))->name('settings.security');

            $router->post('/save', fn () => back()->with('success', 'flash.saved'))->name('test.save');
            $router->post('/form', function (Request $request) {
                $request->validate(['name' => 'required', 'email' => 'required|email', 'rows.*.amount' => 'required']);

                return back();
            })->name('test.form');
            $router->post('/refuse', fn () => back()->with('error', 'flash.notAllowedHere'))->name('test.refuse');
            $router->post('/fail-toast', fn () => back()->with('error', 'flash.serverError:7F3A9C21'))->name('test.fail-toast');
            $router->post('/fail', fn () => throw new RuntimeException('boom'))->name('test.fail');
            $router->get('/forbidden', fn () => abort(403))->name('test.forbidden');
            $router->get('/odd', fn () => response('<!doctype html>'))->name('admin/odd page');
            $router->get('/unnamed', fn () => response('<!doctype html>'));

            $router->get('/api/todos', fn () => ['todos' => []])->name('test.todos');
            $router->get('/sheets/rent', fn () => ['rows' => []])->name('sheets.rent');
            $router->post('/drafts', fn () => ['saved' => true])->name('drafts.autosave');
            $router->post('/broadcasting/auth', fn () => ['auth' => 'x']);
            $router->get('/mybeast/sessions/ping', fn () => ['ok' => true])->name('mybeast.sessions.ping');

            // The host's own report of a page the browser showed from its cache.
            $router->post('/journey/page-shown', fn (Request $request) => response()->json([
                'kept' => Sessions::pageShown(Sessions::routeForAddress((string) $request->input('url')), $request->input('component')),
            ]))->name('journey.page-shown');

            // The host's not-found page: without a route in the group, a 404 never meets the group's middleware.
            $router->fallback(fn () => abort(404));
        });
    }

    /**
     * The steps waiting in the spool, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    protected function spooled(): array
    {
        $files = glob($this->spool.'/*.json') ?: [];
        sort($files);

        return array_map(fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $files);
    }

    /** @return array<string, mixed> */
    protected function soleStep(): array
    {
        $steps = $this->spooled();
        $this->assertCount(1, $steps, 'One step');

        return $steps[0];
    }

    /**
     * The headers an Inertia visit sends.
     *
     * @param  array<string, string>  $more
     * @return array<string, string>
     */
    protected function inertia(array $more = []): array
    {
        return $more + [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => self::VERSION,
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ];
    }

    /** The config read at first use is made again (the Sessions manager keeps the one it was made with). */
    protected function forgetSessions(): void
    {
        $this->app->forgetInstance(\MyBeast\Sessions\Laravel\Sessions::class);
        Sessions::clearResolvedInstances();
    }
}
