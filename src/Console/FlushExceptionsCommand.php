<?php

namespace Abigah\BotCopTrafficClient\Console;

use Abigah\BotCopTrafficClient\Exceptions\ExceptionReporter;
use Illuminate\Console\Command;

/**
 * Sends the exception repeats counted since the last run.
 *
 * First occurrences do not wait for this — they go out the moment they happen —
 * so this command carries only counts, and running it every few minutes is
 * enough. The service provider schedules it automatically when exception
 * reporting is on.
 */
class FlushExceptionsCommand extends Command
{
    protected $signature = 'monitoring:flush-exceptions';

    protected $description = 'Send counted exception repeats to monitoring';

    public function handle(ExceptionReporter $reporter): int
    {
        $count = $reporter->flush();

        $this->components->info($count === 0
            ? 'No exception repeats to send.'
            : "Sent {$count} ".str('fingerprint')->plural($count).'.');

        return self::SUCCESS;
    }
}
