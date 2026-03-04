<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Operation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Models\TestModelWithSequencerPrimaryKey;
use Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

function createTestModel(): Model
{
    return new TestModelWithSequencerPrimaryKey();
}

function simulateCreatingEvent(Model $model): void
{
    $model->triggerCreating();
}

function createOperationFile(string $filename, string $path): void
{
    $filepath = $path.'/'.$filename;

    File::put($filepath, '<?php // Test operation file');
}

/**
 * Global registry for test operations.
 * Since anonymous classes can't be serialized, we store them in a global
 * registry and reference them by ID in the operation files.
 */
$GLOBALS['test_operations'] ??= [];

/**
 * Helper to create a temporary operation file for testing ExecuteOperation.
 * Returns the file path that can be passed to ExecuteOperation constructor.
 *
 * This is needed because ExecuteOperation now accepts operation file paths
 * instead of operation objects, to support serialization of anonymous class operations.
 *
 * @param  Operation   $operation The operation instance to wrap in a file
 * @param  null|string $name      Optional custom file name
 * @return string      The absolute path to the created operation file
 */
function wrapOperationInFile(Operation $operation, ?string $name = null): string
{
    static $counter = 0;
    ++$counter;

    $operationId = sprintf('test_op_%s_', $counter).spl_object_id($operation);
    $GLOBALS['test_operations'][$operationId] = $operation;

    $fileName = $name ?? sprintf('test_operation_%s.php', bin2hex(random_bytes(8)));

    // Use sys_get_temp_dir() instead of database_path('operations') to avoid
    // conflicts with MakeOperationCommandTest which calls File::cleanDirectory()
    // on the database/operations directory between tests when run in parallel.
    $tempDir = sys_get_temp_dir().'/sequencer_test_operations';

    // Use force=true so makeDirectory uses @mkdir internally, which is the
    // correct POSIX behaviour: creating an already-existing directory is not
    // an error, so the warning should not be raised in the first place.
    File::makeDirectory($tempDir, 0o755, true, true);

    $filePath = $tempDir.'/'.$fileName;

    // Create a file that returns the operation from the global registry
    file_put_contents(
        $filePath,
        <<<PHP
<?php

return \$GLOBALS['test_operations']['{$operationId}'];
PHP
    );

    return $filePath;
}
