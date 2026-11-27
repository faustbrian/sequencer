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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Real transactions are needed here: RefreshDatabase wraps each test in one,
// which would hide what a worker that dies inside handle()'s transaction loses.
uses(DatabaseMigrations::class);

/**
 * On a timeout Laravel calls failed() from its alarm handler while handle()'s
 * transaction is still open, then kills the worker, which rolls the open
 * transaction back. The record must still end Failed.
 */
test('marks the record Failed when the worker times out inside the operation transaction', function (): void {
    $directory = storage_path('framework/testing/timeout_recovery_'.uniqid());
    File::makeDirectory($directory, 0o755, true);
    $path = $directory.'/2026_01_01_000000_slow.php';
    File::put($path, <<<'PHP'
<?php

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;

return new class() implements Asynchronous, Operation {
    public function handle(): void {}
};
PHP);

    try {
        $record = OperationModel::query()->create([
            'name' => '2026_01_01_000000_slow',
            'type' => 'async',
            'executed_at' => now(),
            'state' => OperationState::Pending,
        ]);
        $job = new ExecuteOperation($path, $record->id);

        // handle() is inside its transaction and has written part of its work.
        DB::beginTransaction();
        OperationModel::query()->create([
            'name' => 'work_inside_the_operation',
            'type' => 'sync',
            'executed_at' => now(),
            'state' => OperationState::Completed,
        ]);

        $job->failed(
            new TimeoutExceededException('timed out'),
        );

        // The worker is killed; the database discards whatever is still open.
        if (DB::transactionLevel() > 0) {
            DB::rollBack(0);
        }

        $record->refresh();
        expect($record->state)->toBe(OperationState::Failed)
            ->and($record->failed_at)->not->toBeNull()
            ->and($record->errors()->value('exception'))->toBe(TimeoutExceededException::class)
            ->and(OperationModel::query()->where('name', 'work_inside_the_operation')->exists())->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
})->group('regression', 'queue');
