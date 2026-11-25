..  include:: /Includes.rst.txt

..  _large-instances:

======================
Large Matomo instances
======================

Some features need Matomo's full page report over several days, flattened to
one row per URL: the top pages, the views in the page tree and List module,
trending pages and the content review. Matomo builds such a report from the
archives of each day, and the long tail of URLs makes this expensive. On a
Matomo instance with about 195,000 page views per week (8,562 URLs on a
typical day, 7,189 of them with a single hit), Matomo 5 answered:

=================================================  ================================================
:php:`Actions.getPageUrls` with `flat=1`            Result
=================================================  ================================================
one day                                            7.1 seconds
one month                                          14.2 seconds
7 days as a range                                  aborted after 30 seconds (`max_execution_time`)
7 days with `period=day` in one request            aborted after 30 seconds
30 days with `period=day` and `filter_limit=100`   aborted (memory limit of 2 GB)
=================================================  ================================================

Neither `period=day` nor `filter_limit` helps within one request; only one day
per request works. That is too slow for the backend, but fine for a command
line process.

The page view store
===================

:bash:`vendor/bin/typo3 analytics-matomo:import-page-views` fetches the page
report of one day per request and stores one row per URL and day in TYPO3,
with the six counts of the report (page views, visits, time spent, entries,
bounces, exits). Each URL is also mapped to the page and language it routes to
in every TYPO3 site that uses the same Matomo site. Features that need the
full page report over several days can then sum the stored days instead of
asking Matomo.

..  note::

    This version fills the store and reports its state; the backend features
    do not read it yet.

One store is kept per Matomo site (Matomo URL and site ID), however many
TYPO3 sites use it. The token is not part of it. Demo sites are not stored.

Variants of a URL are stored as one: `http` and `https`, and query
strings except a page ID parameter `id`. Matomo's summary row of a truncated
report ("Others") is not stored as a URL, but its page views count in the day's
total. A day counts as truncated when Matomo cut its report, see
`datatable_archiving_maximum_rows_actions` in Matomo's
:file:`config/global.ini.php`.

Scheduler
=========

Add a task :guilabel:`Execute console commands` in the Scheduler with the
command :guilabel:`analytics-matomo:import-page-views`, running hourly, or a
cron job:

..  code-block:: bash
    :caption: crontab

    0 * * * * /path/to/project/vendor/bin/typo3 analytics-matomo:import-page-views

Each run imports today and, until it is final, yesterday of every Matomo site
first. Days are the Matomo site's, from midnight to midnight in its time zone.
A day is final once it was imported at least two hours after its end,
when Matomo has archived its last visits; final days are not imported again.
The remaining requests of the run go to the missing days, newest first, one
Matomo site after the other.

Run the command on one host only. An import of a Matomo site holds a lock,
and a run that finds a Matomo site locked skips it, but the lock is a file on
the host: two hosts running the command would import the same days at the
same time.

..  confval-menu::
    :name: import-page-views-options
    :display: table
    :type:
    :default:

..  confval:: --max-requests
    :name: import-page-views-max-requests
    :type: int
    :default: 50

    Matomo requests (one per day) of a run, shared by all Matomo sites. Limits
    the load a backfill puts on Matomo; the backfill goes on in the next runs.

..  confval:: --timeout
    :name: import-page-views-timeout
    :type: int
    :default: 120

    Seconds to wait for the report of one day.

..  confval:: --retention-days
    :name: import-page-views-retention-days
    :type: int
    :default: 365

    Days kept per Matomo site, today included. 365 days keep the previous
    period of "last 90 days" and "last month" complete.

    Keep it within the report retention of Matomo. When Matomo deletes old
    reports (:guilabel:`Privacy > Anonymize data > Delete old aggregated report
    data`, `delete_reports_older_than`), it answers older days with an empty
    report, and the store would show them as days without views.

..  confval:: --max-remap
    :name: import-page-views-max-remap
    :type: int
    :default: 20000

    Stored URLs mapped again per run, across all sites, see
    :ref:`large-instances-remap`.

..  confval:: --site
    :name: import-page-views-site
    :type: string
    :default: all sites

    Imports only the Matomo site of this TYPO3 site identifier.

..  confval:: --from, --to
    :name: import-page-views-from-to
    :type: Y-m-d
    :default: none

    Imports these days again, for example after Matomo reprocessed its logs.
    :bash:`--from` alone imports up to today, :bash:`--to` alone that day only.

A failing day is logged and skipped; the run goes on with the other days and
Matomo sites and exits with an error code.

Matomo's time limit
-------------------

The report of one day has to fit into Matomo's own `max_execution_time` (PHP
and web server). :bash:`--timeout` only sets how long TYPO3 waits; it cannot
make Matomo work longer. If single days fail with a timeout, raise the limit
for Matomo's API requests.

Backfill
========

The first runs fill the store back to :confval:`--retention-days
<import-page-views-retention-days>`. It takes about the request time of one
day times the number of days: with 7 seconds per day, 365 days take about 43
minutes of Matomo time. With :confval:`--max-requests
<import-page-views-max-requests>` 50 and an hourly task, that is spread over
eight runs, while today stays current from the first run on.

Database size
=============

Measured with MariaDB 11 on a store of 400 days with 8,500 URLs per day
(3.4 million rows) and 300,000 distinct URLs:

=====================================  =====================================
Table                                  Size (data and indexes)
=====================================  =====================================
`tx_analyticsmatomo_url_day`           about 85 bytes per URL and day
`tx_analyticsmatomo_url`               about 225 bytes per distinct URL
`tx_analyticsmatomo_url_page`          about 70 bytes per distinct URL and site
=====================================  =====================================

As a rule of thumb, plan about **40 MB per 1,000 URLs per day and year** of
retention, URL dictionary included. A site with 8,500 URLs per day needs about
340 MB for 365 days.

Retention
=========

Every run deletes the days older than :confval:`--retention-days
<import-page-views-retention-days>`, then the URLs that no stored day refers to
anymore, with their page mappings.

Today and stale data
====================

Today is imported again at every run, so today's numbers are as of the last
run. The :ref:`connection status <connection-status>` shows the stored range,
the time of the last import and the number of truncated days per site. It
warns when today was not imported in the last two hours, for example because
the task stopped.

..  _large-instances-remap:

Changed site configuration
==========================

URLs are mapped to pages when their day is imported. A URL that no longer
routes, for example after a slug change, keeps the page it was last mapped to.
When the base, base variants or languages of a site change, or a TYPO3 site
starts using a Matomo site that already has a store, all stored URLs of that
Matomo site are mapped again for the site. This runs after the imports,
:confval:`--max-remap <import-page-views-max-remap>` URLs per run, and resumes
in the next run. The connection status warns until it is done.
