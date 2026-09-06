<?php

namespace Abigah\BotCopTrafficClient\Heartbeats;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Maps a job class to its heartbeat token.
 *
 * The config file is the usual source, but registration is also available at
 * runtime so a package or a service provider can declare a heartbeat for a job
 * the application does not own and cannot edit.
 */
class HeartbeatRegistry
{
    /** @var array<class-string, string> */
    private array $runtime = [];

    public function __construct(private readonly Config $config) {}

    /**
     * @param  class-string  $job
     */
    public function register(string $job, ?string $token): void
    {
        if (is_string($token) && trim($token) !== '') {
            $this->runtime[$job] = $token;
        }
    }

    /**
     * The token for a job, or null if it has none.
     *
     * Runtime registrations win over config so that an application can override
     * a token a package declared on its behalf. Beyond that the lookup is
     * exact: no walking up the class hierarchy, because two jobs sharing a base
     * class are two heartbeats, and inheriting one silently would make them
     * report as each other.
     *
     * @param  class-string|string  $job
     */
    public function tokenFor(string $job): ?string
    {
        $token = $this->runtime[$job] ?? $this->fromConfig()[$job] ?? null;

        return is_string($token) && trim($token) !== '' ? $token : null;
    }

    public function has(string $job): bool
    {
        return $this->tokenFor($job) !== null;
    }

    /** @return array<class-string, string> */
    private function fromConfig(): array
    {
        $jobs = $this->config->get('monitoring-client.heartbeats.jobs', []);

        return is_array($jobs) ? $jobs : [];
    }
}
