<?php

namespace Abigah\BotCopTrafficClient\Facades;

use Abigah\BotCopTrafficClient\Transport\RecordingTransport;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void ping(?string $token, ?string $message = null, ?int $durationMs = null)
 * @method static void start(?string $token, ?string $message = null)
 * @method static void fail(?string $token, ?string $message = null, ?int $durationMs = null)
 * @method static void report(array $fingerprints, ?string $token = null)
 * @method static bool enabled()
 * @method static list<string> endpoints()
 * @method static RecordingTransport fake()
 *
 * @see \Abigah\BotCopTrafficClient\MonitoringClient
 */
class MonitoringClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Abigah\BotCopTrafficClient\MonitoringClient::class;
    }
}
