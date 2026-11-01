<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Console;

use Illuminate\Console\Command;
use MyBeast\Sessions\Laravel\Transport\Flusher;

/**
 * Every minute (registered on the scheduler while the journey is on): whatever a request or a job did not send.
 * Safe at any time, a deploy included - while the SDK is off it says so and succeeds.
 */
final class FlushCommand extends Command
{
    protected $signature = 'mybeast:sessions-flush';

    protected $description = 'Send the journey steps that wait in the spool to My Beast Sessions';

    public function handle(Flusher $flusher): int
    {
        if (! $flusher->sending()) {
            $this->line('My Beast sessions is off (no URL or server secret): nothing to send.');

            return self::SUCCESS;
        }

        $this->info('Sent '.$flusher->flush().' journey step(s).');

        return self::SUCCESS;
    }
}
