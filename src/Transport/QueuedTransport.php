<?php

namespace Abigah\BotCopTrafficClient\Transport;

use Abigah\BotCopTrafficClient\Contracts\Transport;
use Abigah\BotCopTrafficClient\Jobs\SendSignal;
use Abigah\BotCopTrafficClient\Support\Signal;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

/**
 * Hands the signal to a queue instead of sending it inline.
 *
 * Swallows just as broadly as the HTTP transport does: a queue connection that
 * refuses the push is still a monitoring failure, and still not the caller's
 * problem.
 */
final class QueuedTransport implements Transport
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly string $connection,
    ) {}

    public function send(Signal $signal): void
    {
        try {
            $this->bus->dispatch(
                (new SendSignal($signal->toArray()))->onConnection($this->connection)
            );
        } catch (Throwable) {
            //
        }
    }
}
