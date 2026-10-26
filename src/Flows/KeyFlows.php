<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Flows;

/**
 * The host's key flows - the tasks whose funnels and give-ups the service shows - defined in the host's code. Name the
 * class in config `mybeast-sessions.journey.flows`; `php artisan mybeast:sessions-flows` (a deploy step) pushes them.
 *
 * Changing a flow: keep its slug and its steps' keys (runs and stuck points name them); a new step at the end is
 * safe. A flow left out is retired (its runs are kept).
 */
interface KeyFlows
{
    /** @return list<FlowDefinition> in the order the service shows them */
    public function flows(): array;
}
