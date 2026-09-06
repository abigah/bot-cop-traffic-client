<?php

namespace Abigah\BotCopTrafficClient\Exceptions;

use Illuminate\Support\Str;

/**
 * Removes from an exception message the things that should not leave the
 * server, and trims what is left to the length the schema allows.
 *
 * This is a safety net, not a guarantee, and cannot be one: it only knows the
 * shapes it is told about. The real rule is still not to put secrets in
 * exception messages. What it does buy is the common accident — a query
 * exception quoting the bound parameters, an HTTP client error echoing the
 * Authorization header it just sent.
 */
class Scrubber
{
    /**
     * @param  array<string, string>  $patterns  Regex => replacement.
     */
    public function __construct(
        private readonly array $patterns = [],
        private readonly bool $enabled = true,
    ) {}

    public function message(string $message, int $limit = 2048): string
    {
        return Str::limit($this->scrub($message), $limit, '');
    }

    /**
     * A trace is scrubbed the same way and then cut to whole frames, because
     * half a frame is worse than one fewer.
     */
    public function trace(string $trace, int $frames = 20, int $limit = 8192): string
    {
        $lines = array_slice(preg_split('/\R/', $trace) ?: [], 0, $frames);

        return Str::limit($this->scrub(implode("\n", $lines)), $limit, '');
    }

    private function scrub(string $value): string
    {
        if (! $this->enabled) {
            return $value;
        }

        foreach ($this->patterns as $pattern => $replacement) {
            // A bad pattern in a host's config must not become an exception
            // raised from inside the exception handler.
            $result = @preg_replace($pattern, $replacement, $value);

            if (is_string($result)) {
                $value = $result;
            }
        }

        return $value;
    }
}
