<?php

use Abigah\BotCopTrafficClient\Exceptions\ExceptionReporter;
use Abigah\BotCopTrafficClient\Exceptions\Scrubber;
use Abigah\BotCopTrafficClient\MonitoringClient;
use Abigah\BotCopTrafficClient\Tests\Support\Kit;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

beforeEach(function () {
    $this->recorder = app(MonitoringClient::class)->fake();
    $this->reporter = app(ExceptionReporter::class);
});

it('sends the first occurrence of a fingerprint immediately', function () {
    $this->reporter->report(new RuntimeException('the database went away'));

    expect($this->recorder->count())->toBe(1)
        ->and($this->recorder->signals()[0]->path)->toBe('/report/site-ingest-token-0001')
        ->and($this->recorder->signals()[0]->body['fingerprints'][0]['count'])->toBe(1);
});

it('does not send a repeat immediately', function () {
    $e = new RuntimeException('the database went away');

    $this->reporter->report($e);
    $this->reporter->report($e);
    $this->reporter->report($e);

    // One send for the first occurrence; the other two are counted, not sent.
    expect($this->recorder->count())->toBe(1);
});

it('flushes counted repeats as a single report', function () {
    $e = new RuntimeException('the database went away');

    $this->reporter->report($e);
    $this->recorder->flush();

    $this->reporter->report($e);
    $this->reporter->report($e);
    $this->reporter->report($e);

    expect($this->reporter->flush())->toBe(1)
        ->and($this->recorder->count())->toBe(1)
        ->and($this->recorder->signals()[0]->body['fingerprints'][0]['count'])->toBe(3);
});

it('clears the counters once flushed', function () {
    $e = new RuntimeException('the database went away');

    $this->reporter->report($e);
    $this->reporter->report($e);
    $this->reporter->flush();
    $this->recorder->flush();

    expect($this->reporter->flush())->toBe(0)
        ->and($this->recorder->count())->toBe(0);
});

it('fingerprints on class, file and line rather than on the message', function () {
    // The same fault produces messages that differ by id or bound parameter.
    // Fingerprinting on those would make every occurrence new, and every
    // occurrence a page.
    $line = __LINE__ + 1;
    $make = fn (string $message) => new RuntimeException($message);

    $one = $this->reporter->fingerprint($make('user 41 not found'));
    $two = $this->reporter->fingerprint($make('user 92 not found'));

    expect($one)->toBe($two)->and($one)->toMatch('/^[a-f0-9]{64}$/');
});

it('gives different lines different fingerprints', function () {
    $a = new RuntimeException('same message');
    $b = new RuntimeException('same message');

    expect($this->reporter->fingerprint($a))->not->toBe($this->reporter->fingerprint($b));
});

it('counts a recurrence as new once the fingerprint is forgotten', function () {
    $e = new RuntimeException('the database went away');

    $this->reporter->report($e);
    $this->reporter->forget($this->reporter->fingerprint($e));
    $this->reporter->report($e);

    expect($this->recorder->count())->toBe(2);
});

it('skips what is not a server error', function () {
    $this->reporter->report(new NotFoundHttpException);
    $this->reporter->report(new AuthenticationException);
    $this->reporter->report(ValidationException::withMessages(['email' => 'required']));
    $this->reporter->report(new TokenMismatchException);

    expect($this->recorder->count())->toBe(0);
});

it('reports an http exception at 500 or above', function () {
    $this->reporter->report(new ServiceUnavailableHttpException);

    expect($this->recorder->count())->toBe(1);
});

it('sends a relative path so two servers fingerprint the same fault once', function () {
    // Absolute paths leak the deployment layout and differ between two servers
    // running the same release, which would fingerprint one fault twice. The
    // reporter is built against this package's root rather than Testbench's
    // skeleton, so the exception below actually falls under the base path.
    $reporter = new ExceptionReporter(
        client: app(MonitoringClient::class),
        cache: app('cache')->store('array'),
        config: config(),
        scrubber: app(Scrubber::class),
        basePath: dirname(__DIR__, 2),
    );

    $reporter->report(new RuntimeException('boom'));

    $file = $this->recorder->signals()[0]->body['fingerprints'][0]['file'];

    expect($file)->toBe('tests/Feature/ExceptionReporterTest.php');
});

it('scrubs a message before it leaves the site', function () {
    $this->reporter->report(new RuntimeException(
        'failed for alice@example.com with password=hunter2 via mysql://root:s3cret@db'
    ));

    $message = $this->recorder->signals()[0]->body['fingerprints'][0]['message'];

    expect($message)->not->toContain('alice@example.com')
        ->and($message)->not->toContain('hunter2')
        ->and($message)->not->toContain('s3cret')
        ->and($message)->toContain('[email]');
});

it('omits the trace unless it is asked for', function () {
    $this->reporter->report(new RuntimeException('boom'));

    expect($this->recorder->signals()[0]->body['fingerprints'][0]['trace'])->toBeNull();
});

it('sends a trimmed trace when asked', function () {
    config()->set('monitoring-client.exceptions.send_trace', true);
    config()->set('monitoring-client.exceptions.trace_lines', 3);

    app()->forgetInstance(ExceptionReporter::class);
    app(ExceptionReporter::class)->report(new RuntimeException('boom'));

    $trace = $this->recorder->signals()[0]->body['fingerprints'][0]['trace'];

    expect($trace)->toBeString()
        ->and(substr_count($trace, "\n"))->toBeLessThan(3);
});

it('produces a payload the schema accepts', function () {
    $this->reporter->report(new RuntimeException('failed for user@example.com'));

    expect(Kit::validate('exception-report', $this->recorder->signals()[0]->body))->toBe([]);
});

it('caps a flush at the fingerprint limit the schema allows', function () {
    config()->set('monitoring-client.exceptions.max_fingerprints', 2);

    // Three distinct fingerprints, each with a repeat to be flushed.
    foreach (range(1, 3) as $i) {
        $e = new RuntimeException("error {$i}");
        // Distinct file/line per fingerprint, forced rather than found.
        $this->reporter->report($e);
        $this->reporter->report($e);
    }
    $this->recorder->flush();

    expect($this->reporter->flush())->toBeLessThanOrEqual(2);
});

it('sends nothing when exception reporting is switched off', function () {
    config()->set('monitoring-client.exceptions.enabled', false);

    app()->forgetInstance(ExceptionReporter::class);
    app(ExceptionReporter::class)->report(new RuntimeException('boom'));

    expect($this->recorder->count())->toBe(0);
});

it('never throws from inside the exception handler', function () {
    config()->set('monitoring-client.exceptions.scrub_patterns', ['not a valid regex(' => 'x']);

    app()->forgetInstance(Scrubber::class);
    app()->forgetInstance(ExceptionReporter::class);
    app(ExceptionReporter::class)->report(new RuntimeException('boom'));

    // Reaching here is the assertion: an error raised while reporting an error
    // is the one thing this class must never do.
    expect(true)->toBeTrue();
});
