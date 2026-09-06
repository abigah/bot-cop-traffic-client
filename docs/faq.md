# Setup FAQ

The questions that come up when putting a site under monitoring, and the two
that are easy to get wrong in a way that looks fine.

## What should I monitor on a site?

Two monitors, to start:

1. **The homepage, with a look-for string.** Pick something rendered from the
   database, not from a blade template — a product name, a headline, the current
   year from a settings table. A 200 that renders an error page is still a 200,
   and a look-for string is what tells the difference.
2. **`/up`.** Laravel ships it. It proves the framework booted, which is a
   weaker claim than most people assume — see below.

Mark as `critical` the monitors whose failure means *the site is down*. That
flag is what suppresses heartbeat alerting during an outage, so that a site
falling over produces one incident rather than one incident plus every job that
did not run inside it.

## Why must `/up` never be cached?

Because a cached 200 is a check that never reached the origin.

If a CDN serves `/up` from cache, the prober sees a healthy site for as long as
the cache holds — which is exactly the window in which it was supposed to be
raising the alarm. That is worse than no monitoring, because it is confident.

It needs fixing at both ends.

**At the app**, apply the shipped middleware to the health route:

```php
use Abigah\BotCopTrafficClient\Http\Middleware\NeverCache;

Route::get('/up', function () {
    Event::dispatch(new DiagnosingHealth);

    return response('OK');
})->middleware(NeverCache::class);
```

or, if you are keeping Laravel's built-in route, in `bootstrap/app.php`:

```php
->withRouting(
    health: '/up',
)
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('health', [NeverCache::class]);
})
```

**At the CDN**, headers are not enough: a "Cache Everything" page rule overrides
what the origin asks for. On Cloudflare, add a Cache Rule *above* any such rule:

| Field | Value |
|---|---|
| When incoming requests match | `URI Path equals /up` |
| Then | Bypass cache |

Cache Rules are evaluated in order and the first match wins, so this one has to
sit above the broad rule, not below it.

**The prober does its part too.** It sends `Cache-Control: no-cache` and a
cache-busting query parameter on every check, and records any response carrying
`cf-cache-status: HIT` (or a non-zero `Age`) as *served from cache* rather than
believing it. So a misconfiguration shows up as a flagged check rather than as
silence — but fix it at the origin and the CDN anyway, because a check that is
flagged is a check you are not getting.

## How do I make `/up` mean more than "the framework booted"?

Laravel's health route returns 200 as soon as the application boots. It says
nothing about the database, the cache, the queue or anything else — a site whose
database is unreachable will happily answer `/up` with a 200 while every real
page 500s.

This package deliberately adds no health logic; what "healthy" means is the
application's business. Laravel's own hook for it is the `DiagnosingHealth`
event: throw from a listener and the health route fails.

```php
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\{Cache, DB, Event};

Event::listen(function (DiagnosingHealth $event) {
    DB::connection()->getPdo();

    Cache::store()->put('health', true, 5);

    if (Cache::store()->get('health') !== true) {
        throw new Exception('Cache is not readable.');
    }
});
```

Keep it cheap and keep it to dependencies the site cannot serve a page without.
Checking a third-party API here means their outage becomes your incident.

## Which of my jobs should have a heartbeat?

The ones whose silence is a problem, and which run often enough that silence is
noticeable. A nightly digest, an hourly import, a queue-health job, a scheduled
reconciliation.

Prefer a job that already runs on a schedule over adding one. A synthetic
"heartbeat job" proves only that the thing you added for monitoring is running;
a real job proves that the work is being done.

For a hibernating site this matters twice over: every wake costs, and a real
hourly job is a wake you were already paying for.

## The site hibernates. Does that change anything?

Yes, and the hub needs to know: a hibernating site is marked `hibernates: true`
and its uptime monitors are floored at a 60-minute interval, because every check
wakes the app.

Heartbeats become the primary signal for those sites. Between the hourly checks,
a job that fails to ping is what catches an outage.

## What happens to my heartbeats while the site is down?

Nothing pages. That is the **site rule**: while a site's critical monitors are
down, missed heartbeats are folded into the open incident as context rather than
raised separately. A site that is down does not also need twelve pages saying
its jobs did not run.

When it recovers, the hub waits one interval plus grace before judging
heartbeats again — so a web tier that came back while the queue worker did not
still produces a real verdict, one interval later.

## Do I need the queue for any of this?

No, and by default it is not used. A send is a sub-second HTTP call with a short
timeout.

`MONITORING_CLIENT_QUEUE` will push sends onto a connection if you want the
latency out of the request path, but note the trade: a heartbeat pushed onto a
queue cannot report that the queue has stopped, which is one of the outages
heartbeats exist to catch.

## Will this slow my site down?

The budget is `connect_timeout` + `timeout`, default 1 s + 2 s, and endpoints are
called concurrently so adding a second prober does not add a second timeout.

In practice a ping is a few milliseconds. The timeouts are the worst case, and
the worst case only happens when the prober is unreachable — at which point the
prober's own dead-man's switch is already firing.

## Can I run this alongside Sentry or Flare?

Yes, and it is the expected setup on a site that wants real error tracking. This
package hooks in through `reportable()`, which adds a listener rather than
replacing the handler, so the application's own logging and any other reporter
carry on untouched.

The two answer different questions. Sentry answers "what is this error and where
did it come from". This answers "is this site throwing server errors, and is
that new" — and puts the answer next to the site's uptime history, which is
where you look when you are deciding whether it is an incident.

## Nothing is arriving. What do I check?

In order:

1. `MONITORING_CLIENT_ENABLED=true`. It is false by default and stays false
   until someone sets it.
2. `MONITORING_CLIENT_ENDPOINTS` is set and reachable from the site. An enabled
   site with no endpoint is treated as switched off, because there is nowhere
   for a signal to go.
3. The token for the thing you are testing is set. A missing token is a silent
   no-op by design — a job carrying a heartbeat declaration has to keep running
   on a laptop where nobody set the env var.
4. `MONITORING_CLIENT_LOG_FAILURES=true`, then look for
   `bot-cop-traffic-client: send failed` at **debug** level. If your log level is
   `info` or higher you will not see it.
5. For exception repeats specifically: the scheduler has to be running, or
   `monitoring:flush-exceptions` never fires. First occurrences do not wait for
   the flush, so if first occurrences arrive and repeats do not, this is why.

`php artisan monitoring:deploy start` is the quickest end-to-end test: it uses
the same transport as everything else and tells you whether it thinks the site
is configured.
