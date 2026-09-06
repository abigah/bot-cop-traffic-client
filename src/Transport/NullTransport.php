<?php

namespace Abigah\BotCopTrafficClient\Transport;

use Abigah\BotCopTrafficClient\Contracts\Transport;
use Abigah\BotCopTrafficClient\Support\Signal;

/**
 * The transport bound when the package is switched off, or when no endpoint is
 * configured.
 *
 * It exists so that "disabled" is a binding rather than a condition every
 * caller has to remember to check: a site can keep its heartbeat declarations
 * and its deploy hooks in place on a laptop, and they cost a method call.
 */
final class NullTransport implements Transport
{
    public function send(Signal $signal): void
    {
        //
    }
}
