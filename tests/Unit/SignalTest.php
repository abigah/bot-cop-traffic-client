<?php

use Abigah\BotCopTrafficClient\Enums\PingKind;
use Abigah\BotCopTrafficClient\Support\Signal;

it('sends an ordinary ping as a bodyless GET', function () {
    $signal = Signal::ping('heartbeat-token-0001');

    expect($signal->method)->toBe('GET')
        ->and($signal->path)->toBe('/ping/heartbeat-token-0001')
        ->and($signal->body)->toBeNull();
});

it('suffixes the path for start and fail, and not for a finish', function () {
    expect(Signal::ping('t0000000000000001', PingKind::Start)->path)->toBe('/ping/t0000000000000001/start')
        ->and(Signal::ping('t0000000000000001', PingKind::Fail)->path)->toBe('/ping/t0000000000000001/fail')
        ->and(Signal::ping('t0000000000000001', PingKind::Ping)->path)->toBe('/ping/t0000000000000001');
});

it('becomes a POST once there is something to say', function () {
    $signal = Signal::ping('t0000000000000001', PingKind::Ping, 'Digest sent to 412 recipients', 8410);

    expect($signal->method)->toBe('POST')
        ->and($signal->body)->toBe([
            'message' => 'Digest sent to 412 recipients',
            'duration_ms' => 8410,
        ]);
});

it('omits absent body fields rather than sending nulls', function () {
    // additionalProperties is false and neither field is required, so a null
    // would be refused where an absence is fine.
    expect(Signal::ping('t0000000000000001', PingKind::Ping, 'ran')->body)
        ->toBe(['message' => 'ran']);

    expect(Signal::ping('t0000000000000001', PingKind::Ping, null, 20)->body)
        ->toBe(['duration_ms' => 20]);
});

it('clamps a message to the length the schema allows', function () {
    $signal = Signal::ping('t0000000000000001', PingKind::Ping, str_repeat('x', 2000));

    expect(mb_strlen($signal->body['message']))->toBe(1024);
});

it('refuses to send a negative duration', function () {
    expect(Signal::ping('t0000000000000001', PingKind::Ping, null, -5)->body)
        ->toBe(['duration_ms' => 0]);
});

it('joins a base url to its path without doubling the slash', function () {
    $signal = Signal::ping('t0000000000000001');

    expect($signal->url('https://prober.test'))->toBe('https://prober.test/ping/t0000000000000001')
        ->and($signal->url('https://prober.test/'))->toBe('https://prober.test/ping/t0000000000000001');
});

it('survives a round trip through the queue', function () {
    $signal = Signal::report('ingest-token-000001', [['fingerprint' => 'a']]);

    $restored = Signal::fromArray($signal->toArray());

    expect($restored->toArray())->toBe($signal->toArray());
});
