<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Transport;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use MyBeast\Sessions\Laravel\Journey\Spool;
use MyBeast\Sessions\Laravel\Journey\SpoolSink;

/**
 * Sends the spool to the service (POST /v1/steps): after a response that recorded a step, after each queued job, and
 * every minute (mybeast:sessions-flush) for whatever is left.
 *
 * Unlike errors, a step comes with nearly every request, so a response does not send its own step at once: it sends
 * the spool when the oldest step has waited `journey.flush_after` seconds or a full batch waits - one request then
 * carries many people's steps, and the others pass by. A request's or a job's try is short (it must never hold a
 * worker for long); the minutely run takes its time.
 */
final readonly class Flusher
{
    /** The most steps the service takes in one batch. */
    public const BATCH = 500;

    public function __construct(
        private Spool $spool,
        private SpoolSink $sink,
        private ?ServiceClient $client,
        private int $flushAfter,
    ) {}

    public function sending(): bool
    {
        return $this->client !== null;
    }

    /**
     * After a response (only when it recorded a step - a guest's request does not even look) or a job (it looks:
     * a worker serves no one, so it is a good place to send the web's steps). Sends when the spool is due.
     */
    public function flushIfDue(bool $onlyIfRecorded): void
    {
        if ($this->client === null || ($onlyIfRecorded && ! $this->sink->pending())) {
            return;
        }

        if ($this->spool->due($this->flushAfter, self::BATCH)) {
            $this->flush(timeout: 3, maxBatches: 2);
        }
    }

    /** @return int how many steps left the spool (taken, or refused for good) */
    public function flush(int $timeout = 15, int $maxBatches = 40): int
    {
        if ($this->client === null) {
            return 0;
        }

        $gone = $this->spool->drain(fn (array $steps): Delivery => $this->send($steps, $timeout), self::BATCH, $maxBatches);

        if ($this->spool->count() === 0) {
            $this->sink->sent();
        }

        return $gone;
    }

    /** @param  list<array<string, mixed>>  $steps */
    private function send(array $steps, int $timeout): Delivery
    {
        try {
            $response = $this->client->sendSteps($steps, $timeout);
        } catch (TransportFailed $e) {
            Log::warning('MyBeast sessions: journey steps wait in the spool', ['reason' => $e->getMessage()]);

            return Delivery::Later;
        }

        if ($response->successful()) {
            $this->refused($response, $steps);

            return Delivery::Taken;
        }

        if ($response->status() === 413) {
            if (count($steps) === 1) {
                Log::warning('MyBeast sessions: a journey step too big for the service was dropped', ['route' => $steps[0]['route'] ?? null]);
            }

            return Delivery::TooBig;
        }

        if ($response->status() === 422) {
            // "Not a batch": the body itself is wrong, so sending it again would be refused again.
            Log::warning('MyBeast sessions: the service refused a batch of journey steps as no batch; it was dropped', ['steps' => count($steps), 'answer' => mb_substr($response->body(), 0, 300)]);

            return Delivery::Taken;
        }

        Log::warning('MyBeast sessions: journey steps wait in the spool', ['status' => $response->status()]);

        return Delivery::Later;
    }

    /**
     * A step the service refused never fits (its index and the reason are named): it is dropped with the batch, and
     * said in the log with its route and kind - nothing of the person. Those refused because the project's month is
     * used up are said once a month instead (monthLimit()).
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private function refused(Response $response, array $steps): void
    {
        $all = $response->json('refused');
        $all = is_array($all) ? $all : [];
        $refused = array_values(array_filter($all, fn (mixed $item): bool => ! is_array($item) || ($item['reason'] ?? null) !== 'monthly-limit'));
        $this->monthLimit(count($all) - count($refused), (int) $response->json('accepted'));

        if ($refused === []) {
            return;
        }

        $named = [];

        foreach (array_slice($refused, 0, 10) as $item) {
            $index = is_array($item) && is_int($item['index'] ?? null) ? $item['index'] : null;
            $named[] = [
                'index' => $index,
                'reason' => is_array($item) ? (string) ($item['reason'] ?? '') : '',
                'kind' => $index !== null ? ($steps[$index]['kind'] ?? null) : null,
                'route' => $index !== null ? ($steps[$index]['route'] ?? null) : null,
            ];
        }

        Log::warning('MyBeast sessions: the service refused '.count($refused).' journey step(s); they were dropped', ['refused' => $named]);
    }

    /**
     * Past the project's monthly limit (its package's) the service refuses the rest of the month's steps as
     * `monthly-limit`. They are dropped like any refused step - sent again they would be refused again until the 1st,
     * UTC - but said once a month, and once when the service takes steps again, not on every flush. The spool keeps
     * the month it was said for.
     */
    private function monthLimit(int $over, int $taken): void
    {
        $noted = $this->spool->note('monthly-limit');

        if ($over > 0 && $noted !== gmdate('Y-m')) {
            $this->spool->setNote('monthly-limit', gmdate('Y-m'));
            Log::warning("MyBeast sessions: the project has used up this month's journey steps; the service drops the rest until the 1st (UTC).", ['dropped' => $over]);
        } elseif ($over === 0 && $taken > 0 && $noted !== null) {
            $this->spool->setNote('monthly-limit', null);
            Log::info('MyBeast sessions: the service takes journey steps again.');
        }
    }
}
