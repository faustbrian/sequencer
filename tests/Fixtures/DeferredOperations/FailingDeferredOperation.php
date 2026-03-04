<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\DeferredOperations;

use Cline\Sequencer\Contracts\DeferredOperation;
use RuntimeException;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class FailingDeferredOperation implements DeferredOperation
{
    public static int $attempts = 0;

    public static function reset(): void
    {
        self::$attempts = 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        ++self::$attempts;

        throw new RuntimeException('Deferred operation failed.');
    }
}
