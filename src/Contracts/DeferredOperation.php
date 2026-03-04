<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for deferred operations executed from database-backed schedules.
 *
 * Deferred operations are application-level jobs scheduled for future execution.
 * They are stored with payload and due date in the database and processed by
 * sequencer:deferred-process.
 *
 * @api
 * @author Brian Faust <brian@cline.sh>
 */
interface DeferredOperation
{
    /**
     * Execute the deferred operation with previously stored payload.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
