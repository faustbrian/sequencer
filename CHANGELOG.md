# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.3.0] - 2026-10-24

### Added
- Opt-in whole-run containment for `sequencer:deferred-process`, using GNU
  timeout with an independent PHP OS watchdog. Blocking handlers stop before
  claim recovery; aliases, limits and global CLI options are preserved.
- Configurable timeout, kill grace, recovery margin and timeout binary. Runtime
  prerequisites are checked only when containment is enabled; existing default
  programmatic use and the concrete command API remain unchanged.

### Migration
- Before enabling containment, install Unix CLI PCNTL/POSIX and GNU coreutils
  timeout on each consumer, drain old processors, and rebuild configuration.
  Positive timeout, grace and margin must fit within reservation expiry (the
  defaults require at least 300 seconds). Enabled mode requires an external CLI,
  not nested/programmatic command calls. Recovery remains at least once.

## [3.2.0] - 2026-10-23

### Added
- Allow deferred processors to restrict consumption to selected stored operation
  identifiers with `processDue(..., operations: [...])` or repeatable `--operation`.
- Add opt-in `deferred.erase_payload_for` terminal payload erasure while retaining
  retry inputs and ordinary operation history.
- Added repository-level maintainer guidance in `AGENTS.md`.
- Initial release

### Fixed
- Preserve unrestricted processing for programmatic non-array tag options.
  Cover exact erasure opt-in isolation for both successful and failed operations.
- Keep worker-concurrency formatting consistent when pcntl constants are present,
  cover recovery of persisted claims without reservation timestamps, and isolate
  command discovery inputs from parallel test workers.
- Preserve Laravel 10-compatible model casts and queue traits under current
  refactor tooling. Require persisted claims before executing handlers and keep
  large drains below database parameter ceilings without narrowing public limits.
- Recover expired deferred reservations through the existing processor, claim
  records immediately before execution, and fence stale completion/failure writes.
  Configure `deferred.retry_after_seconds` above the longest handler duration
  (default 300 seconds). Recovery is at-least-once and consumes the existing retry
  budget; an abandoned final attempt becomes failed. No schema migration is needed.
- Preserve deferred model serialization and lifecycle events when acknowledging
  owned reservations, including SQL Server payload erasure; match restricted
  operation identifiers exactly even on case-insensitive database collations.
- Re-resolve queued async operation files from the current app discovery
  paths when the original release path no longer exists during
  zero-downtime deployments.
- Skip redispatching operations that already have a pending execution
  record so the same operation cannot be queued or started again before
  reaching a terminal state.
