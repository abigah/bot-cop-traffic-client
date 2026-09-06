<?php

namespace Abigah\BotCopTrafficClient\Tests\Fixtures;

use Abigah\BotCopTrafficClient\Heartbeats\SendsHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A job that knows more about its own outcome than the queue does: it finishes
 * cleanly whether or not it did the thing, so "handle() returned" would be the
 * wrong signal.
 */
class SelfReportingJob implements ShouldQueue
{
    use Dispatchable;
    use SendsHeartbeat;

    public function __construct(private readonly int $rows = 10) {}

    public function heartbeatToken(): ?string
    {
        return 'self-reporting-token-01';
    }

    public function handle(): void
    {
        $this->heartbeatStarted('importing');

        $this->rows > 0
            ? $this->heartbeat("imported {$this->rows} rows")
            : $this->heartbeatFailed('imported nothing, which is never right');
    }
}
