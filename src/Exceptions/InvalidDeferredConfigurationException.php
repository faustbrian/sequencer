<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidDeferredConfigurationException extends RuntimeException
{
    public static function conflictingTaskMaps(): self
    {
        return new self(
            'Cannot configure both sequencer.deferred.taskMap and '.
            'sequencer.deferred.enforceTaskMap at the same time.',
        );
    }

    public static function invalidTaskClass(string $alias, string $class): self
    {
        return new self(
            sprintf(
                'Deferred operation mapping for "%s" points to invalid class "%s".',
                $alias,
                $class,
            ),
        );
    }
}
