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
    All sites (visits, page views, bounce rate and visit duration per site;
    only for users who may see more than one site, a row opens the site),
    Locations (countries, regions, cities, browser languages), Devices (device
    type, brand, model, screen resolution), Software (operating system
    families, browsers, browser engines), Times (local hour, site hour,
    weekday) and Engagement (new and returning visits, visit duration, pages
    per visit, visit number, days since the last visit)

:guilabel:`Behaviour`
    Pages, Trending pages, Entry pages, Exit pages, Page titles, Site search (keywords,
    keywords without results and categories; needs its own permission, see
    :ref:`configuration <site-search>`) and Performance (average
    timings of Matomo's page performance tracking and the slowest pages; pages
    with fewer than five measured page views are left out), Outlinks,
    Downloads, Events (categories, actions, names) and Contents (names,
    pieces). Without data, these interaction reports name the tracking code
    they need: link tracking, ``trackEvent`` or content tracking. Editors see
    outlinks and downloads into a TYPO3 site only if they may access that site
    and page.

    :guilabel:`Trending pages` lists the pages that gained or lost views
    against the previous period of the same length. A page is listed when its
    views changed by at least 20 %, by at least 3 views and by at least 2 % of
    all page views of the site, so small sites don't show noise. Page details
    show the same rising or falling hint for their page.

    :guilabel:`Content review` (own permission, see :ref:`access <access>`)
    lists the visible pages of the selected subtree without views in the last
    90 days, and pages unchanged for at least a year that still get views,
    ranked by views × days unchanged. "Last changed" is the later of the page
    record's and its content's change. Folders, shortcuts, links, hidden and
    error pages are left out, and pages created during the 90 days are no
    candidates yet. When Matomo has tracked the site for less than 90 days, or
    truncated its page report, pages without a row show "unknown" instead of
    0 views. The report never deletes anything.

:guilabel:`Acquisition`
    All channels (channel types and all referrers), Websites, Search engines
    and keywords, Social networks, AI assistants and Campaigns (names and
    keywords). AI assistants need a Matomo version with that report.
    Campaigns use the core report; when the MarketingCampaignsReporting plugin
    replaces it, the page is not offered.

:guilabel:`Goals`
    Conversions, conversion rate and revenue of all goals with their
    evolution, and one page per goal with conversions by channel and by entry
    page. Channels show their share of the goal's conversions rather than a
    rate per visit: Matomo credits a conversion to the channel saved with it,
    which can be the channel of an earlier visit. Revenue is hidden while it is
    zero. Entry pages show conversion events per entrance as a number, not a
    percentage: a repeatable goal can convert more than once per visit. Goals
    are created in Matomo; a Matomo site without goals shows where to create
    them.

Column headings and metric documentation come from Matomo in the backend user's
language. Click a column heading to sort, search labels, or browse 25 rows per
page. These controls work on cached data without another Matomo request.
Reports without a dimension, such as returning visits, are shown as a list of
metrics.
:guilabel:`CSV` downloads all rows of a table that the user may see, with the
current search and sort, translated column headings and raw numbers (ratios
such as the bounce rate as decimals, durations in seconds). The file is built
from the cached report; Matomo and its token are never exposed. Text cells that
a spreadsheet would read as a formula get a leading apostrophe.
:guilabel:`Compare` adds percentage changes against the previous period; a dash
means no comparable previous value is available.
:guilabel:`Segment` filters every report, page detail and the visitors overview
by a segment stored in Matomo. Only segments Matomo pre-processes with its
archiving (segment editor: reports are pre-processed) and
that the token user may see are offered; the module never builds ad-hoc
segments. The choice is kept per site. A newly created segment has data from
the next archiving run on; older periods need an invalidation in Matomo.

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

:guilabel:`Languages` compares page views, bounce rate and time on page of the
page in every language it is translated to and the user may access, from the
same request. A click on a language switches the page details to it. The
audiences of the languages differ, so read the numbers side by side with care.

:guilabel:`Goals` lists, per goal, the conversions in visits that viewed the
page before, the conversions attributed to the page (shared equally across the
pages of the visit) and the conversions of visits that entered on the page. It
is hidden when the Matomo site has no goals.

:guilabel:`Transitions` shows where visitors of the page come from (search
engines, websites, direct entries and other channels), the previous and next
pages, and the clicked outlinks and downloads. Matomo computes them from the
raw visit logs, so they load only after a click on :guilabel:`Show
transitions` and are cached like every report. Pages link to the
:guilabel:`Page` module; like in the URL reports, editors only see pages they
may access. Outlinks and downloads need the link tracking of the JavaScript
tracker. Links into a TYPO3 site, such as files or pages of another site of the
installation, are shown to editors only if they may access that site and page.

:guilabel:`Speed` shows the average page load time and server time with the
number of measured page views. Matomo collects these timings with its
JavaScript tracker by default; server-side tracking has none. With fewer than
five measurements no average is shown, because single outliers would dominate
it.

:guilabel:`Reading time` compares the average time on page with an estimated
reading time: the words of the visible content elements of the page in that
language (header, subheader and text) divided by
:confval:`analyticsMatomo.wordsPerMinute`. Pages with fewer than 100 words
get no estimate. Take the ratio as an indication: time on page includes idle
time and tabs in the background. Before Matomo 6, the last page of a visit
counts as 0 seconds unless the tracker's heartbeat timer
(``enableHeartBeatTimer``) is enabled, which lowers the average of pages where
visits often end.

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

Views in the page tree
======================

:guilabel:`Settings > Show views in the page tree` in the Matomo Analytics
module shows the page views of the last 30 days next to every page in the page
tree, and as a button to page details in the List module. An arrow marks pages
that rose or fell against the 30 days before, with the thresholds of trending
pages; the tooltip shows both numbers. Views of all languages the user may
access are added up. A dash means unknown: Matomo truncated its page report and
the page may be among the cut rows. One visible page per request can fall back to
a bulk lookup of its canonical URLs in the accessible translations. This bounds
the extra latency if Matomo's per-page lookup is slow. The result is cached per
URL, not tree level. Other unknown counts and a failed fallback keep the dash.
Folders, shortcuts and links get no views.

The setting is per user and off by default. It needs the page statistics
permission. One cached request per site and hour answers every tree level; when
Matomo is unreachable, the tree shows no views and asks again after five
minutes.

Dashboard widgets
=================

The widget group :guilabel:`Matomo` offers:

*   :guilabel:`Site performance`: visits, page views, bounce rate and visit
    duration with trend and sparkline
*   :guilabel:`Visits over time`: visits, visitors, new and returning visits
    per day
*   :guilabel:`Top pages`: the ten most viewed pages with share and trend,
    linked to the Page module
*   :guilabel:`All sites`: visits and trend of every site the user may see

Each widget links to its report page in the :ref:`Matomo Analytics module
<module>` (:guilabel:`Visitors > Overview`, :guilabel:`Visitors > All sites` or
:guilabel:`Behaviour > Pages`) when the user may open that section. Channels,
devices, browsers and countries are reports in the module.

The dashboard preset :guilabel:`Matomo overview` adds all of them at once.

All widgets of a user show the same site and period; changing them in one
widget reloads the others. Only sites with a Matomo connection whose root page
the user may see are offered. If several sites track into the same Matomo site,
:guilabel:`Top pages` lists only the URLs of the selected site.

Look and feel follow the page performance bar and the dashboard widgets of
`TYPO3GmbH/analytics <https://github.com/TYPO3GmbH/analytics>`__.
