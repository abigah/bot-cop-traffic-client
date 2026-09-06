# bot-cop-traffic-client

The site-side toolkit for Bot Cop Traffic Division, an uptime and application
monitoring system. Installed on a monitored site, it reports job heartbeats,
deploy events and server exceptions. It performs no checks and makes no
decisions; the hub owns all alerting.

## Read first

- `README.md` — what the package does and how a site uses it.
- `docs/HANDOFF.md` — where things stand and what is left.
- `docs/faq.md` — the `/up` never-cached rule and the site-setup questions.
- `../bot-cop-traffic-prober/docs/monitoring-prober-design.md` — the full design.
  §4a is the exception reporter, §11.10 the package split, §12 step 2 this
  package's place in the migration.

Do not re-open decisions recorded there without saying so explicitly.

## Conventions

- PHP 8.2+, Laravel 12/13, namespace `Abigah\BotCopTrafficClient`, matching
  `Abigah\BotCopJunkDivision`. Pest + Testbench; `composer check` runs
  `pint --test` then `pest`.
- **No UI dependencies.** No Livewire, no Flux, no views, no migrations, no
  models. The hub has all of those; this package must install on any Laravel or
  Statamic site in a minute. A change that adds one of them belongs in the hub.
- **Nothing here may throw, and nothing may block for long.** A monitoring ping
  must never be the reason a job, a deploy or a request fails. Every send is
  fire-and-forget: short timeout, no retries, `Throwable` swallowed. The
  exception reporter runs inside the exception handler, where throwing is least
  forgivable of all.
- **A missing token is a no-op, not an error.** A job carrying a heartbeat
  declaration has to keep running on a laptop where nobody set the env var.
- `MONITORING_CLIENT_ENABLED` defaults to false so a staging clone of a
  production `.env` cannot start reporting as production.
- Each feature sits behind its own config toggle. The package is intended to
  grow into the general site-side integration point — webhooks and other signals
  a site sends or receives — rather than spawning further packages.
- The client speaks two of the eight contracts: the ping body and the exception
  report. Everything it builds is validated against the pinned schemas in
  `tests/contracts` rather than against a restatement of them.
- `bot-cop-traffic-contracts` is a submodule at `tests/contracts`, pinned to a
  commit. Fresh clone: `composer contracts:init`. Change a contract in that repo
  first, commit and push it there, then `composer contracts:update` and commit
  the moved pin here — never edit `tests/contracts/` in place.
- Never edit anything under `vendor/`.

## Where the pieces are

| Path | Holds |
|---|---|
| `src/MonitoringClient.php` | the whole public surface: ping, start, fail, report |
| `src/Support/Signal.php` | one outbound call, described rather than performed |
| `src/Transport/` | how a signal leaves: HTTP (concurrent fan-out), queued, null, recording |
| `src/Heartbeats/` | the `SendsHeartbeat` trait and the class→token registry |
| `src/Listeners/` | the no-touch heartbeat path, on `JobProcessing`/`Processed`/`Failed` |
| `src/Exceptions/` | the reporter and the message scrubber |
| `src/Console/` | `monitoring:deploy` and `monitoring:flush-exceptions` |
| `src/Http/Middleware/NeverCache.php` | the app's half of the `/up` never-cached rule |

## Sibling repos

- `../bot-cop-traffic-division` — the hub the extranets run; depends on this
  package for its own pings.
- `../bot-cop-traffic-prober` — the Cloudflare Worker; receives this package's
  pings at `/ping/{token}` and `/report/{token}`.
- `../bot-cop-traffic-contracts` — the conformance kit.
- `../laravel-monitoring` — the reference package, kept untouched.
