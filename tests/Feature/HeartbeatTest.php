<?php

use Abigah\BotCopTrafficClient\Heartbeats\HeartbeatRegistry;
use Abigah\BotCopTrafficClient\MonitoringClient;
use Abigah\BotCopTrafficClient\Tests\Fixtures\RegisteredJob;
use Abigah\BotCopTrafficClient\Tests\Fixtures\SelfReportingJob;

beforeEach(function () {
    config()->set('queue.default', 'sync');

    $this->recorder = app(MonitoringClient::class)->fake();
});

it('pings when a registered job finishes', function () {
    config()->set('monitoring-client.heartbeats.jobs', [
        RegisteredJob::class => 'registered-job-token-1',
    ]);

    RegisteredJob::dispatch();

    expect($this->recorder->paths())->toBe(['/ping/registered-job-token-1']);
});

it('reports a failure when a registered job throws', function () {
    config()->set('monitoring-client.heartbeats.jobs', [
        RegisteredJob::class => 'registered-job-token-1',
    ]);

    try {
        RegisteredJob::dispatch(throw: true);
    } catch (RuntimeException) {
        // The sync driver rethrows after the events have fired.
    }

    expect($this->recorder->paths())->toContain('/ping/registered-job-token-1/fail');
});

it('carries the failure message through to the verdict', function () {
    config()->set('monitoring-client.heartbeats.jobs', [
        RegisteredJob::class => 'registered-job-token-1',
    ]);

    try {
        RegisteredJob::dispatch(throw: true);
    } catch (RuntimeException) {
    }

    $failure = collect($this->recorder->signals())
        ->first(fn ($signal) => str_ends_with($signal->path, '/fail'));

    expect($failure->body['message'])->toContain('the import found no rows');
});

it('ignores a job that has not declared a heartbeat', function () {
    RegisteredJob::dispatch();

    expect($this->recorder->count())->toBe(0);
});

it('reports the duration a job took', function () {
    config()->set('monitoring-client.heartbeats.jobs', [
        RegisteredJob::class => 'registered-job-token-1',
    ]);

    RegisteredJob::dispatch();

    expect($this->recorder->signals()[0]->body)->toHaveKey('duration_ms')
        ->and($this->recorder->signals()[0]->body['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('lets a job report an outcome the queue cannot see', function () {
    // handle() returns cleanly in both cases; only the job knows the difference.
    SelfReportingJob::dispatch(rows: 0);

    expect($this->recorder->paths())->toBe([
        '/ping/self-reporting-token-01/start',
        '/ping/self-reporting-token-01/fail',
    ]);
});

it('pings normally when the self-reporting job did its work', function () {
    SelfReportingJob::dispatch(rows: 10);

    expect($this->recorder->paths())->toBe([
        '/ping/self-reporting-token-01/start',
        '/ping/self-reporting-token-01',
    ]);
});

it('sends nothing when heartbeats are switched off', function () {
    // The listener is registered at boot, so the switch has to be set before
    // the application boots rather than in the test body.
    $this->envOverrides = [
        'monitoring-client.heartbeats.enabled' => false,
        'monitoring-client.heartbeats.jobs' => [RegisteredJob::class => 'registered-job-token-1'],
        'queue.default' => 'sync',
    ];
    $this->refreshApplication();

    $recorder = app(MonitoringClient::class)->fake();

    RegisteredJob::dispatch();

    expect($recorder->count())->toBe(0);
});

it('prefers a runtime registration over the config file', function () {
    config()->set('monitoring-client.heartbeats.jobs', [
        RegisteredJob::class => 'from-the-config-file',
    ]);

    app(HeartbeatRegistry::class)->register(RegisteredJob::class, 'from-the-provider');

    RegisteredJob::dispatch();

    expect($this->recorder->paths())->toBe(['/ping/from-the-provider']);
});

it('does not inherit a heartbeat from a parent class', function () {
    // Two jobs sharing a base class are two heartbeats. Inheriting one silently
    // would make them report as each other.
    config()->set('monitoring-client.heartbeats.jobs', [
        RegisteredJob::class => 'registered-job-token-1',
    ]);

    expect(app(HeartbeatRegistry::class)->has(SelfReportingJob::class))->toBeFalse();
});
