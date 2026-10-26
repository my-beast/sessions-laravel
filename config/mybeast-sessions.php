<?php

declare(strict_types=1);

/*
 * MyBeast sessions: recordings of this app's pages and the journey of its signed-in people, sent to
 * sessions.my-beast.com. Every key and URL comes from the
 * project's page there (Overview → Connect a host shows the .env block).
 *
 * Missing or malformed values switch the SDK off - it never throws at boot and never breaks a page.
 */
return [
    // false switches everything off (tests, a local checkout without the service).
    'enabled' => (bool) env('MYBEAST_SESSIONS_ENABLED', true),

    // The service, e.g. https://sessions.my-beast.com (no trailing slash needed).
    'url' => env('MYBEAST_SESSIONS_URL'),

    // pk_…: names the project in the browser. Not a secret.
    'public_key' => env('MYBEAST_SESSIONS_PUBLIC_KEY'),

    // sig_….…: signs the client tokens browser pages carry. Keep it on the server only.
    'signing_key' => env('MYBEAST_SESSIONS_SIGNING_KEY'),

    // sk_….…: this server's secret for the service's API - the journey's steps, the key flows, a person's objection,
    // erasing a person's or an account's recordings or journey, and the masking canary's read-back of a test person's
    // recordings. Without it none of these happen.
    'secret' => env('MYBEAST_SESSIONS_SECRET'),

    // Sent with everything: production, staging, local, … (lowercase letters, digits, _ and -).
    'environment' => env('MYBEAST_SESSIONS_ENVIRONMENT', env('APP_ENV', 'production')),

    // How long a client token lives, in seconds (at most 3600; the page gets a fresh one on every response).
    'token_ttl' => (int) env('MYBEAST_SESSIONS_TOKEN_TTL', 1800),

    /*
     * The journey: one step per page shown or write taken by a signed-in person, kept by this server after the answer
     * went out (Journey\RecordJourneyStep) and sent from a file spool. Route names, statuses, timings and the NAMES
     * of refused fields only - never a value, a message or a URL - so it needs no recording and no masking. Who the
     * person is, and whether they objected: Sessions::journeyUsing(…) in a service provider.
     */
    'journey' => [
        // Off unless switched on (and the URL and the secret above are set).
        'enabled' => (bool) env('MYBEAST_SESSIONS_JOURNEY', false),

        // true: the SDK adds its middleware to the end of the `web` group itself. false: add
        // MyBeast\Sessions\Laravel\Journey\RecordJourneyStep where you want it (once).
        'middleware' => true,

        // Requests that are no one's step (route names, `*` wildcards): autosaves, polling, pages never to be followed.
        // The SDK's own routes (mybeast.*) and broadcasting's sign-in are always skipped.
        'skip_routes' => [],

        // JSON reads that ARE steps (route names, `*` wildcards): a sheet's or a list's data a key flow counts as a
        // visit. Any other JSON read (polling, badges, a search box) is no step.
        'data_reads' => [],

        // The flash key of the error toast a write sent back shows (null: none), and how the toasts that stand for a
        // server error begin (a production app turning a 5xx into "it did not go through"): those are errors, the
        // others refusals. Sessions::journeyUsing(serverError: …) decides instead, when given.
        'error_toast' => 'error',
        'server_error_toasts' => [],

        // Your key flows: a class implementing MyBeast\Sessions\Laravel\Flows\KeyFlows. A deploy step pushes them:
        // php artisan mybeast:sessions-flows
        'flows' => null,

        // Where steps wait to be sent (files - never the database). Each server keeps its own.
        'spool_path' => env('MYBEAST_SESSIONS_SPOOL_PATH', storage_path('app/private/mybeast-sessions-spool')),

        // After a response, the spool is sent once its oldest step has waited this many seconds (or a full batch
        // waits) - one request carries many steps. 0: after every response that kept a step. The scheduler sends
        // whatever is left every minute regardless.
        'flush_after' => (int) env('MYBEAST_SESSIONS_FLUSH_AFTER', 10),
    ],
];
