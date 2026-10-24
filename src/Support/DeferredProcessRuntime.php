<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Support;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

use const FILTER_VALIDATE_INT;
use const PHP_BINARY;
use const PHP_SAPI;
use const SIG_DFL;
use const SIGALRM;

use function array_is_list;
use function array_map;
use function config;
use function filter_var;
use function function_exists;
use function getenv;
use function getmypid;
use function is_array;
use function is_bool;
use function is_executable;
use function is_string;
use function pcntl_alarm;
use function pcntl_exec;
use function pcntl_signal;
use function posix_getppid;
use function putenv;
use function throw_if;
use function throw_unless;

/**
 * The CLI boundary owns the whole-run deadline, including blocking handler I/O.
 * Reservation fencing cannot prevent a still-running expired handler's external
 * effects. This opt-in supervisor ends execution before claim recovery.
 *
 * @internal
 */
final class DeferredProcessRuntime
{
    private const string SUPERVISOR_PID = 'SEQUENCER_DEFERRED_SUPERVISOR_PID';

    private bool $entered = false;

    public function enabled(): bool
    {
        $enabled = config('sequencer.deferred.runtime.enabled', false);

        throw_unless(is_bool($enabled), InvalidArgumentException::class, 'Deferred runtime enabled must be a boolean.');

        return $enabled;
    }

    public function enter(InputInterface $input): void
    {
        $timeout = filter_var(config('sequencer.deferred.runtime.timeout_seconds', 240), FILTER_VALIDATE_INT);
        $grace = filter_var(config('sequencer.deferred.runtime.kill_after_seconds', 10), FILTER_VALIDATE_INT);
        $margin = filter_var(config('sequencer.deferred.runtime.recovery_margin_seconds', 50), FILTER_VALIDATE_INT);
        $expiry = filter_var(config('sequencer.deferred.retry_after_seconds', 300), FILTER_VALIDATE_INT);
        $binary = config('sequencer.deferred.runtime.timeout_binary', '/usr/bin/timeout');

        // Linux alarm_setitimer uses a 32-bit seconds argument; keep the
        // independent watchdog representable (kernel/time/itimer.c).
        throw_if($timeout === false || $grace === false || $margin === false || $expiry === false
            || $timeout < 1 || $grace < 1 || $margin < 1 || $timeout > 2_147_483_647 - $grace
            || $timeout >= $expiry || $grace >= $expiry - $timeout || $margin > $expiry - $timeout - $grace
            || !is_string($binary) || $binary === '', InvalidArgumentException::class, 'Deferred runtime requires positive integer timeout, grace and recovery margin within reservation expiry.');

        // StringInput extends ArgvInput, and programmatic callers can construct
        // ArgvInput too. Only the actual external command's argv may be replayed.
        $rawArguments = $_SERVER['argv'] ?? [];
        throw_unless(is_array($rawArguments) && array_is_list($rawArguments), RuntimeException::class, 'Run deferred processing through the external artisan CLI so its lifetime can be bounded.');
        $arguments = array_map(static function (mixed $argument): string {
            throw_unless(is_string($argument), RuntimeException::class, 'Run deferred processing through the external artisan CLI so its lifetime can be bounded.');

            return $argument;
        }, $rawArguments);
        $actualInput = new ArgvInput($arguments);

        throw_if($input::class !== ArgvInput::class || PHP_SAPI !== 'cli'
            || $input->getFirstArgument() !== 'sequencer:deferred-process'
            || (string) $input !== (string) $actualInput || $this->entered, RuntimeException::class, 'Run deferred processing through the external artisan CLI so its lifetime can be bounded.');

        foreach (['pcntl_exec', 'pcntl_signal', 'pcntl_alarm', 'posix_getppid'] as $function) {
            throw_unless(function_exists($function), RuntimeException::class, 'Bounded deferred processing requires PCNTL and POSIX support.');
        }

        throw_unless(is_executable($binary), RuntimeException::class, 'Bounded deferred processing requires an executable GNU timeout binary.');

        $supervisor = getenv(self::SUPERVISOR_PID);

        if ($supervisor !== false) {
            putenv(self::SUPERVISOR_PID);
            unset($_ENV[self::SUPERVISOR_PID], $_SERVER[self::SUPERVISOR_PID]);
            throw_if((string) posix_getppid() !== $supervisor, RuntimeException::class, 'The deferred processor has no live timeout supervisor.');

            // The OS-default alarm is an independent last stop if the timeout
            // supervisor itself is lost. Do not defer this to PHP signal callbacks.
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_alarm($timeout + $grace);
            $this->entered = true;

            return;
        }

        // Replace this launcher: timeout creates a dedicated process group and
        // sends TERM, then KILL to uncooperative work, without killing Redis workers.
        // Reusing argv also preserves repeated aliases, limits and global options.
        putenv(self::SUPERVISOR_PID.'='.getmypid());
        pcntl_exec($binary, [
            '--signal=TERM', '--kill-after='.$grace.'s', $timeout.'s',
            PHP_BINARY, ...$arguments,
        ]);

        throw new RuntimeException('Unable to start the bounded deferred processor.');
    }
}
