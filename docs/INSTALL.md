# Installing on a monitored site

Written for someone — or something — working in the *site's* repo rather than in
this one. Follow it top to bottom; it is deliberately literal.

## The shape of it

A monitored site sends three kinds of signal — job heartbeats, deploy events and
server exceptions — to **the prober**, which is outside your infrastructure:

```
your site  ──ping/report──▶  prober  ──delivers──▶  hub (the extranet)
                                                     └─ owns every alerting decision
```

The prober's production URL is:

```
https://bot-cop-traffic-prober.thelifeproject.workers.dev
```

That is the endpoint you configure, and it does not change per site. What
changes per site is the **tokens**, which are issued by the hub.

You do not point a site at the hub. The hub can accept these signals directly —
it has the same endpoints for the transitional period before an extranet's
prober is live — but that is a migration state, not a destination, and a site
configured against it has to be reconfigured later. See
[During the transition](#during-the-transition) if you have been told the
extranet is still running in `local` mode.

## Before you start: is your tenant live on the prober?

**Ask, do not assume.** The prober only accepts a token it has learned from a
manifest, which requires the extranet to be configured as a tenant on the prober
with a shared secret. Until that is done for your extranet, pings reach the
prober and resolve to nothing.

If the answer is no, you can still complete every step here except switching it
on: install, publish, declare the heartbeats, wire the deploy script, and leave
`MONITORING_CLIENT_ENABLED=false` until you are told the tenant is live. That is
a legitimate and useful place to stop — it is design §12 step 2, and it means
the switch-on later is one environment variable.

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

### Production

```dotenv
MONITORING_CLIENT_ENABLED=true
MONITORING_CLIENT_ENDPOINTS=https://bot-cop-traffic-prober.thelifeproject.workers.dev
```

That endpoint is the same on every monitored site. Do not substitute an
extranet's URL for it — see [The shape of it](#the-shape-of-it).

Then one line per signal the site sends, with values from the hub (step 4):

```dotenv
MONITORING_CLIENT_DEPLOYMENT_TOKEN=
MONITORING_CLIENT_EXCEPTIONS_ENABLED=true
MONITORING_CLIENT_INGEST_TOKEN=
HEARTBEAT_NIGHTLY_DIGEST=
```

### Staging, local, CI, and any clone of production

```dotenv
MONITORING_CLIENT_ENABLED=false
```

**Set this explicitly in every non-production environment**, and leave the
tokens in place. This is the whole reason the flag exists and defaults to false:
a staging clone restored from a production database and `.env` would otherwise
start reporting as production the moment it boots, and the resulting heartbeats
are indistinguishable from the real site's. You would be debugging a job that is
running fine.

Everything else in the package can stay identically configured across
environments. One flag is the difference.

### `.env.example`

Add every variable above to `.env.example` with empty values, including
`MONITORING_CLIENT_ENABLED=false`. The next person to clone the repo inherits
the safe posture rather than having to know about it.

## 4. Declare what the site will report

This is the part that needs someone who knows the site.

### Where tokens come from

Every token below is issued by **the hub**, on the extranet that monitors this
site — not by this package and not by the prober. Someone with access to the
extranet creates the site's scheduled work on its site page and copies the
token out; they can rotate it there later without touching the site's code.

Tokens are 48 characters. Treat each as a credential: environment only, never
committed, never in the config file.

The prober learns them from the hub's manifest, which is why a token works
within a reconcile of being issued rather than instantly. If a brand-new token
seems ignored for a few minutes, that is why, and it resolves itself.

A missing or empty token is a **silent no-op**, by design — a job carrying a
heartbeat declaration has to keep running in an environment nobody configured.
So it is safe to declare everything now and fill the values in as they are
issued.

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

Add a `HEARTBEAT_*` line per job to `.env` (with the token from the hub) and to
`.env.example` (empty).

### Deploy pings

Add to the deploy script, around the existing steps:

```sh
php artisan monitoring:deploy start
# … build, migrate, restart …
php artisan monitoring:deploy finish
```

and on the failure path, `php artisan monitoring:deploy fail --message="…"`.

The command always exits 0, so this is safe to add to a live deploy script
whether or not monitoring is switched on yet. Set
`MONITORING_CLIENT_DEPLOYMENT_TOKEN` from the hub.

One pair per deploy target, not per monitor: the window belongs to the site.

### `/up`

Two changes, both worth making regardless of whether monitoring is switched on
yet — neither depends on it.

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

In production, on: `MONITORING_CLIENT_EXCEPTIONS_ENABLED=true`, with the site's
`MONITORING_CLIENT_INGEST_TOKEN` from the hub. The ingest token belongs to the
site rather than to a single job, and it is the same one for every exception the
site reports.

Repeats are flushed by a scheduled command, so the site needs its scheduler
running — it almost certainly already does. First occurrences do not wait for
the flush.

If the site already runs Sentry or Flare, that is fine and expected — this hooks
in through `reportable()`, which adds a listener rather than replacing the
handler. Nothing existing changes.

## 5. Verifying

```sh
php artisan monitoring:deploy start
```

**In production, with a token set**, expect:

```
Deployment start ping sent.
```

The command is fire-and-forget, so "sent" means it was dispatched, not that the
prober liked it. Confirm the other end: the deploy window should appear on the
site's page on the extranet. If it does not, work the checklist below.

**Before the tenant is live, or in any environment with the switch off**,
expect:

```
Monitoring is not configured on this site; start ping skipped.
```

That is also a passing result — it proves the package is discovered, the command
is registered, the config is readable and the guard correctly refuses to send
without configuration. Exit code 0 either way; the command never fails a deploy.

### If a signal does not arrive

In order, because this is sorted by how often each is the real cause:

1. `MONITORING_CLIENT_ENABLED` is not `true` in the environment you are actually
   running in. Check the running environment, not the file you edited.
2. The token is empty. A missing token is silent by design.
3. The tenant is not live on the prober yet, or the token was issued so recently
   that the manifest has not been pulled. Ask, rather than assume it is broken.
4. Turn on `MONITORING_CLIENT_LOG_FAILURES=true` and look for
   `bot-cop-traffic-client: send failed` at **debug** level. If the site's log
   level is `info` or higher you will not see it.
5. For exception *repeats* specifically: the scheduler must be running.

Also confirm the site's own test suite still passes. This package adds a queue
event listener and an exception-handler callback; neither should change any
behaviour, and a break is a bug worth reporting back rather than working around.

## During the transition

Some extranets run in `local` mode for a while before their prober is turned on.
In that state the **hub** accepts these signals directly, at its own URL under
its API prefix:

```
https://{extranet}/monitoring/ping/{token}
```

You do not have to choose. `MONITORING_CLIENT_ENDPOINTS` is a comma-separated
list and every endpoint receives every signal, so list both and the site is
correct before, during and after the switch:

```dotenv
MONITORING_CLIENT_ENDPOINTS=https://bot-cop-traffic-prober.thelifeproject.workers.dev,https://{extranet}/monitoring
```

Endpoints are called concurrently, so the second one costs no extra latency, and
a signal arriving at both is harmless — the hub is idempotent on these. Drop the
extranet entry once the prober is authoritative.

Only do this if you have been told the extranet is in `local` mode. Otherwise
the production endpoint alone is correct.

## 6. What to hand back

Report these, briefly:

- Which jobs you declared heartbeats for, and the env var name for each.
- Where the deploy pings went in the deploy script.
- Whether the `/up` middleware and the CDN cache rule were applied, and if the
  CDN rule was not, say so — it cannot be checked from the repo.
- Which environments you set `MONITORING_CLIENT_ENABLED=false` in.
- Any job you considered and deliberately skipped, with the reason.
- Whether signals were confirmed arriving on the extranet, or whether the site
  is staged and waiting for its tenant to go live.

The env var names are the useful part: whoever issues tokens needs to know what
to issue and what it is called.
