<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Flows;

/**
 * One step of a key flow: reached by a journey step whose route is one of `routes` (route names) - a page shown
 * (visit) or a write the server took (save), as the step says. A milestone flow's first step is a start step instead.
 */
final readonly class FlowStepDefinition
{
    public const VISIT = 'visit';

    public const SAVE = 'save';

    public const START = 'start';

    /** @param  list<string>  $routes */
    private function __construct(
        public string $key,
        public string $label,
        public string $kind,
        public array $routes,
    ) {}

    /** @param  list<string>  $routes */
    public static function visit(string $key, string $label, array $routes): self
    {
        return new self($key, $label, self::VISIT, array_values($routes));
    }

    /** @param  list<string>  $routes */
    public static function save(string $key, string $label, array $routes): self
    {
        return new self($key, $label, self::SAVE, array_values($routes));
    }

    /**
     * A milestone flow's first step ("Signed up"): reached when its run starts - a new account's first journey step,
     * any page - so it names no routes and no later step reaches it.
     */
    public static function start(string $key, string $label): self
    {
        return new self($key, $label, self::START, []);
    }

    /** @return array{key: string, label: string, kind: string, routes?: list<string>} as key-flows.schema.json takes it */
    public function toArray(): array
    {
        $step = ['key' => $this->key, 'label' => $this->label, 'kind' => $this->kind];

        return $this->kind === self::START ? $step : $step + ['routes' => $this->routes];
    }
}
