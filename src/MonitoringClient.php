<?php

namespace Abigah\BotCopTrafficClient;

use Abigah\BotCopTrafficClient\Contracts\Transport;
use Abigah\BotCopTrafficClient\Enums\PingKind;
use Abigah\BotCopTrafficClient\Support\Signal;
use Abigah\BotCopTrafficClient\Transport\RecordingTransport;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;

/**
 * Everything a monitored site can say, and the whole of the package's public
 * surface.
 *
 * Four verbs, all of them fire-and-forget and none of them able to throw:
 * ping, start, fail and report. A site that only wants heartbeats never touches
 * the rest, and a site that wants none of it leaves the master switch off and
 * pays for a method call.
 */
class MonitoringClient
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    /**
     * A heartbeat, or the finish of a piece of work that opened with start().
     *
     * There is no finish() by that name because a finish and a heartbeat are
     * the same signal: "this ran, and here is how it went". Whether the hub
     * treats it as a heartbeat or as the close of an event window is decided by
     * how the token was declared, not by how it was sent.
     */
    public function ping(?string $token, ?string $message = null, ?int $durationMs = null): void
    {
        $this->dispatch($token, PingKind::Ping, $message, $durationMs);
    }

    /** Opens a window. A start with no finish inside the hub's timeout is itself a verdict. */
    public function start(?string $token, ?string $message = null): void
    {
        $this->dispatch($token, PingKind::Start, $message);
    }

    /** Says so explicitly, rather than leaving the timeout to infer it. */
    public function fail(?string $token, ?string $message = null, ?int $durationMs = null): void
    {
        $this->dispatch($token, PingKind::Fail, $message, $durationMs);
    }

    /**
     * Delivers a batch of exception fingerprints.
     *
     * Normally called by the reporter rather than by application code; it is
     * public because a site with its own idea of what counts as an error should
     * be able to use the transport without adopting the reportable() hook.
     *
     * @param  list<array<string, mixed>>  $fingerprints
     */
    public function report(array $fingerprints, ?string $token = null): void
    {
        $token ??= $this->config->get('monitoring-client.exceptions.token');

        if ($fingerprints === [] || ! $this->usable($token)) {
            return;
        }

        $this->transport()->send(Signal::report($token, $fingerprints));
    }

    /**
     * Whether signals leave this site at all: the master switch, plus at least
     * one endpoint to send to. A site with the switch on and no endpoint
     * configured is misconfigured rather than enabled, and is treated as off.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('monitoring-client.enabled')
            && $this->endpoints() !== [];
    }

    /** @return list<string> */
    public function endpoints(): array
    {
        $endpoints = $this->config->get('monitoring-client.endpoints', []);

        return array_values(array_filter(
            is_array($endpoints) ? $endpoints : [],
            static fn ($endpoint) => is_string($endpoint) && $endpoint !== '',
        ));
    }

    /**
     * Swaps the transport for one that records, and hands it back so a test can
     * assert against it. The application's own suite is the main audience.
     *
     * It switches the package on and supplies an endpoint as well, because the
     * shipped defaults are off and endpoint-less — which is right for a laptop
     * and useless in a test that is trying to prove a ping was sent.
     */
    public function fake(): RecordingTransport
    {
        $this->config->set('monitoring-client.enabled', true);

        if ($this->endpoints() === []) {
            $this->config->set('monitoring-client.endpoints', ['https://prober.test']);
        }

        $recorder = new RecordingTransport;

        $this->container->instance(Transport::class, $recorder);

        return $recorder;
    }

    private function dispatch(?string $token, PingKind $kind, ?string $message, ?int $durationMs = null): void
    {
        if (! $this->usable($token)) {
            return;
        }

        $this->transport()->send(Signal::ping($token, $kind, $message, $durationMs));
    }

    /**
     * A null or blank token is the ordinary state of an unconfigured site, not
     * a mistake worth an exception: a job carrying a heartbeat declaration
     * should keep running on a laptop where nobody set the token.
     */
    private function usable(?string $token): bool
    {
        return $this->enabled() && is_string($token) && trim($token) !== '';
    }

    private function transport(): Transport
    {
        return $this->container->make(Transport::class);
    }
}
