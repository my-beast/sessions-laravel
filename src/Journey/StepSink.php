<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Journey;

/** Where a recorded step goes: the spool (and on to the service), or a fake's memory in tests. */
interface StepSink
{
    /** @param  array<string, mixed>  $step  one item of schemas/v1/journey-steps.schema.json `steps` */
    public function put(array $step): void;
}
