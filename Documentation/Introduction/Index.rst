..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

The extension adds a statistics bar above the content of the
:guilabel:`Content > Page` module. It shows, for the selected page and
language:

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

Look and feel follow the page performance bar of
`TYPO3GmbH/analytics <https://github.com/TYPO3GmbH/analytics>`__.
