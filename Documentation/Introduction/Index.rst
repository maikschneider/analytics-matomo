..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

The extension shows Matomo statistics in two places: a statistics bar in the
Page module and widgets on the dashboard.

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
available; a link opens the report in Matomo.

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
*   :guilabel:`Channels`, :guilabel:`Devices`, :guilabel:`Browsers` and
    :guilabel:`Countries`: visits by traffic source

The dashboard preset :guilabel:`Matomo overview` adds all of them at once.

All widgets of a user show the same site and period; changing them in one
widget reloads the others. Only sites with a Matomo connection whose root page
the user may see are offered. If several sites track into the same Matomo site,
:guilabel:`Top pages` lists only the URLs of the selected site.

Look and feel follow the page performance bar and the dashboard widgets of
`TYPO3GmbH/analytics <https://github.com/TYPO3GmbH/analytics>`__.
