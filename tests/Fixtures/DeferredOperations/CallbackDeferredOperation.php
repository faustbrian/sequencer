<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\DeferredOperations;

use Cline\Sequencer\Contracts\DeferredOperation;
use Closure;

/**
 * @psalm-immutable
 */
final readonly class CallbackDeferredOperation implements DeferredOperation
{
    /**
     * @param Closure(array<string, mixed>): void $callback
     */
    public function __construct(
        private Closure $callback,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        ($this->callback)($payload);
    }
}
