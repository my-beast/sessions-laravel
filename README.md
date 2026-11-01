# mybeast/sessions-laravel

The Laravel side of **Sessions** (services.my-beast.com/sessions): it signs the short-lived client token a page carries, saying whose
recording it would be (ids only) and whether this page may be recorded at all, and it keeps the **journey** of your
signed-in people - one step per page shown or write taken - for the service's key flows and stuck points. PHP ≥ 8.2,
Laravel 12 and 13.

The recorder itself is the browser SDK's (`@mybeast/sessions`); this side signs its tokens, sends the journey your
server sees, pushes your key flows, and erases a person's recordings or journey when the host asks.

## Install

```bash
composer require mybeast/sessions-laravel
```

```dotenv
MYBEAST_SESSIONS_URL=https://services.my-beast.com/sessions
MYBEAST_SESSIONS_PUBLIC_KEY=pk_…
MYBEAST_SESSIONS_SIGNING_KEY=sig_….…
MYBEAST_SESSIONS_SECRET=sk_….…          # the journey, key flows, objections and erasure
MYBEAST_SESSIONS_ENVIRONMENT=production
MYBEAST_SESSIONS_JOURNEY=true           # off unless switched on
```

A missing or malformed line switches that part off: `clientConfig()` returns null and nothing is recorded; without
the secret no journey is kept and the service is never asked. Tests and a checkout without the service:
`MYBEAST_SESSIONS_ENABLED=false`.

## Use

Hand the page its config, e.g. as an Inertia shared prop:

```php
use MyBeast\Sessions\Laravel\Facades\Sessions;

'sessions' => fn () => $user ? Sessions::clientConfig(
    subject: $user->id,
    account: $workspace?->id,
    consentAt: $user->recording_consent_at,   // null: this page is not recorded
) : null,
```

- **Nothing is recorded without the person's yes.** Pass the moment they said it; null records nothing. The service
  refuses a recording token without it.
- **Masked by default.** `mask: false` only where the project allows unmasked recordings; the service refuses an
  unmasked token for an environment the project requires masking in, and in production always.
- `internal: true` keeps your own people out of customer counts; `test: true` marks a synthetic person (a test or
  canary account): only their recordings can ever be read back (`testRecordings()` below). A recording keeps the mark
  its first token gave it - a token that says otherwise starts a new recording.

## Erasing a person's or an account's recordings

When a person's account is closed (or they ask), erase their recordings; when a whole account (a workspace, team or
organisation - the `account` you pass to `clientConfig()`) is closed, erase every recording made in it, whoever it was
of. Both need the server secret (`MYBEAST_SESSIONS_SECRET=sk_….…`):

```php
$erased = Sessions::eraseSubject($user->id);         // how many went; null while the SDK is off or has no secret
$erased = Sessions::eraseAccount($workspace->id);    // every recording made in it - rows and files
```

Each throws `MyBeast\Sessions\Laravel\ErasureFailed` when the service does not confirm it: call it from a queued job
with retries, so an erasure is never lost to a network blip. Asking again is safe (0 the second time). An id no token
could carry (other than 1-64 of letters, digits and `. _ : -`) answers 0 without asking: nothing is kept under it, and
the service would refuse it forever. The service writes each erasure into its audit log by counts only - never the id.

## The masking canary's read-back

A deploy check can record a synthetic person (`clientConfig(..., test: true)`) and then read back what was stored, to
search it for that person's real values:

```php
$recordings = Sessions::testRecordings($canary->id);   // null while off or without a secret

foreach ($recordings as $recording) {
    // ['id' => '…', 'started_at' => <unix ms>, 'masked' => true, 'chunks' => ['[…rrweb events…]', …], 'unreadable_chunks' => 0]
}
```

Only recordings made under `test: true` come back - never another of the person's, never anyone else's - newest
first, each chunk the JSON text of its events exactly as the browser sent it. Count `unreadable_chunks` as a failure:
those could not be opened, so they were not looked at. It throws `MyBeast\Sessions\Laravel\ExportFailed` when the
service does not hand them over whole - down, refused the secret, or past its caps (200 recordings, 32 MiB of events):
then nothing is returned, never part of them. The service writes each export into its audit log.

The token is the same format as Errors' (`schemas/v1/client-token.schema.json`), signed with this
product's own key.

## The journey

With `MYBEAST_SESSIONS_JOURNEY=true` (and the URL and secret), every meaningful request of a signed-in person becomes
one step (`schemas/v1/journey-steps.schema.json`): a page shown (`visit`), a write taken (`save`), a write sent back
(`refused`, with the **names** of the refused fields), a server failure (`error`) or a door shut (`blocked`:
401/403/404/405/419/429). Of a request it keeps the route's **name**, the method, the status and the time it took -
never a value, a message or a URL - so it needs no recording and no masking.

**Not steps:** guests; HEAD and OPTIONS; prefetches (`Purpose: prefetch`, `Sec-Purpose`); Inertia's partial reloads
and version check; JSON reads (polling, badges) other than your data reads; broadcasting's sign-in; routes named
`mybeast.*`; your skip list.

### The middleware

`MyBeast\Sessions\Laravel\Journey\RecordJourneyStep` joins the end of your `web` group by itself. It works after the
answer has gone out (`terminate`): one small file written, every failure swallowed and logged once per process - it
never slows or breaks a request. To place it yourself, set `journey.middleware` to `false` in the published config
(`php artisan vendor:publish --tag=mybeast-sessions-config`) and add it once:

```php
// bootstrap/app.php
->withMiddleware(fn (Middleware $middleware) => $middleware->appendToGroup('web', RecordJourneyStep::class))
```

A 404 is a step only when a route of the group answers it (`Route::fallback(...)`): an address no route matches never
meets the group's middleware.

### Who the person is (the hooks)

The defaults need no code: the subject is the signed-in user's id (read off the guard that already has them - never
a lookup), no account, `subject_since` is the user's `created_at`, everyone allows it. Change any of them from a
service provider's `boot()`:

```php
use Illuminate\Http\Request;
use MyBeast\Sessions\Laravel\Facades\Sessions;

Sessions::journeyUsing(
    account: fn (Request $request) => $request->user()?->current_team_id,
    allows: fn (string $subject, Request $request) => ! $request->user()?->objects_to_journey,
    // also: subject:, subjectSince:, serverError:, page:
);
```

- `subject` / `account` are ids of 1-64 letters, digits and `. _ : -` (never an e-mail). A subject that is not is not
  sent (logged once); an account that is not is left out.
- `allows` is asked last, only for a request that would be kept: return false for a person who objected.
- `page` names the page answered; by default an Inertia answer's component.
- A route name or page name the service would refuse (a route: 1-120 of letters, digits and `. _ : -`; a page: also
  `/`) goes as null, logged once - an unnamed route too: never its URL.

The rest is config (`mybeast-sessions.journey.*`):

| Key | Default | What |
|---|---|---|
| `skip_routes` | `[]` | Route names (`*` wildcards) that are no one's step: autosaves, polling, pages never to be followed |
| `data_reads` | `[]` | JSON reads that ARE steps (a sheet's or a list's data a key flow counts): kept as visits with `data_read: true` |
| `error_toast` | `'error'` | The flash key of the toast a write sent back shows: flashed by a write, it makes the step `refused` |
| `server_error_toasts` | `[]` | How the toasts that stand for a server error begin (`['flash.serverError']`): those make it `error`. `journeyUsing(serverError:)` decides instead |
| `request_id_header` | `'X-Request-Id'` | The header that carries the request's id - its log lines': the answer's (mybeast/logs-laravel sets it on every answer), else the request's own (a proxy's). Each step names its request, so the replay opens exactly its lines in Logs. `null`: none. Needs Sessions from 28.10.2026 |
| `spool_path` | `storage/app/private/mybeast-sessions-spool` | Where steps wait (files, never the database); each server its own |
| `flush_after` | `10` | Seconds the oldest step waits before a response sends the spool (0: every response) |

### Pages the browser showed from its cache

A page Inertia shows from its prefetch cache (or any client-side visit) never reaches your server. Keep a small route
the browser tells instead, and hand it to the SDK - the report is itself no step:

```php
Route::post('journey/page-shown', fn (Request $request) => response()->json([
    'kept' => Sessions::pageShown(Sessions::routeForAddress((string) $request->input('url')), $request->input('component')),
]))->middleware(['web', 'auth']);
```

`routeForAddress()` reads only the address's path and answers your GET route's name (null for none of your pages);
`pageShown()` keeps one visit step for the request's person and visit, and never throws.

### Sending

Steps wait in the spool and go to `POST /v1/steps` in gzip batches of up to 500, with the secret: after a response
once the oldest has waited `flush_after` seconds, after each queued job, and every minute. The minute needs the
scheduler (`php artisan schedule:work`, or cron running `schedule:run`) - the SDK puts `mybeast:sessions-flush` on it.
Run `php artisan mybeast:sessions-flush` any time to send what waits; while the SDK is off it says so and succeeds.
Past the project's monthly limit (its package's) the service refuses the rest of the month's steps as `monthly-limit`:
they are dropped, and the log says so once a month, and once when the service takes steps again.

A step the service refuses is dropped and logged (it will never be taken); a batch too big is halved; no answer or a
5xx keeps the steps for later (at most 20,000 wait; past that new ones are dropped, logged once).

### Key flows

Define the tasks whose funnels and give-ups the service shows, in your code:

```php
use MyBeast\Sessions\Laravel\Flows\FlowDefinition;
use MyBeast\Sessions\Laravel\Flows\FlowStepDefinition;
use MyBeast\Sessions\Laravel\Flows\KeyFlows;

final class AppKeyFlows implements KeyFlows
{
    public function flows(): array
    {
        return [
            // A first run: starts at a new person's first step (account at most 7 days old), never twice.
            new FlowDefinition('onboarding', 'First run', [
                FlowStepDefinition::start('signed-up', 'Signed up'),
                FlowStepDefinition::save('project-saved', 'First project saved', ['projects.store']),
            ], giveUpAfterHours: 168, newAccountDays: 7),
            new FlowDefinition('add-project', 'Add a project', [
                FlowStepDefinition::visit('opened', 'Opened', ['projects.create']),
                FlowStepDefinition::save('saved', 'Saved', ['projects.store']),
            ]),
        ];
    }
}
```

Name it in config (`'flows' => App\Journey\AppKeyFlows::class`) and push them as a **deploy step**:

```bash
php artisan mybeast:sessions-flows          # or Sessions::syncFlows()
php artisan mybeast:sessions-flows --check  # CI: check only, send nothing
```

They are checked first - slugs and keys (`a-z 0-9 -`, unique), 2-20 steps, a start step only as a milestone flow's
first, a visit or save step naming its routes (names a step can carry: no slash, no space) - and a definition that
does not fit fails the step with every problem named, also while the SDK is off. A route name your app does not have
is warned about. The push replaces the service's flows (one left out is retired, its runs kept): keep a flow's slug
and its steps' keys; a new step at the end is safe.

### Objection and erasure

The journey runs for every signed-in person, so each can object. Keep their no in your own records (the `allows` hook
above, so their steps are not even sent) and tell the service - it erases their journey and drops any step that still
comes:

```php
Sessions::objected($user->id);                  // how many of their steps went
Sessions::objectionLifted($user->id);           // kept again from now on
Sessions::eraseJourney($user->id);              // steps, flow runs, stuck occurrences - their account closed
Sessions::eraseAccountJourney($workspace->id);  // the same for everything kept in a closed account, whoever it was about
```

Each answers null (or does nothing) while the SDK is off or has no secret, 0 (or nothing) without asking for an id no
token could carry, and throws when the service does not confirm - `ErasureFailed` (objected, eraseJourney,
eraseAccountJourney) or `ObjectionNotLifted` - so call them from a queued job with retries. An account's erasure keeps
the people's own records (an objection is theirs, not the account's).

### In your tests

```php
$journey = Sessions::fake();   // the journey on, steps in memory: no spool, no service

$this->actingAs($user)->post('/projects', [...]);

$journey->assertRecordedRoute('projects.store', 'save');
$journey->assertNotRecorded(fn (array $step) => $step['kind'] === 'refused');
// also: assertRecorded(fn), assertRecordedCount(n), assertNothingRecorded(), recorded(fn), last(), ->steps
```

For the service calls (`objected`, `syncFlows`, …) use `Http::fake()`.

## Tests

```bash
composer install && vendor/bin/phpunit
```

`SchemaCopyTest` checks that `schemas/` is the Sessions module's (`services/schemas/sessions`), file for file, when
services/ is two folders up.

## Releasing

Add the version to `CHANGELOG.md`, then tag it: `git tag v0.2.0 && git push --tags`. Packagist reads the tag through
its GitHub hook; there is no version in `composer.json`. The Tests workflow runs on the tag as on every push: PHP 8.2
to 8.4 against Laravel 12 and 13 (Laravel 11 has unfixed advisories, so Composer will not install it).
