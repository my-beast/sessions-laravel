<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Transport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The host server talking to the service with its server secret (MYBEAST_SESSIONS_SECRET). Answers come back as they
 * are - each caller knows what its call's statuses mean (schemas/README.md); only no answer at all throws.
 */
final readonly class ServiceClient
{
    public function __construct(
        private string $url,
        private string $secret,
    ) {}

    /**
     * One batch to POST /v1/steps, gzip.
     *
     * @param  list<array<string, mixed>>  $steps
     *
     * @throws TransportFailed
     */
    public function sendSteps(array $steps, int $timeout): Response
    {
        $body = (string) gzencode((string) json_encode(['steps' => $steps], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return $this->call(fn (PendingRequest $http): Response => $http
            ->withHeaders(['Content-Encoding' => 'gzip'])
            ->withBody($body, 'application/json')
            ->post($this->url.'/v1/steps'), $timeout);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $headers  e.g. Accept-Encoding: gzip for a big answer (the client unpacks it)
     *
     * @throws TransportFailed
     */
    public function request(string $method, string $path, ?array $json = null, int $timeout = 10, array $headers = []): Response
    {
        return $this->call(fn (PendingRequest $http): Response => $http->withHeaders($headers)->send($method, $this->url.$path, $json === null ? [] : ['json' => $json]), $timeout);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws TransportFailed
     */
    private function call(callable $request, int $timeout): Response
    {
        try {
            return $request(Http::withToken($this->secret)->acceptJson()->timeout($timeout)->connectTimeout(min(5, $timeout)));
        } catch (Throwable $e) {
            throw new TransportFailed('The sessions service could not be reached: '.$e->getMessage(), 0, $e);
        }
    }
}
