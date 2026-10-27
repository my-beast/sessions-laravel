<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Journey;

use MyBeast\Sessions\Laravel\Transport\Delivery;

/**
 * The outbox: every step is written here first - one small JSON file each, atomically, never the host's database (a
 * page view must not cost a write there) - and sent to the service from here (Transport\Flusher). While the service
 * is away the files wait; a cap keeps a long outage from filling the disk.
 *
 * A file's name starts with the microsecond it was written, so the oldest go first and its age is read off the name.
 */
final class Spool
{
    /** About 10 MB of steps: hours of a busy host's journey. Past it new steps are dropped (said once in the log). */
    public const MAX_FILES = 20_000;

    public function __construct(
        private readonly string $directory,
    ) {}

    public function directory(): string
    {
        return $this->directory;
    }

    /** @param  array<string, mixed>  $step */
    public function write(array $step): bool
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
            return false;
        }

        if ($this->count() >= self::MAX_FILES) {
            return false;
        }

        $json = json_encode($step, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            return false;
        }

        $name = $this->directory.'/'.sprintf('%016d', (int) (microtime(true) * 1_000_000)).'-'.bin2hex(random_bytes(4)).'.json';
        $tmp = $name.'.tmp';

        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }

        return @rename($tmp, $name);
    }

    /** A line kept beside the steps, never sent as one (`.<name>`): what the flusher has said already. */
    public function note(string $name): ?string
    {
        $value = is_file($this->directory.'/.'.$name) ? @file_get_contents($this->directory.'/.'.$name) : false;

        return $value === false ? null : trim($value);
    }

    public function setNote(string $name, ?string $value): void
    {
        if ($value === null) {
            @unlink($this->directory.'/.'.$name);
        } elseif (is_dir($this->directory) || @mkdir($this->directory, 0750, true)) {
            @file_put_contents($this->directory.'/.'.$name, $value);
        }
    }

    public function count(): int
    {
        return count($this->files());
    }

    /** Worth sending now: a full batch waits, or the oldest step has waited at least $seconds. */
    public function due(int $seconds, int $batch): bool
    {
        $files = $this->files();

        if ($files === []) {
            return false;
        }

        if ($seconds <= 0 || count($files) >= $batch) {
            return true;
        }

        $writtenAt = (int) substr(basename($files[0]), 0, 16) / 1_000_000;

        return microtime(true) - $writtenAt >= $seconds;
    }

    /**
     * Hands the oldest steps to $send in batches. Taken: the batch's files go. Too big: the same steps again in half
     * the batch (one step alone too big can never go, so it goes too). Later: they all wait for the next try. One drain
     * at a time (a file lock): the request that just recorded a step and the minutely command may both try.
     *
     * @param  callable(list<array<string, mixed>>): Delivery  $send
     * @return int how many steps left the spool
     */
    public function drain(callable $send, int $batchSize, int $maxBatches): int
    {
        if (! is_dir($this->directory)) {
            return 0;
        }

        $lock = @fopen($this->directory.'/.drain.lock', 'c');

        if ($lock === false) {
            return 0;
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return 0;
        }

        try {
            $gone = 0;
            $size = max(1, $batchSize);

            for ($try = 0; $try < $maxBatches; $try++) {
                $files = array_slice($this->files(), 0, $size);

                if ($files === []) {
                    break;
                }

                $steps = [];
                $read = [];

                foreach ($files as $file) {
                    $step = json_decode((string) @file_get_contents($file), true);

                    if (is_array($step)) {
                        $steps[] = $step;
                        $read[] = $file;
                    } else {
                        @unlink($file); // Unreadable: it could never be sent.
                    }
                }

                if ($steps === []) {
                    continue;
                }

                $delivery = $send($steps);

                if ($delivery === Delivery::Later) {
                    break; // The service is away: the files wait for the next try.
                }

                if ($delivery === Delivery::TooBig && count($read) > 1) {
                    $size = max(1, intdiv(count($read), 2));

                    continue;
                }

                foreach ($read as $file) {
                    @unlink($file);
                }

                $gone += count($read);
            }

            return $gone;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<string> the waiting steps' files, oldest first */
    private function files(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        $files = glob($this->directory.'/*.json') ?: [];
        sort($files);

        return $files;
    }
}
