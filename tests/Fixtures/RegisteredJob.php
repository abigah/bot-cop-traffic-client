<?php

namespace Abigah\BotCopTrafficClient\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A job the site does not own and cannot edit: it declares its heartbeat in the
 * config registry and knows nothing about monitoring.
 */
class RegisteredJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct(private readonly bool $throw = false) {}

    public function handle(): void
    {
        if ($this->throw) {
            throw new \RuntimeException('the import found no rows');
        }
    }
}
