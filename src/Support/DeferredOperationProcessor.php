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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

use function array_fill;
use function config;
use function count;
use function implode;
use function in_array;
use function is_array;
use function mb_trim;
use function throw_if;

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
     * @param  null|list<string>                                                $operations Stored identifiers to select; null selects all, [] selects none.
     * @return array{processed: int, completed: int, failed: int, retried: int}
     */
    public function processDue(int $limit = 100, ?array $operations = null): array
    {
        $stats = [
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'retried' => 0,
        ];

        foreach ($operations ?? [] as $identifier) {
            throw_if(mb_trim($identifier) === '', InvalidArgumentException::class, 'Deferred operation selectors must not be blank.');
        }

        /** @var int|string $configuredRetryAfter */
        $configuredRetryAfter = config('sequencer.deferred.retry_after_seconds', 300);
        $retryAfter = (int) $configuredRetryAfter;
        throw_if($retryAfter < 1, InvalidArgumentException::class, 'Deferred reservation expiry must be positive.');

        // Claim immediately before execution, not an entire waiting batch:
        // otherwise later siblings can expire before their handler even starts.
        $handled = [];

        for ($index = 0; $index < $limit; ++$index) {
            $operationRecord = $this->claimNext($operations, $retryAfter, $handled);

            if (!$operationRecord instanceof DeferredOperation) {
                break;
            }

            ++$stats['processed'];

            /** @var int|string $key */
            $key = $operationRecord->getKey();
            $handled[] = $key;

            if ($operationRecord->status === DeferredOperationStatus::Failed) {
                ++$stats['failed'];

                continue;
            }

            try {
                $operationClass = $this->registry->resolveClass($operationRecord->operation);

                /** @var DeferredOperationContract $operation */
                $operation = App::make($operationClass);

                /** @var array<string, mixed> $payload */
                $payload = is_array($operationRecord->payload) ? $operationRecord->payload : [];
                $operation->handle($payload);

                $updated = $this->updateOwnedClaim($operationRecord, [
                    'status' => DeferredOperationStatus::Completed,
                    'processed_at' => Date::now(),
                    'reserved_at' => null,
                    'last_error' => null,
                    ...$this->terminalPayload($operationRecord),
                ]);

                $stats['completed'] += $updated;
            } catch (Throwable $throwable) {
                $this->handleFailure($operationRecord, $throwable, $stats);
            }
        }

        return $stats;
    }

    /**
     * @param null|list<string> $operations
     * @param list<int|string>  $handled
     */
    private function claimNext(?array $operations, int $retryAfter, array $handled): ?DeferredOperation
    {
        return DB::connection()->transaction(function () use ($operations, $retryAfter, $handled): ?DeferredOperation {
            $query = DeferredOperation::query();
            $query->whereNotIn('id', $handled);

            if ($operations !== null) {
                $this->restrictOperations($query, $operations);
            }

            /** @var null|DeferredOperation $record */
            $record = $query
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $pending) => $pending
                        ->where('status', DeferredOperationStatus::Pending->value)
                        ->where('due_at', '<=', Date::now()))
                    ->orWhere(fn (Builder $processing) => $processing
                        ->where('status', DeferredOperationStatus::Processing->value)
                        ->where(fn (Builder $expired) => $expired
                            ->whereNull('reserved_at')
                            ->orWhere('reserved_at', '<=', Date::now()->subSeconds($retryAfter)))))
                ->oldest('due_at')
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return null;
            }

            // Reservations count as attempts, including a worker lost before
            // its handler starts; a crash cannot reset the retry budget.
            if ($record->attempts >= $record->max_attempts) {
                $record->update([
                    'status' => DeferredOperationStatus::Failed,
                    'failed_at' => Date::now(),
                    'reserved_at' => null,
                    'last_error' => 'Deferred reservation expired after the final attempt.',
                    ...$this->terminalPayload($record),
                ]);

                return $record;
            }

            $record->update([
                'status' => DeferredOperationStatus::Processing,
                'reserved_at' => Date::now(),
                'attempts' => $record->attempts + 1,
            ]);

            return $record;
        });
    }

    /**
     * @return Builder<DeferredOperation>
     */
    private function ownedClaim(DeferredOperation $record): Builder
    {
        // Attempts increase under the row lock, so a prior lease holder cannot
        // overwrite a replacement's completion or retry state after expiry.
        return DeferredOperation::query()->whereKey($record->getKey())
            ->where('status', DeferredOperationStatus::Processing->value)
            ->where('attempts', $record->attempts);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function updateOwnedClaim(DeferredOperation $record, array $attributes): int
    {
        return DB::connection()->transaction(function () use ($record, $attributes): int {
            /** @var null|DeferredOperation $current */
            $current = $this->ownedClaim($record)->lockForUpdate()->first();

            // Preserve model serialization and lifecycle events. The locked
            // ownership predicate fences an expired handler before Model::update.
            return $current !== null && $current->update($attributes) ? 1 : 0;
        });
    }

    /**
     * @param Builder<DeferredOperation> $query
     * @param list<string>               $operations
     */
    private function restrictOperations(Builder $query, array $operations): void
    {
        // Keep the ordinary indexed prefilter, then enforce the registry's exact
        // identity before claiming/limiting. Default DB collations can fold case,
        // accents or trailing spaces; a binary collation alone can still pad.
        $query->whereIn('operation', $operations);

        if ($operations === []) {
            return;
        }

        $column = $query->getQuery()->getGrammar()->wrap('operation');
        [$expression, $placeholder] = match ($query->getModel()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => ["CAST({$column} AS BINARY)", 'CAST(? AS BINARY)'],
            'pgsql' => ["{$column} COLLATE \"C\"", '?'],
            'sqlite' => ["{$column} COLLATE BINARY", '?'],
            'sqlsrv' => ["CONVERT(VARBINARY(MAX), CAST({$column} AS NVARCHAR(MAX)))", 'CONVERT(VARBINARY(MAX), CAST(? AS NVARCHAR(MAX)))'],
            default => throw new InvalidArgumentException('Exact deferred selection is unsupported for this database driver.'),
        };
        $query->whereRaw($expression.' IN ('.implode(', ', array_fill(0, count($operations), $placeholder)).')', $operations);
    }

    /**
     * @return array{payload: array{}}|array{}
     */
    private function terminalPayload(DeferredOperation $record): array
    {
        /** @var list<string> $identifiers */
        $identifiers = config('sequencer.deferred.erase_payload_for', []);

        return in_array($record->operation, $identifiers, true)
            ? ['payload' => []] : [];
    }

    /**
     * @param array{processed: int, completed: int, failed: int, retried: int} $stats
     */
    private function handleFailure(DeferredOperation $record, Throwable $throwable, array &$stats): void
    {
        if ($record->attempts >= $record->max_attempts) {
            $updated = $this->updateOwnedClaim($record, [
                'status' => DeferredOperationStatus::Failed,
                'failed_at' => Date::now(),
                'reserved_at' => null,
                'last_error' => $throwable->getMessage(),
                ...$this->terminalPayload($record),
            ]);

            $stats['failed'] += $updated;

            return;
        }

        /** @var int $retryDelaySeconds */
        $retryDelaySeconds = config('sequencer.deferred.retry_delay_seconds', 60);

        $updated = $this->updateOwnedClaim($record, [
            'status' => DeferredOperationStatus::Pending,
            'reserved_at' => null,
            'last_error' => $throwable->getMessage(),
            'due_at' => Date::now()->addSeconds($retryDelaySeconds),
        ]);

        $stats['retried'] += $updated;
    }
}
