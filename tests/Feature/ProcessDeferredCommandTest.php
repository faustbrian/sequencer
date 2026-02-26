<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\SequencerManager;
use Illuminate\Support\Facades\Date;
use Tests\Fixtures\DeferredOperations\HelpNewOrganizationEmailOperation;

describe('Process Deferred Command', function (): void {
    beforeEach(function (): void {
        Date::setTestNow('2026-02-26 12:00:00');

        HelpNewOrganizationEmailOperation::reset();

        config()->set('sequencer.deferred.taskMap', [
            'help_new_organization_email' => HelpNewOrganizationEmailOperation::class,
        ]);
        config()->set('sequencer.deferred.enforceTaskMap', []);
    });

    afterEach(function (): void {
        Date::setTestNow();
    });

    test('it processes due deferred operations', function (): void {
        resolve(SequencerManager::class)->defer(
            operation: 'help_new_organization_email',
            payload: ['business_entity_id' => 777],
            dueAt: Date::now()->subMinute(),
        );

        $this->artisan('sequencer:deferred-process')
            ->assertSuccessful()
            ->expectsOutputToContain('Processed 1 deferred operation(s).');

        expect(HelpNewOrganizationEmailOperation::$executed)->toBeTrue()
            ->and(HelpNewOrganizationEmailOperation::$payload)->toMatchArray([
                'business_entity_id' => 777,
            ]);
    });
});
