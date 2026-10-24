[![GitHub Workflow Status][ico-tests]][link-tests]
[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

------

A powerful Laravel package that orchestrates sequential execution of migrations and operations. Ensures database changes and business logic run in chronological order during deployments, preventing conflicts and maintaining data integrity.

## Requirements

> **Requires [PHP 8.5+](https://php.net/releases/)** and Laravel 11+

## Installation

```bash
composer require cline/sequencer
```

## Quick Start

Create an operation, implement the `handle()` method, and execute sequentially with `php artisan sequencer:process`. See [Getting Started](DOCS.md#doc-docs-readme) for detailed examples.

## Deferred application work

`sequencer:deferred-process --limit=100` processes due intents stored by
`Sequencer::defer()`. It claims one record immediately before execution. A lost
reservation becomes eligible again after `sequencer.deferred.retry_after_seconds`
(default 300, or `SEQUENCER_DEFERRED_RETRY_AFTER_SECONDS`). Set this above the
longest handler execution time before enabling a processor. Reservations count
as attempts, including interrupted attempts; an abandoned final attempt becomes
`Failed` without executing again. Existing rows need no schema migration. Drain
old-version deferred processors before starting upgraded consumers: only upgraded
workers enforce replacement-claim ownership.

Recovery is **at least once**, not exactly once: a handler can perform an external
effect before its worker disappears. Handlers must tolerate replay. Without runtime containment, expiry does
not stop an expired worker, but its later completion/failure
cannot overwrite a replacement claim's state. Each invocation visits a record
at most once, even when retry delay is zero; it leaves fresh reservations and terminal rows alone.
Candidate keys are selected at invocation start; each reservation is rechecked
and persisted just before its handler runs. Records becoming due or newly
created during execution wait until the next invocation. A vetoed model save
does not execute a handler or count as a completed reservation.

To consume only selected work, repeat the option with exact stored identifiers:

```bash
php artisan sequencer:deferred-process --operation=registration_credentials --operation=registered_user_shipping_methods
```

Unselected rows remain unchanged, including expired reservations. Without an
option the command still processes all eligible identifiers. Programmatic
`DeferredOperationProcessor::processDue(100, ['registration_credentials'])` uses
the same selection; `null` means unrestricted and `[]` means no work. Select
the exact persisted alias (including whitespace), not its mapped class name.
Schedule restricted commands separately when environments require different work.
Exact selection supports SQLite, MySQL/MariaDB, PostgreSQL and SQL Server,
independently of their default collation. A custom unsupported database driver
rejects restricted consumption instead of broadening it; unrestricted processing
is unchanged.

Set `sequencer.deferred.erase_payload_for` to a list of exact stored identifiers
whose inputs should not remain in history. The processor replaces their payload
with `[]` on `Completed` (including a handler's successful no-op) or `Failed`.
Retryable attempts retain inputs; other identifiers retain their normal payloads.
This does not scrub handler error messages, logs, existing terminal history or
caller-written `Cancelled` records. Account erasure/cancellation must clear those
records at the application lifecycle boundary.

### Optional bounded CLI runtime

Enable `sequencer.deferred.runtime.enabled` (or
`SEQUENCER_DEFERRED_RUNTIME_ENABLED=true`) to bound the **whole command run**,
including blocking handler I/O. The default remains disabled, so existing
programmatic calls and the command's concrete type and constructor are unchanged.
When enabled, invoke the real external `php artisan sequencer:deferred-process`
CLI; direct `handle()`, synthetic inputs and nested `Artisan::call()` are rejected
before any claim. Repeatable `--operation`, `--limit` and global options are
preserved across supervision.

This opt-in mode requires Unix CLI PHP with PCNTL and POSIX, plus an executable
**GNU coreutils `timeout`** (default `/usr/bin/timeout`). These are not new Composer
platform requirements when the feature is disabled. Install the prerequisites on
every consumer host/image and drain existing unbounded processors before enabling
it. Publish/rebuild cached configuration as part of that rollout.

| Runtime setting | Environment variable | Default |
| --- | --- | --- |
| `timeout_seconds` | `SEQUENCER_DEFERRED_RUNTIME_TIMEOUT_SECONDS` | 240 |
| `kill_after_seconds` | `SEQUENCER_DEFERRED_RUNTIME_KILL_AFTER_SECONDS` | 10 |
| `recovery_margin_seconds` | `SEQUENCER_DEFERRED_RUNTIME_RECOVERY_MARGIN_SECONDS` | 50 |
| `timeout_binary` | `SEQUENCER_DEFERRED_RUNTIME_TIMEOUT_BINARY` | `/usr/bin/timeout` |

All three durations must be positive integers and their sum must not exceed
`deferred.retry_after_seconds`. With the defaults, expiry must be at least 300
seconds. The supervisor sends TERM at the timeout and KILL after the grace period;
an independent OS alarm also stops the PHP worker if its supervisor disappears.
The budget is not renewed between handlers. Interrupted claims retain their
existing retry/expiry behavior, and unclaimed work waits for another invocation.

This does not bound direct `DeferredOperationProcessor::processDue()` calls or
queued operation jobs. It also cannot undo an external effect accepted before a
worker dies: replay is still at least once, so handlers must remain replay-safe.

## Documentation

- **[Getting Started](DOCS.md#doc-docs-readme)** - Installation, configuration, and first operation
- **[Basic Usage](DOCS.md#doc-docs-readme)** - Core operations and sequential execution
- **[Monitoring & Status](DOCS.md)** - Check pending, completed, and failed operations
- **[Orchestration Strategies](DOCS.md)** - Sequential, batch, transactional, dependency-based, and scheduled execution
- **[Advanced Operations](DOCS.md)** - Retries, timeouts, batching, chaining, middleware, unique operations, encryption, tags, lifecycle hooks
- **[Programmatic Usage](DOCS.md)** - Facade API, conditional execution, status checks, error handling
- **[Events](DOCS.md)** - Operation lifecycle events for logging, monitoring, and custom workflows
- **[Rollback Support](DOCS.md)** - Automatic rollback on failures
- **[Dependencies](DOCS.md)** - Explicit operation ordering
- **[Conditional Execution](DOCS.md)** - Runtime execution conditions
- **[Skip Operations](DOCS.md)** - Skip operations at runtime with SkipOperationException
- **[Advanced Usage](DOCS.md)** - Transactions, async operations, observability

## Key Features

- ✅ **Orchestration Strategies** - Sequential, batch, transactional, dependency-based, scheduled execution
- ✅ **Flexible Execution** - Switch strategies via config or fluent API
- ✅ **Dependency Resolution** - Explicit operation dependencies with topological sorting
- ✅ **Conditional Execution** - Skip operations based on runtime conditions
- ✅ **Async Operations** - Queue operations for background processing
- ✅ **Retry Mechanisms** - Automatic retries with configurable backoff
- ✅ **Rollback Support** - Automatic rollback of executed operations when failures occur
- ✅ **Encryption** - Automatic payload encryption for sensitive operations
- ✅ **Lifecycle Hooks** - Before/after/failed callbacks for operation execution
- ✅ **Monitoring** - Pulse/Telescope integration with lifecycle events
- ✅ **Testing Helpers** - Comprehensive testing support
- ✅ **Atomic Locking** - Prevent concurrent execution in multi-server environments

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please use the [GitHub security reporting form][link-security] rather than the issue queue.

## Credits

- [Brian Faust][link-maintainer]
- [All Contributors][link-contributors]

## License

The MIT License. Please see [License File](LICENSE.md) for more information.

[ico-tests]: https://github.com/faustbrian/sequencer/actions/workflows/quality-assurance.yaml/badge.svg
[ico-version]: https://img.shields.io/packagist/v/cline/sequencer.svg
[ico-license]: https://img.shields.io/badge/License-MIT-green.svg
[ico-downloads]: https://img.shields.io/packagist/dt/cline/sequencer.svg

[link-tests]: https://github.com/faustbrian/sequencer/actions
[link-packagist]: https://packagist.org/packages/cline/sequencer
[link-downloads]: https://packagist.org/packages/cline/sequencer
[link-security]: https://github.com/faustbrian/sequencer/security
[link-maintainer]: https://github.com/faustbrian
[link-contributors]: ../../contributors
