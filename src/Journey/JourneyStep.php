<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Journey;

/**
 * One step of a person's journey as the service takes it (schemas/v1/journey-steps.schema.json, one item of
 * `steps`): who, which visit, when, what kind - and of the request only its route's name, method, status, time and the
 * NAMES of refused fields. Never a value, a message or a URL.
 *
 * A route or page name the schema would refuse goes as null - not cut, which would make it another name: the service
 * refuses a step whose name does not fit, and a step without the name still counts.
 */
final readonly class JourneyStep
{
    /** A route's NAME as Laravel gives it (`projects.store`): no slash, no space - never a URL. */
    private const ROUTE = '/^[A-Za-z0-9._:-]{1,120}$/';

    /** A page's name (an Inertia component, `Projects/Show`): no space - never a sentence. */
    private const PAGE = '/^[A-Za-z0-9._:\/-]{1,120}$/';

    private const MAX_FIELDS = 20;

    private const FIELD_LENGTH = 80;

    /** A UUID the SDK mints: the service keeps a step sent twice (a retry) once. */
    public string $id;

    /**
     * @param  string  $subject  the person, as the host's id (already checked against the schema's pattern)
     * @param  int|null  $subjectSince  when the person's account was made, unix ms
     * @param  int  $at  when the request came in, unix ms
     * @param  list<string>|null  $fields  a refused write's error keys
     */
    public function __construct(
        public string $subject,
        public ?string $account,
        public ?int $subjectSince,
        public string $environment,
        public string $visit,
        public ?string $recording,
        public int $at,
        public StepKind $kind,
        public ?string $route = null,
        public ?string $method = null,
        public ?int $status = null,
        public ?int $durationMs = null,
        public ?array $fields = null,
        public ?string $page = null,
        public bool $dataRead = false,
        ?string $id = null,
    ) {
        $this->id = $id ?? self::uuid();
    }

    /** @return array<string, mixed> the schema's shape: a name that does not fit is null, refused keys capped */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'account' => $this->account,
            'subject_since' => $this->subjectSince !== null && $this->subjectSince >= 0 ? $this->subjectSince : null,
            'environment' => $this->environment,
            'visit' => $this->visit,
            'recording' => $this->recording,
            'at' => max(0, $this->at),
            'kind' => $this->kind->value,
            'route' => self::routeName($this->route),
            'method' => $this->method === null || $this->method === '' ? null : substr(strtoupper($this->method), 0, 8),
            'status' => $this->status,
            'duration_ms' => $this->durationMs === null ? null : max(0, $this->durationMs),
            'fields' => $this->fields(),
            'page' => self::pageName($this->page),
            'data_read' => $this->dataRead,
        ];
    }

    /** @return list<string>|null at most 20 keys, each at most 80 characters, no repeats */
    private function fields(): ?array
    {
        if ($this->fields === null) {
            return null;
        }

        $keys = array_values(array_unique(array_map(fn (string $key): string => mb_substr($key, 0, self::FIELD_LENGTH), $this->fields)));

        return $keys === [] ? null : array_slice($keys, 0, self::MAX_FIELDS);
    }

    /** The route's name when it is one the service takes, else null. */
    public static function routeName(?string $route): ?string
    {
        return $route !== null && preg_match(self::ROUTE, $route) === 1 ? $route : null;
    }

    /** The page's name when it is one the service takes, else null. */
    public static function pageName(?string $page): ?string
    {
        return $page !== null && preg_match(self::PAGE, $page) === 1 ? $page : null;
    }

    /** A version 4 UUID, without needing ramsey/uuid. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
