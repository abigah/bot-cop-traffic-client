# bot-cop-traffic-client

The site-side toolkit for **Bot Cop Traffic Division**. Install it on a
monitored site and it can report three things: that its jobs are running, that a
deploy started and finished, and that it is throwing server errors.

No UI dependencies, no database, no migrations. It works on any Laravel or
Statamic site, and a site that installs it needs nothing else.

It does **not** perform uptime checks — those come from outside, from the prober
— and it does not decide anything. Every signal it sends is raw; the hub owns
every alerting decision.

## Install

**Installing this on a site? Follow [docs/INSTALL.md](docs/INSTALL.md)** — it is
written to be followed literally, and covers what this section glosses over: the
package is private and unpublished, where the tokens come from, and which
environments get switched on.

The short version. This package is not on Packagist, so the site's
`composer.json` needs a VCS repository entry:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/abigah/bot-cop-traffic-client" }
]
```

```sh
composer require "abigah/bot-cop-traffic-client:dev-main"
php artisan vendor:publish --tag=monitoring-client-config
```

There is no tag yet, so the constraint is `dev-main`. No `minimum-stability`
change is needed — Composer sets the flag implicitly from a `dev-` constraint.

Then, in `.env`, on **production**:

```dotenv
MONITORING_CLIENT_ENABLED=true
MONITORING_CLIENT_ENDPOINTS=https://your-prober.example
```

`your-prober.example` is a placeholder — ask whoever runs the prober for the
real hostname. It is not published here: it is an endpoint anyone could send
requests to, and the prober is billed per request.

and on staging, local, CI, or any clone of production:

```dotenv
MONITORING_CLIENT_ENABLED=false
```

`MONITORING_CLIENT_ENABLED` defaults to **false**, deliberately: a staging clone
restored from a production `.env` should not start reporting as production the
moment it boots. Nothing leaves the site until it is switched on and given
somewhere to send.

`MONITORING_CLIENT_ENDPOINTS` is a comma-separated list of base URLs. In remote
mode these are the probers; in local mode it is the hub accepting pings
directly. The client cannot tell the difference and does not need to — every
endpoint gets every signal, because a ping that arrives twice is harmless and
one that arrives nowhere is a missed heartbeat.

## The one rule

**Nothing in this package can throw, and nothing in it can block for long.** A
monitoring ping must never be the reason a job, a deploy or a request fails, so
the failure mode of the whole package is silence: a short timeout, no retries,
every error swallowed. Set `MONITORING_CLIENT_LOG_FAILURES=true` while you are
wiring a site up and it will write a debug line instead of nothing.

## Heartbeats

A heartbeat is a real job saying it ran. Not a synthetic job — an hourly job you
already have is proof that the scheduler and the queue are alive, and it adds no
extra wake to a site that hibernates.

Tokens are issued per heartbeat by the hub and appear in its manifest. Keep them
in the environment; a token is a credential.

### Without touching the job

Map the class to its token in `config/monitoring-client.php`:

```php
'heartbeats' => [
    'jobs' => [
        \App\Jobs\SendNightlyDigest::class => env('HEARTBEAT_NIGHTLY_DIGEST'),
    ],
],
```

The package listens for `JobProcessed` and `JobFailed` and pings on the way out,
with the duration. The job's own code is untouched, which is what makes this
usable for a job from a package you do not own.

What it can report is correspondingly shallow: success here means `handle()`
returned.

### From inside the job

When the job knows something the queue does not — that it imported zero rows,
that a reconciliation did not balance — add the trait and say so:

```php
use Abigah\BotCopTrafficClient\Heartbeats\SendsHeartbeat;

class ImportOrders implements ShouldQueue
{
    use SendsHeartbeat;

    public function heartbeatToken(): ?string
    {
        return env('HEARTBEAT_IMPORT_ORDERS');
    }

    public function handle(): void
    {
        $this->heartbeatStarted();

        $rows = $this->import();

        $rows > 0
            ? $this->heartbeat("imported {$rows} rows")
            : $this->heartbeatFailed('imported nothing, which is never right');
    }
}
```

`heartbeatStarted()` opens a window: a start with no finish inside the hub's
timeout is itself a verdict, which is what catches a job that hung rather than
one that failed.

Use one mechanism or the other for a given job. Both works, but sends two pings
for one run.

### Scheduled work

A scheduled job needs nothing extra. `Schedule::job()` dispatches a `ShouldQueue`
job through the queue, so `JobProcessed` fires and the registry above pings it
like any other job — the scheduler only decides *when*:

```php
Schedule::job(new SendNightlyDigest)->dailyAt('06:00');
```

That is the intended shape: the heartbeat rides on work the site already does,
and there is no separate heartbeat job to add.

Two cases bypass the queue, and the registry cannot see either of them:

| | Why |
|---|---|
| `Schedule::command('foo:bar')` | A console command was never a job. |
| `Schedule::job(new PlainJob)` where the job is **not** `ShouldQueue` | `Schedule::job()` falls back to `dispatchNow()`. |

Both are still one line each, from the scheduler's own hooks:

```php
Schedule::command('entries:handle-hourly-schedule')
    ->hourly()
    ->onSuccess(fn () => MonitoringClient::ping(config('monitoring-client.heartbeats.scheduled.entries')))
    ->onFailure(fn () => MonitoringClient::fail(config('monitoring-client.heartbeats.scheduled.entries')));
```

Read the token from **config, not `env()`**. `env()` outside a config file
returns null once `config:cache` has run, which is normal in production — and a
null token is a silent no-op, so the heartbeat would stop firing exactly where
it matters and nowhere you would notice.

## Deploy pings

A deploy is an event heartbeat. Call the command from the deploy script:

```sh
php artisan monitoring:deploy start
# … build, migrate, restart …
php artisan monitoring:deploy finish
```

and on the failure path:

```sh
php artisan monitoring:deploy fail --message="composer install failed"
```

It always exits 0. A deploy script that stops because monitoring was unreachable
has been made *less* reliable by being monitored.

Set the token with `MONITORING_CLIENT_DEPLOYMENT_TOKEN`.

## Exception reporting

Off by default. Switch it on with:

```dotenv
MONITORING_CLIENT_EXCEPTIONS_ENABLED=true
MONITORING_CLIENT_INGEST_TOKEN=…
```

It answers one question — *is this site throwing server errors, and is that
new?* It is **not** an error tracker: no trace explorer, no source context, no
releases. A site that wants those runs Flare or Sentry as well, and the two do
not conflict.

How it behaves:

- It reports only what the application itself would report. `dontReport` and
  `shouldReport()` are respected, because it hooks in through `reportable()` and
  Laravel applies those first.
- On top of that it skips anything that is not a genuine server error: HTTP
  exceptions below 500, validation, authentication, authorisation,
  record-not-found, CSRF mismatch, throttling, and maintenance mode.
- Each exception is fingerprinted on **class + file + line**, not on the
  message. The same fault produces messages differing by id or bound parameter,
  and fingerprinting on those would make every occurrence new and every
  occurrence a page.
- The **first** occurrence of a fingerprint is sent immediately. Repeats are
  counted locally and flushed as counts every five minutes, so a 500 storm costs
  one request per flush rather than one per exception.
- Messages are scrubbed (emails, credentials, tokens, long digit runs) and
  truncated. Traces are **opt-in** and trimmed.

Scrubbing is a safety net, not a guarantee — it only knows the shapes it is told
about. The real rule is still not to put secrets in exception messages.

The flush is scheduled for you when reporting is on. It needs the scheduler
running, which a monitored site has anyway.

## `/up`

The recommended monitors for a site are its homepage with a look-for string, and
Laravel's `/up`. Two things are worth knowing, both covered in
[docs/faq.md](docs/faq.md):

1. **`/up` must never be served from cache.** A cached 200 is a check that never
   reached the origin. Apply the shipped middleware, and add the CDN rule.

   ```php
   use Abigah\BotCopTrafficClient\Http\Middleware\NeverCache;

   Route::get('/up', /* … */)->middleware(NeverCache::class);
   ```

   That works for a route you define. Laravel's **built-in** health route
   (`withRouting(health: '/up')`) has no middleware group to attach to — see
   [docs/INSTALL.md](docs/INSTALL.md) for the one-line wrapper that covers it.

2. **`/up` only proves the framework booted.** This package adds no health
   logic. If you want it to mean more, listen for `DiagnosingHealth` — the FAQ
   shows how.

## Testing a site that uses this

```php
$monitoring = MonitoringClient::fake();

ImportOrders::dispatch();

expect($monitoring->paths())->toContain('/ping/'.config('services.heartbeat.import'));
```

`fake()` swaps the transport for one that records, and switches the package on
so a default-off site can still be tested.

## Development

```sh
composer contracts:init   # fresh clone: pull the pinned conformance kit
composer check            # pint --test, then pest
```

The client speaks two of the eight contracts — the ping body and the exception
report — and its tests validate what it actually builds against the pinned
schemas in `tests/contracts`, rather than against a restatement of them.
