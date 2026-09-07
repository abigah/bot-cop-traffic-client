# Handoff — bot-cop-traffic-client

Written 2026-09-06. Read `CLAUDE.md`, then the design doc at
`../bot-cop-traffic-prober/docs/monitoring-prober-design.md` (§4a, §11.10,
§12 step 2), before doing anything.

## Where things stand

The package is built and tested: 61 tests, `composer check` clean. It is not yet
committed, published, or installed anywhere.

Everything the design lists for this package exists:

- **Transport** — `Signal` describes one call; `HttpTransport` sends it to every
  configured endpoint concurrently with a short timeout and swallows everything;
  `QueuedTransport` and `NullTransport` are the other two bindings;
  `RecordingTransport` is what `MonitoringClient::fake()` swaps in.
- **Heartbeats** — both paths. `SendsHeartbeat` for a job that knows its own
  outcome, and a config registry plus `JobProcessing`/`JobProcessed`/`JobFailed`
  listener for a job whose code cannot be touched. Durations are reported.
- **Deploy pings** — `monitoring:deploy start|finish|fail`, always exit 0.
- **Exception reporting** — `reportable()` hook, fingerprint on class+file+line,
  first occurrence sent immediately via an atomic cache `add()`, repeats counted
  and flushed by a scheduled `monitoring:flush-exceptions`, messages scrubbed and
  truncated, traces opt-in.
- **`/up`** — the `NeverCache` middleware and the FAQ covering both ends of the
  never-cached rule plus the `DiagnosingHealth` pattern.

## Decisions taken here

Recorded because they were not in the design doc and are cheap to change now.

- **`MONITORING_CLIENT_ENABLED` defaults to false.** A staging clone restored
  from a production `.env` must not start reporting as production the moment it
  boots. The cost is that a correct install needs one more line; the alternative
  is a class of incident that is very confusing to diagnose.
- **An enabled site with no endpoint is treated as disabled**, not as an error.
  There is nowhere for a signal to go, so it is a misconfiguration, and the
  package's response to a misconfiguration is silence like everything else.
- **Endpoints are fanned out to concurrently, via `Http::pool()`.** Sequential
  sends would multiply the timeout by the number of probers; a caller that
  agreed to wait two seconds did not agree to wait two more because a second
  prober was deployed.
- **A missing or blank token is a silent no-op.** The alternative — throwing, or
  logging a warning — punishes the ordinary case of a job carrying a heartbeat
  declaration on a machine nobody configured.
- **The first-occurrence rule uses the cache's atomic `add()`.** A
  `has()`-then-`put()` would let two concurrent requests both believe they were
  first and both page.
- **Fingerprints use a path relative to the application root.** Absolute paths
  leak the deployment layout and differ between two servers running the same
  release, which would fingerprint one fault twice.
- **HTTP exceptions are judged by status rather than listed in `ignore`.**
  `>= 500` reports, below does not, which covers 404, 419 and 429 without naming
  any of them.
- **`PingHeartbeatForJob` is a container singleton.** It holds job start times
  between `JobProcessing` and `JobProcessed`; a fresh listener per event loses
  them and every heartbeat reports no duration. This was a real bug, caught by
  the duration test.
- **The heartbeat registry does not walk the class hierarchy.** Two jobs sharing
  a base class are two heartbeats, and inheriting one silently would make them
  report as each other.
- **Queueing is off by default and the queued job is not retried.** A heartbeat
  on a queue cannot report that the queue has stopped, and a stale ping
  delivered minutes late is worse than no ping — it claims the site was alive at
  a moment it may not have been.
- **`NeverCache` ships here** even though the design describes the middleware as
  something the app writes. It is five lines, adds no health logic, and makes
  the `/up` guidance actionable rather than aspirational.

## What is left

1. **Nothing is committed.** `git init` is done and `origin` points at
   `git@github.com:abigah/bot-cop-traffic-client.git`, which **does not exist
   yet** — the GitHub repo has to be created in the abigah org, private, like
   its three siblings.
2. **Not installed anywhere.** Design §12 step 2 is the payoff: the client on
   every P2C site means exception reports and job heartbeats start flowing
   months before the extranets move. That needs the hub to accept pings in
   `local` mode first.

   `docs/INSTALL.md` is written for whoever does that installing. It documents
   the **production** topology — the site points at the prober, not at an
   extranet — because an extranet URL is a migration state a site would have to
   be reconfigured out of. Local mode is a footnote there, using the endpoint
   fan-out so a site is correct before, during and after the switch.

   The hub's `local` mode now accepts these signals directly at
   `{extranet}/monitoring/ping/{token}` and `/report/{token}`, which match the
   paths this package sends. Verified: the hub issues `Str::random(48)` tokens,
   which satisfy the prober's `^[A-Za-z0-9_-]{16,128}$`.

   **No tag exists**, deliberately — sites pin `dev-main` until the package has
   been proven against a live tenant. Tag `v0.1.0` at that point; the install
   instructions already cover the switch.
3. **Ping tokens are unversioned.** The contracts repo records this as open: a
   rotated token takes effect on the next manifest pull, so pings using the old
   one fail in between. Nothing to do here until the hub decides how it issues
   tokens.
4. **No Statamic-specific integration.** The design says "any Laravel or
   Statamic site", and nothing here needs Statamic — it installs and works as a
   plain Laravel package. If a Statamic addon wrapper is ever wanted, it belongs
   beside this, not inside it.
5. **`forget()` is site-local only.** The hub resets a fingerprint when it is
   resolved or a deploy finishes; the site has no way of hearing about that yet.
   Harmless — the window TTL expires it anyway — and it is the natural first
   customer for the webhook direction the design imagines this package growing.

## Verified

`composer check` — Pint clean, 61 tests, 102 assertions, all passing under
Testbench on **both Laravel 12 and Laravel 13**, which is every version the
constraint claims.

Laravel 11 was dropped from the constraint rather than left unverified: every
11.x release is currently blocked by unpatched security advisories, so Composer
will not install one and the claim could not be tested. Nothing in the package
is known to be incompatible with it; it is simply not a version anyone should be
installing onto, and every site this package targets is on 12 or 13.

The conformance tests validate the client's actual ping bodies and exception
reports against the pinned schemas in the contracts submodule, and reproduce the
kit's own exception-report example byte for byte.
