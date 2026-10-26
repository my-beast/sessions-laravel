<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Support;

/**
 * Reads config('mybeast-sessions') the same way everywhere: what is on, the service's URL, the server secret and the
 * environment. Anything missing or malformed reads as off - never an exception at boot or in a request.
 *
 * Every key is read with a default: a host's published config may predate a key, and Laravel merges a package's
 * defaults only one level deep (a host's own `journey` array replaces the SDK's whole).
 */
final readonly class Settings
{
    private const SERVER_SECRET = '/^sk_[a-z0-9]{16}\.[A-Za-z0-9_-]{20,}$/';

    private const ENVIRONMENT = '/^[a-z0-9_-]{1,32}$/';

    /** @param  array<string, mixed>  $config  config('mybeast-sessions') */
    public function __construct(private array $config) {}

    /** Not switched off, and the service's URL is a URL. */
    public function on(): bool
    {
        return ($this->config['enabled'] ?? true) === true
            && filter_var((string) ($this->config['url'] ?? ''), FILTER_VALIDATE_URL) !== false;
    }

    public function url(): string
    {
        return rtrim((string) ($this->config['url'] ?? ''), '/');
    }

    /** The server secret while on and well-formed, else null: nothing can be asked of the service without it. */
    public function secret(): ?string
    {
        $secret = (string) ($this->config['secret'] ?? '');

        return $this->on() && preg_match(self::SERVER_SECRET, $secret) === 1 ? $secret : null;
    }

    public function environment(): string
    {
        return strtolower((string) ($this->config['environment'] ?? 'production'));
    }

    /**
     * The journey is recorded: switched on (MYBEAST_SESSIONS_JOURNEY=true), with the URL and the secret to send it
     * with, under an environment name the service takes (a step with another would be refused one by one).
     */
    public function journey(): bool
    {
        return $this->journeyValue('enabled', false) === true
            && $this->secret() !== null
            && preg_match(self::ENVIRONMENT, $this->environment()) === 1;
    }

    public function journeyValue(string $key, mixed $default = null): mixed
    {
        $journey = $this->config['journey'] ?? [];

        return is_array($journey) && array_key_exists($key, $journey) ? $journey[$key] : $default;
    }

    /** @return list<string> a list of route names (or patterns) from the journey's config */
    public function journeyList(string $key): array
    {
        $value = $this->journeyValue($key, []);

        return is_array($value) ? array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== '')) : [];
    }
}
