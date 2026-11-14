=== Honeill Update Log ===
Contributors: honeill
Tags: audit log, activity log, plugins, update history, changelog
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A focused audit log of plugin installs, updates, activations, deactivations and deletions — who, when, from/to version, and success.

== Description ==

Honeill Update Log keeps a tidy record of everything that happens to the plugins on your site, so you always know what changed and when — invaluable when a site breaks right after an update, or when several people manage the same install.

For every event it records:

* the date and time,
* the plugin name and folder/file,
* the event — installed, updated, activated, deactivated, or deleted,
* the version (and, for updates, the version it came **from** and went **to**),
* whether it succeeded,
* and who did it (or "Auto-update / system" for unattended updates).

View the log under **Tools → Update Log**, filter by event type, page through it, and export the whole thing to CSV. Old entries are pruned automatically.

Unlike the big all-in-one activity loggers, this does one thing: plugin lifecycle events, lightweight and exportable.

= Privacy / data =

Everything is stored in a single custom table in your own database. The plugin makes **no external connections** and sends no data anywhere. It records the WordPress display name (or login) of the user who performed each action, purely for your own audit trail.

== Installation ==

1. Upload the `honeill-update-log` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin. Logging starts immediately.
3. View the log under **Tools → Update Log**.

== Frequently Asked Questions ==

= Where is the log stored? =

In a custom table (`{prefix}honeill_update_log`, e.g. `wp_honeill_update_log`) in your site's database. Deleting the plugin removes that table (on every site of a multisite network).

= Does it log theme or WordPress core updates? =

This version focuses on plugins. Theme and core support is a planned addition.

= Does it slow my site down? =

No. It only writes a row when a plugin event actually happens, and the log screen is admin-only.

= How long are entries kept? =

The most recent 5,000 entries are kept, and anything older than a year is pruned by a daily cleanup.

= Why does an update sometimes show "from" as blank? =

The previous version is captured just before an update runs. For a brand-new install (or if the plugin was added before this plugin was active) there is no earlier version to record.

= Can someone wipe the log to hide what they did? =

Clearing is limited to administrators (super admins on multisite), and every clear writes a "Log cleared" entry recording who did it — so it can't be done silently. To disable clearing completely, add: `add_filter( 'honeill_update_log_can_clear', '__return_false' );`

== Changelog ==

= 1.1.1 =
* Fixed: plugin installs were not being logged. Uploading a ZIP that replaces an installed plugin is now logged as an update, with the version it came from.
* Security: a plugin with a very long name or version could avoid being logged at all. Values are now trimmed to fit instead.
* Fixed: failed updates (download, unpack or install errors) could be recorded as successful. Bulk updates now record each plugin's own result, and capture the "from" version too.
* Fixed: deleted plugins' name and version are read just before deletion. The log no longer re-reads every plugin's header on each admin page load.
* Security: the CSV export neutralises spreadsheet formulas in every text cell (including tab and carriage-return prefixes) and writes standard RFC 4180 CSV without PHP 8.4 deprecation notices.
* "Clear log" now asks for confirmation.
* Uninstall now cleans up every site on a multisite network.

= 1.1.0 =
* Hardened "Clear log": administrators only (super admins on multisite), the clear is itself logged so it can't be done silently, and it can be disabled via the honeill_update_log_can_clear filter.
* Added a "Log cleared" event type, shown in the log and the events filter.
* Branded the admin menu label (Tools → Honeill Update Log), tidied the plugin description, and resolved Plugin Check warnings on the plugin's own custom-table queries.

= 1.0.0 =
* Initial release: logs plugin install, update (from/to version), activate, deactivate and delete, with user, timestamp and success. Tools screen with filtering, pagination, CSV export and daily auto-prune.

== Upgrade Notice ==

= 1.1.1 =
Installs are now logged, failed updates are recorded correctly, and a plugin can no longer slip past the log with an over-long version string.
