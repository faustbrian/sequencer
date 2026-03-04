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
final class DeferredOperationNotFoundException extends RuntimeException implements SequencerException
{
    public static function forIdentifier(string $identifier): self
    {
        return new self(
            sprintf('Deferred operation "%s" could not be resolved.', $identifier),
        );
    }
}
