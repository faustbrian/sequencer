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
 * Thrown when an operation class cannot be located in the filesystem.
 *
 * This exception occurs during operation discovery when the system attempts to execute
 * or load an operation class that doesn't exist. Common causes include typos in operation
 * names, deleted operation files, or misconfigured namespace paths in the sequencer
 * configuration (sequencer.namespaces and sequencer.paths).
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationNotFoundException extends RuntimeException implements SequencerException
{
    /**
     * Create exception for missing operation class.
     *
     * Thrown when attempting to execute or load an operation that cannot be found
     * in the configured namespace paths. This typically indicates a configuration
     * issue or deleted operation file.
     *
     * @param  string $operation The fully-qualified class name of the operation that was not found
     * @return self   Exception instance with operation name in error message
     */
    public static function forOperation(string $operation): self
    {
        return new self(sprintf('Operation not found: %s', $operation));
    }

    /**
     * Create exception for an operation file that does not resolve on this worker.
     *
     * Thrown when a queued operation's file exists neither at its dispatched path
     * nor in the current discovery paths, typically because the worker runs a
     * release that does not contain the operation yet.
     *
     * @param  string $path The operation file path the job was dispatched with
     * @return self   Exception instance with the path in the error message
     */
    public static function forUnresolvedPath(string $path): self
    {
        return new self(sprintf('Operation file not found on this worker: %s', $path));
    }
}
