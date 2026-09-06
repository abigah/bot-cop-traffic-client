<?php

use Abigah\BotCopTrafficClient\MonitoringClient;

beforeEach(function () {
    $this->recorder = app(MonitoringClient::class)->fake();
});

it('opens a window on start', function () {
    $this->artisan('monitoring:deploy start')->assertExitCode(0);

    expect($this->recorder->paths())->toBe(['/ping/deploy-token-00000001/start']);
});

it('closes it on finish', function () {
    $this->artisan('monitoring:deploy finish')->assertExitCode(0);

    expect($this->recorder->paths())->toBe(['/ping/deploy-token-00000001']);
});

it('says so explicitly on fail', function () {
    $this->artisan('monitoring:deploy fail --message="composer install failed" --duration=4200')
        ->assertExitCode(0);

    expect($this->recorder->signals()[0]->path)->toBe('/ping/deploy-token-00000001/fail')
        ->and($this->recorder->signals()[0]->body)->toBe([
            'message' => 'composer install failed',
            'duration_ms' => 4200,
        ]);
});

it('rejects a stage it does not know', function () {
    $this->artisan('monitoring:deploy sideways')->assertExitCode(1);

    expect($this->recorder->count())->toBe(0);
});

it('still exits zero when monitoring is not configured', function () {
    // A deploy script that stops because monitoring was unreachable has been
    // made less reliable by being monitored.
    config()->set('monitoring-client.deployments.token', null);

    $this->artisan('monitoring:deploy start')->assertExitCode(0);

    expect($this->recorder->count())->toBe(0);
});

it('sends nothing when deployment pings are switched off', function () {
    config()->set('monitoring-client.deployments.enabled', false);

    $this->artisan('monitoring:deploy start')->assertExitCode(0);

    expect($this->recorder->count())->toBe(0);
});
