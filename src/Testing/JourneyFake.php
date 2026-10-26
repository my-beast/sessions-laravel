<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Testing;

use Closure;
use MyBeast\Sessions\Laravel\Journey\StepSink;
use PHPUnit\Framework\Assert;

/**
 * Sessions::fake(): the journey on, its steps kept in memory instead of the spool - nothing written, nothing sent -
 * with assertions. A step is the array the service would get (schemas/v1/journey-steps.schema.json, one of `steps`).
 */
final class JourneyFake implements StepSink
{
    /** @var list<array<string, mixed>> */
    public array $steps = [];

    public function put(array $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $matching
     * @return list<array<string, mixed>>
     */
    public function recorded(?Closure $matching = null): array
    {
        return array_values(array_filter($this->steps, fn (array $step): bool => $matching === null || $matching($step)));
    }

    /** @param  (Closure(array<string, mixed>): bool)|null  $matching */
    public function assertRecorded(?Closure $matching = null): void
    {
        Assert::assertNotEmpty($this->recorded($matching), 'No journey step matched.');
    }

    /** A step of this route (and kind, when given). */
    public function assertRecordedRoute(string $route, ?string $kind = null): void
    {
        Assert::assertNotEmpty(
            $this->recorded(fn (array $step): bool => $step['route'] === $route && ($kind === null || $step['kind'] === $kind)),
            "No journey step of {$route}".($kind === null ? '' : " ({$kind})").'.',
        );
    }

    /** @param  Closure(array<string, mixed>): bool  $matching */
    public function assertNotRecorded(Closure $matching): void
    {
        Assert::assertSame([], $this->recorded($matching), 'A journey step matched.');
    }

    public function assertRecordedCount(int $count): void
    {
        Assert::assertCount($count, $this->steps, 'Journey steps recorded.');
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertSame([], $this->steps, 'Journey steps were recorded.');
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        return $this->steps === [] ? null : $this->steps[array_key_last($this->steps)];
    }
}
