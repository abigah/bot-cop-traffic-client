<?php

namespace Abigah\BotCopTrafficClient\Listeners;

use Abigah\BotCopTrafficClient\Heartbeats\HeartbeatRegistry;
use Abigah\BotCopTrafficClient\MonitoringClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Str;

/**
 * Pings on a job's way out, for jobs declared in the config registry.
 *
 * This is the no-touch path: the job's own code is unchanged, which is what
 * makes it usable for jobs from a package. What it can report is correspondingly
 * shallow — success here means handle() returned — so a job that knows more
 * about its own outcome should use the SendsHeartbeat trait instead.
 *
 * A job should use one mechanism or the other. Doing both sends two pings for
 * one run; harmless, since the second simply overwrites the first's timestamp,
 * but it means the duration reported is whichever arrived last.
 */
class PingHeartbeatForJob
{
    /** @var array<string, float> Start times by job uuid, for the duration. */
    private array $started = [];

    public function __construct(
        private readonly MonitoringClient $client,
        private readonly HeartbeatRegistry $registry,
    ) {}

    public function handleJobProcessing(JobProcessing $event): void
    {
        if ($this->tokenFor($event->job) === null) {
            return;
        }

        $uuid = $event->job->uuid();

        if ($uuid !== null) {
            $this->started[$uuid] = microtime(true);
        }
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $token = $this->tokenFor($event->job);
        $duration = $this->take($event->job);

        // A released job has not finished, it has rescheduled itself. Pinging
        // here would report a success the job has not had yet, and the next
        // attempt will ping on its own.
        if ($token === null || $event->job->isReleased()) {
            return;
        }

        $this->client->ping($token, durationMs: $duration);
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $token = $this->tokenFor($event->job);
        $duration = $this->take($event->job);

        if ($token === null) {
            return;
        }

        $this->client->fail(
            $token,
            Str::limit($event->exception->getMessage(), 1024, ''),
            $duration,
        );
    }

    private function tokenFor(Job $job): ?string
    {
        return $this->registry->tokenFor($job->resolveName());
    }

    /**
     * The elapsed milliseconds for this job, removing the record as it goes so
     * a long-lived worker does not accumulate one entry per job it ever ran.
     */
    private function take(Job $job): ?int
    {
        $uuid = $job->uuid();

        if ($uuid === null || ! isset($this->started[$uuid])) {
            return null;
        }

        $started = $this->started[$uuid];
        unset($this->started[$uuid]);

        return (int) round((microtime(true) - $started) * 1000);
    }
}
