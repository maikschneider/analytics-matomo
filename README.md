<div align="center">

![Extension icon](Resources/Public/Icons/Extension.svg)

# TYPO3 extension `analytics_matomo`

[![Supported PHP versions](https://img.shields.io/packagist/dependency-v/maikschneider/analytics-matomo/php?logo=php)](https://packagist.org/packages/maikschneider/analytics-matomo)
[![Tests](https://img.shields.io/github/actions/workflow/status/maikschneider/analytics-matomo/tests.yml?label=tests&logo=github)](https://github.com/maikschneider/analytics-matomo/actions/workflows/tests.yml)
[![CGL](https://img.shields.io/github/actions/workflow/status/maikschneider/analytics-matomo/sca.yml?label=cgl&logo=github)](https://github.com/maikschneider/analytics-matomo/actions/workflows/sca.yml)
[![Coverage](https://codecov.io/gh/maikschneider/analytics-matomo/graph/badge.svg)](https://codecov.io/gh/maikschneider/analytics-matomo)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE.md)

</div>

Shows Matomo statistics in the TYPO3 backend: a statistics bar for the current page in the Page module and dashboard widgets for whole sites.

> [!NOTE]
> Alpha. Look and feel follow the page performance bar and dashboard widgets of [TYPO3GmbH/analytics](https://github.com/TYPO3GmbH/analytics), the data comes from your own Matomo.

## ✨ Features

* Statistics bar above the page content: page views, bounce rate, average time on page and exit rate with trend and sparkline, loaded asynchronously, so a slow Matomo never slows down the Page module
* Dashboard widgets: site performance, visits over time, top pages (linked to the Page module), channels, devices, browsers and countries, plus the dashboard preset **Matomo overview**
* Several sites per Matomo instance: all widgets show the site and period chosen by the editor, top pages only list URLs of that site
* Periods of 7, 14 and 30 days, link to the full report in Matomo
* Uses the URL of the selected language
* Opt-in per backend group: separate permissions for page and site statistics
* One HTTP request per page or widget and period, cached for an hour
* Reuses the Matomo URL and site ID of EXT:matomo_integration, EXT:matomo_widgets or EXT:matomo_tracking
* Demo data mode (`analyticsMatomo.demoData`) to try it out without a Matomo instance

## 🔥 Installation

```bash
composer require maikschneider/analytics-matomo
```

Add the site set **Analytics for Matomo** (`maikschneider/analytics-matomo`) to your site.

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| TYPO3 | `^13.4 \|\| ^14.3` |
| Matomo | 5, 6 |

## ⚙️ Configuration

```yaml
# config/sites/<site>/settings.yaml
analyticsMatomo:
  url: 'https://matomo.example.com/'
  idSite: 1
  tokenAuth: '%env(MATOMO_TOKEN)%'
```

Use the token of a Matomo user with view access to this one site.

## 📙 Documentation

[docs.typo3.org](https://docs.typo3.org/p/maikschneider/analytics-matomo/main/en-us/)

## 🧑‍💻 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). `ddev init-typo3` sets up a demo instance with two sites, three languages, an editor and demo data to click through.

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
