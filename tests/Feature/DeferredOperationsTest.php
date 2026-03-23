<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Enums\DeferredOperationStatus;
use Cline\Sequencer\SequencerManager;
use Cline\Sequencer\Support\DeferredOperationProcessor;
use Illuminate\Support\Facades\Date;
use Tests\Fixtures\DeferredOperations\FailingDeferredOperation;
use Tests\Fixtures\DeferredOperations\HelpNewOrganizationEmailOperation;
use Tests\Fixtures\DeferredOperations\MovedHelpNewOrganizationEmailOperation;

describe('Deferred Operations', function (): void {
    beforeEach(function (): void {
        Date::setTestNow('2026-02-26 12:00:00');

        HelpNewOrganizationEmailOperation::reset();
        MovedHelpNewOrganizationEmailOperation::reset();
        FailingDeferredOperation::reset();

        config()->set('sequencer.deferred.taskMap', [
            'help_new_organization_email' => HelpNewOrganizationEmailOperation::class,
            'failing_operation' => FailingDeferredOperation::class,
        ]);
        config()->set('sequencer.deferred.enforceTaskMap', []);
        config()->set('sequencer.deferred.retry_delay_seconds', 0);
    });

    afterEach(function (): void {
        Date::setTestNow();
    });

    test('it stores deferred operations with alias payload and due date', function (): void {
        $dueAt = Date::now()->addWeekdays(2)->setTime(9, 30, 0);

        $record = resolve(SequencerManager::class)->defer(
            operation: 'help_new_organization_email',
            payload: [
                'business_entity_id' => 123,
                'email' => 'help@example.com',
                'locale' => 'fi',
            ],
            dueAt: $dueAt,
        );

        expect($record->operation)->toBe('help_new_organization_email')
            ->and($record->status)->toBe(DeferredOperationStatus::Pending)
            ->and($record->payload)->toMatchArray([
                'business_entity_id' => 123,
                'email' => 'help@example.com',
                'locale' => 'fi',
            ])
            ->and($record->due_at?->format('Y-m-d H:i:s'))->toBe($dueAt->format('Y-m-d H:i:s'));
    });

    test('it executes due deferred operations from processor', function (): void {
        resolve(SequencerManager::class)->defer(
            operation: 'help_new_organization_email',
            payload: [
                'business_entity_id' => 42,
                'email' => 'hello@example.com',
                'locale' => 'fi',
            ],
            dueAt: Date::now()->subMinute(),
        );

        $stats = resolve(DeferredOperationProcessor::class)->processDue();

        expect($stats['processed'])->toBe(1)
            ->and($stats['completed'])->toBe(1)
            ->and($stats['failed'])->toBe(0)
            ->and(HelpNewOrganizationEmailOperation::$executed)->toBeTrue()
            ->and(HelpNewOrganizationEmailOperation::$payload)->toMatchArray([
                'business_entity_id' => 42,
                'email' => 'hello@example.com',
                'locale' => 'fi',
            ]);
    });

    test('it marks successful deferred operations completed and does not reprocess them', function (): void {
        $record = resolve(SequencerManager::class)->defer(
            operation: 'help_new_organization_email',
            payload: [
                'business_entity_id' => 42,
                'email' => 'hello@example.com',
                'locale' => 'fi',
            ],
            dueAt: Date::now()->subMinute(),
        );

        $firstRun = resolve(DeferredOperationProcessor::class)->processDue();
        $record->refresh();

        HelpNewOrganizationEmailOperation::reset();

        $secondRun = resolve(DeferredOperationProcessor::class)->processDue();

        expect($firstRun['processed'])->toBe(1)
            ->and($firstRun['completed'])->toBe(1)
            ->and($record->status)->toBe(DeferredOperationStatus::Completed)
            ->and($record->processed_at)->not->toBeNull()
            ->and($record->reserved_at)->toBeNull()
            ->and($record->last_error)->toBeNull()
            ->and($record->attempts)->toBe(1)
            ->and($secondRun)->toBe([
                'processed' => 0,
                'completed' => 0,
                'failed' => 0,
                'retried' => 0,
            ])
            ->and(HelpNewOrganizationEmailOperation::$executed)->toBeFalse()
            ->and(HelpNewOrganizationEmailOperation::$payload)->toBeNull();
    });

    test('it supports alias remapping for moved deferred operation classes', function (): void {
        config()->set('sequencer.deferred.taskMap', [
            'help_new_organization_email' => MovedHelpNewOrganizationEmailOperation::class,
        ]);

        resolve(SequencerManager::class)->defer(
            operation: 'help_new_organization_email',
            payload: [
                'business_entity_id' => 9_001,
            ],
            dueAt: Date::now()->subMinute(),
        );

        $stats = resolve(DeferredOperationProcessor::class)->processDue();

        expect($stats['completed'])->toBe(1)
            ->and(MovedHelpNewOrganizationEmailOperation::$executed)->toBeTrue()
            ->and(MovedHelpNewOrganizationEmailOperation::$payload)->toMatchArray([
                'business_entity_id' => 9_001,
            ]);
    });

    test('it retries failed deferred operations and marks them failed after max attempts', function (): void {
        resolve(SequencerManager::class)->defer(
            operation: 'failing_operation',
            payload: ['id' => 1],
            dueAt: Date::now()->subMinute(),
            maxAttempts: 2,
        );

        $firstRun = resolve(DeferredOperationProcessor::class)->processDue();
        $secondRun = resolve(DeferredOperationProcessor::class)->processDue();

        expect($firstRun['retried'])->toBe(1)
            ->and($secondRun['failed'])->toBe(1)
            ->and(FailingDeferredOperation::$attempts)->toBe(2);
    });
});
