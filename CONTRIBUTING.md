# Contributing to analytics-matomo

Thank you for considering contributing to analytics-matomo! We welcome contributions of all kinds.

## Local Development Setup

This extension uses DDEV for local development.

```bash
ddev start
ddev composer install
ddev init-typo3
```

`ddev init-typo3` resets the database and can be run again at any time. It sets up a demo instance; the sites `cycles` and `rental` use demo data, so no Matomo instance is needed.

| | |
|---|---|
| Backend | https://analytics-matomo.ddev.site/typo3/ |
| Admin | `admin` / `Passw0rd!` |
| Editor | `editor` / `Passw0rd!`, group "Editors" with both statistics permissions |

What to look at:

* **Page module**: the statistics bar above the content of every page. Numbers differ by page, language and site. Pages without a frontend URL (folder "Storage", shortcut "Shop") have no bar.
* **Dashboard**: both users get the preset **Matomo overview** on their first visit. Switch between the sites in any widget; top pages link to the Page module.
* **Elbe Cycles** (`cycles`, https://analytics-matomo.ddev.site/): English, German (translated, umlaut slugs like `/de/über-uns`) and French (falls back to English, only two pages translated), nested pages down to `/bikes/e-bikes/cargo/long-john-2026`, a hidden page, a 404 page.
* **Velo Verleih Dresden** (`rental`, https://rental.analytics-matomo.ddev.site/): German and English, URLs with a trailing slash.
* **Matomo live demo** (`live`): no demo data, real numbers of a site tracked by demo.matomo.org.

The records live in `Tests/Fixtures/DemoInstance/Database.csv` (format of the functional test fixtures), the sites in `config/sites/`. `Tests/Functional/DemoInstanceTest.php` covers them.

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
