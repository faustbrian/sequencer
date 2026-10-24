<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Commands\ProcessDeferredCommand;
use Cline\Sequencer\Database\Models\DeferredOperation;
use Cline\Sequencer\Enums\DeferredOperationStatus;
use Cline\Sequencer\Support\DeferredOperationProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\DeferredBound\ProbeOperation;

beforeEach(function (): void {
    $this->boundDatabasePath = tempnam(sys_get_temp_dir(), 'sequencer-runtime-db-');
    $this->boundPreviousConnection = config('database.default');
    config()->set('database.connections.bound_worker', [
        'driver' => 'sqlite', 'database' => $this->boundDatabasePath, 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('bound_worker');
    (require dirname(__DIR__, 2).'/database/migrations/add_deferred_operations_table_to_sequencer.php')->up();
    config()->set('sequencer.deferred.runtime.enabled', true);
});

afterEach(function (): void {
    DB::disconnect('bound_worker');
    DB::setDefaultConnection($this->boundPreviousConnection);
    File::delete($this->boundDatabasePath);
});

function packageBoundWorker(array $arguments = ['--operation=bound_probe', '--limit=1'], array $environment = [], array $globalArguments = [], array $phpArguments = []): Process
{
    $db = config('database.connections.'.config('database.default'));

    return new Process([\PHP_BINARY, ...$phpArguments, dirname(__DIR__, 2).'/tests/Fixtures/DeferredBound/artisan', ...$globalArguments, 'sequencer:deferred-process', ...$arguments], dirname(__DIR__, 2), [
        'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/sequencer-bound-config-'.bin2hex(random_bytes(8)),
        'BOUND_DATABASE' => $db['database'], 'BOUND_PRIMARY_KEY_TYPE' => config('sequencer.primary_key_type', 'id'),
        ...$environment,
    ], timeout: 60);
}

function packageBoundProcessAlive(int $pid): bool
{
    if (!posix_kill($pid, 0)) {
        return false;
    }

    // A killed worker can await PID1 reaping in a container. Zombies cannot
    // execute effects; signal-0 alone does not distinguish them from live work.
    $stat = file_get_contents('/proc/'.$pid.'/stat');

    return $stat === false || preg_match('/\\) [ZX] /', $stat) !== 1;
}

it('stops the actual blocked handler before its claim can be reclaimed', function (bool $loseSupervisor): void {
    $path = sys_get_temp_dir().'/sequencer-deferred-bound-'.bin2hex(random_bytes(8));
    $row = DeferredOperation::query()->create([
        'operation' => 'bound_probe', 'payload' => ['path' => $path, 'mode' => 'ignore'],
        'status' => DeferredOperationStatus::Pending, 'due_at' => now()->subMinute(), 'attempts' => 0, 'max_attempts' => 3,
    ]);
    $sibling = DeferredOperation::query()->create([
        'operation' => 'bound_probe', 'payload' => ['path' => $path.'.sibling'],
        'status' => DeferredOperationStatus::Pending, 'due_at' => now(), 'attempts' => 0, 'max_attempts' => 3,
    ]);
    DB::commit();
    DB::purge();
    $worker = packageBoundWorker();
    $pid = null;

    try {
        $worker->start();
        $deadline = microtime(true) + 30;

        while (!is_file($path.'.entered') && $worker->isRunning() && microtime(true) < $deadline) {
            Sleep::usleep(20_000);
        }

        expect(is_file($path.'.entered'))->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        $probe = json_decode(file_get_contents($path.'.entered'), true, flags: \JSON_THROW_ON_ERROR);
        $pid = $probe['pid'];

        if ($loseSupervisor) {
            posix_kill($worker->getPid(), \SIGKILL);
        }

        $deadline = microtime(true) + 30;

        while (($worker->isRunning() || packageBoundProcessAlive($pid)) && microtime(true) < $deadline) {
            Sleep::usleep(20_000);
        }

        // A timeout status is not proof: the handler PID and late-effect
        // boundary must be gone before the 300-second reservation can expire.
        // Measure from claimed work, not the unclaimed launcher's initial boot.
        expect($worker->isRunning())->toBeFalse()
            ->and((hrtime(true) - $probe['started_ns']) / 1_000_000_000)->toBeLessThan(30.0)
            ->and($worker->getExitCode())->not->toBe(0)
            ->and(packageBoundProcessAlive($pid))->toBeFalse()
            ->and(is_file($path.'.effect'))->toBeFalse()
            ->and($row->fresh()->status)->toBe(DeferredOperationStatus::Processing)
            ->and($row->fresh()->attempts)->toBe(1)
            ->and($sibling->fresh()->status)->toBe(DeferredOperationStatus::Pending)
            ->and($sibling->fresh()->attempts)->toBe(0);
        set_error_handler(static fn (int $level, string $message): bool => $level === \E_WARNING && str_contains($message, 'stream_socket_client'));

        try {
            $peer = stream_socket_client('tcp://'.$probe['address'], $errno, $error, 0.2);
        } finally {
            restore_error_handler();
        }

        expect($peer)->toBeFalse();
        $row->fresh()->update(['reserved_at' => now()->subSeconds(300), 'payload' => ['path' => $path.'.retry']]);
        config(['sequencer.deferred.enforceTaskMap' => ['bound_probe' => ProbeOperation::class]]);
        expect(resolve(DeferredOperationProcessor::class)->processDue(1, ['bound_probe'])['completed'])->toBe(1)
            ->and($row->fresh()->attempts)->toBe(2)
            ->and(is_file($path.'.retry.effect'))->toBeTrue()
            ->and(is_file($path.'.effect'))->toBeFalse();
    } finally {
        $worker->stop(0, \SIGKILL);

        if ($pid !== null && posix_kill($pid, 0)) {
            posix_kill($pid, \SIGKILL);
        }

        foreach (glob($path.'*') as $file) {
            File::delete($file);
        }

        DB::purge();
        DB::reconnect();
        DB::table($row->getTable())->whereIn('id', [$row->id, $sibling->id])->delete();
        DB::beginTransaction();
    }
})->with(['timeout supervisor' => false, 'lost supervisor watchdog' => true]);

it('recovers the unchanged operation after acceptance without completion acknowledgement', function (): void {
    $path = sys_get_temp_dir().'/sequencer-deferred-accepted-'.bin2hex(random_bytes(8));
    $payload = ['path' => $path, 'mode' => 'accept_then_block'];
    $row = DeferredOperation::query()->create([
        'operation' => 'bound_probe', 'payload' => $payload,
        'status' => DeferredOperationStatus::Pending, 'due_at' => now()->subMinute(), 'attempts' => 0, 'max_attempts' => 3,
    ]);
    DB::commit();
    DB::purge();
    $worker = packageBoundWorker();
    $pid = null;

    try {
        $worker->start();
        $deadline = microtime(true) + 30;

        while (!is_file($path.'.entered') && $worker->isRunning() && microtime(true) < $deadline) {
            Sleep::usleep(20_000);
        }

        expect(is_file($path.'.accepted'))->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        $pid = json_decode(file_get_contents($path.'.entered'), true, flags: \JSON_THROW_ON_ERROR)['pid'];
        expect(file_get_contents($path.'.accepted'))->toBe("accepted\n")
            ->and($row->fresh()->status)->toBe(DeferredOperationStatus::Processing);
        posix_kill($pid, \SIGKILL);

        try {
            $worker->wait();
        } catch (ProcessSignaledException $exception) {
            expect($exception->getSignal())->toBe(\SIGKILL);
        }

        expect(packageBoundProcessAlive($pid))->toBeFalse()
            ->and($row->fresh()->status)->toBe(DeferredOperationStatus::Processing)
            ->and($row->fresh()->attempts)->toBe(1);
        $row->fresh()->update(['reserved_at' => now()->subSeconds(300)]);
        config(['sequencer.deferred.enforceTaskMap' => ['bound_probe' => ProbeOperation::class]]);
        expect(resolve(DeferredOperationProcessor::class)->processDue(1, ['bound_probe'])['completed'])->toBe(1)
            ->and($row->fresh()->payload)->toEqual($payload)
            ->and($row->fresh()->attempts)->toBe(2)
            ->and($row->fresh()->status)->toBe(DeferredOperationStatus::Completed)
            ->and(file_get_contents($path.'.accepted'))->toBe("accepted\naccepted\n")
            ->and(resolve(DeferredOperationProcessor::class)->processDue(1, ['bound_probe'])['completed'])->toBe(0)
            ->and(file_get_contents($path.'.accepted'))->toBe("accepted\naccepted\n");
    } finally {
        $worker->stop(0, \SIGKILL);

        if ($pid !== null && posix_kill($pid, 0)) {
            posix_kill($pid, \SIGKILL);
        }

        foreach (glob($path.'*') as $file) {
            File::delete($file);
        }

        DB::purge();
        DB::reconnect();
        DB::table($row->getTable())->where('id', $row->id)->delete();
        DB::beginTransaction();
    }
});

it('preserves selected aliases and limit while rejecting unsafe expiry before claiming', function (bool $unsafe, bool $cachedEvents, bool $globalEnvironment = false, bool $cachedConfig = false): void {
    $path = sys_get_temp_dir().'/sequencer-deferred-options-'.bin2hex(random_bytes(8));
    $rows = collect(['bound_other', 'bound_probe', 'bound_excluded'])->map(fn ($alias, $index) => DeferredOperation::query()->create([
        'operation' => $alias, 'payload' => ['path' => $path.'.'.$index], 'status' => DeferredOperationStatus::Pending,
        'due_at' => now()->subMinutes(3 - $index), 'attempts' => 0, 'max_attempts' => 3,
    ]));
    $snapshots = $rows->map(fn ($row) => DB::table($row->getTable())->find($row->id));
    DB::commit();
    DB::purge();
    $eventCache = $path.'.events.php';

    if ($cachedEvents) {
        file_put_contents($eventCache, '<?php return [];');
    }

    $worker = packageBoundWorker(['--operation=bound_probe', '--operation=bound_other', '--limit=1'], [
        ...($unsafe ? ['BOUND_PROBE_EXPIRY' => '2'] : []),
        ...($cachedEvents ? ['APP_EVENTS_CACHE' => $eventCache] : []),
        ...($cachedConfig ? ['APP_CONFIG_CACHE' => $path.'.config.php', 'BOUND_BUILD_CONFIG_CACHE' => '1'] : []),
    ], $globalEnvironment ? ['--env', 'testing'] : []);

    try {
        $worker->run();

        if ($unsafe) {
            expect($worker->getExitCode())->not->toBe(0, $worker->getOutput().$worker->getErrorOutput())
                ->and($worker->getOutput().$worker->getErrorOutput())->toContain('within reservation expiry');
        } else {
            expect($worker->getExitCode())->toBe(0, $worker->getOutput().$worker->getErrorOutput())
                ->and($rows[0]->fresh()->status)->toBe(DeferredOperationStatus::Completed, $worker->getOutput().$worker->getErrorOutput())
                ->and(is_file($path.'.0.effect'))->toBeTrue();

            if ($cachedConfig) {
                expect(json_decode(file_get_contents($path.'.0.entered'), true)['cached_config'])->toBeTrue();
            }
        }

        foreach ($rows as $index => $row) {
            if (!$unsafe && $index <= 0) {
                continue;
            }

            expect(DB::table($row->getTable())->find($row->id))->toEqual($snapshots[$index])
                ->and(is_file($path.'.'.$index.'.effect'))->toBeFalse();
        }
    } finally {
        $worker->stop(0, \SIGKILL);

        foreach (glob($path.'*') as $file) {
            File::delete($file);
        }

        DB::purge();
        DB::reconnect();
        DB::table($rows[0]->getTable())->whereIn('id', $rows->pluck('id'))->delete();
        DB::beginTransaction();
    }
})->with(['normal selected work' => [false, false], 'global options before the command' => [false, false, true], 'cached configuration' => [false, false, false, true], 'unsafe expiry' => [true, false], 'unsafe expiry with older event cache' => [true, true]]);

it('does not restart the whole-run deadline for each handler', function (): void {
    $path = sys_get_temp_dir().'/sequencer-deferred-batch-'.bin2hex(random_bytes(8));
    $rows = collect(range(0, 3))->map(fn ($index) => DeferredOperation::query()->create([
        'operation' => 'bound_probe', 'payload' => ['path' => $path.'.'.$index, 'microseconds' => 8_000_000],
        'status' => DeferredOperationStatus::Pending, 'due_at' => now()->subMinutes(4 - $index), 'attempts' => 0, 'max_attempts' => 3,
    ]));
    DB::commit();
    DB::purge();
    $worker = packageBoundWorker(['--operation=bound_probe', '--limit=100']);

    try {
        $worker->run();
        expect($worker->getExitCode())->not->toBe(0);
        $statuses = $rows->map(fn ($row) => $row->fresh()->status);
        expect($statuses->filter(fn ($status): bool => $status === DeferredOperationStatus::Completed)->count())->toBeBetween(1, 2)
            ->and($statuses->filter(fn ($status): bool => $status === DeferredOperationStatus::Processing)->count())->toBe(1)
            ->and($statuses->filter(fn ($status): bool => $status === DeferredOperationStatus::Pending)->count())->toBeGreaterThanOrEqual(1);
        $interrupted = false;

        foreach ($rows as $index => $row) {
            $row = $row->fresh();

            if ($row->status === DeferredOperationStatus::Completed) {
                expect($interrupted)->toBeFalse()
                    ->and(is_file($path.'.'.$index.'.effect'))->toBeTrue()
                    ->and($row->attempts)->toBe(1);
            } elseif ($row->status === DeferredOperationStatus::Processing) {
                expect($interrupted)->toBeFalse()
                    ->and(is_file($path.'.'.$index.'.effect'))->toBeFalse()
                    ->and($row->attempts)->toBe(1);
                $interrupted = true;
            } else {
                expect($interrupted)->toBeTrue()
                    ->and(is_file($path.'.'.$index.'.effect'))->toBeFalse()
                    ->and($row->attempts)->toBe(0);
            }
        }
    } finally {
        $worker->stop(0, \SIGKILL);

        foreach (glob($path.'*') as $file) {
            File::delete($file);
        }

        DB::purge();
        DB::reconnect();
        DB::table($rows[0]->getTable())->whereIn('id', $rows->pluck('id'))->delete();
        DB::beginTransaction();
    }
});

it('rejects unbounded programmatic command execution before claiming', function (array $parameters): void {
    $row = DeferredOperation::query()->create([
        'operation' => 'bound_probe', 'payload' => [],
        'status' => DeferredOperationStatus::Pending, 'due_at' => now()->subMinute(),
        'attempts' => 0, 'max_attempts' => 3,
    ]);

    expect(fn () => Artisan::call('sequencer:deferred-process', $parameters))
        ->toThrow(RuntimeException::class, 'external artisan CLI');
    expect($row->fresh()->status)->toBe(DeferredOperationStatus::Pending)
        ->and($row->fresh()->attempts)->toBe(0);
})->with(['string input' => [[]], 'array input' => [['--limit' => 1]]]);

it('rejects nested command entry before claiming', function (bool $silent): void {
    $row = DeferredOperation::query()->create([
        'operation' => 'bound_probe', 'payload' => [], 'status' => DeferredOperationStatus::Pending,
        'due_at' => now()->subMinute(), 'attempts' => 0, 'max_attempts' => 3,
    ]);
    $outer = new class($silent) extends Command
    {
        protected $signature = 'bound:outer';

        public function __construct(
            private readonly bool $silent,
        ) {
            parent::__construct();
        }

        public function handle(): int
        {
            return $this->silent
                ? $this->callSilent('sequencer:deferred-process', ['--limit' => 1])
                : $this->call('sequencer:deferred-process', ['--limit' => 1]);
        }
    };
    $target = Artisan::all()['sequencer:deferred-process'];
    $outer->setLaravel(app());
    $outer->setApplication($target->getApplication());

    expect(fn (): int => $outer->run(
        new ArrayInput([]),
        new BufferedOutput(),
    ))
        ->toThrow(RuntimeException::class, 'external artisan CLI');
    expect($row->fresh()->status)->toBe(DeferredOperationStatus::Pending)
        ->and($row->fresh()->attempts)->toBe(0);
})->with(['call' => false, 'callSilent' => true]);

it('rejects direct registered command execution before claiming', function (string $entry): void {
    $row = DeferredOperation::query()->create([
        'operation' => 'bound_probe', 'payload' => [], 'status' => DeferredOperationStatus::Pending,
        'due_at' => now()->subMinute(), 'attempts' => 0, 'max_attempts' => 3,
    ]);
    $command = resolve(ProcessDeferredCommand::class);
    $invoke = match ($entry) {
        'handle' => $command->handle(...),
        'container handle' => fn () => app()->call($command->handle(...)),
        'array run' => fn () => $command->run(
            new ArrayInput([]),
            new BufferedOutput(),
        ),
        'synthetic argv run' => fn () => $command->run(
            new ArgvInput(['artisan', 'sequencer:deferred-process']),
            new BufferedOutput(),
        ),
    };
    expect($invoke)->toThrow(RuntimeException::class, 'external artisan CLI');
    expect($row->fresh()->status)->toBe(DeferredOperationStatus::Pending)
        ->and($row->fresh()->attempts)->toBe(0);
})->with(['handle', 'container handle', 'array run', 'synthetic argv run']);

it('rejects missing runtime prerequisites and a stale supervisor marker before claiming', function (array $environment, string $message, array $phpArguments = []): void {
    $row = DeferredOperation::query()->create([
        'operation' => 'bound_probe', 'payload' => [], 'status' => DeferredOperationStatus::Pending,
        'due_at' => now()->subMinute(), 'attempts' => 0, 'max_attempts' => 3,
    ]);
    $worker = packageBoundWorker(environment: $environment, phpArguments: $phpArguments);
    $worker->run();
    expect($worker->getExitCode())->not->toBe(0)
        ->and($worker->getOutput().$worker->getErrorOutput())->toContain($message)
        ->and($row->fresh()->attempts)->toBe(0)
        ->and($row->fresh()->status)->toBe(DeferredOperationStatus::Pending);
})->with([
    'missing PCNTL' => [[], 'PCNTL and POSIX', ['-d', 'disable_functions=pcntl_exec']],
    'missing binary' => [['BOUND_TIMEOUT_BINARY' => '/missing/timeout'], 'executable GNU timeout'],
    'stale marker' => [['SEQUENCER_DEFERRED_SUPERVISOR_PID' => '99999999'], 'no live timeout supervisor'],
]);
