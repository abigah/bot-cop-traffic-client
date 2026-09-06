<?php

namespace Abigah\BotCopTrafficClient\Jobs;

use Abigah\BotCopTrafficClient\Support\Signal;
use Abigah\BotCopTrafficClient\Transport\HttpTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Carries one signal onto a queue, for sites that would rather not spend even a
 * short timeout inside a web request.
 *
 * Note what this trades away: a heartbeat pushed onto a queue cannot report
 * that the queue has stopped, which is one of the outages heartbeats exist to
 * catch. That is why queueing is off by default, and why this job is not
 * retried — a stale ping delivered minutes late is worse than no ping, because
 * it claims the site was alive at a moment it may not have been.
 *
 * It resolves the HTTP transport rather than the bound one, which on a queueing
 * site is the QueuedTransport that dispatched it.
 */
final class SendSignal implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array{method: string, path: string, body: array<string, mixed>|null}  $signal
     */
    public function __construct(public readonly array $signal) {}

    public function handle(HttpTransport $transport): void
    {
        $transport->send(Signal::fromArray($this->signal));
    }
}
