<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use Cline\Sequencer\Contracts\ExecutionGuard;
use Facade\IgnitionContracts\BaseSolution;
use Facade\IgnitionContracts\ProvidesSolution;
use Facade\IgnitionContracts\Solution;
use RuntimeException;

/**
 * Exception thrown when an execution guard blocks operation processing.
 *
 * This exception indicates that a configured guard has determined that
 * operations should not execute in the current environment. The exception
 * includes details about which guard blocked execution and why.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ExecutionGuardException extends RuntimeException implements ProvidesSolution, SequencerException
{
    /**
     * Create a new execution guard exception.
     *
     * @param ExecutionGuard $guard The guard that blocked execution
     */
    public function __construct(
        public readonly ExecutionGuard $guard,
    ) {
        parent::__construct($guard->reason());
    }

    /**
     * Get the guard that blocked execution.
     */
    public function getGuard(): ExecutionGuard
    {
        return $this->guard;
    }

    /**
     * Get the guard name for logging.
     */
    public function getGuardName(): string
    {
        return $this->guard->name();
    }

    public function getSolution(): Solution
    {
        /** @var BaseSolution $solution */
        $solution = BaseSolution::create('Review package usage and configuration.');

        return $solution
            ->setSolutionDescription('Exception: '.$this->getMessage())
            ->setDocumentationLinks([
                'Package documentation' => 'https://github.com/cline/sequencer',
            ]);
    }
}
