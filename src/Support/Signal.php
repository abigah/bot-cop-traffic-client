<?php

namespace Abigah\BotCopTrafficClient\Support;

use Abigah\BotCopTrafficClient\Enums\PingKind;
use Illuminate\Support\Str;

/**
 * One outbound call, described rather than performed.
 *
 * Separating the description from the sending is what lets the same signal go
 * to several endpoints, be pushed onto a queue, or be recorded by a fake in a
 * test without any of those knowing what it means.
 */
final class Signal
{
    /**
     * @param  array<string, mixed>|null  $body  Sent as JSON when present; a GET with no body otherwise.
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?array $body,
    ) {}

    /**
     * A heartbeat or event ping. The token is the credential and travels in the
     * path, so there is nothing to sign: these come from arbitrary site code
     * that has no tenant secret and should not be given one.
     */
    public static function ping(string $token, PingKind $kind = PingKind::Ping, ?string $message = null, ?int $durationMs = null): self
    {
        $body = array_filter([
            'message' => $message === null ? null : Str::limit($message, 1024, ''),
            'duration_ms' => $durationMs === null ? null : max(0, $durationMs),
        ], static fn ($value) => $value !== null);

        return new self(
            method: $body === [] ? 'GET' : 'POST',
            path: '/ping/'.$token.$kind->suffix(),
            body: $body === [] ? null : $body,
        );
    }

    /**
     * A batch of exception fingerprints. Always a POST: the payload is the
     * point, and the schema requires its version alongside it.
     *
     * @param  list<array<string, mixed>>  $fingerprints
     */
    public static function report(string $token, array $fingerprints): self
    {
        return new self(
            method: 'POST',
            path: '/report/'.$token,
            body: ['schema' => 1, 'fingerprints' => array_values($fingerprints)],
        );
    }

    /**
     * Rebuilds a signal that was serialised onto a queue. Deliberately not a
     * validating constructor: the only thing that puts an array here is
     * toArray(), one job hop earlier.
     *
     * @param  array{method: string, path: string, body: array<string, mixed>|null}  $signal
     */
    public static function fromArray(array $signal): self
    {
        return new self($signal['method'], $signal['path'], $signal['body']);
    }

    /**
     * Where this signal goes for one endpoint. Trailing slashes on a configured
     * base URL are trimmed so that a paste with one does not produce a //ping
     * path the prober will not match.
     */
    public function url(string $endpoint): string
    {
        return rtrim($endpoint, '/').$this->path;
    }

    /** @return array{method: string, path: string, body: array<string, mixed>|null} */
    public function toArray(): array
    {
        return ['method' => $this->method, 'path' => $this->path, 'body' => $this->body];
    }
}
