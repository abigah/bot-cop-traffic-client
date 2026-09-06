<?php

use Abigah\BotCopTrafficClient\MonitoringClient;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;

/**
 * The seam itself. Everything else tests the reporter in isolation; this tests
 * that it is actually attached to the application's exception handler and that
 * attaching it did not change what the application already did.
 */
beforeEach(function () {
    $this->recorder = app(MonitoringClient::class)->fake();
});

it('reports an exception the application reports', function () {
    app(ExceptionHandler::class)->report(new RuntimeException('the database went away'));

    expect($this->recorder->count())->toBe(1)
        ->and($this->recorder->signals()[0]->path)->toBe('/report/site-ingest-token-0001');
});

it('leaves the application logging exactly as it was', function () {
    // The reportable() callback returns nothing rather than false, so it adds
    // a listener instead of replacing the handler's own behaviour.
    Log::shouldReceive('error')->once();

    app(ExceptionHandler::class)->report(new RuntimeException('the database went away'));

    expect($this->recorder->count())->toBe(1);
});

it('respects the application dontReport list', function () {
    // Laravel applies shouldntReport() before reportable callbacks run, so the
    // package reports what the application would have reported and nothing it
    // would not.
    app(ExceptionHandler::class)->dontReport(RuntimeException::class);

    app(ExceptionHandler::class)->report(new RuntimeException('ignore me'));

    expect($this->recorder->count())->toBe(0);
});

it('does not attach itself when exception reporting is off', function () {
    $this->envOverrides = ['monitoring-client.exceptions.enabled' => false];
    $this->refreshApplication();

    $recorder = app(MonitoringClient::class)->fake();

    app(ExceptionHandler::class)->report(new RuntimeException('boom'));

    expect($recorder->count())->toBe(0);
});
