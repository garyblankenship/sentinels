# Changelog

All notable changes to `sentinels` will be documented in this file.

## [Unreleased]

## [0.2.0] - 2025-08-27

### 🚀 Major: Transparent Async Pipeline Execution

#### Added
- **Transparent Async API**: True asynchronous pipeline execution with Laravel queues
  - Add `->async()` to any pipeline - same API, async execution
  - `AsyncContext` extends `Context` with auto-waiting properties
  - Zero new mental models - async works exactly like sync
  - Progressive disclosure: simple by default, powerful when needed

- **AsyncContext Class**: Transparent async result handling
  - Auto-waits when properties are accessed (`$result->payload`)
  - Monitoring methods for power users (`getProgress()`, `getBatchId()`, `getBatchStats()`)
  - Same error handling API as synchronous contexts
  - Batch statistics and progress tracking

- **AsyncBatchManager**: Behind-the-scenes batch orchestration
  - Job dispatching and result aggregation
  - Cache-based result storage and retrieval
  - Automatic cleanup of batch artifacts
  - Comprehensive batch statistics

- **AgentExecutionJob**: Queue job for individual agent execution
  - Handles context serialization/deserialization
  - Individual agent result caching
  - Error capture and aggregation
  - Integration with Laravel's batch system

#### Enhanced
- **Pipeline Class**: Added `async()` method for transparent async execution
  - Maintains same API for both sync and async modes
  - Automatic batch creation and management
  - Transparent result handling
  - Error handling consistency

- **Context Class**: Enhanced serialization support
  - `isSerializable()` method for queue compatibility validation
  - `prepareForQueue()` and `hydrateFromQueue()` for safe async dispatch
  - `getSerializationInfo()` for debugging serialization issues
  - Automatic detection of non-serializable data (closures, resources, PDO)

- **Pipeline Modes**: Enhanced parallel mode with async support
  - Parallel mode can now run truly asynchronous when `async()` is enabled
  - Automatic fallback to simulated parallel for sync execution
  - Maintains API compatibility

#### Examples & Documentation
- **Updated Examples**: All async examples showcase transparent API
  - `async-pipeline.php`: Basic transparent async execution patterns
  - `async-monitoring.php`: Advanced monitoring with transparent API
  - Removed complex batch management code in favor of transparent patterns
  - Taylor Otwell-approved simplicity throughout

#### Technical Improvements
- **Enhanced Testing**: Comprehensive async test suite
  - Tests for transparent API behavior
  - Context serialization validation
  - Error handling consistency
  - Progressive disclosure verification

- **Configuration**: Async-specific configuration options
  - Batch naming patterns
  - Cache key prefixes
  - Cleanup delays and strategies

### 🎯 The Laravel Way
This release embodies Taylor Otwell's philosophy of elegant, simple APIs:
- **One word difference**: `->async()` is all developers need to learn
- **Same mental model**: Async works exactly like sync code
- **Progressive disclosure**: Simple by default, powerful when needed
- **Zero callbacks**: No promises, futures, or async/await patterns
- **Transparent**: Properties "just work" with auto-waiting

### Migration Guide
Existing code continues to work unchanged. To add async execution:

```php
// Before (sync)
$result = pipeline()->pipe($agent)->through($data);

// After (async) - literally just add ->async()
$result = pipeline()->async()->pipe($agent)->through($data);

// Use results exactly the same way
echo $result->payload; // Auto-waits in async mode
```

## [0.1.0] - 2025-08-27

### Added
- Initial release of Sentinels Laravel package
- Core agent-based task execution framework
- Pipeline system with multiple execution modes (sequential, parallel, conditional, map/reduce)
- Immutable Context objects with metadata and correlation IDs
- BaseAgent abstract class with lifecycle hooks
- Dynamic content-based routing strategies
- Built-in observability with events and metrics
- Retry policies with exponential backoff
- Laravel integration with service provider, facade, and Artisan commands
- Artisan commands: `make:agent` and `make:pipeline`
- Comprehensive event system (AgentStarted, AgentCompleted, AgentFailed, PipelineStarted, PipelineCompleted)
- Testing utilities and fixtures for package development
- Support for PHP 8.1+ and Laravel 11.x

### Features
- **Agents**: Single-purpose, testable units of business logic
- **Pipelines**: Composable workflows with conditional branching
- **Context**: Rich metadata carrying through entire execution flow
- **Routing**: Dynamic agent selection based on content analysis
- **Observability**: Built-in correlation IDs, events, and performance metrics
- **Error Handling**: Retry policies, error recovery, and graceful degradation
- **Testing**: Comprehensive test helpers and fixtures

### Documentation
- Comprehensive README with real-world use cases
- Code examples for common patterns
- Comparison with existing Laravel solutions
- Strategic value proposition for teams

[0.2.0]: https://github.com/vampires/sentinels/releases/tag/v0.2.0
[0.1.0]: https://github.com/vampires/sentinels/releases/tag/v0.1.0