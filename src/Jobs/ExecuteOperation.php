<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Jobs;

use Cline\Sequencer\Contracts\HasLifecycleHooks;
use Cline\Sequencer\Contracts\HasMaxExceptions;
use Cline\Sequencer\Contracts\HasMiddleware;
use Cline\Sequencer\Contracts\HasTags;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Retryable;
use Cline\Sequencer\Contracts\ShouldBeUnique;
use Cline\Sequencer\Contracts\Timeoutable;
use Cline\Sequencer\Contracts\WithinTransaction;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Events\OperationEnded;
use Cline\Sequencer\Events\OperationSkipped;
use Cline\Sequencer\Events\OperationStarted;
use Cline\Sequencer\Exceptions\OperationNotFoundException;
use Cline\Sequencer\Exceptions\SkipOperationException;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted as LaravelShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique as LaravelShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

use function array_values;
use function base_path;
use function basename;
use function config;
use function file_exists;
use function hrtime;
use function is_array;
use function mb_rtrim;
use function mb_substr;
use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * Queue job for executing operations asynchronously.
 *
 * Dispatched by SequentialOrchestrator for operations implementing the Asynchronous
 * interface. Provides identical transaction wrapping, error handling, and audit trail
 * functionality as synchronous operations, enabling background processing without
 * sacrificing reliability or traceability.
 *
 * The job automatically configures itself based on operation-specific interfaces:
 * - Retryable: sets tries, backoff, and retryUntil from operation
 * - Timeoutable: sets timeout and failOnTimeout from operation
 * - HasMaxExceptions: sets maxExceptions from operation
 * - HasMiddleware: applies operation-defined middleware stack
 * - HasTags: applies operation-defined tags for monitoring
 * - ShouldBeUnique: enforces uniqueness constraints from operation
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ExecuteOperation implements LaravelShouldBeEncrypted, LaravelShouldBeUnique, ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * Automatically configured from operation's tries() method if it implements
     * the Retryable interface. Defaults to null which uses queue configuration.
     */
    public ?int $tries = null;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * Supports both fixed delays (int) and exponential backoff (array of delays).
     * Automatically configured from operation's backoff() method if it implements
     * the Retryable interface. Defaults to null which uses queue configuration.
     *
     * @var null|array<int, int>|int
     */
    public array|int|null $backoff = null;

    /**
     * The DateTime when the job should stop attempting retries.
     *
     * Automatically configured from operation's retryUntil() method if it implements
     * the Retryable interface. After this time, the job will not be retried even if
     * it hasn't reached the maximum tries limit.
     */
    public ?DateTimeInterface $retryUntil = null;

    /**
     * The number of seconds the job can run before timing out.
     *
     * Automatically configured from operation's timeout() method if it implements
     * the Timeoutable interface. Defaults to null which uses queue configuration.
     */
    public ?int $timeout = null;

    /**
     * Indicates if the job should be marked as failed on timeout.
     *
     * Automatically configured from operation's failOnTimeout() method if it implements
     * the Timeoutable interface. When false, the job is released back to the queue on
     * timeout instead of being marked as failed.
     */
    public bool $failOnTimeout = false;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * Automatically configured from operation's maxExceptions() method if it implements
     * the HasMaxExceptions interface. Useful for operations with intermittent failures
     * that should eventually succeed without exhausting retry limits.
     */
    public ?int $maxExceptions = null;

    /**
     * How often this job was put back because no operation file resolved.
     *
     * A worker on an older release does not have a newly deployed operation
     * file. The job is then pushed again with a delay, carrying this count,
     * until a worker that has the file takes it or the configured bound is
     * reached. Old payloads lack the property and start at zero.
     */
    private int $unresolvedRetries = 0;

    /**
     * Unique key of a ShouldBeUnique operation, captured at dispatch.
     *
     * Lets a worker without the operation file release the same unique lock
     * the dispatcher acquired. Null for other operations and old payloads.
     */
    private ?string $uniqueKey = null;

    /**
     * Create a new job instance.
     *
     * Automatically configures job behavior based on operation interfaces. If the operation
     * implements Retryable, Timeoutable, or HasMaxExceptions, the corresponding job properties
     * are set from the operation's methods. This allows operations to define their own retry,
     * timeout, and failure handling strategies.
     *
     * Note: To support anonymous class operations, we serialize the operation file path instead
     * of the operation object itself (PHP cannot serialize anonymous classes). The operation is
     * re-instantiated from the file path when the job is processed.
     *
     * @param string     $operationPath Absolute file path to the operation file. The operation
     *                                  is required from this path to get the instance. This allows
     *                                  anonymous class operations to be serialized for queue jobs.
     * @param int|string $recordId      Primary key of the Operation model record for status tracking
     *                                  and audit trail. Used to update execution timestamps (executed_at,
     *                                  completed_at, failed_at) and record error details in the operations
     *                                  table for monitoring and debugging purposes.
     */
    public function __construct(
        private readonly string $operationPath,
        private readonly int|string $recordId,
    ) {
        // Require operation file to get instance for configuration
        $operation = $this->loadOperation();

        // Apply retry configuration from operation if it implements Retryable
        if ($operation instanceof Retryable) {
            $this->tries = $operation->tries();
            $backoff = $operation->backoff();
            $this->backoff = is_array($backoff) ? array_values($backoff) : $backoff;
            $this->retryUntil = $operation->retryUntil();
        }

        // Apply timeout configuration from operation if it implements Timeoutable
        if ($operation instanceof Timeoutable) {
            $this->timeout = $operation->timeout();
            $this->failOnTimeout = $operation->failOnTimeout();
        }

        if ($operation instanceof ShouldBeUnique) {
            $this->uniqueKey = $operation->uniqueId();
        }

        // Apply max exceptions configuration from operation if it implements HasMaxExceptions
        if (!$operation instanceof HasMaxExceptions) {
            return;
        }

        $this->maxExceptions = $operation->maxExceptions();
    }

    /**
     * Get the middleware the job should pass through.
     *
     * Retrieves middleware stack from the operation if it implements HasMiddleware,
     * allowing operations to define their own job middleware for rate limiting,
     * logging, or custom behavior without modifying the job class.
     *
     * @return array<int, object> Array of middleware instances to apply to the job
     */
    public function middleware(): array
    {
        $operation = $this->resolveOperation();

        if ($operation instanceof HasMiddleware) {
            return array_values($operation->middleware());
        }

        return [];
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * Retrieves tags from the operation if it implements HasTags, enabling
     * operation-specific tagging for queue monitoring, filtering, and analytics
     * in tools like Laravel Horizon. Tags help identify and group related jobs.
     *
     * @return array<int, string> Array of tag strings for job identification
     */
    public function tags(): array
    {
        $operation = $this->resolveOperation();

        if ($operation instanceof HasTags) {
            return array_values($operation->tags());
        }

        return [];
    }

    /**
     * Get the unique ID for the job.
     *
     * Retrieves custom unique identifier from the operation if it implements
     * ShouldBeUnique, otherwise defaults to the operation record ID. Used by
     * Laravel's unique job implementation to prevent duplicate job execution.
     *
     * @return string Unique identifier for preventing duplicate job execution
     */
    public function uniqueId(): string
    {
        $operation = $this->resolveOperation();

        if ($operation instanceof ShouldBeUnique) {
            return $operation->uniqueId();
        }

        return $this->uniqueKey ?? (string) $this->recordId;
    }

    /**
     * Get the number of seconds the unique lock should be maintained.
     *
     * Retrieves lock duration from the operation if it implements ShouldBeUnique,
     * otherwise defaults to 3600 seconds (1 hour). The lock prevents duplicate jobs
     * with the same uniqueId from being processed for this duration.
     *
     * @return int Number of seconds to maintain the unique job lock
     */
    public function uniqueFor(): int
    {
        $operation = $this->resolveOperation();

        if ($operation instanceof ShouldBeUnique) {
            return $operation->uniqueFor();
        }

        return 3_600;
    }

    /**
     * Get the cache repository for unique lock.
     *
     * Retrieves custom cache repository from the operation if it implements
     * ShouldBeUnique. Allows operations to use different cache stores for
     * unique lock management (e.g., Redis for distributed systems).
     *
     * Note: Returns default cache store if operation doesn't specify one, as
     * Laravel requires a non-null value when job implements ShouldBeUnique.
     *
     * @return Repository Cache store for unique locks
     */
    public function uniqueVia(): Repository
    {
        $operation = $this->resolveOperation();

        if ($operation instanceof ShouldBeUnique) {
            $repository = $operation->uniqueVia();

            if ($repository instanceof Repository) {
                return $repository;
            }
        }

        return Container::getInstance()->make(Repository::class);
    }

    /**
     * Execute the queued operation.
     *
     * Requires the operation from the file path, then executes it within an optional database
     * transaction based on configuration and operation interfaces. Updates the operation record
     * with completion or failure timestamps. Errors are logged and rethrown to trigger
     * queue retry mechanisms.
     *
     * @throws Throwable When operation execution fails, after recording error details
     */
    public function handle(): void
    {
        $startTime = hrtime(true);
        $record = OperationModel::query()->findOrFail($this->recordId);

        // A worker on a release without this operation file must not run it
        if ($this->resolveOperationPath() === null) {
            $this->deferUnresolvedOperation();

            return;
        }

        // Require operation file to get instance
        $operation = $this->loadOperation();

        Event::dispatch(
            new OperationStarted($operation, ExecutionMethod::Async),
        );

        // Execute before hook if operation implements HasLifecycleHooks
        if ($operation instanceof HasLifecycleHooks) {
            $operation->before();
        }

        $autoTransaction = config('sequencer.execution.auto_transaction', true);
        $useTransaction = $operation instanceof WithinTransaction || $autoTransaction;

        try {
            if ($useTransaction) {
                DB::transaction(fn () => $operation->handle());
            } else {
                $operation->handle();
            }

            $record->update([
                'completed_at' => Date::now(),
                'state' => OperationState::Completed,
            ]);

            // Execute after hook if operation implements HasLifecycleHooks
            if ($operation instanceof HasLifecycleHooks) {
                $operation->after();
            }

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1e6);

            Event::dispatch(
                new OperationEnded($operation, ExecutionMethod::Async, $elapsedMs),
            );
        } catch (SkipOperationException $exception) {
            $record->update([
                'skipped_at' => Date::now(),
                'skip_reason' => $exception->getMessage(),
                'state' => OperationState::Skipped,
            ]);

            /** @var string $logChannel */
            $logChannel = config('sequencer.errors.log_channel', 'stack');
            Log::channel($logChannel)->info('Operation skipped during execution', [
                'operation' => $record->name,
                'reason' => $exception->getMessage(),
            ]);

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1e6);

            Event::dispatch(
                new OperationSkipped($operation, ExecutionMethod::Async, $exception->getMessage(), $elapsedMs),
            );
        } catch (Throwable $throwable) {
            $record->update([
                'failed_at' => Date::now(),
                'state' => OperationState::Failed,
            ]);

            $this->recordError($record, $throwable);

            // Execute failed hook if operation implements HasLifecycleHooks
            if ($operation instanceof HasLifecycleHooks) {
                $operation->failed($throwable);
            }

            throw $throwable;
        }
    }

    /**
     * Mark the operation Failed when the queue gives up on the job.
     *
     * Laravel calls this when the job exhausts its attempts, times out, is
     * picked up again after its worker died, or fails while it waits for an
     * unresolved operation file. Only a record still Pending changes: when
     * handle() already recorded a failure, completion or skip, that outcome
     * stands. A Failed record is offered again by discovery on the next run,
     * while a Pending one would be skipped forever.
     *
     * The operation's own lifecycle hooks are not called here, because the
     * operation file may not exist on this worker.
     *
     * @param null|Throwable $exception The reason the queue gave up, when known
     */
    public function failed(?Throwable $exception = null): void
    {
        $record = OperationModel::query()->find($this->recordId);

        if (!$record instanceof OperationModel || $record->state !== OperationState::Pending) {
            return;
        }

        $record->update([
            'failed_at' => Date::now(),
            'state' => OperationState::Failed,
        ]);

        if (!$exception instanceof Throwable) {
            return;
        }

        $this->recordError($record, $exception);
    }

    /**
     * Put the job back until a worker that has the operation file takes it.
     *
     * Web and worker servers can deploy separately while sharing one queue, so
     * a worker still on the previous release can take a job for an operation
     * file it does not have yet. The job is pushed again with a delay on the
     * same connection and queue; a batched job is released instead so batch
     * accounting stays intact, which spends one of its attempts. After the
     * configured number of retries the job fails, and failed() marks the record.
     *
     * @throws OperationNotFoundException When run outside a queue worker
     */
    private function deferUnresolvedOperation(): void
    {
        $exception = OperationNotFoundException::forUnresolvedPath($this->operationPath);

        if ($this->job === null) {
            throw $exception;
        }

        $retries = $this->batchId === null
            ? $this->unresolvedRetries
            : $this->attempts() - 1;

        /** @var int $maxRetries */
        $maxRetries = config('sequencer.queue.unresolved_operation.max_retries', 60);

        if ($retries >= $maxRetries) {
            $this->fail($exception);

            return;
        }

        /** @var int $delay */
        $delay = config('sequencer.queue.unresolved_operation.retry_delay', 60);

        /** @var string $logChannel */
        $logChannel = config('sequencer.errors.log_channel', 'stack');
        Log::channel($logChannel)->warning('Operation file not found on this worker; retrying later', [
            'operation' => basename($this->operationPath),
            'retry' => $retries + 1,
            'max_retries' => $maxRetries,
            'delay' => $delay,
        ]);

        if ($this->batchId !== null) {
            $this->release($delay);

            return;
        }

        $retry = clone $this;
        $retry->job = null;
        ++$retry->unresolvedRetries;

        Queue::connection($this->job->getConnectionName())
            ->laterOn($this->job->getQueue(), $delay, $retry);
    }

    /**
     * Record operation error for audit trail and logging.
     *
     * Creates an OperationError record with exception details (class, message, trace, context)
     * and logs the failure to the configured log channel. Only executes if error recording
     * is enabled in configuration (sequencer.errors.record). Captures complete exception
     * context including file location, line number, and error code for debugging purposes.
     *
     * @param OperationModel $record    The operation record that failed execution. Used to link
     *                                  the error record to the specific operation for querying
     *                                  and debugging failed operations via Sequencer::getErrors().
     * @param Throwable      $exception The exception that caused the operation failure. All
     *                                  exception data (class, message, trace, file, line, code)
     *                                  is extracted and persisted to the operation_errors table
     *                                  for comprehensive failure analysis and debugging.
     */
    private function recordError(OperationModel $record, Throwable $exception): void
    {
        if (!config('sequencer.errors.record', true)) {
            return;
        }

        OperationError::query()->create([
            'operation_id' => $record->id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'context' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'code' => $exception->getCode(),
            ],
            'created_at' => Date::now(),
        ]);

        /** @var string $logChannel */
        $logChannel = config('sequencer.errors.log_channel', 'stack');
        Log::channel($logChannel)->error('Operation failed', [
            'operation' => $record->name,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Require the operation from a deploy-safe path.
     *
     * Jobs may outlive the release path they were dispatched from. When the original
     * absolute path no longer exists, re-resolve the operation against the current
     * discovery paths and the current app base path before failing.
     *
     * @return Operation Loaded operation instance
     */
    private function loadOperation(): Operation
    {
        $path = $this->resolveOperationPath();

        if ($path === null) {
            throw OperationNotFoundException::forUnresolvedPath($this->operationPath);
        }

        /** @var Operation $operation */
        $operation = require $path;

        return $operation;
    }

    /**
     * Require the operation when its file resolves on this worker.
     *
     * Used by the methods Laravel calls around handle() and failed(), which
     * must not fail on a worker that does not have the operation file.
     *
     * @return null|Operation Loaded operation instance, or null when no file resolves
     */
    private function resolveOperation(): ?Operation
    {
        if ($this->resolveOperationPath() === null) {
            return null;
        }

        return $this->loadOperation();
    }

    /**
     * Resolve the current filesystem path for the operation file.
     *
     * @return null|string Absolute path to the operation file, or null when none exists
     */
    private function resolveOperationPath(): ?string
    {
        if (file_exists($this->operationPath)) {
            return $this->operationPath;
        }

        $basename = basename($this->operationPath);
        $relativePath = $this->extractRelativeOperationPath();

        /** @var array<int, string> $discoveryPaths */
        $discoveryPaths = config('sequencer.execution.discovery_paths', [base_path('database/operations')]);

        foreach ($discoveryPaths as $discoveryPath) {
            $candidatePath = sprintf('%s/%s', mb_rtrim($discoveryPath, '/'), $basename);

            if (file_exists($candidatePath)) {
                return $candidatePath;
            }
        }

        if ($relativePath !== null) {
            $candidatePath = base_path($relativePath);

            if (file_exists($candidatePath)) {
                return $candidatePath;
            }
        }

        return null;
    }

    /**
     * Extract an app-relative operation path from a stale absolute path.
     *
     * @return null|string Relative path such as database/operations/example.php
     */
    private function extractRelativeOperationPath(): ?string
    {
        if (preg_match('#(?:^|/)(database/operations/.+)$#', $this->operationPath, $matches) !== 1) {
            return null;
        }

        return str_starts_with($matches[1], '/')
            ? mb_substr($matches[1], 1)
            : $matches[1];
    }
}
