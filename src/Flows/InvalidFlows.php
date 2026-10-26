<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Flows;

use InvalidArgumentException;

/** The host's key flows do not fit the contract: nothing was sent. Every problem is named. */
final class InvalidFlows extends InvalidArgumentException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct("The key flows do not fit:\n- ".implode("\n- ", $problems));
    }
}
