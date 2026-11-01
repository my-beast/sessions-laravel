<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Journey;

use MyBeast\Sessions\Laravel\Support\LogOnce;

/** The real sink: into the spool, and remember that something waits (the request's end then sends it when due). */
final class SpoolSink implements StepSink
{
    private bool $pending = false;

    public function __construct(
        private readonly Spool $spool,
    ) {}

    public function put(array $step): void
    {
        if ($this->spool->write($step)) {
            $this->pending = true;

            return;
        }

        LogOnce::warning('journey.spool', 'My Beast sessions: journey steps are dropped - the spool is full or not writable ('.$this->spool->directory().').');
    }

    public function pending(): bool
    {
        return $this->pending;
    }

    public function sent(): void
    {
        $this->pending = false;
    }
}
