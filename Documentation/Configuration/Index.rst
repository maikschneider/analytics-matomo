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

..  confval:: analyticsMatomo.demoData
    :type: bool
    :default: false

    Shows generated statistics instead of asking Matomo, to try out the
    extension or to take screenshots without a Matomo instance. Replaces any
    connection of the site and is marked with a :guilabel:`Demo data` badge.
    Top pages are the pages of the site in every language, and each site gets
    its own numbers. Never enable it on a production site.

..  confval:: analyticsMatomo.wordsPerMinute
    :type: int
    :default: 220

    Reading speed for the estimated reading time in :ref:`page details
    <page-details>`. 220 words per minute is a common value for adults reading
    on screen; lower it for difficult texts or a younger audience.

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

Statistics are opt-in per backend group. Module sections use TYPO3's normal
module access (see below), not custom options. Features outside the module
are granted in the backend group record, tab :guilabel:`Access Rights`, field
:guilabel:`Custom module options`, section :guilabel:`Analytics for Matomo`:

:guilabel:`View page statistics` (`analytics_matomo:pageStatistics`)
    The statistics bar in the Page module. Editors see it only for pages they
    may show and languages they may edit.

:guilabel:`View site statistics` (`analytics_matomo:siteStatistics`)
    The dashboard widgets. Editors see only sites whose root page they may
    show. The widgets themselves are granted as usual via
    :guilabel:`Dashboard widgets`.

:guilabel:`View site search keywords` (`analytics_matomo:siteSearch`)
    The report page :guilabel:`Behaviour > Site search` in the module, in
    addition to the Behaviour section. Search keywords can contain personal
    data, such as names or e-mail addresses typed into the search field.

Administrators always have all of them. Error details from Matomo are shown to
administrators only.

The site-wide module reports and widgets show the numbers of the selected
Matomo site. If several TYPO3 sites track into the same Matomo site, their
visits are shown together; only URL reports such as :guilabel:`Top pages` and
:guilabel:`Behaviour > Pages` are limited to the URLs of the
selected site. Use one Matomo site per TYPO3 site if editors of one site must
not see the numbers of another. Top pages lists only pages and languages the
user may access; URLs without a page are shown to administrators only.

Backend module
==============

:guilabel:`Matomo Analytics` under Web/Content has the sections Visitors,
Behaviour, Acquisition, Goals and Page details. Grant the parent module
(`analytics_matomo`) and the wanted sections (`analytics_matomo_visitors`,
`analytics_matomo_behaviour`, `analytics_matomo_acquisition`,
`analytics_matomo_goals`, `analytics_matomo_page`) in the backend group's module
access (`groupMods`). Both the parent and the requested section are required;
without the parent the module does not appear in the menu. No custom option,
including `analytics_matomo:siteStatistics`, is required for these sections;
only the site search report page needs `analytics_matomo:siteSearch`.

The module checks its own module access before loading reports. Its site
selection uses that access instead of the widget option, while still requiring
a readable site root page and a Matomo connection. The dashboard widgets and
their shared selection AJAX route require `analytics_matomo:siteStatistics`;
module access alone does not grant either. The Page module bar AJAX route
requires `analytics_matomo:pageStatistics`. Inherited AJAX module access is an
additional check, not a substitute for these feature options.

The selected page determines the site; without a page, use the site dropdown.
Site, period (presets or a custom date range) and comparison are shared with the
dashboard widgets. Report page and language are stored per section. The language
filters URL reports only; other reports cover all languages.

..  _site-search:

Site search
===========

:guilabel:`Behaviour > Site search` lists search keywords, keywords without
results and search categories of the whole site. Matomo records them only when

*   site search is enabled in the Matomo website settings, and
*   the URL of the search result page carries one of the configured search
    parameters (default `q`, `query`, `s`, `search`, `searchword`, `k`,
    `keyword`), or the result page calls `trackSiteSearch`.

Keywords without results need `trackSiteSearch` with the number of results.
Matomo recommends calling it instead of `trackPageView` on the result page,
so adapt the tracking code of that page accordingly. The data attributes keep
Fluid's escaping of the keyword.

indexed_search, in an override of :file:`Templates/Search/Search.html`:

..  code-block:: html

    <f:for each="{resultsets}" as="result" iteration="iteration">
        <f:if condition="{iteration.isFirst} && {searchParams.sword}">
            <div hidden data-matomo-search="{searchParams.sword}" data-matomo-search-count="{result.count}"></div>
        </f:if>
    </f:for>
    <script>
        const search = document.querySelector('[data-matomo-search]');
        if (search) {
            (window._paq = window._paq || []).push(['trackSiteSearch', search.dataset.matomoSearch, false, Number(search.dataset.matomoSearchCount)]);
        }
    </script>

Apache Solr for TYPO3, in an override of :file:`Templates/Search/Results.html`:

..  code-block:: html

    <f:if condition="{resultSet.hasSearched}">
        <div hidden data-matomo-search="{resultSet.usedQuery.rawSearchTerm}" data-matomo-search-count="{resultSet.allResultCount}"></div>
    </f:if>

Use the same script as above.

Caching
=======

Results are cached for one hour in the cache `analytics_matomo`. Change the
lifetime in :file:`config/system/additional.php`:

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['analytics_matomo']['options']['defaultLifetime'] = 900;
