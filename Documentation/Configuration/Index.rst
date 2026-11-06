..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

Site settings
=============

..  code-block:: yaml
    :caption: config/sites/<site>/settings.yaml

    analyticsMatomo:
      url: 'https://matomo.example.com/'
      idSite: 1
      tokenAuth: '%env(MATOMO_TOKEN)%'

..  confval:: analyticsMatomo.url
    :type: string
    :default: ''

    Base URL of your Matomo. Use the final URL: redirects are not followed, so
    the token is never resent to another address.

..  confval:: analyticsMatomo.idSite
    :type: int
    :default: 0

    ID of the website in Matomo.

..  confval:: analyticsMatomo.tokenAuth
    :type: string
    :default: ''

    Token of a Matomo user with **view** access to this one site. Keep it out of
    the repository with an environment variable. The token is sent in the body
    of a POST request, never in the URL. Empty: anonymous access.

Reuse of other Matomo extensions
================================

Without :confval:`analyticsMatomo.url`, URL and site ID are taken together
from the first of these extensions that has a URL configured. They are never
mixed between extensions.

==========================  =========================================  ================================
Extension                   URL                                        Site ID (site config)
==========================  =========================================  ================================
EXT:matomo_integration      `matomoIntegrationUrl` (site config)       `matomoIntegrationSiteId`
EXT:matomo_widgets          `matomoWidgetsUrl` (site config)           `matomoWidgetsIdSite`
EXT:matomo_tracking         `matomoUrl` (extension configuration)      `matomoTrackingSiteId`
==========================  =========================================  ================================

:confval:`analyticsMatomo.tokenAuth` is used with any of them. Without it, the
token `matomoWidgetsTokenAuth` is used, but only for the Matomo of
EXT:matomo_widgets. The token of EXT:matomo_tracking is not reused: it is a
tracking token.

Access
======

Statistics are opt-in per backend group. Grant them in the backend group
record, tab :guilabel:`Access Rights`, field :guilabel:`Custom module options`,
section :guilabel:`Analytics for Matomo`:

:guilabel:`View page statistics` (`analytics_matomo:pageStatistics`)
    The statistics bar in the Page module. Editors see it only for pages they
    may show and languages they may edit.

:guilabel:`View site statistics` (`analytics_matomo:siteStatistics`)
    The dashboard widgets. Editors see only sites whose root page they may
    show. The widgets themselves are granted as usual via
    :guilabel:`Dashboard widgets`.

Administrators always have both. Error details from Matomo are shown to
administrators only.

Caching
=======

Results are cached for one hour in the cache `analytics_matomo`. Change the
lifetime in :file:`config/system/additional.php`:

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['analytics_matomo']['options']['defaultLifetime'] = 900;
