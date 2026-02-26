<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Database\Models;

use Cline\Sequencer\Enums\DeferredOperationStatus;
use Cline\VariableKeys\Database\Concerns\HasVariablePrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class DeferredOperation extends Model
{
    use HasFactory;
    use HasVariablePrimaryKey;

    /** @var array<int, string> */
    protected $fillable = [
        'operation',
        'payload',
        'due_at',
        'status',
        'attempts',
        'max_attempts',
        'reserved_at',
        'processed_at',
        'failed_at',
        'last_error',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'due_at' => 'datetime',
        'reserved_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
        'status' => DeferredOperationStatus::class,
    ];

    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('sequencer.table_names.deferred_operations', 'deferred_operations');
    }
}
