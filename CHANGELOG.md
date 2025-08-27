# Changelog

All notable changes to `sentinels` will be documented in this file.

## [Unreleased]

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

[0.1.0]: https://github.com/vampires/sentinels/releases/tag/v0.1.0