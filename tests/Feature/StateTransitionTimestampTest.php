<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\SequencerManager;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/sequencer_state_transitions_'.uniqid();
    File::makeDirectory($this->tempDir, 0o755, true);
    config(['sequencer.execution.discovery_paths' => [$this->tempDir]]);
});

afterEach(function (): void {
    File::deleteDirectory($this->tempDir);
});

describe('State Transition Timestamp Management', function (): void {
    test('failed operation retried successfully clears failed_at and sets completed_at', function (): void {
        // Arrange: Create an operation that fails on first run, succeeds on retry
        $counterFile = $this->tempDir.'/retry_counter.txt';
        File::put($counterFile, '0');

        $op = <<<PHP
<?php
use Cline\\Sequencer\\Contracts\\Operation;

return new class() implements Operation {
    public function handle(): void {
        \$counterFile = '{$counterFile}';
        \$count = (int) file_get_contents(\$counterFile);
        \$count++;
        file_put_contents(\$counterFile, (string) \$count);

        if (\$count === 1) {
            throw new \\RuntimeException('First run fails');
        }
        // Second run succeeds
    }
};
PHP;

        File::put($this->tempDir.'/2024_01_01_000000_retry_test.php', $op);

        $sequencer = resolve(SequencerManager::class);

        // Act: First execution - should fail
        try {
            $sequencer->execute('2024_01_01_000000_retry_test.php');
            $this->fail('Expected operation to fail on first run');
        } catch (RuntimeException) {
            // Expected
        }

        // Assert: After first run - should have failed_at set, completed_at null
        $record = OperationModel::query()->where('name', '2024_01_01_000000_retry_test')->latest()->first();
        expect($record)->not->toBeNull()
            ->and($record->state)->toBe(OperationState::Failed)
            ->and($record->failed_at)->not->toBeNull()
            ->and($record->completed_at)->toBeNull()
            ->and($record->skipped_at)->toBeNull();

        // Act: Retry execution - should succeed (wait 1 second to avoid unique constraint)
        $this->travel(1)->second();
        $sequencer->execute('2024_01_01_000000_retry_test.php');

        // Assert: After retry - should have completed_at set, failed_at cleared
        $retryRecord = OperationModel::query()
            ->where('name', '2024_01_01_000000_retry_test')
            ->latest()
            ->first();

        expect($retryRecord)->not->toBeNull()
            ->and($retryRecord->state)->toBe(OperationState::Completed)
            ->and($retryRecord->completed_at)->not->toBeNull()
            ->and($retryRecord->failed_at)->toBeNull('failed_at should be cleared when transitioning to Completed')
            ->and($retryRecord->skipped_at)->toBeNull();
    })->group('integration', 'state-transitions', 'timestamps');

    test('completed operation re-run that fails clears completed_at and sets failed_at', function (): void {
        // Arrange: Create an operation that succeeds on first run, fails on re-run
        $counterFile = $this->tempDir.'/rerun_counter.txt';
        File::put($counterFile, '0');

        $op = <<<PHP
<?php
use Cline\\Sequencer\\Contracts\\Operation;

return new class() implements Operation {
    public function handle(): void {
        \$counterFile = '{$counterFile}';
        \$count = (int) file_get_contents(\$counterFile);
        \$count++;
        file_put_contents(\$counterFile, (string) \$count);

        if (\$count === 2) {
            throw new \\RuntimeException('Second run fails');
        }
        // First run succeeds
    }
};
PHP;

        File::put($this->tempDir.'/2024_01_01_000000_rerun_test.php', $op);

        $sequencer = resolve(SequencerManager::class);

        // Act: First execution - should succeed
        $sequencer->execute('2024_01_01_000000_rerun_test.php');

        // Assert: After first run - should have completed_at set
        $record = OperationModel::query()->where('name', '2024_01_01_000000_rerun_test')->latest()->first();
        expect($record)->not->toBeNull()
            ->and($record->state)->toBe(OperationState::Completed)
            ->and($record->completed_at)->not->toBeNull()
            ->and($record->failed_at)->toBeNull()
            ->and($record->skipped_at)->toBeNull();

        // Act: Re-run execution - should fail (wait 1 second to avoid unique constraint)
        $this->travel(1)->second();

        try {
            $sequencer->execute('2024_01_01_000000_rerun_test.php');
            $this->fail('Expected operation to fail on re-run');
        } catch (RuntimeException) {
            // Expected
        }

        // Assert: After re-run failure - should have failed_at set, completed_at cleared
        $rerunRecord = OperationModel::query()
            ->where('name', '2024_01_01_000000_rerun_test')
            ->latest()
            ->first();

        expect($rerunRecord)->not->toBeNull()
            ->and($rerunRecord->state)->toBe(OperationState::Failed)
            ->and($rerunRecord->failed_at)->not->toBeNull()
            ->and($rerunRecord->completed_at)->toBeNull('completed_at should be cleared when transitioning to Failed')
            ->and($rerunRecord->skipped_at)->toBeNull();
    })->group('integration', 'state-transitions', 'timestamps');

    test('multiple retry cycles maintain clean state timestamps', function (): void {
        // Arrange: Create an operation that alternates between success and failure
        $counterFile = $this->tempDir.'/cycle_counter.txt';
        File::put($counterFile, '0');

        $op = <<<PHP
<?php
use Cline\\Sequencer\\Contracts\\Operation;

return new class() implements Operation {
    public function handle(): void {
        \$counterFile = '{$counterFile}';
        \$count = (int) file_get_contents(\$counterFile);
        \$count++;
        file_put_contents(\$counterFile, (string) \$count);

        // Fail on odd runs, succeed on even runs
        if (\$count % 2 === 1) {
            throw new \\RuntimeException('Odd run fails');
        }
    }
};
PHP;

        File::put($this->tempDir.'/2024_01_01_000000_cycle_test.php', $op);

        $sequencer = resolve(SequencerManager::class);

        // Cycle 1: Fail
        try {
            $sequencer->execute('2024_01_01_000000_cycle_test.php');
            $this->fail('Expected first run to fail');
        } catch (RuntimeException) {
            // Expected
        }

        $record1 = OperationModel::query()->where('name', '2024_01_01_000000_cycle_test')->latest()->first();
        expect($record1->state)->toBe(OperationState::Failed)
            ->and($record1->failed_at)->not->toBeNull()
            ->and($record1->completed_at)->toBeNull();

        // Cycle 2: Succeed
        $this->travel(1)->second();
        $sequencer->execute('2024_01_01_000000_cycle_test.php');

        $record2 = OperationModel::query()->where('name', '2024_01_01_000000_cycle_test')->latest()->first();
        expect($record2->state)->toBe(OperationState::Completed)
            ->and($record2->completed_at)->not->toBeNull()
            ->and($record2->failed_at)->toBeNull('failed_at should be cleared after success');

        // Cycle 3: Fail again
        $this->travel(1)->second();

        try {
            $sequencer->execute('2024_01_01_000000_cycle_test.php');
            $this->fail('Expected third run to fail');
        } catch (RuntimeException) {
            // Expected
        }

        $record3 = OperationModel::query()->where('name', '2024_01_01_000000_cycle_test')->latest()->first();
        expect($record3->state)->toBe(OperationState::Failed)
            ->and($record3->failed_at)->not->toBeNull()
            ->and($record3->completed_at)->toBeNull('completed_at should be cleared after failure');

        // Cycle 4: Succeed again
        $this->travel(1)->second();
        $sequencer->execute('2024_01_01_000000_cycle_test.php');

        $record4 = OperationModel::query()->where('name', '2024_01_01_000000_cycle_test')->latest()->first();
        expect($record4->state)->toBe(OperationState::Completed)
            ->and($record4->completed_at)->not->toBeNull()
            ->and($record4->failed_at)->toBeNull('failed_at should be cleared after success')
            ->and($record4->skipped_at)->toBeNull();
    })->group('integration', 'state-transitions', 'timestamps', 'retry-cycles');

    test('skipped operation re-run that succeeds clears skipped_at and sets completed_at', function (): void {
        // Arrange: Create a skipped operation
        $counterFile = $this->tempDir.'/skip_counter.txt';
        File::put($counterFile, '0');

        $op = <<<PHP
<?php
use Cline\\Sequencer\\Contracts\\Operation;
use Cline\\Sequencer\\Exceptions\\OperationSkippedException;

return new class() implements Operation {
    public function handle(): void {
        \$counterFile = '{$counterFile}';
        \$count = (int) file_get_contents(\$counterFile);
        \$count++;
        file_put_contents(\$counterFile, (string) \$count);

        if (\$count === 1) {
            throw OperationSkippedException::withReason('Skipping first run');
        }
        // Second run succeeds
    }
};
PHP;

        File::put($this->tempDir.'/2024_01_01_000000_skip_test.php', $op);

        $sequencer = resolve(SequencerManager::class);

        // Act: First execution - should skip
        $sequencer->execute('2024_01_01_000000_skip_test.php');

        // Assert: After skip
        $record = OperationModel::query()->where('name', '2024_01_01_000000_skip_test')->latest()->first();
        expect($record)->not->toBeNull()
            ->and($record->state)->toBe(OperationState::Skipped)
            ->and($record->skipped_at)->not->toBeNull()
            ->and($record->completed_at)->toBeNull()
            ->and($record->failed_at)->toBeNull();

        // Act: Re-run - should succeed (wait 1 second to avoid unique constraint)
        $this->travel(1)->second();
        $sequencer->execute('2024_01_01_000000_skip_test.php');

        // Assert: After success - skipped_at should be cleared
        $retryRecord = OperationModel::query()
            ->where('name', '2024_01_01_000000_skip_test')
            ->latest()
            ->first();

        expect($retryRecord)->not->toBeNull()
            ->and($retryRecord->state)->toBe(OperationState::Completed)
            ->and($retryRecord->completed_at)->not->toBeNull()
            ->and($retryRecord->skipped_at)->toBeNull('skipped_at should be cleared when transitioning to Completed')
            ->and($retryRecord->failed_at)->toBeNull();
    })->group('integration', 'state-transitions', 'timestamps', 'skip');

    test('async operation retry clears conflicting timestamps', function (): void {
        // Arrange: Create an async operation that fails on first run, succeeds on retry
        $counterFile = $this->tempDir.'/async_counter.txt';
        File::put($counterFile, '0');

        $op = <<<PHP
<?php
use Cline\\Sequencer\\Contracts\\Asynchronous;
use Cline\\Sequencer\\Contracts\\Operation;

return new class() implements Operation, Asynchronous {
    public function handle(): void {
        \$counterFile = '{$counterFile}';
        \$count = (int) file_get_contents(\$counterFile);
        \$count++;
        file_put_contents(\$counterFile, (string) \$count);

        if (\$count === 1) {
            throw new \\RuntimeException('First async run fails');
        }
        // Second run succeeds
    }
};
PHP;

        File::put($this->tempDir.'/2024_01_01_000000_async_retry.php', $op);

        $sequencer = resolve(SequencerManager::class);

        // Act: First execution - should fail (sync for test simplicity)
        try {
            $sequencer->executeSync('2024_01_01_000000_async_retry.php');
            $this->fail('Expected operation to fail on first run');
        } catch (RuntimeException) {
            // Expected
        }

        // Assert: After first run - should have failed_at set
        $record = OperationModel::query()->where('name', '2024_01_01_000000_async_retry')->latest()->first();
        expect($record)->not->toBeNull()
            ->and($record->state)->toBe(OperationState::Failed)
            ->and($record->failed_at)->not->toBeNull();

        // Act: Retry execution - should succeed (wait 1 second to avoid unique constraint)
        $this->travel(1)->second();
        $sequencer->executeSync('2024_01_01_000000_async_retry.php');

        // Assert: After retry - failed_at should be cleared
        $retryRecord = OperationModel::query()
            ->where('name', '2024_01_01_000000_async_retry')
            ->latest()
            ->first();

        expect($retryRecord)->not->toBeNull()
            ->and($retryRecord->state)->toBe(OperationState::Completed)
            ->and($retryRecord->completed_at)->not->toBeNull()
            ->and($retryRecord->failed_at)->toBeNull('failed_at should be cleared in async operation retry')
            ->and($retryRecord->skipped_at)->toBeNull();
    })->group('integration', 'state-transitions', 'timestamps', 'async');
});
