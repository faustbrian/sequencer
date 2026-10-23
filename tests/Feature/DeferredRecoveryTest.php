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
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\DeferredOperations\CallbackDeferredOperation;
use Tests\Fixtures\DeferredOperations\FailingDeferredOperation;
use Tests\Fixtures\DeferredOperations\HelpNewOrganizationEmailOperation;

beforeEach(function (): void {
    Date::setTestNow('2026-10-23 12:00:00');
    HelpNewOrganizationEmailOperation::reset();
    FailingDeferredOperation::reset();
    config()->set('sequencer.deferred.taskMap', [
        'selected' => HelpNewOrganizationEmailOperation::class,
        'unrelated' => FailingDeferredOperation::class,
        'callback' => CallbackDeferredOperation::class,
    ]);
    config()->set('sequencer.deferred.enforceTaskMap', []);
    config()->set('sequencer.deferred.retry_after_seconds', 300);
    config()->set('sequencer.deferred.erase_payload_for', []);
});

afterEach(function (): void {
    Date::setTestNow();
});

test('recovers only expired reservations including the exact expiry boundary', function (int $age, bool $expired): void {
    $row = resolve(SequencerManager::class)->defer('selected', ['value' => 'recover me'], Date::now());
    $row->update(['status' => DeferredOperationStatus::Processing, 'attempts' => 1, 'reserved_at' => Date::now()->subSeconds($age)]);

    resolve(DeferredOperationProcessor::class)->processDue();

    expect(HelpNewOrganizationEmailOperation::$executed)->toBe($expired)
        ->and($row->fresh()->status)->toBe($expired ? DeferredOperationStatus::Completed : DeferredOperationStatus::Processing)
        ->and($row->fresh()->attempts)->toBe($expired ? 2 : 1);
})->with([[299, false], [300, true], [301, true]]);

test('does not reserve the unstarted siblings of a running handler', function (): void {
    resolve(SequencerManager::class)->defer('callback', [], Date::now()->subMinute());
    $sibling = resolve(SequencerManager::class)->defer('selected', [], Date::now());
    app()->instance(CallbackDeferredOperation::class, new CallbackDeferredOperation(function () use ($sibling): void {
        expect($sibling->fresh()->status)->toBe(DeferredOperationStatus::Pending)
            ->and($sibling->fresh()->attempts)->toBe(0);
    }));

    expect(resolve(DeferredOperationProcessor::class)->processDue()['completed'])->toBe(2);
});

test('filters both expired reservations and pending work before applying the limit', function (): void {
    $excluded = resolve(SequencerManager::class)->defer('unrelated', ['secret' => 'leave unchanged'], Date::now()->subMinutes(4));
    $excluded->update(['status' => DeferredOperationStatus::Processing, 'attempts' => 1, 'reserved_at' => Date::now()->subMinutes(8)]);

    $snapshot = $excluded->fresh()->getRawOriginal();
    $selected = resolve(SequencerManager::class)->defer('selected', ['value' => 1], Date::now()->subMinute());
    $future = resolve(SequencerManager::class)->defer('selected', [], Date::now()->addMinute());

    $this->artisan('sequencer:deferred-process', ['--operation' => ['selected'], '--limit' => 1])->assertSuccessful();

    expect($selected->fresh()->status)->toBe(DeferredOperationStatus::Completed)
        ->and($excluded->fresh()->getRawOriginal())->toBe($snapshot)
        ->and($future->fresh()->attempts)->toBe(0)
        ->and(FailingDeferredOperation::$attempts)->toBe(0);
});

test('an explicit empty or unmatched selection never broadens to all operations', function (array $selection): void {
    $row = resolve(SequencerManager::class)->defer('selected', [], Date::now());
    expect(resolve(DeferredOperationProcessor::class)->processDue(100, $selection)['processed'])->toBe(0)
        ->and($row->fresh()->status)->toBe(DeferredOperationStatus::Pending);
})->with([[[]], [['unknown']]]);

test('an abandoned final attempt becomes failed without invoking its handler', function (): void {
    $row = resolve(SequencerManager::class)->defer('selected', ['secret' => 'erase'], Date::now(), maxAttempts: 1);
    $row->update(['status' => DeferredOperationStatus::Processing, 'attempts' => 1, 'reserved_at' => Date::now()->subMinutes(8)]);

    config()->set('sequencer.deferred.erase_payload_for', ['selected']);

    resolve(DeferredOperationProcessor::class)->processDue();

    expect($row->fresh()->status)->toBe(DeferredOperationStatus::Failed)
        ->and($row->fresh()->attempts)->toBe(1)
        ->and($row->fresh()->payload)->toBe([])
        ->and(HelpNewOrganizationEmailOperation::$executed)->toBeFalse();
});

test('a stale owner cannot overwrite its replacement disposition', function (bool $oldFails): void {
    $row = resolve(SequencerManager::class)->defer('callback', [], Date::now());
    $calls = 0;
    app()->instance(CallbackDeferredOperation::class, new CallbackDeferredOperation(function () use (&$calls, $oldFails): void {
        ++$calls;

        if ($calls === 1) {
            Date::setTestNow(Date::now()->addSeconds(301));
            resolve(DeferredOperationProcessor::class)->processDue(1);
            throw_if($oldFails, RuntimeException::class, 'Expired owner failed');

            return;
        }

        throw_unless($oldFails, RuntimeException::class, 'Replacement needs retry');
    }));

    resolve(DeferredOperationProcessor::class)->processDue(1);

    expect($calls)->toBe(2)
        ->and($row->fresh()->attempts)->toBe(2)
        ->and($row->fresh()->status)->toBe($oldFails ? DeferredOperationStatus::Completed : DeferredOperationStatus::Pending);
})->with([true, false]);

test('erases opted-in payloads only when processing becomes terminal', function (bool $fails, bool $erase): void {
    $alias = $fails ? 'unrelated' : 'selected';
    $payload = ['nested' => ['password' => 'distinctive-sensitive-value']];
    $row = resolve(SequencerManager::class)->defer($alias, $payload, Date::now(), maxAttempts: 2);
    config()->set('sequencer.deferred.erase_payload_for', $erase ? [$alias] : []);
    config()->set('sequencer.deferred.retry_delay_seconds', 0);

    resolve(DeferredOperationProcessor::class)->processDue(1);

    if ($fails) {
        expect($row->fresh()->status)->toBe(DeferredOperationStatus::Pending)
            ->and($row->fresh()->payload)->toBe($payload);
        resolve(DeferredOperationProcessor::class)->processDue(1);
    } else {
        expect(HelpNewOrganizationEmailOperation::$payload)->toBe($payload);
    }

    $raw = DB::table($row->getTable())->where('id', $row->getKey())->value('payload');
    expect($row->fresh()->payload)->toBe($erase ? [] : $payload)
        ->and(str_contains((string) $raw, 'distinctive-sensitive-value'))->toBe(!$erase)
        ->and($row->fresh()->status)->toBe($fails ? DeferredOperationStatus::Failed : DeferredOperationStatus::Completed);
})->with([[false, true], [false, false], [true, true], [true, false]]);
