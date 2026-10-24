<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Commands\ProcessDeferredCommand;
use Cline\Sequencer\SequencerManager;
use Cline\Sequencer\Support\DeferredOperationProcessor;
use Cline\Sequencer\Support\DeferredProcessRuntime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Request;
use Symfony\Component\Console\Input\ArgvInput;
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

    test('it respects the processing limit option', function (): void {
        resolve(SequencerManager::class)->defer(
            operation: 'help_new_organization_email',
            payload: ['business_entity_id' => 111],
            dueAt: Date::now()->subMinutes(2),
        );

        resolve(SequencerManager::class)->defer(
            operation: 'help_new_organization_email',
            payload: ['business_entity_id' => 222],
            dueAt: Date::now()->subMinute(),
        );

        $this->artisan('sequencer:deferred-process', ['--limit' => 1])
            ->assertSuccessful()
            ->expectsOutputToContain('Processed 1 deferred operation(s).');

        expect(HelpNewOrganizationEmailOperation::$executed)->toBeTrue()
            ->and(HelpNewOrganizationEmailOperation::$payload)->toMatchArray([
                'business_entity_id' => 111,
            ]);
    });
});

test('opted in containment rejects programmatic processing before claiming', function (): void {
    config()->set('sequencer.deferred.runtime.enabled', true);
    config()->set('sequencer.deferred.enforceTaskMap', ['help_new_organization_email' => HelpNewOrganizationEmailOperation::class]);

    $row = resolve(SequencerManager::class)->defer('help_new_organization_email', [], now()->subMinute());

    expect(fn () => Artisan::call('sequencer:deferred-process'))
        ->toThrow(RuntimeException::class, 'external artisan CLI');
    expect($row->fresh()->attempts)->toBe(0);
});

test('default command retains its concrete binding and public constructor', function (): void {
    config()->set('sequencer.deferred.runtime', []);
    config()->set('sequencer.deferred.runtime.timeout_binary', '/missing/timeout');

    $command = new ProcessDeferredCommand(resolve(DeferredOperationProcessor::class));
    expect($command)->toBeInstanceOf(ProcessDeferredCommand::class)
        ->and(resolve(ProcessDeferredCommand::class))->toBeInstanceOf($command::class);
    $this->artisan('sequencer:deferred-process')->assertSuccessful();
    $this->artisan('list')->assertSuccessful();
});

test('rejects unsafe runtime policy before claiming', function (array $policy): void {
    config()->set('sequencer.deferred.runtime.enabled', true);
    config()->set($policy);
    config()->set('sequencer.deferred.enforceTaskMap', ['help_new_organization_email' => HelpNewOrganizationEmailOperation::class]);

    $row = resolve(SequencerManager::class)->defer('help_new_organization_email', [], now()->subMinute());
    expect(fn () => Artisan::call('sequencer:deferred-process'))
        ->toThrow(InvalidArgumentException::class);
    expect($row->fresh()->attempts)->toBe(0);
})->with([
    'expiry below default budget' => [['sequencer.deferred.retry_after_seconds' => 299]],
    'zero timeout' => [['sequencer.deferred.runtime.timeout_seconds' => 0]],
    'negative grace' => [['sequencer.deferred.runtime.kill_after_seconds' => -1]],
    'malformed margin' => [['sequencer.deferred.runtime.recovery_margin_seconds' => 'invalid']],
    'overflowing watchdog' => [['sequencer.deferred.runtime.timeout_seconds' => \PHP_INT_MAX]],
    'nonboolean opt in' => [['sequencer.deferred.runtime.enabled' => 'yes']],
    'empty binary' => [['sequencer.deferred.runtime.timeout_binary' => '']],
]);

test('admits safe runtime policy before enforcing the external CLI boundary', function (array $policy): void {
    config()->set('sequencer.deferred.runtime.enabled', true);
    config()->set($policy);

    expect(fn () => Artisan::call('sequencer:deferred-process'))
        ->toThrow(RuntimeException::class, 'external artisan CLI');
})->with([
    'default exact boundary' => [[]],
    'old published config with opt in' => [['sequencer.deferred.runtime' => ['enabled' => true]]],
    'above default boundary' => [['sequencer.deferred.retry_after_seconds' => 301]],
    'custom exact boundary' => [['sequencer.deferred.runtime.timeout_seconds' => 2,
        'sequencer.deferred.runtime.kill_after_seconds' => 1,
        'sequencer.deferred.runtime.recovery_margin_seconds' => 1,
        'sequencer.deferred.retry_after_seconds' => 4]],
]);

test('does not trust the mutable HTTP server bag as process argv', function (): void {
    config()->set('sequencer.deferred.runtime', ['enabled' => true, 'timeout_binary' => '/missing/timeout']);
    $argv = ['artisan', 'sequencer:deferred-process'];
    Request::swap(Illuminate\Http\Request::create('/', 'GET', [], [], [], ['argv' => $argv]));
    $runtime = resolve(DeferredProcessRuntime::class);
    expect(fn () => $runtime->enter(
        new ArgvInput($argv),
    ))
        ->toThrow(RuntimeException::class, 'external artisan CLI');
});

test('rejects malformed process arguments instead of replaying partial input', function (mixed $argv): void {
    $original = $_SERVER['argv'];
    $_SERVER['argv'] = $argv;

    try {
        expect(fn () => resolve(DeferredProcessRuntime::class)->enter(
            new ArgvInput(['artisan', 'sequencer:deferred-process']),
        ))->toThrow(RuntimeException::class, 'external artisan CLI');
    } finally {
        $_SERVER['argv'] = $original;
    }
})->with([
    'scalar' => ['not an argv list'],
    'nonstring element' => [['artisan', 123]],
    'nonlist keys' => [['tool' => 'artisan']],
]);
