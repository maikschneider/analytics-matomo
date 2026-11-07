..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

The extension shows Matomo statistics in the Matomo Analytics backend module,
a statistics bar in the Page module and widgets on the dashboard.

..  _module:

Matomo Analytics module
=======================

:guilabel:`Visitors > Overview` shows visits, unique visitors, page views,
bounce rate, visit duration and actions per visit. The evolution chart's metric
picker switches locally without another request. Points use days up to 62 days,
weeks up to 366 days and months above that. Weeks and months are whole Matomo
calendar buckets, including those crossing the range boundaries. Comparison
aligns buckets by position and draws the previous period dashed. Missing values
remain unknown (a dash in tiles, a gap in the chart). Unique visitors may be
unavailable depending on Matomo's archiving configuration.

Aggregated counters show visits, actions and visitors in the last 30 minutes.
They are independent of the selected historical period and cached for only
60 seconds; reload the module to refresh them. The overview also works in demo
mode without contacting Matomo.

Every other report page shows one or more Matomo reports as tables:

:guilabel:`Visitors`
    Locations (countries, regions, cities, browser languages), Devices (device
    type, brand, model, screen resolution), Software (operating system
    families, browsers, browser engines), Times (local hour, site hour,
    weekday) and Engagement (new and returning visits, visit duration, pages
    per visit, visit number, days since the last visit)

:guilabel:`Behaviour`
    Pages, Entry pages, Exit pages, Page titles, Site search (keywords,
    keywords without results and categories; needs its own permission, see
    :ref:`configuration <site-search>`) and Performance (average
    timings of Matomo's page performance tracking and the slowest pages; pages
    with fewer than five measured page views are left out), Outlinks,
    Downloads, Events (categories, actions, names) and Contents (names,
    pieces). Without data, these interaction reports name the tracking code
    they need: link tracking, ``trackEvent`` or content tracking.

:guilabel:`Acquisition`
    All channels (channel types and all referrers), Websites, Search engines
    and keywords, Social networks, AI assistants and Campaigns (names and
    keywords). AI assistants need a Matomo version with that report.
    Campaigns use the core report; when the MarketingCampaignsReporting plugin
    replaces it, the page is not offered.

Column headings and metric documentation come from Matomo in the backend user's
language. Click a column heading to sort, search labels, or browse 25 rows per
page. These controls work on cached data without another Matomo request.
Reports without a dimension, such as returning visits, are shown as a list of
metrics.
:guilabel:`Compare` adds percentage changes against the previous period; a dash
means no comparable previous value is available.

For page URLs, the page tree selects the site and filters to the selected page
and its descendants. The site root shows the whole site. The language control
filters URL rows only. Only readable pages and permitted languages are shown;
URLs without a matching TYPO3 page are visible to administrators only. Row
links open page details, the Page module, page properties or the frontend.
A warning indicates when Matomo has truncated a report: missing pages have
unknown values, not zero visits.

Site, period and comparison are shared with the dashboard widgets. Other module
sections show an empty state until their reports are available. Demo mode also
supplies the report tables without contacting Matomo.

.. _page-details:

Page details
------------

:guilabel:`Page details` shows the page selected in the page tree, in the
language from the docheader: page views, bounce rate, average time on page and
exit rate as tiles, plus their evolution chart. All numbers come from one
bulk request for the page's frontend URL. Hidden pages use their own URL.
Users with access to this section do not also need the statistics bar
permission.

:guilabel:`Speed` shows the average page load time and server time with the
number of measured page views. Matomo collects these timings with its
JavaScript tracker by default; server-side tracking has none. With fewer than
five measurements no average is shown, because single outliers would dominate
it.

Statistics bar
==============

The bar sits above the content of the :guilabel:`Content > Page` module. It
shows, for the selected page and language:

*   page views
*   bounce rate
*   average time on page
*   exit rate

Each value comes with the change against the previous period of the same length
and, on hover or focus, a sparkline per day. Periods of 7, 14 and 30 days are
available; a link opens the report in Matomo. :guilabel:`Details` opens
:ref:`page details <page-details>` with the same page, language and period
for users who may open that module section.

The bar is loaded asynchronously via a backend AJAX route that inherits its
access from the Page module, so a slow or unreachable Matomo never slows down
the module. Pages without a frontend URL (folders, sys folders, links) get no
bar.

Dashboard widgets
=================

The widget group :guilabel:`Matomo` offers:

*   :guilabel:`Site performance`: visits, page views, bounce rate and visit
    duration with trend and sparkline
*   :guilabel:`Visits over time`: visits, visitors, new and returning visits
    per day
*   :guilabel:`Top pages`: the ten most viewed pages with share and trend,
    linked to the Page module

Each widget links to its report page in the :ref:`Matomo Analytics module
<module>` (:guilabel:`Visitors > Overview` or :guilabel:`Behaviour > Pages`)
when the user may open that section. Channels, devices, browsers and
countries are reports in the module.

The dashboard preset :guilabel:`Matomo overview` adds all of them at once.

All widgets of a user show the same site and period; changing them in one
widget reloads the others. Only sites with a Matomo connection whose root page
the user may see are offered. If several sites track into the same Matomo site,
:guilabel:`Top pages` lists only the URLs of the selected site.

Look and feel follow the page performance bar and the dashboard widgets of
`TYPO3GmbH/analytics <https://github.com/TYPO3GmbH/analytics>`__.
