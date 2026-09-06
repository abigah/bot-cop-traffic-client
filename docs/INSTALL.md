# Installing on a monitored site

Written for someone — or something — working in the *site's* repo rather than in
this one. Follow it top to bottom; it is deliberately literal.

## Read this first: what you can and cannot finish today

You can install this package now and you should. You **cannot** make signals
arrive yet, and you should not spend time trying.

The receiving end is not built. The prober is deployed but inert, and the tokens
this package needs are issued by the hub (`bot-cop-traffic-division`), which does
not exist yet. There is nowhere for a ping to go and nothing to authenticate it.

So the finish line for an installation today is:

> The package is installed, its config is published, its heartbeats and deploy
> hooks are declared, and `MONITORING_CLIENT_ENABLED` is **false**.

That is a complete, correct install. Everything is staged and switched off, and
turning it on later is two environment variables. Do not treat the absence of
traffic as a problem to debug — see [Verifying](#verifying) for what success
actually looks like.

If you have been given a real endpoint and real tokens, then the hub exists and
this document is out of date; check `docs/HANDOFF.md` in this repo.

## Prerequisites

- PHP 8.2+, Laravel 12 or 13. Laravel 11 is **not** supported: every 11.x release
  is currently blocked by security advisories, so it cannot be installed or
  tested against.
- The machine running `composer` needs read access to the private
  `abigah/bot-cop-traffic-client` repo on GitHub. If `gh auth status` is happy
  and git uses a credential helper, Composer will be too.
- The site should have a scheduler running if you intend to use exception
  reporting. It almost certainly already does.

## 1. Add the repository and require the package

This package is **private and not on Packagist**, so a bare
`composer require abigah/bot-cop-traffic-client` will fail. Add a VCS repository
first — the same pattern the extranets already use for `abigah/laravel-monitoring`.

In the site's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/abigah/bot-cop-traffic-client" }
]
```

Then:

```sh
composer require "abigah/bot-cop-traffic-client:dev-main"
```

**No `minimum-stability` change is needed.** Composer sets the stability flag
implicitly from the `dev-main` constraint. If you find yourself editing
`minimum-stability`, stop — something else is wrong.

Once this package is tagged, the constraint becomes a normal one and the
repository entry stays exactly as it is:

```sh
composer require "abigah/bot-cop-traffic-client:^0.1.0"
```

There is no tag yet. Use `dev-main` and do not invent a version.

### Statamic sites

Nothing extra. This is a plain Laravel package with no Statamic dependency, and
it is auto-discovered like any other. Do not add it to any addon list.

## 2. Publish the config

```sh
php artisan vendor:publish --tag=monitoring-client-config
```

That writes `config/monitoring-client.php`. Read it — the comments in it are the
reference for every option and are more current than any summary.

## 3. Environment

Add to `.env` **and** to `.env.example`:

```dotenv
MONITORING_CLIENT_ENABLED=false
MONITORING_CLIENT_ENDPOINTS=
```

Leave both as shown. `MONITORING_CLIENT_ENABLED` defaults to false anyway, but
setting it explicitly documents the intent and gives whoever switches it on
later something to find.

Do not guess an endpoint URL. An enabled site with no endpoint is treated as
switched off, and an enabled site with a *wrong* endpoint spends a timeout on
every ping for nothing.

## 4. Declare what the site will report

This is the part worth doing carefully now, because it is the part that needs
someone who knows the site. Tokens are unavailable, so every declaration below
reads an env var that is currently empty — which is a silent no-op by design.

### Heartbeats

Pick the jobs whose *silence* is a problem. A nightly digest, an hourly import, a
scheduled reconciliation. Prefer jobs that already run on a schedule over adding
one: a synthetic heartbeat job proves only that the thing you added for
monitoring is running.

For a job whose code you should not touch, map it in
`config/monitoring-client.php`:

```php
'heartbeats' => [
    'jobs' => [
        \App\Jobs\SendNightlyDigest::class => env('HEARTBEAT_NIGHTLY_DIGEST'),
    ],
],
```

For a job that knows more about its outcome than "handle() returned", use the
trait instead — see the README. Use one mechanism or the other per job, not both.

Add an empty `HEARTBEAT_*` line to `.env` and `.env.example` for each one.

### Deploy pings

Add to the deploy script, around the existing steps:

```sh
php artisan monitoring:deploy start
# … build, migrate, restart …
php artisan monitoring:deploy finish
```

and on the failure path, `php artisan monitoring:deploy fail --message="…"`.

The command always exits 0, so this is safe to add to a live deploy script
before monitoring is switched on. Add an empty
`MONITORING_CLIENT_DEPLOYMENT_TOKEN` to `.env` and `.env.example`.

### `/up`

Two changes, both worth making now because neither depends on monitoring being
live.

Apply the never-cache middleware to the health route:

```php
use Abigah\BotCopTrafficClient\Http\Middleware\NeverCache;
```

```php
->withRouting(health: '/up')
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('health', [NeverCache::class]);
})
```

And if the site is behind Cloudflare, add a Cache Rule bypassing cache for
`/up`, **above** any "Cache Everything" rule. Headers alone will not save you
from one. See `docs/faq.md` in this repo for why this matters more than it
looks like it does.

### Exception reporting

Leave `MONITORING_CLIENT_EXCEPTIONS_ENABLED=false` for now. Add the line, and
add an empty `MONITORING_CLIENT_INGEST_TOKEN`, so both are visible.

If the site already runs Sentry or Flare, that is fine and expected — this hooks
in through `reportable()`, which adds a listener rather than replacing the
handler. Nothing existing changes.

## 5. Verifying

Success today looks like **nothing happening**, so verify the wiring rather than
the traffic.

```sh
php artisan monitoring:deploy start
```

Expected output:

```
Monitoring is not configured on this site; start ping skipped.
```

That message is the install working. It proves the package is discovered, the
command is registered, the config is readable, and the guard correctly refuses
to send without configuration. Exit code 0.

If you instead see `Deployment start ping sent.`, then `MONITORING_CLIENT_ENABLED`
is true and an endpoint is set — which is wrong for today unless you were
explicitly given both.

Also confirm the site's own test suite still passes. This package adds a queue
event listener and an exception-handler callback; neither should change any
behaviour, and if something breaks, that is a bug in this package worth
reporting back rather than working around.

## 6. What to hand back

Report these, briefly:

- Which jobs you declared heartbeats for, and the env var name for each.
- Where the deploy pings went in the deploy script.
- Whether the `/up` middleware and the CDN cache rule were applied, and if the
  CDN rule was not, say so — it cannot be checked from the repo.
- Any job you considered and deliberately skipped, with the reason.

The env var names are the useful part: whoever issues tokens later needs to know
what to issue and what to call it.

## Turning it on, later

When the hub exists and tokens have been issued:

```dotenv
MONITORING_CLIENT_ENABLED=true
MONITORING_CLIENT_ENDPOINTS=https://…
HEARTBEAT_NIGHTLY_DIGEST=…
MONITORING_CLIENT_DEPLOYMENT_TOKEN=…
```

Nothing in the code changes. If signals do not arrive, work through the
"Nothing is arriving" checklist at the end of `docs/faq.md` — in order, because
it is sorted by how often each cause is the real one.
