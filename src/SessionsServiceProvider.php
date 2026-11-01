<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use MyBeast\Sessions\Laravel\Console\FlowsCommand;
use MyBeast\Sessions\Laravel\Console\FlushCommand;
use MyBeast\Sessions\Laravel\Journey\Journey;
use MyBeast\Sessions\Laravel\Journey\JourneyHooks;
use MyBeast\Sessions\Laravel\Journey\RecordJourneyStep;
use MyBeast\Sessions\Laravel\Journey\Spool;
use MyBeast\Sessions\Laravel\Journey\SpoolSink;
use MyBeast\Sessions\Laravel\Journey\StepSink;
use MyBeast\Sessions\Laravel\Support\LogOnce;
use MyBeast\Sessions\Laravel\Support\Settings;
use MyBeast\Sessions\Laravel\Testing\JourneyFake;
use MyBeast\Sessions\Laravel\Transport\Flusher;
use MyBeast\Sessions\Laravel\Transport\ServiceClient;
use Throwable;

/**
 * Plugs My Beast sessions in: the config, the Sessions manager, and the journey - its middleware at the end of the
 * `web` group (unless config `journey.middleware` is false), its spool sent after each response and job and every
 * minute while it is on. Recording itself happens in the browser.
 */
final class SessionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mybeast-sessions.php', 'mybeast-sessions');

        LogOnce::forget();

        $this->app->singleton(Sessions::class, fn ($app): Sessions => new Sessions((array) $app['config']->get('mybeast-sessions', []), $app));

        // Made per use from the config as it is then: switching the journey on or off takes effect at once.
        $this->app->bind(Settings::class, fn ($app): Settings => new Settings((array) $app['config']->get('mybeast-sessions', [])));
        $this->app->singleton(JourneyHooks::class);
        $this->app->singleton(Spool::class, fn ($app): Spool => new Spool((string) $app->make(Settings::class)->journeyValue('spool_path', storage_path('app/private/mybeast-sessions-spool'))));
        $this->app->singleton(SpoolSink::class);
        $this->app->singleton(StepSink::class, fn ($app): StepSink => $app->make(SpoolSink::class));
        $this->app->bind(Journey::class, function ($app): Journey {
            $sink = $app->make(StepSink::class);

            return new Journey($app->make(Settings::class), $app->make(JourneyHooks::class), $sink, faked: $sink instanceof JourneyFake);
        });
        $this->app->bind(Flusher::class, function ($app): Flusher {
            $settings = $app->make(Settings::class);
            $secret = $settings->secret();

            return new Flusher(
                $app->make(Spool::class),
                $app->make(SpoolSink::class),
                $secret === null ? null : new ServiceClient($settings->url(), $secret),
                (int) $settings->journeyValue('flush_after', 10),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/mybeast-sessions.php' => config_path('mybeast-sessions.php')], 'mybeast-sessions-config');
            $this->commands([FlushCommand::class, FlowsCommand::class]);
        }

        $settings = $this->app->make(Settings::class);

        // Always there when asked for (it does nothing while the journey is off), so a test or a later switch finds it.
        if ($settings->journeyValue('middleware', true) === true) {
            $this->appendToWebGroup();
        }

        if (! $settings->journey()) {
            return;
        }

        // Sent right after the response went out (the person has their page), after each queued job, and every minute.
        $this->app->terminating(fn () => $this->flushQuietly(onlyIfRecorded: true));
        Event::listen([JobProcessed::class, JobFailed::class], fn () => $this->flushQuietly(onlyIfRecorded: false));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Not onOneServer(): each server has its own spool.
            $schedule->command('mybeast:sessions-flush')->everyMinute()->withoutOverlapping();
        });
    }

    private function flushQuietly(bool $onlyIfRecorded): void
    {
        try {
            $this->app->make(Flusher::class)->flushIfDue($onlyIfRecorded);
        } catch (Throwable) {
            // The minutely flush will get it.
        }
    }

    /**
     * RecordJourneyStep joins the end of the `web` group, through the HTTP kernel - not the router only: the kernel
     * writes its own groups into the router when it is first made (after the providers boot in a test, before them for
     * a real request) and would drop a middleware pushed onto the router's group alone. A host without a `web` group
     * adds it where it wants.
     */
    private function appendToWebGroup(): void
    {
        $append = static function (mixed $kernel): void {
            if (! $kernel instanceof HttpKernel) {
                return;
            }

            try {
                $kernel->appendMiddlewareToGroup('web', RecordJourneyStep::class);
            } catch (Throwable) {
                // No `web` group (an API-only host).
            }
        };

        if ($this->app->resolved(HttpKernelContract::class)) {
            $append($this->app->make(HttpKernelContract::class));

            return;
        }

        $this->app->afterResolving(HttpKernelContract::class, $append);
    }
}
