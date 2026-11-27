#
# Page view store: Matomo's page report per day, imported by analytics-matomo:import-page-views
#

#
# One row per Matomo site (URL + site ID); token access is checked when the data is read
#
CREATE TABLE tx_analyticsmatomo_connection (
	uid int(11) unsigned NOT NULL auto_increment,
	identifier char(64) DEFAULT '' NOT NULL,
	url varchar(255) DEFAULT '' NOT NULL,
	id_site int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY identifier (identifier)
);

#
# URL dictionary per connection: hash of the scheme-less normalized URL,
# display URL with scheme and original encoding
#
CREATE TABLE tx_analyticsmatomo_url (
	uid int(11) unsigned NOT NULL auto_increment,
	connection int(11) unsigned DEFAULT '0' NOT NULL,
	url_hash char(40) DEFAULT '' NOT NULL,
	url text,

	PRIMARY KEY (uid),
	UNIQUE KEY url_hash (connection, url_hash)
);

#
# Counts per URL and day (day as integer Ymd)
#
CREATE TABLE tx_analyticsmatomo_url_day (
	connection int(11) unsigned DEFAULT '0' NOT NULL,
	day int(11) unsigned DEFAULT '0' NOT NULL,
	url int(11) unsigned DEFAULT '0' NOT NULL,
	nb_hits int(11) unsigned DEFAULT '0' NOT NULL,
	nb_visits int(11) unsigned DEFAULT '0' NOT NULL,
	sum_time_spent int(11) unsigned DEFAULT '0' NOT NULL,
	entry_nb_visits int(11) unsigned DEFAULT '0' NOT NULL,
	entry_bounce_count int(11) unsigned DEFAULT '0' NOT NULL,
	exit_nb_visits int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (connection, day, url),
	KEY url_day (connection, url, day)
);

#
# Imported days; total_hits includes Matomo's summary rows, summary_hits > 0 means Matomo truncated the day
#
CREATE TABLE tx_analyticsmatomo_import (
	connection int(11) unsigned DEFAULT '0' NOT NULL,
	day int(11) unsigned DEFAULT '0' NOT NULL,
	imported_at int(11) unsigned DEFAULT '0' NOT NULL,
	total_hits int(11) unsigned DEFAULT '0' NOT NULL,
	summary_hits int(11) unsigned DEFAULT '0' NOT NULL,
	row_count int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (connection, day)
);

#
# URL -> page per TYPO3 site, by last sight; language_id -1: not on this site, page_id 0: not routable
#
CREATE TABLE tx_analyticsmatomo_url_page (
	site varchar(255) DEFAULT '' NOT NULL,
	url int(11) unsigned DEFAULT '0' NOT NULL,
	language_id int(11) DEFAULT '-1' NOT NULL,
	page_id int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (site, url),
	KEY page (site, page_id, language_id)
);

#
# Hash of each site's base, languages and base variants; a change re-maps all URLs of the site,
# in chunks over several runs: pending_hash is the hash being mapped, remap_cursor the last URL uid mapped
#
CREATE TABLE tx_analyticsmatomo_site_state (
	site varchar(255) DEFAULT '' NOT NULL,
	config_hash char(40) DEFAULT '' NOT NULL,
	pending_hash char(40) DEFAULT '' NOT NULL,
	remap_cursor int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (site)
);
