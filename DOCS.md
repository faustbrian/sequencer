## Table of Contents

1. [Getting Started](#doc-docs-readme) (`docs/README.md`)
2. [Execution Guards](#doc-cookbooks-execution-guards) (`cookbooks/execution-guards.php`)
3. [Execution Strategies](#doc-cookbooks-execution-strategies) (`cookbooks/execution-strategies.php`)
4. [Execution Guards](#doc-docs-execution-guards) (`docs/execution-guards.md`)
5. [Execution Strategies](#doc-docs-execution-strategies) (`docs/execution-strategies.md`)
<a id="doc-docs-readme"></a>

Sequencer provides a flexible framework for executing sequences of operations with various execution strategies, guards, and error handling.

## Installation

```bash
composer require cline/sequencer
```

## Basic Usage

```php
use Cline\Sequencer\Sequencer;
use Cline\Sequencer\Operation;

// Define operations
$operations = [
    new Operation('validate', fn($data) => $this->validate($data)),
    new Operation('process', fn($data) => $this->process($data)),
    new Operation('notify', fn($data) => $this->notify($data)),
];

// Execute sequence
$sequencer = new Sequencer($operations);
$result = $sequencer->run($inputData);
```

## Operations

Operations are individual units of work:

```php
use Cline\Sequencer\Operation;

// Simple operation
$op = new Operation('name', function ($data) {
    // Do something
    return $result;
});

// Operation with dependencies
$op = new Operation('process', function ($data, $context) {
    $validated = $context->get('validate');
    return $this->process($validated);
});
```

## Execution Flow

```php
$sequencer = new Sequencer([
    new Operation('step1', fn() => 'one'),
    new Operation('step2', fn() => 'two'),
    new Operation('step3', fn() => 'three'),
]);

// Operations run in order
$result = $sequencer->run();
// Result contains output from all operations
```

## Error Handling

```php
$sequencer = new Sequencer($operations);

try {
    $result = $sequencer->run($data);
} catch (OperationFailedException $e) {
    echo $e->getOperation(); // Failed operation name
    echo $e->getMessage();   // Error message
}
```

## Next Steps

- [Execution Strategies](#doc-docs-execution-strategies) - Different execution patterns
- [Execution Guards](#doc-docs-execution-guards) - Conditional execution

<a id="doc-cookbooks-execution-guards"></a>

```php
<?php declare(strict_types=1);

use Cline\Sequencer\Contracts\ExecutionGuard;
use Cline\Sequencer\Guards\HostnameGuard;
use Cline\Sequencer\Guards\IpAddressGuard;

/**
 * Execution Guards Cookbook
 *
 * This cookbook demonstrates how to configure and use Sequencer's execution
 * guards to control WHERE operations are allowed to execute.
 *
 * Guards differ from strategies:
 * - Strategies control HOW/WHEN operations execute (command vs migration)
 * - Guards control WHETHER operations execute based on runtime conditions
 *
 * Use guards to restrict `php artisan sequencer:process` to specific servers,
 * preventing accidental execution in wrong environments.
 *
 * @see \Cline\Sequencer\Contracts\ExecutionGuard
 * @see \Cline\Sequencer\Guards\HostnameGuard
 * @see \Cline\Sequencer\Guards\IpAddressGuard
 * @see \Cline\Sequencer\Support\GuardManager
 */

// =============================================================================
// CONFIGURATION
// =============================================================================

/**
 * Example 1: Restrict execution to specific hostnames
 *
 * Only allow operations to run on servers named 'hel2' or 'hel3'.
 * Useful for multi-server deployments where only certain servers should
 * execute operations.
 *
 * config/sequencer.php:
 */
return [
    'guards' => [
        [
            'driver' => HostnameGuard::class,
            'config' => [
                'allowed' => ['hel2', 'hel3'],
            ],
        ],
    ],
];

/**
 * If this runs on server 'us3', execution is blocked with:
 * "Execution blocked: hostname 'us3' is not in allowed list [hel2, hel3]"
 */

// =============================================================================

/**
 * Example 2: Restrict execution to specific IP addresses
 *
 * Only allow operations to run from servers with specific IPs.
 * Supports exact matches and CIDR notation for network ranges.
 *
 * config/sequencer.php:
 */
return [
    'guards' => [
        [
            'driver' => IpAddressGuard::class,
            'config' => [
                'allowed' => [
                    '10.0.1.50',          // Exact IP
                    '192.168.1.0/24',     // CIDR range (all 192.168.1.x)
                    '2001:db8::/32',      // IPv6 CIDR range
                ],
            ],
        ],
    ],
];

// =============================================================================

/**
 * Example 3: Combine multiple guards
 *
 * All guards must pass for execution to proceed. Use multiple guards
 * for layered security.
 *
 * config/sequencer.php:
 */
return [
    'guards' => [
        [
            'driver' => HostnameGuard::class,
            'config' => [
                'allowed' => ['prod-1', 'prod-2'],
            ],
        ],
        [
            'driver' => IpAddressGuard::class,
            'config' => [
                'allowed' => ['10.0.0.0/8'],
            ],
        ],
    ],
];

/**
 * Execution only proceeds if:
 * 1. Hostname is 'prod-1' or 'prod-2' AND
 * 2. IP is in the 10.x.x.x range
 */

// =============================================================================
// BUILT-IN GUARDS
// =============================================================================

/**
 * HostnameGuard
 *
 * Checks the server's hostname via gethostname().
 *
 * Config options:
 * - allowed: array of allowed hostnames (exact match, case-sensitive)
 * - current_hostname: override detection (useful for testing)
 *
 * Empty allowed list = guard disabled (allows all)
 */
return [
    'guards' => [
        [
            'driver' => HostnameGuard::class,
            'config' => [
                'allowed' => ['web-1', 'web-2', 'worker-1'],
            ],
        ],
    ],
];

/**
 * IpAddressGuard
 *
 * Checks the server's IP address. Supports:
 * - Exact IPv4: '192.168.1.100'
 * - Exact IPv6: '2001:db8::1'
 * - CIDR IPv4: '10.0.0.0/8', '192.168.1.0/24'
 * - CIDR IPv6: '2001:db8::/32'
 *
 * Config options:
 * - allowed: array of IPs/CIDR ranges
 * - current_ip: override detection (useful for testing)
 *
 * Empty allowed list = guard disabled (allows all)
 */
return [
    'guards' => [
        [
            'driver' => IpAddressGuard::class,
            'config' => [
                'allowed' => [
                    '10.0.0.0/8',         // Private class A
                    '172.16.0.0/12',      // Private class B
                    '192.168.0.0/16',     // Private class C
                ],
            ],
        ],
    ],
];

// =============================================================================
// CUSTOM GUARDS
// =============================================================================

/**
 * Example 4: Create a custom environment guard
 *
 * Guards must implement the ExecutionGuard interface.
 */

final readonly class EnvironmentGuard implements ExecutionGuard
{
    /** @var array<string> */
    private array $allowedEnvironments;

    private string $currentEnvironment;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        /** @var array<string> $allowed */
        $allowed = $config['allowed'] ?? [];
        $this->allowedEnvironments = $allowed;

        /** @var string $current */
        $current = $config['current_environment'] ?? app()->environment();
        $this->currentEnvironment = $current;
    }

    public function shouldExecute(): bool
    {
        if ($this->allowedEnvironments === []) {
            return true;
        }

        return in_array($this->currentEnvironment, $this->allowedEnvironments, true);
    }

    public function reason(): string
    {
        return sprintf(
            "Execution blocked: environment '%s' is not in allowed list [%s]",
            $this->currentEnvironment,
            implode(', ', $this->allowedEnvironments),
        );
    }

    public function name(): string
    {
        return 'Environment Guard';
    }
}

/**
 * Register your custom guard the same way as built-in guards:
 */
return [
    'guards' => [
        [
            'driver' => EnvironmentGuard::class,
            'config' => [
                'allowed' => ['production', 'staging'],
            ],
        ],
    ],
];

// =============================================================================
// TESTING WITH GUARDS
// =============================================================================

/**
 * Example 5: Testing guard behavior
 */
use Cline\Sequencer\Exceptions\ExecutionGuardException;
use Cline\Sequencer\Support\GuardManager;

test('operations blocked on unauthorized hostname', function (): void {
    config([
        'sequencer.guards' => [
            [
                'driver' => HostnameGuard::class,
                'config' => [
                    'allowed' => ['allowed-server'],
                    'current_hostname' => 'blocked-server',
                ],
            ],
        ],
    ]);

    $manager = resolve(GuardManager::class);
    $manager->clearCache();

    expect(fn () => $manager->check())
        ->toThrow(ExecutionGuardException::class);
});

test('operations allowed on authorized hostname', function (): void {
    config([
        'sequencer.guards' => [
            [
                'driver' => HostnameGuard::class,
                'config' => [
                    'allowed' => ['allowed-server'],
                    'current_hostname' => 'allowed-server',
                ],
            ],
        ],
    ]);

    $manager = resolve(GuardManager::class);
    $manager->clearCache();

    expect($manager->isAllowed())->toBeTrue();
});

/**
 * Example 6: Checking guard status without throwing
 */
test('check guard status programmatically', function (): void {
    $manager = resolve(GuardManager::class);

    // Check if execution is allowed
    if ($manager->isAllowed()) {
        // Proceed with execution
    }

    // Get the blocking guard if any
    $blocker = $manager->getBlockingGuard();

    if ($blocker !== null) {
        // Log or handle the block
        logger()->warning('Execution blocked', [
            'guard' => $blocker->name(),
            'reason' => $blocker->reason(),
        ]);
    }
});

// =============================================================================
// GUARD EXCEPTION HANDLING
// =============================================================================

/**
 * Example 7: Handle guard exceptions in deployment scripts
 */
use Illuminate\Support\Facades\Artisan;

try {
    Artisan::call('sequencer:process');
} catch (ExecutionGuardException $e) {
    // $e->guard contains the blocking guard instance
    echo "Blocked by: {$e->guard->name()}\n";
    echo "Reason: {$e->guard->reason()}\n";

    // Exit with error code for CI/CD pipelines
    exit(1);
}

// =============================================================================
// COMMON PATTERNS
// =============================================================================

/**
 * Pattern 1: Production-only operations
 *
 * Ensure operations never accidentally run in development.
 */
return [
    'guards' => [
        [
            'driver' => HostnameGuard::class,
            'config' => [
                'allowed' => array_filter([
                    env('OPERATIONS_ALLOWED_HOST_1'),
                    env('OPERATIONS_ALLOWED_HOST_2'),
                ]),
            ],
        ],
    ],
];

/**
 * Pattern 2: Data center restrictions
 *
 * Only run on servers in specific network segments.
 */
return [
    'guards' => [
        [
            'driver' => IpAddressGuard::class,
            'config' => [
                'allowed' => [
                    '10.1.0.0/16',  // EU data center
                    '10.2.0.0/16',  // US data center
                ],
            ],
        ],
    ],
];

/**
 * Pattern 3: Disable guards in testing
 *
 * Empty guards array means all execution is allowed.
 */

// phpunit.xml or Pest.php
// <env name="SEQUENCER_GUARDS_ENABLED" value="false"/>

// config/sequencer.php
return [
    'guards' => env('SEQUENCER_GUARDS_ENABLED', true) ? [
        [
            'driver' => HostnameGuard::class,
            'config' => ['allowed' => ['prod-1']],
        ],
    ] : [],
];
```

<a id="doc-cookbooks-execution-strategies"></a>

```php
<?php declare(strict_types=1);

use Cline\Sequencer\Enums\ExecutionStrategy;

/**
 * Execution Strategies Cookbook
 *
 * This cookbook demonstrates how to configure and use Sequencer's execution
 * strategies to control when and how operations are executed.
 *
 * Sequencer provides two execution strategies via the ExecutionStrategy enum:
 *
 * 1. ExecutionStrategy::Command (default)
 *    - Operations run only via explicit `php artisan sequencer:process`
 *    - Maximum control over when operations execute
 *    - Best for teams wanting explicit deployment steps
 *
 * 2. ExecutionStrategy::Migration
 *    - Operations run automatically during `php artisan migrate`
 *    - Operations interleave with migrations by timestamp
 *    - Best for teams wanting a single deployment command
 *
 * @see \Cline\Sequencer\Enums\ExecutionStrategy
 * @see \Cline\Sequencer\Contracts\ExecutionStrategy
 * @see \Cline\Sequencer\Strategies\CommandStrategy
 * @see \Cline\Sequencer\Strategies\MigrationStrategy
 */

// =============================================================================
// CONFIGURATION
// =============================================================================

/**
 * Example 1: Using the default command strategy
 *
 * The command strategy is the default. Operations only execute when you
 * explicitly run the sequencer:process command.
 *
 * config/sequencer.php:
 */
return [
    'strategy' => ExecutionStrategy::Command->value, // or env('SEQUENCER_STRATEGY', 'command')
];

/**
 * Deployment workflow with command strategy:
 *
 * ```bash
 * php artisan migrate
 * php artisan sequencer:process
 * ```
 *
 * Or with isolation for multi-server deployments:
 *
 * ```bash
 * php artisan migrate
 * php artisan sequencer:process --isolated
 * ```
 */

// =============================================================================

/**
 * Example 2: Using the migration strategy
 *
 * The migration strategy hooks into Laravel's migration events. Operations
 * execute automatically during `php artisan migrate`.
 *
 * config/sequencer.php:
 */
return [
    'strategy' => ExecutionStrategy::Migration->value, // or env('SEQUENCER_STRATEGY', 'migration')

    'migration_strategy' => [
        // Execute operations even when no migrations are pending
        'run_on_no_pending_migrations' => true,

        // Only execute during these commands
        'allowed_commands' => [
            'migrate',
        ],

        // Force synchronous execution even for async operations
        'force_sync' => false,
    ],
];

/**
 * Deployment workflow with event strategy:
 *
 * ```bash
 * php artisan migrate  # Operations run automatically!
 * ```
 *
 * That's it! Operations execute during the migrate command.
 */

// =============================================================================
// HOW EVENT STRATEGY WORKS
// =============================================================================

/**
 * The event strategy listens to Laravel's migration events:
 *
 * 1. MigrationsStarted
 *    - Fired before any migrations run
 *    - Sequencer dispatches OperationsStarted event
 *
 * 2. MigrationEnded (for each migration)
 *    - Fired after each individual migration
 *    - Sequencer executes operations with timestamp <= migration timestamp
 *    - This interleaves operations with migrations chronologically
 *
 * 3. MigrationsEnded
 *    - Fired after all migrations complete
 *    - Sequencer executes any remaining operations
 *    - Sequencer dispatches OperationsEnded event
 *
 * 4. NoPendingMigrations
 *    - Fired when `migrate` runs but no migrations are pending
 *    - If `run_on_no_pending_migrations` is true, executes all pending operations
 *
 * Example timeline:
 *
 * Migrations:
 *   2024_01_01_000000_create_users_table
 *   2024_01_03_000000_create_posts_table
 *
 * Operations:
 *   2024_01_02_000000_seed_admin_user.php
 *   2024_01_04_000000_send_welcome_emails.php
 *
 * Execution order:
 *   1. create_users_table (migration)
 *   2. seed_admin_user (operation - timestamp is between migrations)
 *   3. create_posts_table (migration)
 *   4. send_welcome_emails (operation - timestamp is after migrations)
 */

// =============================================================================
// CONFIGURATION OPTIONS
// =============================================================================

/**
 * Example 3: Configure which commands trigger operations
 *
 * By default, only `migrate` triggers operations. You can add other commands
 * if needed, but be careful with `migrate:fresh` as it drops all tables first.
 */
return [
    'migration_strategy' => [
        'allowed_commands' => [
            'migrate',
            'migrate:fresh', // Be careful! Tables are dropped first
        ],
    ],
];

/**
 * Example 4: Disable execution when no migrations are pending
 *
 * By default, operations execute even when `migrate` finds no pending
 * migrations. Set this to false if you only want operations to run when
 * there are actual migrations.
 */
return [
    'migration_strategy' => [
        'run_on_no_pending_migrations' => false,
    ],
];

/**
 * Example 5: Force synchronous execution
 *
 * Normally, operations implementing the Asynchronous interface are
 * dispatched to the queue during migrate. Set `force_sync` to true
 * to ensure all operations complete before migrate exits.
 */
return [
    'migration_strategy' => [
        'force_sync' => true,
    ],
];

// =============================================================================
// CHOOSING A STRATEGY
// =============================================================================

/**
 * Use ExecutionStrategy::Command when:
 *
 * - You need maximum control over operation execution
 * - You want to run operations independently of migrations
 * - You need tag filtering (--tags option)
 * - You're using complex orchestration strategies
 * - You need isolation locking (--isolated option)
 *
 * Use ExecutionStrategy::Migration when:
 *
 * - You want a simple single-command deployment
 * - Operations and migrations should always run together
 * - You trust timestamp-based ordering
 * - Your operations are idempotent (safe to re-run)
 * - You want automatic interleaving with migrations
 */

// =============================================================================
// SWITCHING STRATEGIES
// =============================================================================

/**
 * Switching from command to migration strategy:
 *
 * 1. Update config/sequencer.php:
 *    'strategy' => ExecutionStrategy::Migration->value,
 *
 * 2. Update deployment scripts:
 *    Before: php artisan migrate && php artisan sequencer:process
 *    After:  php artisan migrate
 *
 * 3. Consider if async operations need force_sync:
 *    'force_sync' => true,  // Ensure all complete before deploy finishes
 *
 * Switching from migration to command strategy:
 *
 * 1. Update config/sequencer.php:
 *    'strategy' => ExecutionStrategy::Command->value,
 *
 * 2. Update deployment scripts:
 *    Before: php artisan migrate
 *    After:  php artisan migrate && php artisan sequencer:process
 */

// =============================================================================
// TESTING STRATEGIES
// =============================================================================

/**
 * Example 6: Testing with command strategy
 */
use Cline\Sequencer\Facades\Sequencer;

test('operations execute via explicit command', function (): void {
    // Command strategy is default - operations only run when called
    Sequencer::execute('2024_01_01_000000_my_operation');

    expect(/* operation completed */)->toBeTrue();
});

/**
 * Example 7: Testing with event strategy
 */
use Illuminate\Support\Facades\Artisan;

test('operations execute during migrate', function (): void {
    config(['sequencer.strategy' => 'migration']);

    // Create operation file...

    Artisan::call('migrate');

    expect(/* operation completed */)->toBeTrue();
});

// =============================================================================
// ENVIRONMENT-BASED CONFIGURATION
// =============================================================================

/**
 * Example 8: Different strategies per environment
 *
 * .env.local:
 * SEQUENCER_STRATEGY=command
 *
 * .env.production:
 * SEQUENCER_STRATEGY=event
 *
 * This allows developers to run operations manually in local development
 * while production deployments run everything with a single command.
 */

// =============================================================================
// ROLLBACK CONSIDERATIONS
// =============================================================================

/**
 * Important: Rollback behavior differs between strategies
 *
 * Command Strategy:
 * - Full rollback support
 * - If an operation fails, all previous operations implementing Rollbackable
 *   are rolled back in reverse order
 *
 * Event Strategy:
 * - Operations only rollback (not migrations)
 * - When an operation fails during `migrate`, the migration that triggered
 *   it has already committed
 * - Only operations rolled back, not the preceding migration
 *
 * If you need transactional rollback of both migrations and operations,
 * use the command strategy with TransactionalBatchOrchestrator.
 */
```

<a id="doc-docs-execution-guards"></a>

Control operation execution with guards and conditions.

## Basic Guards

```php
use Cline\Sequencer\Operation;
use Cline\Sequencer\Guards\Guard;

$operation = new Operation('premium-feature', function ($data) {
    return $this->premiumProcess($data);
});

// Add guard
$operation->guard(new Guard(fn($ctx) => $ctx->user()->isPremium()));

// Operation only runs if guard passes
$sequencer->run($data);
```

## Built-in Guards

### Condition Guard

```php
use Cline\Sequencer\Guards\ConditionGuard;

$guard = new ConditionGuard(fn($context) => $context->get('enabled'));

$operation = new Operation('optional-step', $callback);
$operation->guard($guard);
```

### Authentication Guard

```php
use Cline\Sequencer\Guards\AuthGuard;

$guard = new AuthGuard();  // Checks if user is authenticated

$operation = new Operation('protected', $callback);
$operation->guard($guard);
```

### Permission Guard

```php
use Cline\Sequencer\Guards\PermissionGuard;

$guard = new PermissionGuard('admin');

$operation = new Operation('admin-only', $callback);
$operation->guard($guard);
```

### Rate Limit Guard

```php
use Cline\Sequencer\Guards\RateLimitGuard;

$guard = new RateLimitGuard(
    key: 'api-calls',
    maxAttempts: 100,
    perMinutes: 1
);

$operation = new Operation('api-call', $callback);
$operation->guard($guard);
```

## Multiple Guards

```php
$operation = new Operation('restricted', $callback);

// All guards must pass (AND)
$operation->guard(new AuthGuard());
$operation->guard(new PermissionGuard('editor'));
$operation->guard(new RateLimitGuard('edits', 10, 1));
```

## Guard Modes

```php
use Cline\Sequencer\Guards\GuardMode;

// AND mode (default) - all must pass
$operation->guardMode(GuardMode::AND);

// OR mode - any must pass
$operation->guardMode(GuardMode::OR);

// Example
$operation = new Operation('access', $callback);
$operation->guardMode(GuardMode::OR);
$operation->guard(new PermissionGuard('admin'));
$operation->guard(new PermissionGuard('editor'));
// Passes if user is admin OR editor
```

## Custom Guards

```php
use Cline\Sequencer\Contracts\Guard;
use Cline\Sequencer\Context;

class BusinessHoursGuard implements Guard
{
    public function allows(Context $context): bool
    {
        $hour = now()->hour;
        return $hour >= 9 && $hour < 17;
    }

    public function message(): string
    {
        return 'This operation is only available during business hours.';
    }
}

$operation = new Operation('business', $callback);
$operation->guard(new BusinessHoursGuard());
```

## Guard Callbacks

```php
$operation = new Operation('feature', $callback);

// On guard failure
$operation->onGuardFailed(function ($guard, $context) {
    logger()->warning('Guard failed', [
        'guard' => get_class($guard),
        'message' => $guard->message(),
    ]);
});

// Skip vs fail
$operation->whenGuardFails('skip');  // Skip operation silently
$operation->whenGuardFails('fail');  // Throw exception
```

## Conditional Operations

```php
use Cline\Sequencer\Sequencer;

$sequencer = new Sequencer([
    new Operation('always', fn() => 'runs'),

    Operation::when(
        condition: fn($ctx) => $ctx->get('feature_enabled'),
        operation: new Operation('feature', fn() => 'optional')
    ),

    Operation::unless(
        condition: fn($ctx) => $ctx->get('skip_notification'),
        operation: new Operation('notify', fn() => 'notified')
    ),
]);
```

## Guard Groups

```php
use Cline\Sequencer\Guards\GuardGroup;

$adminGuards = new GuardGroup([
    new AuthGuard(),
    new PermissionGuard('admin'),
]);

$operations = [
    new Operation('admin1', $callback1)->guard($adminGuards),
    new Operation('admin2', $callback2)->guard($adminGuards),
    new Operation('admin3', $callback3)->guard($adminGuards),
];
```

<a id="doc-docs-execution-strategies"></a>

Different strategies for executing operation sequences.

## Sequential Strategy

Execute operations one after another:

```php
use Cline\Sequencer\Sequencer;
use Cline\Sequencer\Strategies\SequentialStrategy;

$sequencer = new Sequencer($operations);
$sequencer->setStrategy(new SequentialStrategy());

// Operations run in order: 1 → 2 → 3
$result = $sequencer->run($data);
```

## Parallel Strategy

Execute independent operations concurrently:

```php
use Cline\Sequencer\Strategies\ParallelStrategy;

$sequencer = new Sequencer($operations);
$sequencer->setStrategy(new ParallelStrategy());

// Operations run simultaneously
$result = $sequencer->run($data);
```

## Pipeline Strategy

Pass output of each operation to the next:

```php
use Cline\Sequencer\Strategies\PipelineStrategy;

$operations = [
    new Operation('double', fn($n) => $n * 2),
    new Operation('add10', fn($n) => $n + 10),
    new Operation('square', fn($n) => $n * $n),
];

$sequencer = new Sequencer($operations);
$sequencer->setStrategy(new PipelineStrategy());

// 5 → 10 → 20 → 400
$result = $sequencer->run(5);
```

## Conditional Strategy

Execute based on conditions:

```php
use Cline\Sequencer\Strategies\ConditionalStrategy;

$strategy = new ConditionalStrategy([
    'validate' => fn($data) => !empty($data),
    'premium' => fn($data) => $data['user']->isPremium(),
]);

$sequencer = new Sequencer($operations);
$sequencer->setStrategy($strategy);

// Only runs operations where condition is true
$result = $sequencer->run($data);
```

## Retry Strategy

Automatically retry failed operations:

```php
use Cline\Sequencer\Strategies\RetryStrategy;

$strategy = new RetryStrategy(
    maxAttempts: 3,
    delay: 1000, // milliseconds
    backoff: 2.0 // exponential backoff multiplier
);

$sequencer = new Sequencer($operations);
$sequencer->setStrategy($strategy);

// Retries failed operations up to 3 times
$result = $sequencer->run($data);
```

## Transaction Strategy

All-or-nothing execution with rollback:

```php
use Cline\Sequencer\Strategies\TransactionStrategy;

$operations = [
    new Operation('create', fn($d) => $this->create($d), rollback: fn($d) => $this->delete($d)),
    new Operation('update', fn($d) => $this->update($d), rollback: fn($d) => $this->revert($d)),
    new Operation('notify', fn($d) => $this->notify($d)),
];

$sequencer = new Sequencer($operations);
$sequencer->setStrategy(new TransactionStrategy());

// If any operation fails, previous operations are rolled back
$result = $sequencer->run($data);
```

## Custom Strategy

```php
use Cline\Sequencer\Contracts\ExecutionStrategy;
use Cline\Sequencer\Context;

class CustomStrategy implements ExecutionStrategy
{
    public function execute(array $operations, Context $context): mixed
    {
        $results = [];

        foreach ($operations as $operation) {
            // Custom execution logic
            if ($this->shouldRun($operation, $context)) {
                $results[$operation->name()] = $operation->run($context);
            }
        }

        return $results;
    }

    private function shouldRun(Operation $operation, Context $context): bool
    {
        // Custom logic
        return true;
    }
}

$sequencer = new Sequencer($operations);
$sequencer->setStrategy(new CustomStrategy());
```

## Combining Strategies

```php
use Cline\Sequencer\Strategies\CompositeStrategy;

$strategy = new CompositeStrategy([
    new RetryStrategy(maxAttempts: 3),
    new TransactionStrategy(),
]);

$sequencer = new Sequencer($operations);
$sequencer->setStrategy($strategy);

// Retries with transaction rollback on final failure
$result = $sequencer->run($data);
```
