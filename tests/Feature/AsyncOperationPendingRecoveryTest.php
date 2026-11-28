<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Exceptions\OperationNotFoundException;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Cline\Sequencer\SequentialOrchestrator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * These tests push operations through the real database queue and process them
 * with `queue:work --once --tries=1`, matching a Horizon supervisor that runs
 * every job once. A record left Pending is skipped by discovery forever, so each
 * scenario asserts the record either completes or ends Failed.
 */
describe('Asynchronous operation Pending recovery', function (): void {
    beforeEach(function (): void {
        $this->tempDir = storage_path('framework/testing/pending_recovery_'.uniqid());
        $this->otherReleaseDir = storage_path('framework/testing/pending_recovery_parked_'.uniqid());
        $this->marker = sys_get_temp_dir().'/sequencer_pending_recovery_'.uniqid();
        File::makeDirectory($this->tempDir, 0o755, true);
        File::makeDirectory($this->otherReleaseDir, 0o755, true);

        Config::set('sequencer.execution.discovery_paths', [$this->tempDir]);
        Config::set('sequencer.queue.connection', 'database');
        Config::set('sequencer.queue.queue', 'default');
        Config::set('queue.failed.driver', 'null');
    });

    afterEach(function (): void {
        File::deleteDirectory($this->tempDir);
        File::deleteDirectory($this->otherReleaseDir);

        if (!file_exists($this->marker)) {
            return;
        }

        File::delete($this->marker);
    });

    /**
     * Write an Asynchronous operation that records its execution in the marker file.
     */
    function writeAsyncOperation(string $directory, string $name, string $marker, string $body = ''): string
    {
        $path = $directory.'/'.$name.'.php';
        $handle = $body !== '' ? $body : sprintf("file_put_contents('%s', 'executed');", $marker);

        File::put($path, <<<PHP
<?php

use Cline\\Sequencer\\Contracts\\Asynchronous;
use Cline\\Sequencer\\Contracts\\Operation;

return new class() implements Asynchronous, Operation {
    public function handle(): void
    {
        {$handle}
    }
};
PHP);

        return $path;
    }

    function workOnce(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 1,
            '--queue' => 'default',
        ]);
    }

    function queuedJobCount(): int
    {
        return DB::table('jobs')->count();
    }

    describe('worker without the operation file', function (): void {
        test('requeues the job with a delay instead of running or failing it', function (): void {
            Config::set('sequencer.queue.unresolved_operation.retry_delay', 60);
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000000_late_worker', $this->marker);

            resolve(SequentialOrchestrator::class)->process();

            // The worker server has not deployed this release yet: the file is not on it.
            File::move($path, $this->otherReleaseDir.'/2026_01_01_000000_late_worker.php');

            $before = now()->getTimestamp();
            workOnce();

            $record = OperationModel::query()->sole();
            expect($record->state)->toBe(OperationState::Pending)
                ->and($record->failed_at)->toBeNull()
                ->and(file_exists($this->marker))->toBeFalse()
                ->and(queuedJobCount())->toBe(1)
                ->and(DB::table('jobs')->value('attempts'))->toBe(0)
                ->and(DB::table('jobs')->value('available_at'))->toBeGreaterThanOrEqual($before + 60);
        })->group('regression', 'queue');

        test('requeues on the connection and queue the job came from after the configured delay', function (): void {
            // The default connection differs, so a retry pushed there would run inline instead.
            Config::set('queue.default', 'sync');
            Config::set('sequencer.queue.unresolved_operation.retry_delay', 5);
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000022_own_queue', $this->marker);

            resolve(SequentialOrchestrator::class)->process(queue: 'backfills');
            File::move($path, $this->otherReleaseDir.'/2026_01_01_000022_own_queue.php');

            $before = now()->getTimestamp();
            Artisan::call('queue:work', [
                'connection' => 'database',
                '--once' => true,
                '--tries' => 1,
                '--queue' => 'backfills',
            ]);
            $after = now()->getTimestamp();

            $job = DB::table('jobs')->sole();
            expect($job->queue)->toBe('backfills')
                ->and($job->attempts)->toBe(0)
                ->and($job->available_at)->toBeGreaterThanOrEqual($before + 5)
                ->and($job->available_at)->toBeLessThanOrEqual($after + 5);
        })->group('regression', 'queue');

        test('keeps a job chain in order and runs each operation once', function (): void {
            Config::set('sequencer.queue.unresolved_operation.retry_delay', 60);
            $secondMarker = $this->marker.'_second';
            $first = writeAsyncOperation($this->tempDir, '2026_01_01_000023_chain_first', $this->marker);
            $second = $this->tempDir.'/2026_01_01_000024_chain_second.php';
            File::put($second, sprintf(<<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;

return new class() implements Asynchronous, Operation {
    public function handle(): void
    {
        $first = file_exists('%1$s') ? 'after-first' : 'before-first';
        file_put_contents('%2$s', $first."\n", FILE_APPEND);
    }
};
PHP, $this->marker, $secondMarker));

            $records = [];

            foreach (['2026_01_01_000023_chain_first', '2026_01_01_000024_chain_second'] as $name) {
                $records[] = OperationModel::query()->create([
                    'name' => $name,
                    'type' => 'async',
                    'executed_at' => now(),
                    'state' => OperationState::Pending,
                ]);
            }

            Bus::chain([
                new ExecuteOperation($first, $records[0]->id),
                new ExecuteOperation($second, $records[1]->id),
            ])->onConnection('database')->onQueue('default')->dispatch();

            try {
                File::move($first, $this->otherReleaseDir.'/2026_01_01_000023_chain_first.php');
                workOnce();

                expect(queuedJobCount())->toBe(1)
                    ->and(file_exists($secondMarker))->toBeFalse()
                    ->and($records[1]->fresh()->state)->toBe(OperationState::Pending);

                File::move($this->otherReleaseDir.'/2026_01_01_000023_chain_first.php', $first);
                $this->travel(61)->seconds();
                workOnce();
                workOnce();
                workOnce();

                expect(file_get_contents($secondMarker))->toBe("after-first\n")
                    ->and($records[0]->fresh()->state)->toBe(OperationState::Completed)
                    ->and($records[1]->fresh()->state)->toBe(OperationState::Completed)
                    ->and(queuedJobCount())->toBe(0);
            } finally {
                if (file_exists($secondMarker)) {
                    File::delete($secondMarker);
                }
            }
        })->group('regression', 'queue');

        test('runs the operation once a worker has the file', function (): void {
            Config::set('sequencer.queue.unresolved_operation.retry_delay', 60);
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000001_deployed_later', $this->marker);
            $parked = $this->otherReleaseDir.'/2026_01_01_000001_deployed_later.php';

            resolve(SequentialOrchestrator::class)->process();
            File::move($path, $parked);
            workOnce();

            // The worker server deploys the release.
            File::move($parked, $path);
            $this->travel(61)->seconds();
            workOnce();

            $record = OperationModel::query()->sole();
            expect($record->state)->toBe(OperationState::Completed)
                ->and($record->completed_at)->not->toBeNull()
                ->and(file_get_contents($this->marker))->toBe('executed')
                ->and(queuedJobCount())->toBe(0);
        })->group('regression', 'queue');

        test('marks the record Failed once the retry bound is exhausted', function (): void {
            Config::set('sequencer.queue.unresolved_operation.retry_delay', 60);
            Config::set('sequencer.queue.unresolved_operation.max_retries', 1);
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000002_never_deployed', $this->marker);

            resolve(SequentialOrchestrator::class)->process();
            File::move($path, $this->otherReleaseDir.'/2026_01_01_000002_never_deployed.php');

            workOnce();
            $this->travel(61)->seconds();
            workOnce();

            $record = OperationModel::query()->sole();
            expect($record->state)->toBe(OperationState::Failed)
                ->and($record->failed_at)->not->toBeNull()
                ->and(queuedJobCount())->toBe(0)
                ->and(file_exists($this->marker))->toBeFalse()
                ->and(OperationError::query()->where('operation_id', $record->id)->count())->toBe(1);
        })->group('regression', 'queue');

        test('discovery dispatches the operation again after it failed', function (): void {
            Config::set('sequencer.queue.unresolved_operation.max_retries', 0);
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000003_retried_next_deploy', $this->marker);
            $parked = $this->otherReleaseDir.'/2026_01_01_000003_retried_next_deploy.php';

            resolve(SequentialOrchestrator::class)->process();
            File::move($path, $parked);
            workOnce();
            expect(OperationModel::query()->sole()->state)->toBe(OperationState::Failed);

            // Next deploy: the file is back everywhere.
            File::move($parked, $path);
            $this->travel(1)->minutes();
            resolve(SequentialOrchestrator::class)->process();
            workOnce();

            expect(OperationModel::query()->where('state', OperationState::Completed)->count())->toBe(1)
                ->and(file_get_contents($this->marker))->toBe('executed');
        })->group('regression', 'queue');
    });

    describe('job that never returns from handle', function (): void {
        test('marks the record Failed when the worker gives up on an abandoned job', function (): void {
            writeAsyncOperation($this->tempDir, '2026_01_01_000004_killed', $this->marker);

            resolve(SequentialOrchestrator::class)->process();

            // A worker reserved the job and died (timeout kill, memory, restart):
            // the reservation expired with the attempt already counted.
            DB::table('jobs')->update([
                'attempts' => 1,
                'reserved_at' => now()->subHour()->getTimestamp(),
            ]);

            workOnce();

            $record = OperationModel::query()->sole();
            expect($record->state)->toBe(OperationState::Failed)
                ->and($record->failed_at)->not->toBeNull()
                ->and(file_exists($this->marker))->toBeFalse()
                ->and(queuedJobCount())->toBe(0)
                ->and(OperationError::query()->where('operation_id', $record->id)->count())->toBe(1);
        })->group('regression', 'queue');

        test('marks the record Failed when a worker without the file gives up on it', function (): void {
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000008_killed_old_worker', $this->marker);

            resolve(SequentialOrchestrator::class)->process();
            DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => now()->subHour()->getTimestamp()]);
            File::move($path, $this->otherReleaseDir.'/2026_01_01_000008_killed_old_worker.php');

            workOnce();

            $record = OperationModel::query()->sole();
            expect($record->state)->toBe(OperationState::Failed)
                ->and(queuedJobCount())->toBe(0)
                ->and(OperationError::query()->where('operation_id', $record->id)->value('exception'))
                ->toBe(MaxAttemptsExceededException::class);
        })->group('regression', 'queue');

        test('runs the lifecycle failed hook once when the operation throws', function (): void {
            $hookMarker = $this->marker.'_hook';
            File::put($this->tempDir.'/2026_01_01_000009_hooked.php', sprintf(<<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\HasLifecycleHooks;
use Cline\Sequencer\Contracts\Operation;

return new class() implements Asynchronous, HasLifecycleHooks, Operation {
    public function before(): void {}

    public function handle(): void
    {
        file_put_contents('%1$s', "handle\n", FILE_APPEND);

        throw new RuntimeException('hooked');
    }

    public function after(): void {}

    public function failed(Throwable $exception): void
    {
        file_put_contents('%2$s', "failed\n", FILE_APPEND);
    }
};
PHP, $this->marker, $hookMarker));

            $failedJobs = 0;
            Event::listen(JobFailed::class, function () use (&$failedJobs): void {
                ++$failedJobs;
            });

            try {
                resolve(SequentialOrchestrator::class)->process();
                workOnce();

                $record = OperationModel::query()->sole();
                expect(file_get_contents($this->marker))->toBe("handle\n")
                    ->and(file_get_contents($hookMarker))->toBe("failed\n")
                    ->and($record->state)->toBe(OperationState::Failed)
                    ->and(OperationError::query()->count())->toBe(1)
                    ->and($failedJobs)->toBe(1)
                    ->and(queuedJobCount())->toBe(0);
            } finally {
                if (file_exists($hookMarker)) {
                    File::delete($hookMarker);
                }
            }
        })->group('regression', 'queue');

        test('retries a Retryable operation for each of its tries', function (): void {
            File::put($this->tempDir.'/2026_01_01_000010_retryable.php', sprintf(<<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Retryable;

return new class() implements Asynchronous, Operation, Retryable {
    public function handle(): void
    {
        file_put_contents('%s', "handle\n", FILE_APPEND);

        throw new RuntimeException('again');
    }

    public function tries(): int { return 3; }

    public function backoff(): int { return 0; }

    public function retryUntil(): ?DateTimeInterface { return null; }
};
PHP, $this->marker));

            resolve(SequentialOrchestrator::class)->process();
            workOnce();
            workOnce();
            workOnce();

            expect(file_get_contents($this->marker))->toBe(str_repeat("handle\n", 3))
                ->and(OperationModel::query()->sole()->state)->toBe(OperationState::Failed)
                ->and(queuedJobCount())->toBe(0);
        })->group('regression', 'queue');

        test('keeps the single error recorded by handle when the operation throws', function (): void {
            writeAsyncOperation($this->tempDir, '2026_01_01_000005_throws', $this->marker, "throw new RuntimeException('boom');");

            resolve(SequentialOrchestrator::class)->process();
            workOnce();

            $record = OperationModel::query()->sole();
            expect($record->state)->toBe(OperationState::Failed)
                ->and(OperationError::query()->where('operation_id', $record->id)->count())->toBe(1)
                ->and(OperationError::query()->where('operation_id', $record->id)->value('message'))->toBe('boom');
        })->group('regression', 'queue');

        test('does not touch a record that already completed', function (): void {
            writeAsyncOperation($this->tempDir, '2026_01_01_000006_completed', $this->marker);

            resolve(SequentialOrchestrator::class)->process();
            workOnce();

            $record = OperationModel::query()->sole();
            $job = new ExecuteOperation($this->tempDir.'/2026_01_01_000006_completed.php', $record->id);
            $job->failed(
                new RuntimeException('late failure'),
            );

            $record->refresh();
            expect($record->state)->toBe(OperationState::Completed)
                ->and($record->failed_at)->toBeNull()
                ->and(OperationError::query()->count())->toBe(0);
        })->group('regression', 'queue');
    });

    describe('failed()', function (): void {
        test('leaves terminal records unchanged', function (string $state, array $attributes): void {
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000011_terminal', $this->marker);
            $record = OperationModel::query()->create([
                'name' => '2026_01_01_000011_terminal',
                'type' => 'async',
                'executed_at' => now()->subMinute(),
                'state' => OperationState::from($state),
                ...$attributes,
            ]);
            $before = $record->fresh()->getAttributes();

            new ExecuteOperation($path, $record->id)->failed(
                new RuntimeException('late'),
            );

            expect($record->fresh()->getAttributes())->toBe($before)
                ->and(OperationError::query()->count())->toBe(0);
        })->with([
            'completed' => ['completed', ['completed_at' => '2026-01-01 00:00:00']],
            'failed' => ['failed', ['failed_at' => '2026-01-01 00:00:00']],
            'skipped' => ['skipped', ['skipped_at' => '2026-01-01 00:00:00', 'skip_reason' => 'not needed']],
            'rolled back' => ['rolled_back', ['completed_at' => '2026-01-01 00:00:00', 'rolled_back_at' => '2026-01-01 00:00:00']],
        ])->group('regression', 'queue');

        test('marks a Pending record Failed with the exception recorded', function (): void {
            $this->freezeTime();
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000012_pending', $this->marker);
            $record = OperationModel::query()->create([
                'name' => '2026_01_01_000012_pending',
                'type' => 'async',
                'executed_at' => now(),
                'state' => OperationState::Pending,
            ]);
            $job = new ExecuteOperation($path, $record->id);
            File::delete($path);

            $job->failed(
                new LogicException('gave up'),
            );

            $record->refresh();
            $error = OperationError::query()->sole();
            expect($record->state)->toBe(OperationState::Failed)
                ->and($record->failed_at->getTimestamp())->toBe(now()->getTimestamp())
                ->and($record->completed_at)->toBeNull()
                ->and($error->exception)->toBe(LogicException::class)
                ->and($error->message)->toBe('gave up');
        })->group('regression', 'queue');

        test('marks a Pending record Failed without an exception', function (): void {
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000013_no_exception', $this->marker);
            $record = OperationModel::query()->create([
                'name' => '2026_01_01_000013_no_exception',
                'type' => 'async',
                'executed_at' => now(),
                'state' => OperationState::Pending,
            ]);

            new ExecuteOperation($path, $record->id)->failed(null);

            expect($record->fresh()->state)->toBe(OperationState::Failed)
                ->and(OperationError::query()->count())->toBe(0);
        })->group('regression', 'queue');

        test('ignores a missing record', function (): void {
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000014_no_record', $this->marker);

            new ExecuteOperation($path, 999_999)->failed(
                new RuntimeException('x'),
            );

            expect(OperationModel::query()->count())->toBe(0);
        })->group('regression', 'queue');
    });

    describe('job methods on a worker without the operation file', function (): void {
        test('return defaults instead of failing', function (): void {
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000015_defaults', $this->marker);
            $job = new ExecuteOperation($path, 42);
            File::delete($path);

            expect($job->middleware())->toBe([])
                ->and($job->tags())->toBe([])
                ->and($job->uniqueId())->toBe('42')
                ->and($job->uniqueFor())->toBe(3_600)
                ->and($job->uniqueVia())->toBeInstanceOf(Repository::class);
        })->group('regression', 'queue');

        test('keep the unique key the dispatcher used', function (): void {
            $path = $this->tempDir.'/2026_01_01_000016_unique.php';
            File::put($path, <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\ShouldBeUnique;

return new class() implements Asynchronous, Operation, ShouldBeUnique {
    public function handle(): void {}

    public function uniqueId(): string { return 'custom-key'; }

    public function uniqueFor(): int { return 60; }

    public function uniqueVia(): ?Illuminate\Contracts\Cache\Repository { return null; }
};
PHP);
            $job = unserialize(serialize(
                new ExecuteOperation($path, 42),
            ));
            File::delete($path);

            expect($job->uniqueId())->toBe('custom-key');
        })->group('regression', 'queue');

        test('throws a sequencer exception when handled outside a queue worker', function (): void {
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000017_direct', $this->marker);
            $record = OperationModel::query()->create([
                'name' => '2026_01_01_000017_direct',
                'type' => 'async',
                'executed_at' => now(),
                'state' => OperationState::Pending,
            ]);
            $job = new ExecuteOperation($path, $record->id);
            File::delete($path);

            expect(fn () => $job->handle())
                ->toThrow(OperationNotFoundException::class, 'Operation file not found on this worker');
        })->group('regression', 'queue');

        test('runs the file found through a discovery path without deferring', function (): void {
            $dispatchedPath = $this->otherReleaseDir.'/2026_01_01_000018_moved_release.php';
            writeAsyncOperation($this->otherReleaseDir, '2026_01_01_000018_moved_release', $this->marker);
            $record = OperationModel::query()->create([
                'name' => '2026_01_01_000018_moved_release',
                'type' => 'async',
                'executed_at' => now(),
                'state' => OperationState::Pending,
            ]);
            $job = new ExecuteOperation($dispatchedPath, $record->id);

            // The release that dispatched the job is gone; the current one has the file.
            File::move($dispatchedPath, $this->tempDir.'/2026_01_01_000018_moved_release.php');
            $job->withFakeQueueInteractions()->handle();

            $job->assertNotReleased();
            expect($record->fresh()->state)->toBe(OperationState::Completed)
                ->and(file_get_contents($this->marker))->toBe('executed');
        })->group('regression', 'queue');

        test('runs a job queued by the previous package version', function (): void {
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000019_old_payload', $this->marker);
            $record = OperationModel::query()->create([
                'name' => '2026_01_01_000019_old_payload',
                'type' => 'async',
                'executed_at' => now(),
                'state' => OperationState::Pending,
            ]);

            // Payload properties the previous release serialized; the new ones are absent.
            $values = array_filter(
                new ExecuteOperation($path, $record->id)->__serialize(),
                fn (string $key): bool => !str_contains($key, 'unresolvedRetries') && !str_contains($key, 'uniqueKey'),
                \ARRAY_FILTER_USE_KEY,
            );
            $job = new ReflectionClass(ExecuteOperation::class)->newInstanceWithoutConstructor();
            $job->__unserialize($values);

            $job->handle();

            expect($record->fresh()->state)->toBe(OperationState::Completed)
                ->and($job->uniqueId())->toBe((string) $record->id);
        })->group('regression', 'queue');

        test('releases a batched job without touching the batch counts', function (): void {
            Config::set('queue.batching.database', 'testing');
            Config::set('queue.batching.table', 'job_batches');
            $path = writeAsyncOperation($this->tempDir, '2026_01_01_000020_batched', $this->marker);
            $record = OperationModel::query()->create([
                'name' => '2026_01_01_000020_batched',
                'type' => 'async',
                'executed_at' => now(),
                'state' => OperationState::Pending,
            ]);
            $batch = Bus::batch([new ExecuteOperation($path, $record->id)])
                ->onConnection('database')
                ->onQueue('default')
                ->dispatch();
            File::move($path, $this->otherReleaseDir.'/2026_01_01_000020_batched.php');

            $before = now()->getTimestamp();
            workOnce();

            $batch = $batch->fresh();
            expect($record->fresh()->state)->toBe(OperationState::Pending)
                ->and($batch->pendingJobs)->toBe(1)
                ->and($batch->failedJobs)->toBe(0)
                ->and(queuedJobCount())->toBe(1)
                ->and(DB::table('jobs')->value('attempts'))->toBe(1)
                ->and(DB::table('jobs')->value('available_at'))->toBeGreaterThanOrEqual($before + 60);
        })->group('regression', 'queue');
    });

    describe('push that fails after the record was written', function (): void {
        test('marks the record Failed and rethrows', function (): void {
            Config::set('sequencer.queue.connection', 'not-configured');
            writeAsyncOperation($this->tempDir, '2026_01_01_000007_push_fails', $this->marker);

            expect(fn () => resolve(SequentialOrchestrator::class)->process())
                ->toThrow(InvalidArgumentException::class);

            $record = OperationModel::query()->sole();
            expect($record->state)->toBe(OperationState::Failed)
                ->and($record->failed_at)->not->toBeNull()
                ->and(OperationError::query()->where('operation_id', $record->id)->count())->toBe(1);

            // Discovery offers it again once the queue works.
            Config::set('sequencer.queue.connection', 'database');
            $this->travel(1)->minutes();
            resolve(SequentialOrchestrator::class)->process();

            expect(queuedJobCount())->toBe(1);
        })->group('regression', 'queue');

        test('rethrows the push failure when marking the record also fails', function (): void {
            Config::set('sequencer.queue.connection', 'not-configured');
            writeAsyncOperation($this->tempDir, '2026_01_01_000027_marking_fails', $this->marker);
            Event::listen('eloquent.updating: '.OperationModel::class, function (): void {
                throw new LogicException('database down');
            });

            expect(fn () => resolve(SequentialOrchestrator::class)->process())
                ->toThrow(InvalidArgumentException::class);
        })->group('regression', 'queue');

        test('records a failing operation once on a sync queue', function (): void {
            Config::set('sequencer.queue.connection', 'sync');
            writeAsyncOperation($this->tempDir, '2026_01_01_000026_sync_throws', $this->marker, "throw new RuntimeException('sync boom');");

            expect(fn () => resolve(SequentialOrchestrator::class)->process())
                ->toThrow(RuntimeException::class, 'sync boom');

            $record = OperationModel::query()->sole();
            expect($record->state)->toBe(OperationState::Failed)
                ->and(OperationError::query()->where('operation_id', $record->id)->count())->toBe(1);
        })->group('regression', 'queue');

        test('marks the record Failed when building the job throws', function (): void {
            File::put($this->tempDir.'/2026_01_01_000021_constructor_fails.php', <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Retryable;

return new class() implements Asynchronous, Operation, Retryable {
    public function handle(): void {}

    public function tries(): int { throw new LogicException('bad tries'); }

    public function backoff(): int { return 0; }

    public function retryUntil(): ?DateTimeInterface { return null; }
};
PHP);

            expect(fn () => resolve(SequentialOrchestrator::class)->process())
                ->toThrow(LogicException::class, 'bad tries');

            expect(OperationModel::query()->sole()->state)->toBe(OperationState::Failed)
                ->and(queuedJobCount())->toBe(0);
        })->group('regression', 'queue');
    });
});
