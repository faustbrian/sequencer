---
title: Getting Started
description: Install and start using Sequencer for operation execution in PHP.
---

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
