<?php

use Abigah\BotCopTrafficClient\Contracts\Transport;
use Abigah\BotCopTrafficClient\Jobs\SendSignal;
use Abigah\BotCopTrafficClient\MonitoringClient;
use Abigah\BotCopTrafficClient\Transport\NullTransport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('sends a ping to every configured endpoint', function () {
    Http::fake();
    config()->set('monitoring-client.endpoints', ['https://one.test', 'https://two.test']);

    app(MonitoringClient::class)->ping('heartbeat-token-0001');

    Http::assertSent(fn ($request) => $request->url() === 'https://one.test/ping/heartbeat-token-0001');
    Http::assertSent(fn ($request) => $request->url() === 'https://two.test/ping/heartbeat-token-0001');
    Http::assertSentCount(2);
});

it('carries the schema version on every send', function () {
    Http::fake();

    app(MonitoringClient::class)->ping('heartbeat-token-0001');

    Http::assertSent(fn ($request) => $request->header('X-Monitoring-Schema') === ['1']);
});

it('sends nothing while the master switch is off', function () {
    Http::fake();
    config()->set('monitoring-client.enabled', false);

    app(MonitoringClient::class)->ping('heartbeat-token-0001');

    Http::assertNothingSent();
    expect(app(Transport::class))->toBeInstanceOf(NullTransport::class);
});

it('treats an enabled site with no endpoint as switched off', function () {
    // Enabled and endpoint-less is a misconfiguration, not a state to honour:
    // there is nowhere for a signal to go.
    config()->set('monitoring-client.endpoints', []);

    expect(app(MonitoringClient::class)->enabled())->toBeFalse();
});

it('does nothing when the token is missing, rather than complaining', function () {
    Http::fake();

    // The ordinary state of a job carrying a heartbeat declaration on a machine
    // where nobody set the env var. It must keep running.
    app(MonitoringClient::class)->ping(null);
    app(MonitoringClient::class)->ping('');
    app(MonitoringClient::class)->ping('   ');

    Http::assertNothingSent();
});

it('never lets a transport failure reach the caller', function () {
    Http::fake(fn () => throw new RuntimeException('the network is on fire'));

    app(MonitoringClient::class)->ping('heartbeat-token-0001');

    // Reaching here is the assertion: a monitoring ping must never be the
    // reason a job, a deploy or a request fails.
    expect(true)->toBeTrue();
});

it('records signals instead of sending them once faked', function () {
    $recorder = app(MonitoringClient::class)->fake();

    app(MonitoringClient::class)->start('deploy-token-00000001');
    app(MonitoringClient::class)->ping('deploy-token-00000001', durationMs: 41_000);

    expect($recorder->paths())->toBe([
        '/ping/deploy-token-00000001/start',
        '/ping/deploy-token-00000001',
    ]);
});

it('switches itself on when faked, so a default-off site can still be tested', function () {
    config()->set('monitoring-client.enabled', false);
    config()->set('monitoring-client.endpoints', []);

    $recorder = app(MonitoringClient::class)->fake();
    app(MonitoringClient::class)->ping('heartbeat-token-0001');

    expect($recorder->count())->toBe(1);
});

it('pushes onto a queue when one is configured', function () {
    Bus::fake();
    config()->set('monitoring-client.queue', 'redis');

    app(MonitoringClient::class)->ping('heartbeat-token-0001');

    Bus::assertDispatched(
        SendSignal::class,
        fn ($job) => $job->signal['path'] === '/ping/heartbeat-token-0001'
    );
});
