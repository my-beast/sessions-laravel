<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Flows;

/**
 * A key flow: its steps in order. A run starts at the first step, moves to the furthest step reached later (skipping
 * allowed) and completes at the last one; with no new step for `giveUpAfterHours` it has been given up.
 *
 * A milestone flow (`newAccountDays` set: a first run) starts differently: at a NEW person's first journey step - their
 * account at most that many days old then (the steps' subject_since) - and never twice for one person. Its first step
 * is a start step (FlowStepDefinition::start), reached by that start; the later ones are the milestones.
 *
 * Checked before anything is sent (FlowsPayload), not here: every problem of every flow is then told at once.
 */
final readonly class FlowDefinition
{
    /** A flow's give-up time unless it says otherwise. */
    public const DEFAULT_GIVE_UP_AFTER_HOURS = 24;

    /** @param  list<FlowStepDefinition>  $steps  at least two */
    public function __construct(
        public string $slug,
        public string $name,
        public array $steps,
        public int $giveUpAfterHours = self::DEFAULT_GIVE_UP_AFTER_HOURS,
        public ?int $newAccountDays = null,
    ) {}

    /** A milestone flow: started by a new account's first journey step, not by its own first step. */
    public function startsWithNewAccounts(): bool
    {
        return $this->newAccountDays !== null;
    }

    /** @return array<string, mixed> as key-flows.schema.json takes it */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'give_up_after_hours' => $this->giveUpAfterHours,
            'new_account_days' => $this->newAccountDays,
            'steps' => array_map(fn (FlowStepDefinition $step): array => $step->toArray(), array_values($this->steps)),
        ];
    }
}
