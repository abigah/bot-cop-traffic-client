<?php

namespace Abigah\BotCopTrafficClient\Console;

use Abigah\BotCopTrafficClient\MonitoringClient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The deploy script's way in.
 *
 * A deploy is an event heartbeat: start opens a window, finish closes it, fail
 * says so explicitly. The window is what catches a deploy that hung, which a
 * finish-only signal cannot — nothing arriving looks exactly like nothing
 * having been deployed.
 *
 *     php artisan monitoring:deploy start
 *     php artisan monitoring:deploy finish
 *     php artisan monitoring:deploy fail --message="composer install failed"
 *
 * It always exits 0. A deploy script that stops because monitoring was
 * unreachable has been made less reliable by being monitored.
 */
class DeployPingCommand extends Command
{
    protected $signature = 'monitoring:deploy
                            {stage : start, finish or fail}
                            {--message= : Context passed through to the verdict}
                            {--duration= : How long the deploy took, in milliseconds}';

    protected $description = 'Tell monitoring that a deployment started, finished or failed';

    public function handle(MonitoringClient $client, Config $config): int
    {
        $stage = strtolower((string) $this->argument('stage'));

        if (! in_array($stage, ['start', 'finish', 'fail'], strict: true)) {
            $this->components->error("Unknown stage [{$stage}]. Expected start, finish or fail.");

            return self::FAILURE;
        }

        if (! (bool) $config->get('monitoring-client.deployments.enabled', true)) {
            $this->components->info('Deployment pings are disabled; nothing sent.');

            return self::SUCCESS;
        }

        $token = $config->get('monitoring-client.deployments.token');
        $message = $this->option('message') ?: null;
        $duration = $this->option('duration') !== null ? (int) $this->option('duration') : null;

        match ($stage) {
            'start' => $client->start($token, $message),
            'finish' => $client->ping($token, $message, $duration),
            'fail' => $client->fail($token, $message, $duration),
        };

        // Deliberately does not report whether the send succeeded: it is
        // fire-and-forget, so there is nothing truthful to say beyond this.
        $this->components->info(
            $client->enabled() && $token
                ? "Deployment {$stage} ping sent."
                : "Monitoring is not configured on this site; {$stage} ping skipped."
        );

        return self::SUCCESS;
    }
}
