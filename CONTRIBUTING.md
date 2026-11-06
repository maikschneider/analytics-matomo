# Contributing to analytics-matomo

Thank you for considering contributing to analytics-matomo! We welcome contributions of all kinds.

## Local Development Setup

This extension uses DDEV for local development.

```bash
ddev start
ddev composer install
ddev init-typo3
```

## Running Tests

Tests use the TYPO3 Testing Framework and PHPUnit.

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/phpunit --testsuite Functional
```

## Code Quality Standards

Please run all checks before submitting a pull request.

```bash
ddev composer sca
```

Individual tools: `php:fixer`, `php:stan`, `php:rector`, `php:lint`, `yaml:lint`, `typoscript:lint`, `editorconfig:lint`, `language:lint`, `composer:normalize`.

`ddev composer ci:sca` runs the same tools read-only, as in CI. Its `ci:php:fixer` and `ci:php:stan` steps write checkstyle reports (`php-cs-fixer.xml`, `phpstan.xml`) that CI turns into pull request annotations.

## Commits and Pull Requests

* [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) (`feat:`, `fix:`, `docs:`, …)
* One pull request per issue, body `Resolves #N.`
* Add an entry to the `[Unreleased]` section of `CHANGELOG.md`
* New behaviour is configured through the site settings, not hardcoded

## License

By contributing, you agree that your contributions will be licensed under GPL-2.0-or-later.
