<?php

namespace Abigah\BotCopTrafficClient\Tests;

use Abigah\BotCopTrafficClient\MonitoringClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MonitoringClientServiceProvider::class];
    }

    /**
     * Config applied on top of the defaults below, for the handful of settings
     * the package reads at boot — the heartbeat listener and the exception
     * handler hook. Set it and call refreshApplication() to test those.
     *
     * @var array<string, mixed>
     */
    protected array $envOverrides = [];

    protected function defineEnvironment($app): void
    {
        // The shipped defaults are "off, with nowhere to send", which is right
        // for a laptop and useless for a test suite. Every test starts from a
        // configured site and switches parts off deliberately.
        $app['config']->set('monitoring-client.enabled', true);
        $app['config']->set('monitoring-client.endpoints', ['https://prober.test']);
        $app['config']->set('monitoring-client.exceptions.enabled', true);
        $app['config']->set('monitoring-client.exceptions.token', 'site-ingest-token-0001');
        $app['config']->set('monitoring-client.deployments.token', 'deploy-token-00000001');
        $app['config']->set('cache.default', 'array');

        foreach ($this->envOverrides as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
