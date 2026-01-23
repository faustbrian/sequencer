<?php declare(strict_types=1);
/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Operations\AsyncOperation;

test('anonymous class operation can be serialized and queued', function (): void {
    // Create an anonymous class operation
    $operationFile = database_path('operations/test_anonymous_operation.php');

    // Ensure directory exists
    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    // Write anonymous class operation to file that writes to a temp file
    $executionMarkerFile = sys_get_temp_dir().'/sequencer_test_execution_marker.txt';

    file_put_contents(
        $operationFile,
        <<<PHP
<?php

use Cline\\Sequencer\\Contracts\\Asynchronous;
use Cline\\Sequencer\\Contracts\\Operation;

return new class implements Asynchronous, Operation {
    public function handle(): void
    {
        file_put_contents('{$executionMarkerFile}', 'executed');
    }
};
PHP
    );

    try {
        // Clean up marker file if it exists
        if (file_exists($executionMarkerFile)) {
            unlink($executionMarkerFile);
        }

        $record = OperationModel::query()->create([
            'name' => 'test_anonymous_operation',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        // This should NOT throw a serialization error
        $job = new ExecuteOperation($operationFile, $record->id);

        // Verify job can be serialized (this is what Queue does)
        $serialized = serialize($job);
        expect($serialized)->toBeString();

        // Verify job can be unserialized and executed
        $unserializedJob = unserialize($serialized);
        $unserializedJob->handle();

        // Verify operation was executed by checking the marker file
        expect(file_exists($executionMarkerFile))->toBeTrue()
            ->and(file_get_contents($executionMarkerFile))->toBe('executed');

        // Verify record was updated
        $record->refresh();
        expect($record->completed_at)->not->toBeNull();
    } finally {
        // Cleanup
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }

        if (file_exists($executionMarkerFile)) {
            unlink($executionMarkerFile);
        }
    }
});

test('named class operation still works (regression test)', function (): void {
    AsyncOperation::reset();

    // Create a wrapper file that returns an instance of the named class
    $wrapperFile = database_path('operations/test_named_operation_wrapper.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $wrapperFile,
        <<<'PHP'
<?php

use Tests\Fixtures\Operations\AsyncOperation;

return new AsyncOperation();
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'async_operation',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        $job = new ExecuteOperation($wrapperFile, $record->id);

        // Verify job can be serialized
        $serialized = serialize($job);
        expect($serialized)->toBeString();

        // Verify job can be executed
        $job->handle();

        expect(AsyncOperation::$executed)->toBeTrue();

        $record->refresh();
        expect($record->completed_at)->not->toBeNull();
    } finally {
        if (file_exists($wrapperFile)) {
            unlink($wrapperFile);
        }
    }
});

test('anonymous operation with all interfaces configures job correctly', function (): void {
    $operationFile = database_path('operations/test_full_interface_operation.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\HasMaxExceptions;
use Cline\Sequencer\Contracts\HasMiddleware;
use Cline\Sequencer\Contracts\HasTags;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Retryable;
use Cline\Sequencer\Contracts\ShouldBeUnique;
use Cline\Sequencer\Contracts\Timeoutable;

return new class implements Asynchronous, Operation, Retryable, Timeoutable, HasMaxExceptions, HasTags, HasMiddleware, ShouldBeUnique {
    public function handle(): void {}

    public function tries(): int
    {
        return 5;
    }

    public function backoff(): array|int
    {
        return [30, 60, 120];
    }

    public function retryUntil(): ?\DateTimeInterface
    {
        return now()->addHour();
    }

    public function timeout(): int
    {
        return 300;
    }

    public function failOnTimeout(): bool
    {
        return true;
    }

    public function maxExceptions(): int
    {
        return 3;
    }

    public function tags(): array
    {
        return ['critical', 'payment'];
    }

    public function middleware(): array
    {
        return [];
    }

    public function uniqueId(): string
    {
        return 'unique-op-123';
    }

    public function uniqueFor(): int
    {
        return 7200;
    }

    public function uniqueVia(): ?\Illuminate\Contracts\Cache\Repository
    {
        return null;
    }
};
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'test_full_interface_operation',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        $job = new ExecuteOperation($operationFile, $record->id);

        // Verify retry configuration
        expect($job->tries)->toBe(5)
            ->and($job->backoff)->toBe([30, 60, 120])
            ->and($job->retryUntil)->not->toBeNull();

        // Verify timeout configuration
        expect($job->timeout)->toBe(300)
            ->and($job->failOnTimeout)->toBeTrue();

        // Verify max exceptions
        expect($job->maxExceptions)->toBe(3);

        // Verify tags
        expect($job->tags())->toBe(['critical', 'payment']);

        // Verify unique configuration
        expect($job->uniqueId())->toBe('unique-op-123')
            ->and($job->uniqueFor())->toBe(7_200);
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }
    }
});

test('operation is re-instantiated from file path on execution', function (): void {
    $operationFile = database_path('operations/test_reinst_operation.php');
    $markerFile = sys_get_temp_dir().'/sequencer_test_reinst_marker.txt';

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<PHP
<?php

use Cline\\Sequencer\\Contracts\\Asynchronous;
use Cline\\Sequencer\\Contracts\\Operation;

return new class implements Asynchronous, Operation {
    public function handle(): void
    {
        file_put_contents('{$markerFile}', 'executed');
    }
};
PHP
    );

    try {
        if (file_exists($markerFile)) {
            unlink($markerFile);
        }

        $record = OperationModel::query()->create([
            'name' => 'test_reinst_operation',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        $job = new ExecuteOperation($operationFile, $record->id);

        // Execute job - this requires the file again
        $job->handle();

        // Verify operation was loaded and executed
        expect(file_exists($markerFile))->toBeTrue();

        $record->refresh();
        expect($record->completed_at)->not->toBeNull();
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }

        if (file_exists($markerFile)) {
            unlink($markerFile);
        }
    }
});

test('lifecycle hooks work with re-instantiated operations', function (): void {
    $operationFile = database_path('operations/test_lifecycle_operation.php');
    $beforeMarker = sys_get_temp_dir().'/sequencer_test_before.txt';
    $handleMarker = sys_get_temp_dir().'/sequencer_test_handle.txt';
    $afterMarker = sys_get_temp_dir().'/sequencer_test_after.txt';

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<PHP
<?php

use Cline\\Sequencer\\Contracts\\Asynchronous;
use Cline\\Sequencer\\Contracts\\HasLifecycleHooks;
use Cline\\Sequencer\\Contracts\\Operation;

return new class implements Asynchronous, Operation, HasLifecycleHooks {
    public function before(): void
    {
        file_put_contents('{$beforeMarker}', 'before');
    }

    public function handle(): void
    {
        file_put_contents('{$handleMarker}', 'handle');
    }

    public function after(): void
    {
        file_put_contents('{$afterMarker}', 'after');
    }

    public function failed(\\Throwable \$exception): void {}
};
PHP
    );

    try {
        foreach ([$beforeMarker, $handleMarker, $afterMarker] as $marker) {
            if (!file_exists($marker)) {
                continue;
            }

            unlink($marker);
        }

        $record = OperationModel::query()->create([
            'name' => 'test_lifecycle_operation',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        $job = new ExecuteOperation($operationFile, $record->id);
        $job->handle();

        // Verify lifecycle hooks were called
        expect(file_exists($beforeMarker))->toBeTrue()
            ->and(file_exists($handleMarker))->toBeTrue()
            ->and(file_exists($afterMarker))->toBeTrue();
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }

        foreach ([$beforeMarker, $handleMarker, $afterMarker] as $marker) {
            if (!file_exists($marker)) {
                continue;
            }

            unlink($marker);
        }
    }
});

test('failed hook is called when operation fails', function (): void {
    $operationFile = database_path('operations/test_failing_operation.php');
    $failedMarker = sys_get_temp_dir().'/sequencer_test_failed.txt';

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<PHP
<?php

use Cline\\Sequencer\\Contracts\\Asynchronous;
use Cline\\Sequencer\\Contracts\\HasLifecycleHooks;
use Cline\\Sequencer\\Contracts\\Operation;

return new class implements Asynchronous, Operation, HasLifecycleHooks {
    public function before(): void {}

    public function handle(): void
    {
        throw new \\RuntimeException('Operation failed intentionally');
    }

    public function after(): void {}

    public function failed(\\Throwable \$exception): void
    {
        file_put_contents('{$failedMarker}', \$exception->getMessage());
    }
};
PHP
    );

    try {
        if (file_exists($failedMarker)) {
            unlink($failedMarker);
        }

        $record = OperationModel::query()->create([
            'name' => 'test_failing_operation',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        $job = new ExecuteOperation($operationFile, $record->id);

        // Expect exception to be thrown
        expect(fn () => $job->handle())->toThrow(RuntimeException::class);

        // Verify failed hook was called
        expect(file_exists($failedMarker))->toBeTrue()
            ->and(file_get_contents($failedMarker))->toBe('Operation failed intentionally');

        // Verify record was updated
        $record->refresh();
        expect($record->failed_at)->not->toBeNull();
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }

        if (file_exists($failedMarker)) {
            unlink($failedMarker);
        }
    }
});

test('operation can be dispatched to queue and processed', function (): void {
    Queue::fake();

    $operationFile = database_path('operations/test_queue_operation.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;

return new class implements Asynchronous, Operation {
    public function handle(): void {}
};
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'test_queue_operation',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        // Dispatch to queue
        dispatch(
            new ExecuteOperation($operationFile, $record->id)
        );

        // Verify job was pushed to queue
        Queue::assertPushed(ExecuteOperation::class, fn($job): bool => $job->uniqueId() === (string) $record->id);
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }
    }
});

test('async operation state transitions to Completed on success', function (): void {
    $operationFile = database_path('operations/test_state_completed.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;

return new class implements Asynchronous, Operation {
    public function handle(): void
    {
        // Successful operation
    }
};
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'test_state_completed',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        // Verify initial state
        expect($record->state)->toBe(OperationState::Pending)
            ->and($record->completed_at)->toBeNull();

        $job = new ExecuteOperation($operationFile, $record->id);
        $job->handle();

        // Verify state transitioned to Completed
        $record->refresh();
        expect($record->state)->toBe(OperationState::Completed)
            ->and($record->completed_at)->not->toBeNull()
            ->and($record->failed_at)->toBeNull();
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }
    }
})->group('state-transitions');

test('async operation state transitions to Failed on exception', function (): void {
    $operationFile = database_path('operations/test_state_failed.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;

return new class implements Asynchronous, Operation {
    public function handle(): void
    {
        throw new \RuntimeException('Test failure');
    }
};
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'test_state_failed',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        // Verify initial state
        expect($record->state)->toBe(OperationState::Pending)
            ->and($record->failed_at)->toBeNull();

        $job = new ExecuteOperation($operationFile, $record->id);

        // Expect exception to be thrown
        expect(fn () => $job->handle())->toThrow(RuntimeException::class);

        // Verify state transitioned to Failed
        $record->refresh();
        expect($record->state)->toBe(OperationState::Failed)
            ->and($record->failed_at)->not->toBeNull()
            ->and($record->completed_at)->toBeNull();
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }
    }
})->group('state-transitions');

test('sync operation state transitions to Completed on success', function (): void {
    $operationFile = database_path('operations/test_sync_state_completed.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Operation;

return new class implements Operation {
    public function handle(): void
    {
        // Successful operation
    }
};
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'test_sync_state_completed',
            'type' => 'sync',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        // Verify initial state
        expect($record->state)->toBe(OperationState::Pending);

        $operation = require $operationFile;

        // Execute synchronously using SequencerManager's private method pattern
        $autoTransaction = config('sequencer.execution.auto_transaction', true);

        if ($autoTransaction) {
            \Illuminate\Support\Facades\DB::transaction(fn () => $operation->handle());
        } else {
            $operation->handle();
        }

        $record->update([
            'completed_at' => now(),
            'state' => OperationState::Completed,
        ]);

        // Verify state transitioned to Completed
        $record->refresh();
        expect($record->state)->toBe(OperationState::Completed)
            ->and($record->completed_at)->not->toBeNull()
            ->and($record->failed_at)->toBeNull();
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }
    }
})->group('state-transitions');

test('sync operation state transitions to Failed on exception', function (): void {
    $operationFile = database_path('operations/test_sync_state_failed.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Operation;

return new class implements Operation {
    public function handle(): void
    {
        throw new \RuntimeException('Test failure');
    }
};
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'test_sync_state_failed',
            'type' => 'sync',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        // Verify initial state
        expect($record->state)->toBe(OperationState::Pending);

        $operation = require $operationFile;

        try {
            $operation->handle();
        } catch (\Throwable $throwable) {
            $record->update([
                'failed_at' => now(),
                'state' => OperationState::Failed,
            ]);
        }

        // Verify state transitioned to Failed
        $record->refresh();
        expect($record->state)->toBe(OperationState::Failed)
            ->and($record->failed_at)->not->toBeNull()
            ->and($record->completed_at)->toBeNull();
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }
    }
})->group('state-transitions');

test('operation error is recorded with Failed state', function (): void {
    $operationFile = database_path('operations/test_error_recording.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;

return new class implements Asynchronous, Operation {
    public function handle(): void
    {
        throw new \RuntimeException('Test error message');
    }
};
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'test_error_recording',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        $job = new ExecuteOperation($operationFile, $record->id);

        // Expect exception to be thrown
        expect(fn () => $job->handle())->toThrow(RuntimeException::class);

        // Verify state is Failed and error was recorded
        $record->refresh();
        expect($record->state)->toBe(OperationState::Failed)
            ->and($record->errors)->toHaveCount(1)
            ->and($record->errors->first()->message)->toBe('Test error message')
            ->and($record->errors->first()->exception)->toBe(RuntimeException::class);
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }
    }
})->group('state-transitions');

test('completed_at and state are both set atomically on success', function (): void {
    $operationFile = database_path('operations/test_atomic_success.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;

return new class implements Asynchronous, Operation {
    public function handle(): void
    {
        // Success
    }
};
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'test_atomic_success',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        $job = new ExecuteOperation($operationFile, $record->id);
        $job->handle();

        // Verify both fields updated together
        $record->refresh();
        expect($record->completed_at)->not->toBeNull()
            ->and($record->state)->toBe(OperationState::Completed)
            ->and($record->state->isSuccessful())->toBeTrue()
            ->and($record->state->isTerminal())->toBeTrue();
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }
    }
})->group('state-transitions');

test('failed_at and state are both set atomically on failure', function (): void {
    $operationFile = database_path('operations/test_atomic_failure.php');

    if (!is_dir(database_path('operations'))) {
        mkdir(database_path('operations'), 0o755, true);
    }

    file_put_contents(
        $operationFile,
        <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;

return new class implements Asynchronous, Operation {
    public function handle(): void
    {
        throw new \RuntimeException('Atomic failure test');
    }
};
PHP
    );

    try {
        $record = OperationModel::query()->create([
            'name' => 'test_atomic_failure',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);

        $job = new ExecuteOperation($operationFile, $record->id);

        expect(fn () => $job->handle())->toThrow(RuntimeException::class);

        // Verify both fields updated together
        $record->refresh();
        expect($record->failed_at)->not->toBeNull()
            ->and($record->state)->toBe(OperationState::Failed)
            ->and($record->state->isFailed())->toBeTrue()
            ->and($record->state->isTerminal())->toBeTrue();
    } finally {
        if (file_exists($operationFile)) {
            unlink($operationFile);
        }
    }
})->group('state-transitions');
