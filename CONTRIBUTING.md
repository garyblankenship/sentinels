# Contributing to Sentinels

Thank you for considering contributing to the Sentinels Laravel package! We welcome contributions from the community.

## Development Setup

### Requirements
- PHP 8.1 or higher
- Composer
- Laravel 11.x

### Installation

1. Fork the repository
2. Clone your fork:
```bash
git clone https://github.com/YOUR-USERNAME/sentinels.git
cd sentinels
```

3. Install dependencies:
```bash
composer install
```

## Running Tests

Run the test suite with:

```bash
composer test
```

Run specific test suites:

```bash
# Unit tests only
vendor/bin/phpunit --testsuite=Unit

# Feature tests only
vendor/bin/phpunit --testsuite=Feature
```

## Code Quality Tools

### PHPStan (Static Analysis)
```bash
vendor/bin/phpstan analyse
```

### PHP CS Fixer (Code Style)
```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
```

To automatically fix code style issues:
```bash
vendor/bin/php-cs-fixer fix
```

### PHP CodeSniffer
```bash
vendor/bin/phpcs --standard=PSR12 src/
```

## Pull Request Process

1. **Fork & Create Branch**: Fork the repo and create your branch from `main`
2. **Write Tests**: Add tests for any new features or bug fixes
3. **Update Documentation**: Update the README.md if needed
4. **Follow Standards**: Ensure your code follows PSR-12 standards
5. **Run Tests**: Make sure all tests pass
6. **Commit Messages**: Use clear, descriptive commit messages
7. **Submit PR**: Open a pull request with a clear description

## Coding Standards

- Follow PSR-12 coding standards
- Use PHP 8.1+ features where appropriate
- Keep methods focused and under 20 lines when possible
- Write descriptive PHPDoc comments
- Use type declarations for all parameters and return types

## Testing Guidelines

- Write tests for all new features
- Aim for high code coverage (80%+)
- Use descriptive test method names
- Follow Arrange-Act-Assert pattern
- Mock external dependencies

## Creating New Agents

When creating new example agents:

1. Extend `BaseAgent`
2. Implement required abstract methods
3. Add PHPDoc comments
4. Include usage examples
5. Write unit tests

Example:

```php
<?php

namespace App\Agents;

use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class MyCustomAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        // Your agent logic here
        return $context->with($processedData);
    }

    public function getName(): string
    {
        return 'My Custom Agent';
    }

    public function getDescription(): string
    {
        return 'Processes data in a custom way';
    }
}
```

## Reporting Issues

When reporting issues, please include:

- PHP version
- Laravel version
- Sentinels version
- Steps to reproduce
- Expected behavior
- Actual behavior
- Any error messages or stack traces

## Feature Requests

We welcome feature requests! Please:

- Check existing issues first
- Clearly describe the feature
- Explain the use case
- Provide code examples if possible

## Questions?

Feel free to open an issue for any questions about contributing.

Thank you for helping make Sentinels better!