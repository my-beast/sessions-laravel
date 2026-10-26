<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Tests;

use MyBeast\Sessions\Laravel\Journey\StepKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What an answer makes of a request, and that the kinds are the contract's. */
final class StepKindTest extends TestCase
{
    public function test_the_kinds_are_the_contracts(): void
    {
        $schema = json_decode((string) file_get_contents(Fixtures::schemaDir().'/journey-steps.schema.json'), true);

        $this->assertSame($schema['properties']['steps']['items']['properties']['kind']['enum'], array_column(StepKind::cases(), 'value'));
    }

    /** @return iterable<string, array{0: bool, 1: int, 2: bool, 3: bool, 4: StepKind}> */
    public static function answers(): iterable
    {
        yield 'a page' => [true, 200, false, false, StepKind::Visit];
        yield 'a redirecting page' => [true, 302, false, false, StepKind::Visit];
        yield 'a page not found' => [true, 404, false, false, StepKind::Blocked];
        yield 'a page not allowed' => [true, 403, false, false, StepKind::Blocked];
        yield 'a bad page request' => [true, 400, false, false, StepKind::Blocked];
        yield 'a page that failed' => [true, 500, false, false, StepKind::Error];
        yield 'a page failed, shown as a toast' => [true, 302, false, true, StepKind::Error];
        yield 'a save' => [false, 303, false, false, StepKind::Save];
        yield 'a JSON save' => [false, 200, false, false, StepKind::Save];
        yield 'a form sent back' => [false, 303, true, false, StepKind::Refused];
        yield 'a JSON refusal' => [false, 422, false, false, StepKind::Refused];
        yield 'a conflict' => [false, 409, false, false, StepKind::Refused];
        yield 'a save not allowed' => [false, 403, false, false, StepKind::Blocked];
        yield 'a page expired' => [false, 419, false, false, StepKind::Blocked];
        yield 'too many tries' => [false, 429, false, false, StepKind::Blocked];
        yield 'a save that failed' => [false, 500, false, false, StepKind::Error];
        yield 'a save failed, shown as a toast' => [false, 303, true, true, StepKind::Error];
    }

    #[DataProvider('answers')]
    public function test_what_an_answer_makes_of_a_request(bool $read, int $status, bool $refused, bool $serverError, StepKind $kind): void
    {
        $this->assertSame($kind, StepKind::forAnswer($read, $status, $refused, $serverError));
    }
}
