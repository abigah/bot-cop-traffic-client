<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Http\Exceptions\MaintenanceModeException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;

return [

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    |
    | Where this site's signals go. Every ping and every exception report is
    | sent to all of them, because the client cannot know which of them is
    | listening: in `remote` mode these are the probers, in `local` mode this is
    | the hub accepting pings directly, and during a migration between the two
    | it is both. Nothing here depends on what is on the other end — the paths
    | and payloads are the same either way.
    |
    | Base URLs only, no trailing path. Comma-separate several in the env var.
    |
    */

    'endpoints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MONITORING_CLIENT_ENDPOINTS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | The master switch. Off means every call in this package becomes a no-op
    | that still returns cleanly, so an application can keep its heartbeat
    | declarations and its deploy hooks in place on a machine that should not be
    | reporting — a developer's laptop, a CI runner, a staging clone restored
    | from production.
    |
    | It defaults to off precisely so that a clone of a production .env cannot
    | start reporting as production the moment it boots.
    |
    */

    'enabled' => env('MONITORING_CLIENT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Every send is fire-and-forget: a short timeout, no retries, and no
    | exception ever escaping into the caller. A monitoring ping must never be
    | the reason a job, a deploy or a request fails, so the failure mode of this
    | package is silence.
    |
    | `timeout` is deliberately small. A ping that has not been answered in a
    | couple of seconds has already cost the caller more than the signal is
    | worth, and the prober answers immediately after one state write.
    |
    | `log_failures` writes a debug line when a send fails. Useful while wiring
    | a site up; noise once it works, and never above debug level, because a
    | monitoring failure is not the application's problem to report.
    |
    */

    'timeout' => env('MONITORING_CLIENT_TIMEOUT', 2),

    'connect_timeout' => env('MONITORING_CLIENT_CONNECT_TIMEOUT', 1),

    'log_failures' => env('MONITORING_CLIENT_LOG_FAILURES', false),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Off by default: a send is a sub-second HTTP call with a short timeout, and
    | pushing it onto a queue trades that for a job that cannot report a
    | heartbeat if the queue itself is the thing that has stopped — which is
    | exactly the outage heartbeats exist to catch.
    |
    | Set this to a connection name to push sends onto it anyway. Reasonable for
    | the exception reporter on a latency-sensitive site, where the first
    | occurrence of a fingerprint is sent from inside a web request.
    |
    */

    'queue' => env('MONITORING_CLIENT_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Heartbeats
    |--------------------------------------------------------------------------
    |
    | Heartbeats ride on real jobs rather than a synthetic one: a job that
    | already runs hourly is proof that the scheduler and the queue are alive,
    | and adds no extra wake to a site that hibernates.
    |
    | Two ways to declare one, and a job may use either:
    |
    | `jobs` maps a job class to its heartbeat token. The package listens for
    | JobProcessed and JobFailed and pings on the way out, so the job's own code
    | is untouched. Best for jobs you do not own, or where success simply means
    | "handle() returned".
    |
    | Or add the SendsHeartbeat trait to the job and call its methods inside
    | handle(). Best when the job knows something the queue does not — that it
    | processed zero records, or reconciled a total that did not balance.
    |
    | Tokens are issued per heartbeat by the hub and appear in its manifest.
    | Keep them in the environment, not here: a token is a credential.
    |
    */

    'heartbeats' => [

        'enabled' => env('MONITORING_CLIENT_HEARTBEATS_ENABLED', true),

        'jobs' => [
            // \App\Jobs\SendNightlyDigest::class => env('HEARTBEAT_NIGHTLY_DIGEST'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Deployments
    |--------------------------------------------------------------------------
    |
    | A deploy is an event heartbeat: `start` opens a window, `finish` closes
    | it, `fail` says so explicitly. A start with no finish inside the hub's
    | timeout is itself a verdict, which is what catches a deploy that hung
    | rather than one that broke.
    |
    | Call the artisan command from the deploy script:
    |
    |     php artisan monitoring:deploy start
    |     php artisan monitoring:deploy finish
    |     php artisan monitoring:deploy fail --message="composer install failed"
    |
    */

    'deployments' => [

        'enabled' => env('MONITORING_CLIENT_DEPLOYMENTS_ENABLED', true),

        'token' => env('MONITORING_CLIENT_DEPLOYMENT_TOKEN'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Exception reporting
    |--------------------------------------------------------------------------
    |
    | Answers one question: is this site throwing server errors, and is that
    | new? It is not an error tracker. There is no trace explorer, no source
    | context and no releases; a site that wants those runs Flare or Sentry as
    | well, and the two do not conflict.
    |
    | Only what the application itself would report is reported — `dontReport`
    | and `shouldReport()` are respected — and on top of that everything that is
    | not a genuine server error is skipped (see `ignore` below).
    |
    | Each exception is fingerprinted on class, file and line. The first
    | occurrence of a fingerprint is sent immediately, because a new kind of
    | error is the thing worth waking someone for. Repeats are counted locally
    | and flushed as counts, so a storm costs one request per flush rather than
    | one per exception.
    |
    */

    'exceptions' => [

        'enabled' => env('MONITORING_CLIENT_EXCEPTIONS_ENABLED', false),

        'token' => env('MONITORING_CLIENT_INGEST_TOKEN'),

        /*
        | The cache store holding fingerprint counts between flushes, and how
        | long a fingerprint is remembered as "already seen". The window is what
        | makes a recurrence after a quiet period count as new again; it should
        | comfortably exceed the flush interval.
        */

        'cache_store' => env('MONITORING_CLIENT_CACHE_STORE'),

        'window_minutes' => env('MONITORING_CLIENT_EXCEPTION_WINDOW', 60),

        /*
        | The most fingerprints one flush will carry. The schema caps a report
        | at 100; beyond that the excess is dropped rather than split, because a
        | site throwing more than 100 distinct errors in a flush interval has
        | one problem, not a hundred.
        */

        'max_fingerprints' => 100,

        /*
        | Exception classes that are never server errors, however loudly they
        | are thrown. Matched on instanceof, so listing a base class or an
        | interface covers everything under it.
        |
        | Anything implementing HttpExceptionInterface is judged by its status
        | rather than listed here: below 500 is the client's mistake, 500 and
        | above is the site's. That is also what covers 404s, 419s and 429s
        | without naming them.
        */

        'ignore' => [
            AuthorizationException::class,
            AuthenticationException::class,
            RecordsNotFoundException::class,
            MaintenanceModeException::class,
            TokenMismatchException::class,
            ValidationException::class,
        ],

        /*
        | Scrubbing runs over the message before it leaves the site. The
        | defaults cover what turns up in a database or HTTP error message by
        | accident — an address, a bearer token, a connection string with a
        | password in it. Add patterns for anything your own messages carry.
        |
        | Scrubbing is not a substitute for not putting secrets in exception
        | messages, and it cannot be: it only knows the shapes it is told.
        */

        'scrub' => true,

        'scrub_patterns' => [
            // user@example.com
            '/[\w.+-]+@[\w-]+\.[\w.-]+/i' => '[email]',
            // scheme://user:password@host
            '/\b([a-z][a-z0-9+.-]*:\/\/[^\s:@\/]+):[^\s@\/]+@/i' => '$1:[redacted]@',
            // password=hunter2, "token": "abc…", Bearer abc…
            '/\b(bearer|token|api[_-]?key|secret|password|passwd|pwd)(["\':\s=]+)([^\s,;"\')]+)/i' => '$1$2[redacted]',
            // long digit runs: card numbers, account numbers
            '/\b\d{13,19}\b/' => '[redacted]',
        ],

        /*
        | Sending a trace is opt-in and trimmed to the frames that are yours.
        | Off by default: the fingerprint already says where the error is, and a
        | trace is the part of an error report most likely to carry something
        | that should not leave the server.
        */

        'send_trace' => env('MONITORING_CLIENT_EXCEPTION_TRACE', false),

        'trace_lines' => 20,

    ],

];
