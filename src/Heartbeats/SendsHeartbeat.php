<?php

namespace Abigah\BotCopTrafficClient\Heartbeats;

use Abigah\BotCopTrafficClient\Facades\MonitoringClient;

/**
 * Lets a job ping from inside handle().
 *
 * Use this over the config registry when the job knows something the queue does
 * not. "handle() returned" is a weak definition of success for an import that
 * found no rows, a reconciliation that did not balance, or a digest that went
 * to nobody — those finish cleanly and are still the outage. A job that can
 * tell the difference should say so with heartbeatFailed().
 *
 * The job declares its token by overriding heartbeatToken(). Returning null —
 * which is what the default does on a site that has not set the env var — makes
 * every call here a no-op, so a job carrying a heartbeat declaration still runs
 * anywhere.
 */
trait SendsHeartbeat
{
    /**
     * The heartbeat's token, issued by the hub and normally read from the
     * environment. Override this; there is no sensible default.
     */
    public function heartbeatToken(): ?string
    {
        return null;
    }

    /** The job ran and did what it was supposed to. */
    public function heartbeat(?string $message = null, ?int $durationMs = null): void
    {
        MonitoringClient::ping($this->heartbeatToken(), $message, $durationMs);
    }

    /** The job has started work worth watching for a timeout. */
    public function heartbeatStarted(?string $message = null): void
    {
        MonitoringClient::start($this->heartbeatToken(), $message);
    }

    /** The job ran and the outcome was wrong, whether or not it threw. */
    public function heartbeatFailed(?string $message = null, ?int $durationMs = null): void
    {
        MonitoringClient::fail($this->heartbeatToken(), $message, $durationMs);
    }
}
