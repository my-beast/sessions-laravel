<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use MyBeast\Sessions\Laravel\ExportFailed;
use MyBeast\Sessions\Laravel\Facades\Sessions;

/**
 * The masking canary's read-back (Sessions::testRecordings): a synthetic person's recordings made under `test: true`,
 * each chunk the JSON text of its events. Whole or not at all: an export the service cut at its caps, or an answer
 * that is not an export, throws ExportFailed - a canary must never pass on part of them. Null while off.
 */
final class TestRecordingsTest extends JourneyTestCase
{
    private const EXPORT = [
        'recordings' => [
            [
                'id' => '0199f5a0-0000-7000-8000-0000000000a1',
                'started_at' => 1_761_475_200_123,
                'masked' => true,
                'chunks' => ['[{"type":4,"timestamp":1761475200123,"data":{"href":"http://localhost/","width":1280,"height":800}}]', '[]'],
                'unreadable_chunks' => 0,
            ],
        ],
        'truncated' => false,
    ];

    public function test_the_canary_reads_back_a_test_persons_recordings(): void
    {
        Http::fake(['sessions.example.test/*' => Http::response(self::EXPORT)]);

        $this->assertSame(self::EXPORT['recordings'], Sessions::testRecordings('canary:42'));

        Http::assertSent(fn (ClientRequest $r): bool => $r->method() === 'GET'
            && $r->url() === 'https://sessions.example.test/api/v1/subjects/canary%3A42/recordings/test-export'
            && $r->hasHeader('Authorization', 'Bearer '.self::SECRET)
            && $r->hasHeader('Accept-Encoding', 'gzip'));
    }

    public function test_none_is_an_empty_list(): void
    {
        Http::fake(['sessions.example.test/*' => Http::response(['recordings' => [], 'truncated' => false])]);

        $this->assertSame([], Sessions::testRecordings(42));
    }

    public function test_an_export_that_is_not_whole_is_never_returned(): void
    {
        $answer = null;
        Http::fake(['sessions.example.test/*' => function (ClientRequest $request) use (&$answer) {
            return $answer($request);
        }]);

        $chunkNotText = self::EXPORT;
        $chunkNotText['recordings'][0]['chunks'] = [['type' => 4]];

        foreach ([
            'cut at the caps' => fn () => Http::response(['truncated' => true] + self::EXPORT),
            'down' => fn () => Http::response('down', 503),
            'refused the secret' => fn () => Http::response(['message' => 'no'], 401),
            'not an export' => fn () => Http::response(['ok' => true]),
            'no word on whether it was cut' => fn () => Http::response(['recordings' => self::EXPORT['recordings']]),
            'a chunk that is not text' => fn () => Http::response($chunkNotText),
            'no answer' => Http::failedConnection(),
        ] as $case => $answer) {
            try {
                Sessions::testRecordings('user-1');
                $this->fail("Returned: {$case}");
            } catch (ExportFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_without_a_secret_nothing_is_asked(): void
    {
        Http::fake();
        config(['mybeast-sessions.secret' => null]);
        $this->forgetSessions();

        $this->assertNull(Sessions::testRecordings('user-1'));
        Http::assertNothingSent();
    }
}
