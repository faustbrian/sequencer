<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\DeferredOperations;

use Cline\Sequencer\Contracts\DeferredOperation;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class HelpNewOrganizationEmailOperation implements DeferredOperation
{
    public static bool $executed = false;

    /** @var null|array<string, mixed> */
    public static ?array $payload = null;

    public static function reset(): void
    {
        self::$executed = false;
        self::$payload = null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        self::$executed = true;
        self::$payload = $payload;
    }
}
