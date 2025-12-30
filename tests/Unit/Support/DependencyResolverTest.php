<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Exceptions\CircularDependencyException;
use Cline\Sequencer\Support\DependencyResolver;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Operations\BasicOperation;
use Tests\Fixtures\Operations\CircularOperationA;
use Tests\Fixtures\Operations\CircularOperationB;
use Tests\Fixtures\Operations\DependentOperation;
use Tests\Fixtures\Operations\MigrationDependentOperation;
use Tests\Fixtures\Operations\MultiDependencyOperation;
use Tests\Fixtures\Operations\OperationA;
use Tests\Fixtures\Operations\OperationB;
use Tests\Fixtures\Operations\OperationC;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Arrange
    $this->migrator = resolve(Migrator::class);
    $this->resolver = new DependencyResolver($this->migrator);
});
describe('dependenciesSatisfied()', function (): void {
    test('returns true for operations without dependencies', function (): void {
        // Arrange
        $operation = new BasicOperation();

        // Act
        $result = $this->resolver->dependenciesSatisfied($operation);

        // Assert
        expect($result)->toBeTrue();
    });

    test('returns true when all dependencies are satisfied', function (): void {
        // Arrange
        OperationModel::factory()->create([
            'name' => BasicOperation::class,
            'completed_at' => now(),
        ]);
        $operation = new DependentOperation();

        // Act
        $result = $this->resolver->dependenciesSatisfied($operation);

        // Assert
        expect($result)->toBeTrue();
    });

    test('returns true when migration dependencies are satisfied', function (): void {
        // Arrange
        $repository = $this->migrator->getRepository();
        $repository->log('2024_01_01_000000_create_users_table', 1);

        $operation = new MigrationDependentOperation();

        // Act
        $result = $this->resolver->dependenciesSatisfied($operation);

        // Assert
        expect($result)->toBeTrue();
    });

    test('returns false when dependencies are not satisfied', function (): void {
        // Arrange
        $operation = new DependentOperation();

        // Act
        $result = $this->resolver->dependenciesSatisfied($operation);

        // Assert
        expect($result)->toBeFalse();
    });

    test('returns false when migration dependency is not run', function (): void {
        // Arrange
        $operation = new MigrationDependentOperation();

        // Act
        $result = $this->resolver->dependenciesSatisfied($operation);

        // Assert
        expect($result)->toBeFalse();
    });
});

describe('getUnsatisfiedDependencies()', function (): void {
    test('returns empty array for operations without dependencies', function (): void {
        // Arrange
        $operation = new BasicOperation();

        // Act
        $result = $this->resolver->getUnsatisfiedDependencies($operation);

        // Assert
        expect($result)->toBe([]);
    });

    test('returns list of unsatisfied dependencies', function (): void {
        // Arrange
        $operation = new MultiDependencyOperation();

        // Act
        $result = $this->resolver->getUnsatisfiedDependencies($operation);

        // Assert
        expect($result)->toHaveCount(3);
        expect($result)->toContain(OperationA::class);
        expect($result)->toContain(OperationB::class);
        expect($result)->toContain('2024_01_01_000000_create_users_table');
    });

    test('returns partially unsatisfied dependencies', function (): void {
        // Arrange
        OperationModel::factory()->create([
            'name' => OperationA::class,
            'completed_at' => now(),
        ]);
        $operation = new MultiDependencyOperation();

        // Act
        $result = $this->resolver->getUnsatisfiedDependencies($operation);

        // Assert
        expect($result)->toHaveCount(2);
        expect($result)->toContain(OperationB::class);
        expect($result)->toContain('2024_01_01_000000_create_users_table');
        expect($result)->not->toContain(OperationA::class);
    });
});

describe('sortByDependencies()', function (): void {
    test('sorts operations in correct dependency order', function (): void {
        // Arrange
        $tasks = [
            [
                'type' => 'operation',
                'timestamp' => '2024-01-02 00:00:00',
                'data' => ['class' => DependentOperation::class],
                'operation' => new DependentOperation(),
            ],
            [
                'type' => 'operation',
                'timestamp' => '2024-01-01 00:00:00',
                'data' => ['class' => BasicOperation::class],
                'operation' => new BasicOperation(),
            ],
        ];

        // Act
        $result = $this->resolver->sortByDependencies($tasks);

        // Assert
        expect($result[0]['data']['class'])->toBe(BasicOperation::class);
        expect($result[1]['data']['class'])->toBe(DependentOperation::class);
    });

    test('sorts parallel operations without dependencies', function (): void {
        // Arrange
        $tasks = [
            [
                'type' => 'operation',
                'timestamp' => '2024-01-01 00:00:00',
                'data' => ['class' => BasicOperation::class],
                'operation' => new BasicOperation(),
            ],
            [
                'type' => 'operation',
                'timestamp' => '2024-01-02 00:00:00',
                'data' => ['class' => OperationA::class],
                'operation' => new OperationA(),
            ],
        ];

        // Act
        $result = $this->resolver->sortByDependencies($tasks);

        // Assert
        expect($result)->toHaveCount(2);
        expect($result[0]['data']['class'])->toBe(BasicOperation::class);
        expect($result[1]['data']['class'])->toBe(OperationA::class);
    });

    test('sorts mixed migrations and operations', function (): void {
        // Arrange
        $tasks = [
            [
                'type' => 'operation',
                'timestamp' => '2024-01-02 00:00:00',
                'data' => ['class' => MigrationDependentOperation::class],
                'operation' => new MigrationDependentOperation(),
            ],
            [
                'type' => 'migration',
                'timestamp' => '2024-01-01 00:00:00',
                'data' => ['name' => '2024_01_01_000000_create_users_table'],
            ],
        ];

        // Act
        $result = $this->resolver->sortByDependencies($tasks);

        // Assert
        expect($result[0]['type'])->toBe('migration');
        expect($result[1]['type'])->toBe('operation');
    });

    test('throws exception for circular dependencies', function (): void {
        // Arrange
        $tasks = [
            [
                'type' => 'operation',
                'timestamp' => '2024-01-01 00:00:00',
                'data' => ['class' => CircularOperationA::class],
                'operation' => new CircularOperationA(),
            ],
            [
                'type' => 'operation',
                'timestamp' => '2024-01-02 00:00:00',
                'data' => ['class' => CircularOperationB::class],
                'operation' => new CircularOperationB(),
            ],
        ];

        // Act & Assert
        $this->expectException(CircularDependencyException::class);
        $this->resolver->sortByDependencies($tasks);
    });

    test('handles empty task array', function (): void {
        // Arrange
        $tasks = [];

        // Act
        $result = $this->resolver->sortByDependencies($tasks);

        // Assert
        expect($result)->toBe([]);
    });

    test('handles single operation with no dependencies', function (): void {
        // Arrange
        $tasks = [
            [
                'type' => 'operation',
                'timestamp' => '2024-01-01 00:00:00',
                'data' => ['class' => BasicOperation::class],
                'operation' => new BasicOperation(),
            ],
        ];

        // Act
        $result = $this->resolver->sortByDependencies($tasks);

        // Assert
        expect($result)->toHaveCount(1);
        expect($result[0]['data']['class'])->toBe(BasicOperation::class);
    });

    test('handles complex dependency chain', function (): void {
        // Arrange
        $tasks = [
            [
                'type' => 'operation',
                'timestamp' => '2024-01-03 00:00:00',
                'data' => ['class' => OperationC::class],
                'operation' => new OperationC(),
            ],
            [
                'type' => 'operation',
                'timestamp' => '2024-01-02 00:00:00',
                'data' => ['class' => OperationB::class],
                'operation' => new OperationB(),
            ],
            [
                'type' => 'operation',
                'timestamp' => '2024-01-01 00:00:00',
                'data' => ['class' => OperationA::class],
                'operation' => new OperationA(),
            ],
        ];

        // Act
        $result = $this->resolver->sortByDependencies($tasks);

        // Assert
        expect($result[0]['data']['class'])->toBe(OperationA::class);
        expect($result[1]['data']['class'])->toBe(OperationB::class);
        expect($result[2]['data']['class'])->toBe(OperationC::class);
    });

    test('lazy loads operation instance when not pre instantiated', function (): void {
        // Arrange: Create a temp file with anonymous class
        $tempPath = storage_path('framework/testing/operations_'.uniqid());
        File::makeDirectory($tempPath, 0o755, true);

        $operationFile = $tempPath.'/2024_01_01_000000_basic_operation.php';
        File::put(
            $operationFile,
            <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP
        );

        $tasks = [
            [
                'type' => 'operation',
                'timestamp' => '2024-01-01 00:00:00',
                'data' => ['class' => $operationFile],
                // Note: No 'operation' key - should trigger lazy loading via require
            ],
        ];

        // Act
        $result = $this->resolver->sortByDependencies($tasks);

        // Assert
        expect($result)->toHaveCount(1);
        expect($result[0]['data']['class'])->toBe($operationFile);
        expect($result[0])->toHaveKey('operation');
        expect($result[0]['operation'])->toBeInstanceOf(Cline\Sequencer\Contracts\Operation::class);

        // Cleanup
        File::deleteDirectory($tempPath);
    });

    test('handles dependency not in sorted array during iteration', function (): void {
        // Arrange: Create scenario where areDependenciesInSorted returns false
        // OperationB depends on OperationA, OperationC depends on OperationB
        // When checking OperationC, OperationB is not yet sorted (still waiting for OperationA)
        $tasks = [
            [
                'type' => 'operation',
                'timestamp' => '2024-01-03 00:00:00',
                'data' => ['class' => OperationC::class],
                'operation' => new OperationC(), // depends on OperationB
            ],
            [
                'type' => 'operation',
                'timestamp' => '2024-01-02 00:00:00',
                'data' => ['class' => OperationB::class],
                'operation' => new OperationB(), // depends on OperationA
            ],
            [
                'type' => 'operation',
                'timestamp' => '2024-01-01 00:00:00',
                'data' => ['class' => OperationA::class],
                'operation' => new OperationA(), // no dependencies
            ],
        ];

        // Act
        $result = $this->resolver->sortByDependencies($tasks);

        // Assert
        // During first iteration:
        // - OperationA sorted (no deps)
        // - OperationB cannot sort (OperationA not yet in sortedNames array mapping)
        // - OperationC cannot sort (OperationB not sorted)
        // This exercises the false branch of array_all at line 256
        expect($result)->toHaveCount(3);
        expect($result[0]['data']['class'])->toBe(OperationA::class);
        expect($result[1]['data']['class'])->toBe(OperationB::class);
        expect($result[2]['data']['class'])->toBe(OperationC::class);
    });
});
