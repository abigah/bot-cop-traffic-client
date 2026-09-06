<?php

namespace Abigah\BotCopTrafficClient\Exceptions;

use Abigah\BotCopTrafficClient\MonitoringClient;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Answers one question: is this site throwing server errors, and is that new?
 *
 * The shape of it is a first-occurrence rule. A fingerprint seen for the first
 * time is sent at once, because a new kind of error is the thing worth waking
 * someone for; every repeat after that is counted locally and flushed as a
 * number. A site in a 500 storm therefore costs one request per flush rather
 * than one per exception, which is what stops the reporter becoming the outage.
 *
 * Everything here is best-effort. It runs inside the exception handler, so a
 * failure here would be an error raised while reporting an error — the one
 * place in an application where throwing is least forgivable.
 */
class ExceptionReporter
{
    private const PREFIX = 'bot-cop-traffic-client:exceptions:';

    public function __construct(
        private readonly MonitoringClient $client,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly Scrubber $scrubber,
        private readonly string $basePath = '',
    ) {}

    /**
     * Registered as a reportable() callback, so Laravel has already applied the
     * application's own dontReport list and shouldReport() before this runs.
     * What is left to decide is only whether this is a genuine server error.
     */
    public function report(Throwable $e): void
    {
        try {
            if (! $this->enabled() || ! $this->reportable($e)) {
                return;
            }

            $this->record($e);
        } catch (Throwable) {
            // An exception raised while reporting an exception is the one thing
            // this class must never do.
        }
    }

    /**
     * Sends everything counted since the last flush and clears the counters.
     *
     * Called by the scheduled monitoring:flush-exceptions command. Returns how
     * many fingerprints went out, which is what the command reports.
     */
    public function flush(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $pending = $this->cache->pull($this->key('pending'), []);

        if (! is_array($pending) || $pending === []) {
            return 0;
        }

        $payload = [];

        foreach (array_unique($pending) as $fingerprint) {
            $entry = $this->take((string) $fingerprint);

            if ($entry !== null) {
                $payload[] = $entry;
            }
        }

        if ($payload === []) {
            return 0;
        }

        // The schema caps a report at 100 fingerprints. The excess is dropped
        // rather than split across requests: a site throwing more than 100
        // distinct errors in one flush interval has one problem, not a hundred,
        // and the first hundred describe it.
        $max = (int) $this->config->get('monitoring-client.exceptions.max_fingerprints', 100);
        $payload = array_slice($payload, 0, max(1, $max));

        $this->client->report($payload);

        return count($payload);
    }

    /**
     * Forgets a fingerprint, so a recurrence counts as new again.
     *
     * The hub calls the equivalent of this when a fingerprint is resolved or a
     * deploy finishes; the site side has it so that a deploy script can clear
     * the slate locally at the same moment.
     */
    public function forget(string $fingerprint): void
    {
        foreach (['seen', 'record', 'count', 'last'] as $part) {
            $this->cache->forget($this->key($part.':'.$fingerprint));
        }
    }

    /**
     * class + file + line, hashed.
     *
     * Not the message: the same fault produces messages that differ by id,
     * timestamp or bound parameter, and fingerprinting on those would make
     * every occurrence a new error and every occurrence a page.
     */
    public function fingerprint(Throwable $e): string
    {
        return hash('sha256', implode('|', [
            $e::class,
            $this->relative($e->getFile()),
            $e->getLine(),
        ]));
    }

    /**
     * Whether this is a genuine server error.
     *
     * HTTP exceptions are judged by status — below 500 is the client's mistake,
     * which covers 404, 419 and 429 without naming any of them — and everything
     * in the ignore list is matched on instanceof, so listing a base class or
     * an interface covers what is under it.
     */
    public function reportable(Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() >= 500;
        }

        foreach ($this->config->get('monitoring-client.exceptions.ignore', []) as $ignored) {
            if (is_string($ignored) && $e instanceof $ignored) {
                return false;
            }
        }

        return true;
    }

    private function record(Throwable $e): void
    {
        $fingerprint = $this->fingerprint($e);
        $now = $this->now();
        $ttl = $this->ttl();
        $seen = $this->key('seen:'.$fingerprint);

        // add() is the whole first-occurrence rule: it writes only if the key is
        // absent, and reports which happened, in one atomic step. A plain
        // has()-then-put() would let two concurrent requests both believe they
        // were first and both page.
        $first = $this->cache->add($seen, $now, $ttl);

        if ($first) {
            $entry = $this->entry($e, $fingerprint, firstSeen: $now, lastSeen: $now, count: 1);

            $this->cache->put($this->key('record:'.$fingerprint), $entry, $ttl);
            $this->client->report([$entry]);

            return;
        }

        // A repeat. Keep the record's first_seen, move its last_seen, and add
        // to the count the next flush will carry.
        $this->cache->put($this->key('last:'.$fingerprint), $now, $ttl);

        if (! $this->cache->add($this->key('count:'.$fingerprint), 1, $ttl)) {
            $this->cache->increment($this->key('count:'.$fingerprint));
        }

        if (! $this->cache->has($this->key('record:'.$fingerprint))) {
            // The seen marker outlived its record, or the record was evicted.
            // Rebuild it from this occurrence rather than lose the repeat.
            $this->cache->put(
                $this->key('record:'.$fingerprint),
                $this->entry($e, $fingerprint, firstSeen: $now, lastSeen: $now, count: 1),
                $ttl,
            );
        }

        $this->pend($fingerprint);
    }

    /**
     * Reads one fingerprint's counted repeats and clears them.
     *
     * @return array<string, mixed>|null
     */
    private function take(string $fingerprint): ?array
    {
        $record = $this->cache->get($this->key('record:'.$fingerprint));
        $count = (int) $this->cache->pull($this->key('count:'.$fingerprint), 0);

        if (! is_array($record) || $count < 1) {
            return null;
        }

        $record['count'] = $count;
        $record['last_seen'] = $this->cache->get($this->key('last:'.$fingerprint), $record['last_seen']);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Throwable $e, string $fingerprint, string $firstSeen, string $lastSeen, int $count): array
    {
        return [
            'fingerprint' => $fingerprint,
            'class' => substr($e::class, 0, 512),
            'message' => $this->scrubber->message($e->getMessage()),
            'file' => substr($this->relative($e->getFile()), 0, 1024),
            'line' => max(0, $e->getLine()),
            'first_seen' => $firstSeen,
            'last_seen' => $lastSeen,
            'count' => $count,
            'trace' => $this->trace($e),
        ];
    }

    private function trace(Throwable $e): ?string
    {
        if (! (bool) $this->config->get('monitoring-client.exceptions.send_trace', false)) {
            return null;
        }

        return $this->scrubber->trace(
            $e->getTraceAsString(),
            (int) $this->config->get('monitoring-client.exceptions.trace_lines', 20),
        );
    }

    private function pend(string $fingerprint): void
    {
        $key = $this->key('pending');
        $pending = $this->cache->get($key, []);
        $pending = is_array($pending) ? $pending : [];

        if (! in_array($fingerprint, $pending, strict: true)) {
            $pending[] = $fingerprint;
            $this->cache->put($key, $pending, $this->ttl());
        }
    }

    /**
     * A path relative to the application root. Absolute paths leak the
     * deployment layout and differ between two servers running the same
     * release, which would fingerprint the same fault twice.
     */
    private function relative(string $path): string
    {
        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
            return ltrim(substr($path, strlen($this->basePath)), DIRECTORY_SEPARATOR);
        }

        return $path;
    }

    /** The schema pins one timestamp form: UTC, seconds, a Z suffix and no offset. */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function ttl(): int
    {
        return max(1, (int) $this->config->get('monitoring-client.exceptions.window_minutes', 60)) * 60;
    }

    private function enabled(): bool
    {
        return $this->client->enabled()
            && (bool) $this->config->get('monitoring-client.exceptions.enabled', false)
            && (string) $this->config->get('monitoring-client.exceptions.token', '') !== '';
    }

    private function key(string $suffix): string
    {
        return self::PREFIX.$suffix;
    }
}
