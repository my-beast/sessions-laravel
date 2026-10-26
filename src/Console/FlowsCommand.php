<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use MyBeast\Sessions\Laravel\Flows\FlowDefinition;
use MyBeast\Sessions\Laravel\Flows\FlowsPayload;
use MyBeast\Sessions\Laravel\Flows\FlowStepDefinition;
use MyBeast\Sessions\Laravel\Flows\FlowSyncFailed;
use MyBeast\Sessions\Laravel\Flows\InvalidFlows;
use MyBeast\Sessions\Laravel\Sessions;

/**
 * A deploy's step: pushes the host's key flows (config `journey.flows`) to the service, replacing the ones there.
 * Definitions that do not fit fail it (every problem named), even while the SDK is off - so a CI run catches them; the
 * service not taking them fails it too. While the SDK is off or names no flows it says so and succeeds.
 */
final class FlowsCommand extends Command
{
    protected $signature = 'mybeast:sessions-flows
        {--check : Only check the definitions, send nothing}';

    protected $description = 'Push the key flows defined in this app to sessions.my-beast.com';

    public function handle(Sessions $sessions, Router $router): int
    {
        try {
            $flows = $sessions->keyFlows();

            if ($flows === null) {
                $this->line('No key flows defined (config mybeast-sessions.journey.flows): nothing to send.');

                return self::SUCCESS;
            }

            $definitions = $flows->flows();
            FlowsPayload::build($definitions);
        } catch (InvalidFlows $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->warnUnknownRoutes($definitions, $router);

        if ($this->option('check')) {
            $this->info(count($definitions).' key flow(s) fit.');

            return self::SUCCESS;
        }

        try {
            $count = $sessions->syncFlows($flows);
        } catch (FlowSyncFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($count === null) {
            $this->line('MyBeast sessions is off (no URL or server secret): '.count($definitions).' key flow(s) fit, nothing sent.');

            return self::SUCCESS;
        }

        $this->info("The service has {$count} key flow(s).");

        return self::SUCCESS;
    }

    /**
     * A route name no route of this app has never reaches its step: said, not refused - a route may live only in
     * some environments, or be on its way.
     *
     * @param  list<FlowDefinition>  $definitions
     */
    private function warnUnknownRoutes(array $definitions, Router $router): void
    {
        $routes = $router->getRoutes();

        if (method_exists($routes, 'refreshNameLookups')) {
            $routes->refreshNameLookups(); // Names given after a route was added (->name()) are looked up only then.
        }

        foreach ($definitions as $flow) {
            foreach ($flow->steps as $step) {
                foreach ($step instanceof FlowStepDefinition ? $step->routes : [] as $route) {
                    if (! $routes->hasNamedRoute($route)) {
                        $this->warn("Flow \"{$flow->slug}\", step \"{$step->key}\": no route is named {$route}.");
                    }
                }
            }
        }
    }
}
