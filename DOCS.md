## Table of Contents

1. Getting Started (`docs/README.md`)
2. Execution Guards (`docs/execution-guards.md`)
3. Execution Strategies (`docs/execution-strategies.md`)
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

- [Execution Strategies](./execution-strategies.md) - Different execution patterns
- [Execution Guards](./execution-guards.md) - Conditional execution

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
