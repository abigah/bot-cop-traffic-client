<?php

namespace Abigah\BotCopTrafficClient\Contracts;

use Abigah\BotCopTrafficClient\Support\Signal;

/**
 * How a signal leaves the site.
 *
 * The one rule every implementation keeps: send() does not throw. A monitoring
 * ping must never be the reason a job, a deploy or a request fails, so the
 * failure mode of this whole package is silence — optionally a debug line, and
 * nothing else.
 */
interface Transport
{
    public function send(Signal $signal): void;
}
