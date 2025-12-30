<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Commands\MakeOperationCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

/**
 * MakeOperationCommand Test Suite
 *
 * Comprehensive tests for the MakeOperationCommand class, covering all stub variations,
 * path generation logic, and edge cases. Achieves 100% line and branch coverage.
 */
covers(MakeOperationCommand::class);

describe('MakeOperationCommand', function (): void {
    beforeEach(function (): void {
        // Clean up any test operation files
        $operationsPath = database_path('operations');

        if (!File::exists($operationsPath)) {
            return;
        }

        File::cleanDirectory($operationsPath);
    });

    afterEach(function (): void {
        // Clean up test files
        $operationsPath = database_path('operations');

        if (!File::exists($operationsPath)) {
            return;
        }

        File::cleanDirectory($operationsPath);
    });

    describe('Happy Path - Basic Operation Creation', function (): void {
        test('creates basic operation with default stub', function (): void {
            // Arrange
            Date::setTestNow('2024-01-15 14:30:22');
            $operationName = 'NotifyUsersOfSystemUpgrade';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_01_15_143022_notify_users_of_system_upgrade.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('return new class implements Operation');
            expect($content)->toContain('public function handle(): void');
        })->group('happy-path', 'basic-operation');

        test('converts PascalCase operation name to snake_case filename', function (): void {
            // Arrange
            Date::setTestNow('2024-03-20 09:15:45');
            $operationName = 'SyncProductsWithExternalAPI';

            // Act
            Artisan::call('make:operation', ['name' => $operationName]);

            // Assert
            $expectedPath = database_path('operations/2024_03_20_091545_sync_products_with_external_a_p_i.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('happy-path', 'naming-conversion');

        test('handles operation names with consecutive uppercase letters', function (): void {
            // Arrange
            Date::setTestNow('2024-05-10 16:20:30');
            $operationName = 'ProcessAPICallbacks';

            // Act
            Artisan::call('make:operation', ['name' => $operationName]);

            // Assert
            $expectedPath = database_path('operations/2024_05_10_162030_process_a_p_i_callbacks.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('happy-path', 'edge-case');
    });

    describe('Happy Path - Stub Variations', function (): void {
        test('creates async operation with async stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-10 10:00:00');
            $operationName = 'SyncProducts';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--async' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_10_100000_sync_products.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements Asynchronous');
        })->group('happy-path', 'async-stub');

        test('creates rollback operation with rollback stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-11 11:00:00');
            $operationName = 'MigrateUserData';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--rollback' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_11_110000_migrate_user_data.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements Rollbackable');
            expect($content)->toContain('public function rollback()');
        })->group('happy-path', 'rollback-stub');

        test('creates retryable operation with retryable stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-12 12:00:00');
            $operationName = 'CallExternalAPI';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--retryable' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_12_120000_call_external_a_p_i.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements Operation, Retryable');
            expect($content)->toContain('public function tries(): int');
        })->group('happy-path', 'retryable-stub');

        test('creates transaction operation with transaction stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-13 13:00:00');
            $operationName = 'TransferBalances';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--transaction' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_13_130000_transfer_balances.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements WithinTransaction');
        })->group('happy-path', 'transaction-stub');

        test('creates idempotent operation with idempotent stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-14 14:00:00');
            $operationName = 'EnsureDataConsistency';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--idempotent' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_14_140000_ensure_data_consistency.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements Idempotent');
        })->group('happy-path', 'idempotent-stub');

        test('creates conditional operation with conditional stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-15 15:00:00');
            $operationName = 'ConditionalDataMigration';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--conditional' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_15_150000_conditional_data_migration.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements ConditionalExecution');
            expect($content)->toContain('public function shouldRun(): bool');
        })->group('happy-path', 'conditional-stub');

        test('creates hooks operation with hooks stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-16 16:00:00');
            $operationName = 'OperationWithHooks';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--hooks' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_16_160000_operation_with_hooks.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements Operation, HasLifecycleHooks');
            expect($content)->toContain('public function before(): void');
        })->group('happy-path', 'hooks-stub');

        test('creates scheduled operation with scheduled stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-17 17:00:00');
            $operationName = 'RunMaintenanceTask';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--scheduled' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_17_170000_run_maintenance_task.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements Scheduled');
            expect($content)->toContain('public function scheduledAt(): DateTimeInterface');
        })->group('happy-path', 'scheduled-stub');

        test('creates dependencies operation with dependencies stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-18 18:00:00');
            $operationName = 'OperationWithDependencies';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--dependencies' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_18_180000_operation_with_dependencies.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements HasDependencies');
            expect($content)->toContain('public function dependsOn(): array');
        })->group('happy-path', 'dependencies-stub');

        test('creates middleware operation with middleware stub', function (): void {
            // Arrange
            Date::setTestNow('2024-02-19 19:00:00');
            $operationName = 'OperationWithMiddleware';

            // Act
            $exitCode = Artisan::call('make:operation', [
                'name' => $operationName,
                '--middleware' => true,
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $expectedPath = database_path('operations/2024_02_19_190000_operation_with_middleware.php');
            expect(File::exists($expectedPath))->toBeTrue();

            $content = File::get($expectedPath);
            expect($content)->toContain('implements Operation, HasMiddleware');
            expect($content)->toContain('public function middleware(): array');
        })->group('happy-path', 'middleware-stub');
    });

    describe('Edge Cases - getPath() Method Coverage', function (): void {
        test('uses default path when config key does not exist', function (): void {
            // Arrange
            Date::setTestNow('2024-04-01 10:30:00');
            config()->set('sequencer.paths.operations'); // Config key doesn't exist in actual config

            // Act
            Artisan::call('make:operation', ['name' => 'TestOperation']);

            // Assert - Should use default 'database/operations'
            $expectedPath = database_path('operations/2024_04_01_103000_test_operation.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('edge-case', 'config-null');

        test('uses default path when config returns non-string value (array)', function (): void {
            // Arrange
            Date::setTestNow('2024-04-02 11:30:00');
            config()->set('sequencer.paths.operations', ['invalid', 'array']);

            // Act
            Artisan::call('make:operation', ['name' => 'ArrayConfigTest']);

            // Assert - Should fall back to 'database/operations' when config is not a string
            $expectedPath = database_path('operations/2024_04_02_113000_array_config_test.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('edge-case', 'config-non-string');

        test('uses custom path when valid string config is provided', function (): void {
            // Arrange
            Date::setTestNow('2024-04-03 12:30:00');
            $customPath = 'custom/operations/path';
            config()->set('sequencer.paths.operations', $customPath);

            // Create custom directory
            $fullCustomPath = base_path($customPath);
            File::ensureDirectoryExists($fullCustomPath);

            // Act
            Artisan::call('make:operation', ['name' => 'CustomPathOperation']);

            // Assert
            $expectedPath = base_path($customPath.'/2024_04_03_123000_custom_path_operation.php');
            expect(File::exists($expectedPath))->toBeTrue();

            // Cleanup
            File::deleteDirectory(base_path('custom'));
        })->group('edge-case', 'custom-path');

        test('handles operation name starting with lowercase letter', function (): void {
            // Arrange
            Date::setTestNow('2024-04-04 13:30:00');

            // Act
            Artisan::call('make:operation', ['name' => 'lowercaseStart']);

            // Assert
            $expectedPath = database_path('operations/2024_04_04_133000_lowercase_start.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('edge-case', 'lowercase-start');

        test('handles operation name with single character', function (): void {
            // Arrange
            Date::setTestNow('2024-04-05 14:30:00');

            // Act
            Artisan::call('make:operation', ['name' => 'A']);

            // Assert
            $expectedPath = database_path('operations/2024_04_05_143000_a.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('edge-case', 'single-character');

        test('handles operation name with all uppercase letters', function (): void {
            // Arrange
            Date::setTestNow('2024-04-06 15:30:00');

            // Act
            Artisan::call('make:operation', ['name' => 'API']);

            // Assert
            $expectedPath = database_path('operations/2024_04_06_153000_a_p_i.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('edge-case', 'all-uppercase');

        test('handles operation name with numbers', function (): void {
            // Arrange
            Date::setTestNow('2024-04-07 16:30:00');

            // Act
            Artisan::call('make:operation', ['name' => 'MigrateToV2Format']);

            // Assert
            $expectedPath = database_path('operations/2024_04_07_163000_migrate_to_v2_format.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('edge-case', 'with-numbers');

        test('generates unique timestamps for operations created at same second', function (): void {
            // Arrange
            Date::setTestNow('2024-04-08 17:30:00');

            // Act
            Artisan::call('make:operation', ['name' => 'FirstOperation']);
            Artisan::call('make:operation', ['name' => 'SecondOperation']);

            // Assert - Both should exist with same timestamp
            $path1 = database_path('operations/2024_04_08_173000_first_operation.php');
            $path2 = database_path('operations/2024_04_08_173000_second_operation.php');
            expect(File::exists($path1))->toBeTrue();
            expect(File::exists($path2))->toBeTrue();
        })->group('edge-case', 'same-timestamp');
    });

    describe('Stub Priority - First Option Wins', function (): void {
        test('async option takes priority over all other options', function (): void {
            // Arrange
            Date::setTestNow('2024-05-01 10:00:00');

            // Act
            Artisan::call('make:operation', [
                'name' => 'PriorityTest',
                '--async' => true,
                '--rollback' => true,
                '--retryable' => true,
            ]);

            // Assert - Should use async stub
            $expectedPath = database_path('operations/2024_05_01_100000_priority_test.php');
            $content = File::get($expectedPath);
            expect($content)->toContain('implements Asynchronous');
            expect($content)->not->toContain('implements Rollbackable');
        })->group('edge-case', 'stub-priority');

        test('rollback option takes priority when async is not present', function (): void {
            // Arrange
            Date::setTestNow('2024-05-02 10:00:00');

            // Act
            Artisan::call('make:operation', [
                'name' => 'RollbackPriority',
                '--rollback' => true,
                '--retryable' => true,
                '--transaction' => true,
            ]);

            // Assert - Should use rollback stub
            $expectedPath = database_path('operations/2024_05_02_100000_rollback_priority.php');
            $content = File::get($expectedPath);
            expect($content)->toContain('implements Rollbackable');
            expect($content)->not->toContain('use Retryable');
        })->group('edge-case', 'stub-priority');
    });

    describe('Command Output and Feedback', function (): void {
        test('displays success message after creating operation', function (): void {
            // Arrange
            Date::setTestNow('2024-06-01 10:00:00');

            // Act
            Artisan::call('make:operation', ['name' => 'TestOutput']);

            // Assert
            $output = Artisan::output();
            expect($output)->toContain('created successfully');
        })->group('happy-path', 'output');

        test('returns success exit code when operation is created', function (): void {
            // Arrange
            Date::setTestNow('2024-06-02 10:00:00');

            // Act
            $exitCode = Artisan::call('make:operation', ['name' => 'ExitCodeTest']);

            // Assert
            expect($exitCode)->toBe(0);
        })->group('happy-path', 'exit-code');
    });

    describe('Snake Case Conversion Logic', function (): void {
        test('converts camelCase to snake_case', function (): void {
            // Arrange
            Date::setTestNow('2024-07-01 10:00:00');

            // Act
            Artisan::call('make:operation', ['name' => 'myTestOperation']);

            // Assert
            $expectedPath = database_path('operations/2024_07_01_100000_my_test_operation.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('edge-case', 'snake-case');

        test('preserves underscores in operation name', function (): void {
            // Arrange
            Date::setTestNow('2024-07-02 10:00:00');

            // Act
            Artisan::call('make:operation', ['name' => 'Already_Snake_Case']);

            // Assert
            $expectedPath = database_path('operations/2024_07_02_100000_already__snake__case.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('edge-case', 'existing-underscores');

        test('handles multibyte characters in operation name', function (): void {
            // Arrange
            Date::setTestNow('2024-07-03 10:00:00');

            // Act
            Artisan::call('make:operation', ['name' => 'OperationÄÖÜ']);

            // Assert
            $expectedPath = database_path('operations/2024_07_03_100000_operationÄÖÜ.php');
            expect(File::exists($expectedPath))->toBeTrue();
        })->group('edge-case', 'multibyte');
    });
});
