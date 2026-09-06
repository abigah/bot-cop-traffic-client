<?php

namespace Abigah\BotCopTrafficClient;

use Abigah\BotCopTrafficClient\Console\DeployPingCommand;
use Abigah\BotCopTrafficClient\Console\FlushExceptionsCommand;
use Abigah\BotCopTrafficClient\Contracts\Transport;
use Abigah\BotCopTrafficClient\Exceptions\ExceptionReporter;
use Abigah\BotCopTrafficClient\Exceptions\Scrubber;
use Abigah\BotCopTrafficClient\Heartbeats\HeartbeatRegistry;
use Abigah\BotCopTrafficClient\Listeners\PingHeartbeatForJob;
use Abigah\BotCopTrafficClient\Transport\HttpTransport;
use Abigah\BotCopTrafficClient\Transport\NullTransport;
use Abigah\BotCopTrafficClient\Transport\QueuedTransport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Throwable;

class MonitoringClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitoring-client.php', 'monitoring-client');

        $this->app->singleton(MonitoringClient::class);
        $this->app->singleton(HeartbeatRegistry::class);

        // A singleton because it holds job start times between JobProcessing
        // and JobProcessed. A fresh listener per event would lose them, and
        // every heartbeat would report no duration.
        $this->app->singleton(PingHeartbeatForJob::class);

        $this->registerTransport();
        $this->registerReporter();
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
        $this->registerHeartbeatListener();
        $this->registerExceptionHandler();
    }

    /**
     * Three transports, one binding.
     *
     * Resolving which one to use here rather than at every call site is what
     * lets a disabled site keep its heartbeat declarations in place: "off"
     * becomes a NullTransport, not a condition every caller has to remember.
     */
    protected function registerTransport(): void
    {
        $this->app->singleton(HttpTransport::class, function ($app) {
            $config = $app->make(Config::class);

            return new HttpTransport(
                http: $app->make(Http::class),
                endpoints: $app->make(MonitoringClient::class)->endpoints(),
                timeout: (float) $config->get('monitoring-client.timeout', 2),
                connectTimeout: (float) $config->get('monitoring-client.connect_timeout', 1),
                logFailures: (bool) $config->get('monitoring-client.log_failures', false),
                logger: $app->bound('log') ? $app->make('log') : null,
            );
        });

        $this->app->singleton(Transport::class, function ($app) {
            if (! $app->make(MonitoringClient::class)->enabled()) {
                return new NullTransport;
            }

            $queue = $app->make(Config::class)->get('monitoring-client.queue', false);

            if (is_string($queue) && $queue !== '' && $queue !== 'false') {
                return new QueuedTransport($app->make(\Illuminate\Contracts\Bus\Dispatcher::class), $queue);
            }

            return $app->make(HttpTransport::class);
        });
    }

    protected function registerReporter(): void
    {
        $this->app->singleton(Scrubber::class, function ($app) {
            $config = $app->make(Config::class);

            return new Scrubber(
                patterns: (array) $config->get('monitoring-client.exceptions.scrub_patterns', []),
                enabled: (bool) $config->get('monitoring-client.exceptions.scrub', true),
            );
        });

        $this->app->singleton(ExceptionReporter::class, function ($app) {
            $config = $app->make(Config::class);

            return new ExceptionReporter(
                client: $app->make(MonitoringClient::class),
                cache: $app->make(CacheFactory::class)->store(
                    $config->get('monitoring-client.exceptions.cache_store')
                ),
                config: $config,
                scrubber: $app->make(Scrubber::class),
                basePath: $app->basePath(),
            );
        });
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/monitoring-client.php' => config_path('monitoring-client.php'),
        ], 'monitoring-client-config');
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([DeployPingCommand::class, FlushExceptionsCommand::class]);

        // Scheduled from here rather than left to the application, because a
        // flush that nobody remembered to schedule turns every repeat into a
        // count that is never sent.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if ((bool) $this->app->make(Config::class)->get('monitoring-client.exceptions.enabled', false)) {
                $schedule->command(FlushExceptionsCommand::class)
                    ->everyFiveMinutes()
                    ->withoutOverlapping()
                    ->runInBackground();
            }
        });
    }

    /**
     * The no-touch heartbeat path: jobs named in the config registry ping on
     * their way out without their own code knowing about it.
     */
    protected function registerHeartbeatListener(): void
    {
        $config = $this->app->make(Config::class);

        if (! (bool) $config->get('monitoring-client.heartbeats.enabled', true)) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);

        $events->listen(JobProcessing::class, [PingHeartbeatForJob::class, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [PingHeartbeatForJob::class, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [PingHeartbeatForJob::class, 'handleJobFailed']);
    }

    /**
     * Hooks the reporter onto the application's exception handler.
     *
     * reportable() is the right seam because Laravel has already applied the
     * application's own dontReport list and shouldReport() by the time the
     * callback runs: the package reports what the application would have
     * reported, minus what is not a server error. The callback returns nothing,
     * which leaves the application's own logging exactly as it was.
     */
    protected function registerExceptionHandler(): void
    {
        if (! (bool) $this->app->make(Config::class)->get('monitoring-client.exceptions.enabled', false)) {
            return;
        }

        try {
            $handler = $this->app->make(ExceptionHandler::class);
        } catch (Throwable) {
            return;
        }

        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (Throwable $e) {
            $this->app->make(ExceptionReporter::class)->report($e);
        });
    }
}
