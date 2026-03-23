<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\DeferredOperationNotFoundException;
use Cline\Sequencer\Exceptions\InvalidDeferredConfigurationException;
use Cline\Sequencer\Support\DeferredOperationRegistry;
use Tests\Fixtures\DeferredOperations\HelpNewOrganizationEmailOperation;

describe('DeferredOperationRegistry', function (): void {
    beforeEach(function (): void {
        config()->set('sequencer.deferred.taskMap', []);
        config()->set('sequencer.deferred.enforceTaskMap', []);
    });

    test('it stores mapped classes by alias', function (): void {
        config()->set('sequencer.deferred.taskMap', [
            'help_new_organization_email' => HelpNewOrganizationEmailOperation::class,
        ]);

        $registry = resolve(DeferredOperationRegistry::class);

        expect($registry->normalizeForStorage(HelpNewOrganizationEmailOperation::class))
            ->toBe('help_new_organization_email')
            ->and($registry->resolveClass('help_new_organization_email'))
            ->toBe(HelpNewOrganizationEmailOperation::class);
    });

    test('it rejects unknown aliases when enforced mapping is enabled', function (): void {
        config()->set('sequencer.deferred.enforceTaskMap', [
            'help_new_organization_email' => HelpNewOrganizationEmailOperation::class,
        ]);

        $registry = resolve(DeferredOperationRegistry::class);

        expect(fn () => $registry->normalizeForStorage('missing_alias'))
            ->toThrow(DeferredOperationNotFoundException::class);

        expect(fn () => $registry->resolveClass(HelpNewOrganizationEmailOperation::class))
            ->toThrow(DeferredOperationNotFoundException::class);
    });

    test('it rejects conflicting task map configuration', function (): void {
        config()->set('sequencer.deferred.taskMap', [
            'help_new_organization_email' => HelpNewOrganizationEmailOperation::class,
        ]);
        config()->set('sequencer.deferred.enforceTaskMap', [
            'welcome_organization_email' => HelpNewOrganizationEmailOperation::class,
        ]);

        $registry = resolve(DeferredOperationRegistry::class);

        expect(fn () => $registry->normalizeForStorage('help_new_organization_email'))
            ->toThrow(InvalidDeferredConfigurationException::class);
    });

    test('it rejects invalid mapped classes', function (): void {
        config()->set('sequencer.deferred.taskMap', [
            'help_new_organization_email' => stdClass::class,
        ]);

        $registry = resolve(DeferredOperationRegistry::class);

        expect(fn () => $registry->resolveClass('help_new_organization_email'))
            ->toThrow(InvalidDeferredConfigurationException::class);
    });
});
