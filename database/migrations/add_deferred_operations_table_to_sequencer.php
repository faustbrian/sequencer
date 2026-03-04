<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\VariableKeys\Enums\PrimaryKeyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $primaryKeyType = PrimaryKeyType::tryFrom(config('sequencer.primary_key_type', 'id')) ?? PrimaryKeyType::ID;

        $connection = config('database.default');
        $useJsonb = DB::connection($connection)->getDriverName() === 'pgsql';

        Schema::create(config('sequencer.table_names.deferred_operations', 'deferred_operations'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            $table->string('operation')->comment('Deferred operation alias or class reference');
            $useJsonb
                ? $table->jsonb('payload')->comment('Serialized operation payload')
                : $table->json('payload')->comment('Serialized operation payload');
            $table->timestamp('due_at')->comment('When operation becomes eligible for execution');
            $table->string('status')->default('pending')->comment('pending|processing|completed|failed|cancelled');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('reserved_at')->nullable()->comment('When operation was claimed by processor');
            $table->timestamp('processed_at')->nullable()->comment('When operation completed successfully');
            $table->timestamp('failed_at')->nullable()->comment('When operation exhausted retries');
            $table->text('last_error')->nullable()->comment('Last error message from processing');
            $table->timestamps();

            $table->index(['status', 'due_at'], 'deferred_operations_status_due_idx');
            $table->index('operation', 'deferred_operations_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('sequencer.table_names.deferred_operations', 'deferred_operations'));
    }
};
