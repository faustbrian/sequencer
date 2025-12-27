---
title: Execution Strategies
description: Different strategies for executing operation sequences.
---

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
