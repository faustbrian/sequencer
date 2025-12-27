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
