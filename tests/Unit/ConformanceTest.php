<?php

use Abigah\BotCopTrafficClient\Enums\PingKind;
use Abigah\BotCopTrafficClient\Support\Signal;
use Abigah\BotCopTrafficClient\Tests\Support\Kit;

/**
 * The client speaks two of the eight contracts: the optional ping body and the
 * exception report. These tests validate what it actually builds against the
 * pinned schemas, rather than against a copy of them written here — so a
 * contract change lands as a submodule bump and whatever it breaks breaks
 * loudly.
 */
it('has the pinned conformance kit available', function () {
    expect(Kit::path())->toBeDirectory()
        ->and(Kit::schemaNames())->toContain('ping', 'exception-report');
});

it('builds a ping body the schema accepts', function () {
    $body = Signal::ping('t0000000000000001', PingKind::Ping, 'Nightly digest sent', 8410)->body;

    expect(Kit::validate('ping', $body))->toBe([]);
});

it('builds an empty-message ping the schema accepts', function () {
    // additionalProperties is false, so a body carrying only one of the two
    // optional fields has to be exactly that and not the other set to null.
    expect(Kit::validate('ping', Signal::ping('t0000000000000001', PingKind::Ping, 'ran')->body))->toBe([]);
    expect(Kit::validate('ping', Signal::ping('t0000000000000001', PingKind::Ping, null, 5)->body))->toBe([]);
});

it('builds an exception report the schema accepts', function () {
    $body = Signal::report('ingest-token-0000001', [
        [
            'fingerprint' => hash('sha256', 'QueryException|app/Foo.php|84'),
            'class' => 'Illuminate\\Database\\QueryException',
            'message' => 'SQLSTATE[HY000] [2002] Connection refused',
            'file' => 'app/Http/Controllers/OrderController.php',
            'line' => 84,
            'first_seen' => '2026-09-05T15:02:11Z',
            'last_seen' => '2026-09-05T15:04:02Z',
            'count' => 7,
            'trace' => null,
        ],
    ])->body;

    expect(Kit::validate('exception-report', $body))->toBe([]);
});

it('produces the same shape as the kit example', function () {
    $example = Kit::example('exception-report');

    $built = Signal::report('ingest-token-0000001', $example['fingerprints'])->body;

    expect($built)->toBe($example)
        ->and(Kit::validate('exception-report', $built))->toBe([]);
});
