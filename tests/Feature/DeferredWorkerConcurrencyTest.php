<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\DeferredOperation;
use Cline\Sequencer\Enums\DeferredOperationStatus;
use Cline\Sequencer\SequencerManager;
use Cline\Sequencer\Support\DeferredOperationProcessor;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Fixtures\DeferredOperations\CallbackDeferredOperation;

// MySQL 8 exposes actual row-lock waits; SQLite cannot prove competing claims.
test('independent workers serialize claims and recover a lost or expired owner', function (bool $crash): void {
    if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires MySQL 8 row-lock instrumentation and pcntl.');
    }

    // RefreshDatabase's ambient transaction cannot publish fixtures to children.
    // Only this test's row/file are committed, and both are removed in finally.
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    Date::setTestNow('2026-10-23 12:00:00');
    config()->set('sequencer.deferred.taskMap', ['worker' => CallbackDeferredOperation::class]);
    config()->set('sequencer.deferred.retry_after_seconds', 300);
    config()->set('sequencer.deferred.erase_payload_for', ['worker']);

    $row = resolve(SequencerManager::class)->defer('worker', ['secret' => 'retain until terminal'], Date::now(), maxAttempts: 3);
    $ledger = tempnam(sys_get_temp_dir(), 'sequencer-worker-');
    $workers = [];

    try {
        $workers[] = forkDeferredWorker(function ($socket) use ($row, $ledger): void {
            DeferredOperation::updating(function (DeferredOperation $record) use ($row, $socket): void {
                if ($record->getKey() !== $row->getKey() || $record->status !== DeferredOperationStatus::Processing) {
                    return;
                }

                deferredWorkerSend($socket, ['phase' => 'locked']);
                deferredWorkerRead($socket);
            });
            app()->bind(CallbackDeferredOperation::class, fn (): CallbackDeferredOperation => new CallbackDeferredOperation(function () use ($socket, $ledger): void {
                file_put_contents($ledger, "original\n", \FILE_APPEND | \LOCK_EX);
                deferredWorkerSend($socket, ['phase' => 'executing']);
                deferredWorkerRead($socket);
            }));
            deferredWorkerSend($socket, ['stats' => resolve(DeferredOperationProcessor::class)->processDue(1)]);
        });
        expect(deferredWorkerRead($workers[0]['socket'])['phase'])->toBe('locked');
        $workers[] = forkDeferredWorker(function ($socket): void {
            app()->bind(CallbackDeferredOperation::class, fn (): CallbackDeferredOperation => new CallbackDeferredOperation(function (): void {
                throw new RuntimeException('A fresh competing worker must not execute the handler.');
            }));
            deferredWorkerSend($socket, ['phase' => 'attempting']);
            deferredWorkerSend($socket, ['stats' => resolve(DeferredOperationProcessor::class)->processDue(1)]);
        });
        expect(deferredWorkerRead($workers[1]['socket'])['phase'])->toBe('attempting');
        $deadline = microtime(true) + 10;

        do {
            $waits = DB::selectOne('SELECT COUNT(*) AS waiting FROM performance_schema.data_lock_waits');

            if ((int) $waits->waiting > 0) {
                break;
            }

            Sleep::usleep(10_000);
        } while (microtime(true) < $deadline);

        expect((int) $waits->waiting)->toBeGreaterThan(0);
        deferredWorkerSend($workers[0]['socket'], ['phase' => 'persist']);
        expect(deferredWorkerRead($workers[0]['socket'])['phase'])->toBe('executing')
            ->and(deferredWorkerRead($workers[1]['socket'])['stats'])->toBe(['processed' => 0, 'completed' => 0, 'failed' => 0, 'retried' => 0])
            ->and($row->fresh()->attempts)->toBe(1)
            ->and($row->fresh()->status)->toBe(DeferredOperationStatus::Processing);

        if ($crash) {
            expect(posix_kill($workers[0]['pid'], \SIGKILL))->toBeTrue();
            pcntl_waitpid($workers[0]['pid'], $status);
        }

        // Even a dead owner is not recovered while its reservation is fresh.
        expect(resolve(DeferredOperationProcessor::class)->processDue(1)['processed'])->toBe(0);
        Date::setTestNow('2026-10-23 12:05:00');
        app()->bind(CallbackDeferredOperation::class, fn (): CallbackDeferredOperation => new CallbackDeferredOperation(function () use ($ledger): void {
            file_put_contents($ledger, "replacement\n", \FILE_APPEND | \LOCK_EX);
        }));
        expect(resolve(DeferredOperationProcessor::class)->processDue(1)['completed'])->toBe(1);
        $snapshot = $row->fresh()->getRawOriginal();

        if (!$crash) {
            deferredWorkerSend($workers[0]['socket'], ['phase' => 'finish']);
            expect(deferredWorkerRead($workers[0]['socket'])['stats'])->toBe(['processed' => 1, 'completed' => 0, 'failed' => 0, 'retried' => 0]);
        }

        expect($row->fresh()->getRawOriginal())->toBe($snapshot)
            ->and($row->fresh()->status)->toBe(DeferredOperationStatus::Completed)
            ->and($row->fresh()->attempts)->toBe(2)
            ->and($row->fresh()->payload)->toBe([])
            ->and(file_get_contents($ledger))->toBe("original\nreplacement\n");
    } finally {
        foreach ($workers as $worker) {
            fclose($worker['socket']);

            if (pcntl_waitpid($worker['pid'], $status, \WNOHANG) !== 0) {
                continue;
            }

            posix_kill($worker['pid'], \SIGKILL);
            pcntl_waitpid($worker['pid'], $status);
        }

        DeferredOperation::query()->whereKey($row->getKey())->delete();
        File::delete($ledger);
        Date::setTestNow();
    }
})->with([false, true]);

/**
 * @return array{pid: int, socket: resource}
 */
function forkDeferredWorker(Closure $action): array
{
    // Do not inherit a live PDO socket and accidentally close a parent's session.
    DB::disconnect();
    $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    throw_if($pair === false, RuntimeException::class, 'Unable to create deferred worker barrier.');

    $pid = pcntl_fork();

    if ($pid === -1) {
        fclose($pair[0]);
        fclose($pair[1]);

        throw new RuntimeException('Unable to create isolated deferred worker.');
    }

    if ($pid === 0) {
        fclose($pair[0]);
        stream_set_timeout($pair[1], 15);
        DB::purge();

        try {
            $action($pair[1]);
            $code = 0;
        } catch (Throwable $error) {
            deferredWorkerSend($pair[1], ['error' => $error->getMessage()]);
            $code = 1;
        } finally {
            DB::disconnect();
            fclose($pair[1]);
        }

        exit($code);
    }

    fclose($pair[1]);
    stream_set_timeout($pair[0], 15);

    return ['pid' => $pid, 'socket' => $pair[0]];
}

/**
 * @param resource $socket
 */
function deferredWorkerSend($socket, array $message): void
{
    fwrite($socket, json_encode($message, \JSON_THROW_ON_ERROR)."\n");
}

/**
 * @param resource $socket @return array<string, mixed>
 */
function deferredWorkerRead($socket): array
{
    $line = fgets($socket);
    throw_if($line === false, RuntimeException::class, 'Deferred worker exited or exceeded its bounded barrier timeout.');

    $message = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

    if (isset($message['error'])) {
        throw new RuntimeException($message['error']);
    }

    return $message;
}
