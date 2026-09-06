<?php

namespace Abigah\BotCopTrafficClient\Enums;

/**
 * The three shapes a ping comes in, and the URL suffix each one carries.
 *
 * `Ping` is the ordinary "I ran" signal and has no suffix; a heartbeat sends
 * only these. `Start` and `Fail` bracket work that takes long enough to be
 * worth watching — a deploy, a nightly import — where a start with no finish
 * inside the timeout is itself a verdict.
 */
enum PingKind: string
{
    case Ping = 'ping';
    case Start = 'start';
    case Fail = 'fail';

    /**
     * What this kind appends to /ping/{token}. Nothing, for an ordinary ping:
     * a finish is the absence of a suffix, not a /finish route.
     */
    public function suffix(): string
    {
        return $this === self::Ping ? '' : '/'.$this->value;
    }
}
