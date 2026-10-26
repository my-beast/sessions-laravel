<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Flows;

use MyBeast\Sessions\Laravel\Journey\JourneyStep;

/**
 * The body of PUT /api/v1/flows (schemas/v1/key-flows.schema.json), checked here first: a flow the service would
 * refuse fails the deploy step with every problem named, instead of reaching the service half right.
 *
 * Besides the schema's shape it checks what the schema cannot say: slugs and step keys unique, a start step only as a
 * milestone flow's first step (and a milestone flow starting with one), and a visit or save step naming routes a
 * journey step can carry.
 */
final class FlowsPayload
{
    private const SLUG = '/^[a-z0-9-]{1,40}$/';

    private const MAX_FLOWS = 50;

    private const MAX_STEPS = 20;

    private const MAX_ROUTES = 50;

    /**
     * @param  array<mixed>  $flows  the host's KeyFlows::flows()
     * @return array{flows: list<array<string, mixed>>}
     *
     * @throws InvalidFlows
     */
    public static function build(array $flows): array
    {
        $problems = [];

        if (count($flows) > self::MAX_FLOWS) {
            $problems[] = 'At most '.self::MAX_FLOWS.' key flows ('.count($flows).' given).';
        }

        $slugs = [];
        $payload = [];

        foreach (array_values($flows) as $position => $flow) {
            if (! $flow instanceof FlowDefinition) {
                $problems[] = "Flow #{$position} is not a ".FlowDefinition::class.'.';

                continue;
            }

            $where = "Flow \"{$flow->slug}\"";
            array_push($problems, ...self::flowProblems($flow, $where));

            if (in_array($flow->slug, $slugs, true)) {
                $problems[] = "{$where}: a second flow with this slug.";
            }

            $slugs[] = $flow->slug;
            $payload[] = $flow->toArray();
        }

        if ($problems !== []) {
            throw new InvalidFlows($problems);
        }

        return ['flows' => $payload];
    }

    /** @return list<string> */
    private static function flowProblems(FlowDefinition $flow, string $where): array
    {
        $problems = [];

        if (preg_match(self::SLUG, $flow->slug) !== 1) {
            $problems[] = "{$where}: a slug is 1-40 of a-z, 0-9 and -.";
        }

        if (! self::text($flow->name)) {
            $problems[] = "{$where}: a name is 1-80 characters.";
        }

        if ($flow->giveUpAfterHours < 1 || $flow->giveUpAfterHours > 720) {
            $problems[] = "{$where}: giveUpAfterHours is 1-720.";
        }

        if ($flow->newAccountDays !== null && ($flow->newAccountDays < 1 || $flow->newAccountDays > 90)) {
            $problems[] = "{$where}: newAccountDays is 1-90 (or null).";
        }

        $steps = array_values($flow->steps);

        if (count($steps) < 2 || count($steps) > self::MAX_STEPS) {
            $problems[] = "{$where}: a flow has 2-".self::MAX_STEPS.' steps ('.count($steps).' given).';
        }

        $keys = [];

        foreach ($steps as $index => $step) {
            if (! $step instanceof FlowStepDefinition) {
                $problems[] = "{$where}: step #{$index} is not a ".FlowStepDefinition::class.'.';

                continue;
            }

            array_push($problems, ...self::stepProblems($flow, $step, $index, "{$where}, step \"{$step->key}\""));

            if (in_array($step->key, $keys, true)) {
                $problems[] = "{$where}: a second step with the key \"{$step->key}\".";
            }

            $keys[] = $step->key;
        }

        if ($flow->startsWithNewAccounts() && ($steps[0] ?? null) instanceof FlowStepDefinition && $steps[0]->kind !== FlowStepDefinition::START) {
            $problems[] = "{$where}: a milestone flow (newAccountDays) starts with a start step (FlowStepDefinition::start).";
        }

        return $problems;
    }

    /** @return list<string> */
    private static function stepProblems(FlowDefinition $flow, FlowStepDefinition $step, int $index, string $where): array
    {
        $problems = [];

        if (preg_match(self::SLUG, $step->key) !== 1) {
            $problems[] = "{$where}: a key is 1-40 of a-z, 0-9 and -.";
        }

        if (! self::text($step->label)) {
            $problems[] = "{$where}: a label is 1-80 characters.";
        }

        if ($step->kind === FlowStepDefinition::START) {
            if ($index !== 0 || ! $flow->startsWithNewAccounts()) {
                $problems[] = "{$where}: a start step is only the first step of a milestone flow (newAccountDays set).";
            }

            return $problems;
        }

        if ($step->routes === []) {
            $problems[] = "{$where}: a {$step->kind} step names the routes that reach it.";
        }

        if (count($step->routes) > self::MAX_ROUTES) {
            $problems[] = "{$where}: at most ".self::MAX_ROUTES.' routes.';
        }

        foreach ($step->routes as $route) {
            // A name a journey step can carry (JourneyStep::routeName): any other could never be reached.
            if (! is_string($route) || JourneyStep::routeName($route) === null) {
                $problems[] = "{$where}: a route is a route name of 1-120 letters, digits and . _ : - (".json_encode($route).').';

                break;
            }
        }

        return $problems;
    }

    private static function text(string $value): bool
    {
        return trim($value) !== '' && mb_strlen($value) <= 80;
    }
}
