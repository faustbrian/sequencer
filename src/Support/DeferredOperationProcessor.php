<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Support;

use Cline\Sequencer\Contracts\DeferredOperation as DeferredOperationContract;
use Cline\Sequencer\Database\Models\DeferredOperation;
use Cline\Sequencer\Enums\DeferredOperationStatus;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

use function config;
use function is_array;

/**
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class DeferredOperationProcessor
{
    public function __construct(
        private DeferredOperationRegistry $registry,
    ) {}

    /**
     * Process due deferred operations.
     *
     * @return array{processed: int, completed: int, failed: int, retried: int}
     */
    public function processDue(int $limit = 100): array
    {
        $stats = [
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'retried' => 0,
        ];

        /** @var list<DeferredOperation> $operations */
        $operations = DB::transaction(function () use ($limit): array {
            /** @var list<DeferredOperation> $rows */
            $rows = DeferredOperation::query()
                ->where('status', DeferredOperationStatus::Pending->value)
                ->where('due_at', '<=', Date::now())
                ->oldest('due_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($rows as $row) {
                $row->update([
                    'status' => DeferredOperationStatus::Processing,
                    'reserved_at' => Date::now(),
                    'attempts' => $row->attempts + 1,
                ]);
            }

            return $rows;
        });

        foreach ($operations as $operationRecord) {
            ++$stats['processed'];

            try {
                $operationClass = $this->registry->resolveClass($operationRecord->operation);

                /** @var DeferredOperationContract $operation */
                $operation = App::make($operationClass);

                /** @var array<string, mixed> $payload */
                $payload = is_array($operationRecord->payload) ? $operationRecord->payload : [];
                $operation->handle($payload);

                $operationRecord->update([
                    'status' => DeferredOperationStatus::Completed,
                    'processed_at' => Date::now(),
                    'reserved_at' => null,
                    'last_error' => null,
                ]);

                ++$stats['completed'];
            } catch (Throwable $throwable) {
                $this->handleFailure($operationRecord, $throwable, $stats);
            }
        }

        return $stats;
    }

    /**
     * @param array{processed: int, completed: int, failed: int, retried: int} $stats
     */
    private function handleFailure(DeferredOperation $record, Throwable $throwable, array &$stats): void
    {
        if ($record->attempts >= $record->max_attempts) {
            $record->update([
                'status' => DeferredOperationStatus::Failed,
                'failed_at' => Date::now(),
                'reserved_at' => null,
                'last_error' => $throwable->getMessage(),
            ]);

            ++$stats['failed'];

            return;
        }

        /** @var int $retryDelaySeconds */
        $retryDelaySeconds = config('sequencer.deferred.retry_delay_seconds', 60);

        $record->update([
            'status' => DeferredOperationStatus::Pending,
            'reserved_at' => null,
            'last_error' => $throwable->getMessage(),
            'due_at' => Date::now()->addSeconds($retryDelaySeconds),
        ]);

        ++$stats['retried'];
    }
}
