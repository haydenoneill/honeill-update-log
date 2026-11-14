# Honeill Update Log

A focused audit log of plugin installs, updates, activations, deactivations and deletions. It records who did it, when, the from/to version, and whether it worked.

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/honeill-update-log?label=WordPress.org)](https://wordpress.org/plugins/honeill-update-log/)
![Requires WordPress 5.6+](https://img.shields.io/badge/WordPress-5.6%2B-21759b)
![Requires PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License GPLv2+](https://img.shields.io/badge/license-GPLv2%2B-blue)

**Install it from WordPress.org:** <https://wordpress.org/plugins/honeill-update-log/>

## What it logs

For every plugin event:

- date and time (stored in UTC, shown in the site's time zone)
- plugin name and file
- event: installed, updated, activated, deactivated, deleted, or log cleared
- version, plus the from → to version for updates. This includes bulk and automatic updates, and uploads that replace an installed plugin.
- whether it succeeded, judged per plugin even in bulk updates
- who did it, or "Auto-update / system" for unattended runs

View the log under **Tools → Honeill Update Log**. You can filter by event, page through results, and export to CSV. Formula injection is neutralised in the CSV. The newest 5,000 entries are kept, and anything older than a year is pruned daily.

## Privacy

The log stays in one custom table (`{prefix}honeill_update_log`) in your own database and never leaves your site. The only outside connection is the opt-in usage statistics below.

## Hardening

- Only administrators can view and export the log. Clearing it is limited to administrators (super admins on multisite), asks for confirmation, and is itself logged.
- Disable clearing entirely with:

  ```php
  add_filter( 'honeill_update_log_can_clear', '__return_false' );
  ```

## Usage statistics (opt-in)

The plugin can send basic, non-personal site information (site address, WordPress/PHP/database versions, language, theme, web server, memory limit, and user/post/page/media counts) to its author once a week, **only if an administrator opts in**. It's off by default, can be turned off at any time in the plugin settings, and opting out or deleting the plugin erases the site's data. Details: <https://honeill-plugin-telemetry.vercel.app/privacy>. The code is in `includes/telemetry.php`.

## Development

This repository mirrors the plugin as published on WordPress.org (`trunk` and tags in [plugins.svn.wordpress.org](https://plugins.svn.wordpress.org/honeill-update-log/)). There is no build step.

```
honeill-update-log.php   main plugin file
includes/telemetry.php   opt-in usage statistics
uninstall.php            drops the table and options (every site on multisite)
readme.txt               WordPress.org readme (changelog lives here)
```

## Licence

GPLv2 or later. See [LICENSE](LICENSE).
