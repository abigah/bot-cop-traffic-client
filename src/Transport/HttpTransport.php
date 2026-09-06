<?php

namespace Abigah\BotCopTrafficClient\Transport;

use Abigah\BotCopTrafficClient\Contracts\Transport;
use Abigah\BotCopTrafficClient\Support\Signal;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The real transport: one short-timeout HTTP call per endpoint, sent
 * concurrently, with every failure swallowed.
 *
 * Fan-out is unconditional. The client cannot know which endpoint is listening
 * — in remote mode these are the probers, in local mode the hub — and a ping
 * that reaches two of them is idempotent, while one that reaches none is a
 * missed heartbeat. So it sends to all of them and does not care which
 * answered.
 *
 * Concurrently, because the alternative multiplies the timeout by the number of
 * endpoints. A caller that agreed to wait two seconds for monitoring did not
 * agree to wait two more because a second prober was deployed.
 *
 * There are no retries. A retry doubles the time the caller waits for something
 * it is not allowed to fail on, and the next heartbeat is a better retry than
 * an immediate one: if the endpoint is unreachable now it is probably still
 * unreachable in a millisecond, and if the site itself is down then the missed
 * ping is the signal rather than a lost one.
 */
final class HttpTransport implements Transport
{
    /**
     * @param  list<string>  $endpoints
     */
    public function __construct(
        private readonly Http $http,
        private readonly array $endpoints,
        private readonly float $timeout = 2.0,
        private readonly float $connectTimeout = 1.0,
        private readonly bool $logFailures = false,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function send(Signal $signal): void
    {
        if ($this->endpoints === []) {
            return;
        }

        try {
            $responses = $this->http->pool(fn (Pool $pool) => array_map(
                fn (string $endpoint) => $this->request($pool, $endpoint, $signal),
                $this->endpoints,
            ));
        } catch (Throwable $e) {
            // Deliberately broad. Anything at all from the HTTP stack — a DNS
            // failure, a TLS error, a malformed configured URL — is a
            // monitoring problem, and a monitoring problem must not become the
            // caller's problem.
            $this->report($signal, null, $e->getMessage());

            return;
        }

        if ($this->logFailures) {
            $this->inspect($signal, $responses);
        }
    }

    /**
     * A pooled request answers with a Response or, when it could not be made at
     * all, with the Throwable instead of raising it. Both are outcomes here.
     */
    private function request(Pool $pool, string $endpoint, Signal $signal): mixed
    {
        $request = $pool
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders(['X-Monitoring-Schema' => '1'])
            ->withUserAgent('bot-cop-traffic-client');

        $url = $signal->url($endpoint);

        return $signal->body === null
            ? $request->get($url)
            : $request->asJson()->post($url, $signal->body);
    }

    /**
     * @param  array<int, mixed>  $responses
     */
    private function inspect(Signal $signal, array $responses): void
    {
        foreach (array_values($responses) as $index => $response) {
            $endpoint = $this->endpoints[$index] ?? null;

            if ($response instanceof Throwable) {
                $this->report($signal, $endpoint, $response->getMessage());
            } elseif ($response instanceof Response && $response->failed()) {
                $this->report($signal, $endpoint, 'HTTP '.$response->status());
            }
        }
    }

    private function report(Signal $signal, ?string $endpoint, string $reason): void
    {
        if (! $this->logFailures) {
            return;
        }

        // Debug, never higher. A site whose logs fill with monitoring warnings
        // stops reading its logs, which costs more than the missed ping.
        $this->logger?->debug('bot-cop-traffic-client: send failed', [
            'endpoint' => $endpoint,
            'path' => $signal->path,
            'reason' => $reason,
        ]);
    }
}
