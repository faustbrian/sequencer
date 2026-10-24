<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use Cline\CodingStandard\Rector\Factory;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Rector\Class_\ModelCastsPropertyToCastsMethodRector;
use RectorLaravel\Rector\Class_\ReplaceQueueTraitsWithQueueableRector;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;
return Factory::create(
    paths: [__DIR__.'/src', __DIR__.'/tests'],
    skip: [
        /*
        | CLI supervision must replay PHP's actual process argv, not the
        | mutable HTTP request server bag.
        */
        ServerVariableToRequestFacadeRector::class => [
            __DIR__.'/src/Support/DeferredProcessRuntime.php',
            __DIR__.'/tests/Feature/ProcessDeferredCommandTest.php',
        ],
        // Production constraints still admit Laravel 10 through variable-keys.
        // These rewrites require newer frameworks than that public baseline.
        ModelCastsPropertyToCastsMethodRector::class,
        ReplaceQueueTraitsWithQueueableRector::class,
        ContainerBindConcreteWithClosureOnlyRector::class,
        NewlineBetweenClassLikeStmtsRector::class,
        RemoveUnreachableStatementRector::class => [__DIR__.'/tests' ],
    ],
    maxProcesses: 1,
);
