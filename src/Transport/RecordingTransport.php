<?php

namespace Abigah\BotCopTrafficClient\Transport;

use Abigah\BotCopTrafficClient\Contracts\Transport;
use Abigah\BotCopTrafficClient\Support\Signal;

/**
 * Records signals instead of sending them, so an application's own test suite
 * can assert that a job pings without standing up an HTTP fake.
 *
 * Bind it with MonitoringClient::fake() in a test's setUp.
 */
final class RecordingTransport implements Transport
{
    /** @var list<Signal> */
    private array $signals = [];

    public function send(Signal $signal): void
    {
        $this->signals[] = $signal;
    }

    /** @return list<Signal> */
    public function signals(): array
    {
        return $this->signals;
    }

    /**
     * The paths that were sent, which is usually the whole of what a test wants
     * to assert: /ping/{token}, /ping/{token}/start, /report/{token}.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(static fn (Signal $signal) => $signal->path, $this->signals);
    }

    public function sent(string $path): bool
    {
        return in_array($path, $this->paths(), strict: true);
    }

    public function count(): int
    {
        return count($this->signals);
    }

    public function flush(): void
    {
        $this->signals = [];
    }
}
