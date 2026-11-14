<?php
/**
 * Plugin Name: Honeill Update Log
 * Description: Know exactly what changed and when. Logs every plugin install, update, activation, deactivation and deletion — with who did it, the from/to version, and whether it worked — all viewable and exportable under Tools.
 * Version: 1.2.0
 * Author: Hayden O'Neill
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: honeill-update-log
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * Honeill Update Log is free software: you can redistribute it and/or modify it under the terms of
 * the GNU General Public License, version 2 or (at your option) any later version.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Honeill_Update_Log
{
    const TABLE          = 'honeill_update_log';
    const OPT_DB_VERSION = 'honeill_update_log_db_version';
    const OPT_SNAPSHOT   = 'honeill_update_log_versions';
    const CRON_PRUNE     = 'honeill_update_log_prune';
    const DB_VERSION     = '2';
    const RETENTION_DAYS = 365;
    const MAX_ROWS       = 5000;
    const PER_PAGE       = 50;

    /** @var array<string,string> plugin file => version captured just before an update */
    private static $pre = array();

    /** @var array<string,bool> plugin file => whether its package installed without error */
    private static $results = array();

    /** @var array<string,array<string,string>> plugin folder => (file => version) present before an install/upload overwrote it */
    private static $pre_install = array();

    /** @var array<string,array<string,string>> plugin file => name/version captured just before deletion */
    private static $pre_delete = array();

    public static function boot(): void
    {
        add_filter('upgrader_pre_install', array(__CLASS__, 'capture_pre'), 10, 2);
        add_filter('upgrader_source_selection', array(__CLASS__, 'capture_pre_install'), 10, 4);
        add_filter('upgrader_install_package_result', array(__CLASS__, 'capture_result'), 10, 2);
        add_action('upgrader_process_complete', array(__CLASS__, 'on_upgrade'), 10, 2);
        add_action('activated_plugin', array(__CLASS__, 'on_activate'), 10, 2);
        add_action('deactivated_plugin', array(__CLASS__, 'on_deactivate'), 10, 2);
        add_action('delete_plugin', array(__CLASS__, 'capture_pre_delete'));
        add_action('deleted_plugin', array(__CLASS__, 'on_delete'), 10, 2);

        add_action('admin_init', array(__CLASS__, 'admin_init'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_honeill_update_log_export', array(__CLASS__, 'export'));
        add_action('admin_post_honeill_update_log_clear', array(__CLASS__, 'clear'));
        add_action(self::CRON_PRUNE, array(__CLASS__, 'prune'));
    }

    /* -----------------------------------------------------------------------
     * Activation / housekeeping
     * --------------------------------------------------------------------- */

    public static function activate(): void
    {
        self::create_table();
        if (!wp_next_scheduled(self::CRON_PRUNE)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CRON_PRUNE);
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_PRUNE);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_PRUNE);
        }
    }

    public static function admin_init(): void
    {
        // Self-heal if the table is missing (e.g. plugin updated without re-activation).
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            self::create_table();
            // The pre-1.1.1 per-request plugin snapshot is no longer used.
            delete_option(self::OPT_SNAPSHOT);
        }
        if (!wp_next_scheduled(self::CRON_PRUNE)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CRON_PRUNE);
        }
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    private static function create_table(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            logged_at datetime NOT NULL,
            event varchar(20) NOT NULL,
            item_type varchar(20) NOT NULL DEFAULT 'plugin',
            item_name varchar(191) NOT NULL DEFAULT '',
            item_slug varchar(191) NOT NULL DEFAULT '',
            version_from varchar(32) NOT NULL DEFAULT '',
            version_to varchar(32) NOT NULL DEFAULT '',
            success tinyint(1) NOT NULL DEFAULT 1,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_name varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY logged_at (logged_at),
            KEY event (event)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
    }

    /* -----------------------------------------------------------------------
     * Plugin metadata helpers
     * --------------------------------------------------------------------- */

    /** @return array|null header data for one plugin file, or null if it's gone */
    private static function plugin_data(string $file): ?array
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $path = WP_PLUGIN_DIR . '/' . $file;
        if (!is_readable($path)) {
            return null;
        }
        $data = get_plugin_data($path, false, false);
        return !empty($data['Name']) ? $data : null;
    }

    /** Plugin files referenced by an upgrader $hook_extra (bulk 'plugins' or single 'plugin'). */
    private static function extract_plugins(array $hook_extra): array
    {
        if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            return array_values($hook_extra['plugins']);
        }
        if (!empty($hook_extra['plugin'])) {
            return array((string) $hook_extra['plugin']);
        }
        return array();
    }

    /* -----------------------------------------------------------------------
     * Event capture
     * --------------------------------------------------------------------- */

    /** Filter: record each plugin's version BEFORE it is overwritten by an update. Must return $response. */
    public static function capture_pre($response, $hook_extra)
    {
        // Single and automatic updates pass type=plugin; bulk updates pass only the plugin file.
        if (is_array($hook_extra) && (isset($hook_extra['plugin']) || ($hook_extra['type'] ?? '') === 'plugin')) {
            foreach (self::extract_plugins($hook_extra) as $file) {
                $data = self::plugin_data($file);
                if ($data) {
                    self::$pre[$file] = isset($data['Version']) ? (string) $data['Version'] : '';
                }
            }
        }
        return $response;
    }

    /**
     * Filter: before an install (or an uploaded ZIP replacing an existing plugin) is copied into
     * place, note whatever already lives in the destination folder, so a replacement is logged as
     * an update with the version it came from. Must return $source.
     */
    public static function capture_pre_install($source, $remote_source = '', $upgrader = null, $hook_extra = array())
    {
        if (is_array($hook_extra) && ($hook_extra['type'] ?? '') === 'plugin' && ($hook_extra['action'] ?? '') === 'install' && is_string($source)) {
            $folder = basename(untrailingslashit($source));
            if ($folder !== '' && $folder !== '.' && is_dir(WP_PLUGIN_DIR . '/' . $folder)) {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $existing = array();
                foreach (get_plugins('/' . $folder) as $file => $data) {
                    $existing[$folder . '/' . $file] = isset($data['Version']) ? (string) $data['Version'] : '';
                }
                self::$pre_install[$folder] = $existing;
            }
        }
        return $source;
    }

    /** Filter: remember whether each plugin package installed cleanly (per plugin, so bulk updates are accurate). */
    public static function capture_result($result, $hook_extra)
    {
        if (is_array($hook_extra) && !empty($hook_extra['plugin'])) {
            self::$results[(string) $hook_extra['plugin']] = !is_wp_error($result);
        }
        return $result;
    }

    public static function on_upgrade($upgrader, $hook_extra): void
    {
        if (!is_array($hook_extra) || ($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        if (($hook_extra['action'] ?? '') === 'install') {
            self::on_install($upgrader);
            return;
        }

        $files = self::extract_plugins($hook_extra);
        $result = isset($upgrader->result) ? $upgrader->result : null;

        foreach ($files as $file) {
            $data = self::plugin_data($file);
            $to   = $data && isset($data['Version']) ? (string) $data['Version'] : '';
            $name = $data && !empty($data['Name']) ? (string) $data['Name'] : self::display_name($file);
            $from = self::$pre[$file] ?? '';

            if (isset(self::$results[$file])) {
                $ok = self::$results[$file] && $to !== '';
            } elseif (isset(self::$pre[$file])) {
                // WordPress < 5.7 reports no per-plugin result: a readable version means the files landed.
                $ok = ($to !== '') && !(is_wp_error($result) && count($files) === 1);
            } else {
                // The package never reached the install step (download or unpack failed).
                $ok = false;
            }

            // A failed update leaves (or restores) the old files: log the version still installed as
            // "from", and no "to".
            self::insert('update', $name, $file, $ok ? $from : ($from !== '' ? $from : $to), $ok ? $to : '', $ok);
        }
    }

    /** An install, or an uploaded ZIP that replaced an existing plugin (logged as an update). */
    private static function on_install($upgrader): void
    {
        $file = '';
        if (is_object($upgrader) && method_exists($upgrader, 'plugin_info')) {
            $file = (string) $upgrader->plugin_info();
        }

        $skin_result = (is_object($upgrader) && isset($upgrader->skin->result)) ? $upgrader->skin->result : null;
        $failed = is_wp_error($skin_result) || $file === '';

        if ($file === '') {
            // Nothing landed. Name the package if WordPress read its header before failing.
            $new = (is_object($upgrader) && !empty($upgrader->new_plugin_data) && is_array($upgrader->new_plugin_data)) ? $upgrader->new_plugin_data : array();
            if (!empty($new['Name'])) {
                self::insert('install', (string) $new['Name'], '', '', '', false);
            }
            return;
        }

        $folder   = strpos($file, '/') !== false ? strstr($file, '/', true) : '';
        $existing = ($folder !== '' && isset(self::$pre_install[$folder])) ? self::$pre_install[$folder] : array();
        $data     = self::plugin_data($file);
        $name     = $data && !empty($data['Name']) ? (string) $data['Name'] : self::display_name($file);
        $to       = $data && isset($data['Version']) ? (string) $data['Version'] : '';
        $ok       = !$failed && $to !== '';

        if (array_key_exists($file, $existing)) {
            self::insert('update', $name, $file, $existing[$file], $ok ? $to : '', $ok);
        } else {
            self::insert('install', $name, $file, '', $to, $ok);
        }
    }

    public static function on_activate($plugin, $network_wide = false): void
    {
        $data = self::plugin_data((string) $plugin);
        self::insert('activate', $data['Name'] ?? self::display_name($plugin), (string) $plugin, '', $data['Version'] ?? '', true);
    }

    public static function on_deactivate($plugin, $network_wide = false): void
    {
        $data = self::plugin_data((string) $plugin);
        self::insert('deactivate', $data['Name'] ?? self::display_name($plugin), (string) $plugin, '', $data['Version'] ?? '', true);
    }

    /** Action: read name/version while the files still exist — they are gone by the time "deleted_plugin" fires. */
    public static function capture_pre_delete($plugin_file): void
    {
        $data = self::plugin_data((string) $plugin_file);
        if ($data) {
            self::$pre_delete[(string) $plugin_file] = array(
                'name'    => (string) $data['Name'],
                'version' => isset($data['Version']) ? (string) $data['Version'] : '',
            );
        }
    }

    public static function on_delete($plugin_file, $deleted = true): void
    {
        $plugin_file = (string) $plugin_file;
        $snap = self::$pre_delete[$plugin_file] ?? array();
        self::insert(
            'delete',
            $snap['name'] ?? self::display_name($plugin_file),
            $plugin_file,
            '',
            $snap['version'] ?? '',
            (bool) $deleted
        );
    }

    private static function display_name($file): string
    {
        // Fall back to the folder/file name when no header is available.
        $file  = (string) $file;
        $parts = explode('/', $file);
        return $parts[0] !== '' ? $parts[0] : $file;
    }

    private static function insert(string $event, string $name, string $slug, string $from, string $to, bool $success): void
    {
        global $wpdb;

        $uid = get_current_user_id();
        if ($uid) {
            $user = wp_get_current_user();
            $user_name = $user->display_name ? $user->display_name : $user->user_login;
        } else {
            $user_name = __('Auto-update / system', 'honeill-update-log');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- writing to this plugin's own audit table; not cacheable
        // Values longer than their column make $wpdb->insert() refuse the whole row, which would let
        // a plugin with an over-long name or version slip past the log. Trim to fit instead.
        $wpdb->insert(
            self::table(),
            array(
                'logged_at'    => current_time('mysql', true), // GMT
                'event'        => $event,
                'item_type'    => 'plugin',
                'item_name'    => self::fit($name !== '' ? $name : $slug, 191),
                'item_slug'    => self::fit($slug, 191),
                'version_from' => self::fit($from, 32),
                'version_to'   => self::fit($to, 32),
                'success'      => $success ? 1 : 0,
                'user_id'      => $uid,
                'user_name'    => self::fit((string) $user_name, 191),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s')
        );
    }

    private static function fit(string $value, int $length): string
    {
        $value = wp_check_invalid_utf8($value, true);
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    /* -----------------------------------------------------------------------
     * Maintenance
     * --------------------------------------------------------------------- */

    public static function prune(): void
    {
        global $wpdb;
        $table = self::table();

        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * DAY_IN_SECONDS));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- maintenance on this plugin's own table
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE logged_at < %s", $cutoff));

        // Keep at most MAX_ROWS rows.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- maintenance on this plugin's own table
        $boundary = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS));
        if ($boundary) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- maintenance on this plugin's own table
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d", (int) $boundary));
        }
    }

    /* -----------------------------------------------------------------------
     * Admin screen
     * --------------------------------------------------------------------- */

    public static function menu(): void
    {
        add_management_page(
            __('Plugin Update Log', 'honeill-update-log'),
            __('Honeill Update Log', 'honeill-update-log'),
            'manage_options',
            'honeill-update-log',
            array(__CLASS__, 'render')
        );
    }

    private static function events(): array
    {
        return array(
            'install'    => __('Installed', 'honeill-update-log'),
            'update'     => __('Updated', 'honeill-update-log'),
            'activate'   => __('Activated', 'honeill-update-log'),
            'deactivate' => __('Deactivated', 'honeill-update-log'),
            'delete'     => __('Deleted', 'honeill-update-log'),
            'cleared'    => __('Log cleared', 'honeill-update-log'),
        );
    }

    /**
     * Who may clear the log — deliberately stricter than viewing: super admins on multisite,
     * administrators on single sites. Return false from this filter to disable clearing entirely,
     * e.g. add_filter('honeill_update_log_can_clear', '__return_false');
     */
    private static function can_clear(): bool
    {
        $cap = is_multisite() ? 'manage_network_options' : 'manage_options';
        return (bool) apply_filters('honeill_update_log_can_clear', current_user_can($cap));
    }

    private static function current_filter(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter; not state-changing
        $event = isset($_GET['event']) ? sanitize_key(wp_unslash($_GET['event'])) : '';
        return array_key_exists($event, self::events()) ? $event : '';
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'honeill-update-log'));
        }

        global $wpdb;
        $table  = self::table();
        $events = self::events();
        $filter = self::current_filter();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination value
        $paged  = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ($paged - 1) * self::PER_PAGE;

        if ($filter !== '') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table; value prepared
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event = %s", $filter));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table; values prepared
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE event = %s ORDER BY id DESC LIMIT %d OFFSET %d", $filter, self::PER_PAGE, $offset));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table; no user input
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table; values prepared
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, $offset));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Plugin Update Log', 'honeill-update-log') . '</h1>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag
        if (isset($_GET['honeill_cleared'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Log cleared.', 'honeill-update-log') . '</p></div>';
        }

        // Filter + actions row.
        echo '<form method="get" style="display:inline-block;margin:6px 0">';
        echo '<input type="hidden" name="page" value="honeill-update-log">';
        echo '<select name="event" onchange="this.form.submit()">';
        echo '<option value="">' . esc_html__('All events', 'honeill-update-log') . '</option>';
        foreach ($events as $slug => $label) {
            echo '<option value="' . esc_attr($slug) . '"' . selected($filter, $slug, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> <noscript><button class="button">' . esc_html__('Filter', 'honeill-update-log') . '</button></noscript>';
        echo '</form>';

        echo ' <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field('honeill_update_log_export');
        echo '<input type="hidden" name="action" value="honeill_update_log_export">';
        submit_button(__('Export CSV', 'honeill-update-log'), 'secondary', 'submit', false);
        echo '</form>';

        if (self::can_clear()) {
            echo ' <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline" onsubmit="return confirm(' . esc_attr(wp_json_encode(__('Clear the whole log? This cannot be undone.', 'honeill-update-log'))) . ');">';
            wp_nonce_field('honeill_update_log_clear');
            echo '<input type="hidden" name="action" value="honeill_update_log_clear">';
            submit_button(__('Clear log', 'honeill-update-log'), 'delete', 'submit', false);
            echo '</form>';
        }

        // Table.
        echo '<table class="widefat striped" style="margin-top:10px"><thead><tr>';
        foreach (array(
            __('When', 'honeill-update-log'),
            __('Event', 'honeill-update-log'),
            __('Plugin', 'honeill-update-log'),
            __('Version', 'honeill-update-log'),
            __('Result', 'honeill-update-log'),
            __('By', 'honeill-update-log'),
        ) as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="6">' . esc_html__('No events logged yet.', 'honeill-update-log') . '</td></tr>';
        } else {
            $fmt = get_option('date_format') . ' ' . get_option('time_format');
            foreach ($rows as $row) {
                $when = wp_date($fmt, strtotime($row->logged_at . ' UTC'));
                if ($row->version_from !== '' && $row->version_to !== '') {
                    $version = $row->version_from . ' → ' . $row->version_to;
                } elseif ($row->version_to !== '') {
                    $version = $row->version_to;
                } elseif ($row->version_from !== '') {
                    $version = $row->version_from;
                } else {
                    $version = '—';
                }
                $label = $events[$row->event] ?? $row->event;
                $result = $row->success
                    ? '<span style="color:#2271b1">' . esc_html__('Success', 'honeill-update-log') . '</span>'
                    : '<span style="color:#b32d2e">' . esc_html__('Failed', 'honeill-update-log') . '</span>';

                echo '<tr>';
                echo '<td>' . esc_html($when) . '</td>';
                echo '<td>' . esc_html($label) . '</td>';
                echo '<td>' . esc_html($row->item_name) . '<br><code style="font-size:11px;color:#646970">' . esc_html($row->item_slug) . '</code></td>';
                echo '<td>' . esc_html($version) . '</td>';
                echo '<td>' . wp_kses_post($result) . '</td>';
                echo '<td>' . esc_html($row->user_name) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        // Pagination.
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages > 1) {
            $base = add_query_arg(array('page' => 'honeill-update-log', 'event' => $filter, 'paged' => '%#%'), admin_url('tools.php'));
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(array(
                'base'    => $base,
                'format'  => '',
                'current' => $paged,
                'total'   => $pages,
            ))) . '</div></div>';
        }

        echo '<p class="description" style="margin-top:10px">' . esc_html(sprintf(
            /* translators: 1: number of entries kept, 2: retention in days */
            __('Keeps up to %1$d entries, pruned daily after %2$d days.', 'honeill-update-log'),
            self::MAX_ROWS,
            self::RETENTION_DAYS
        )) . '</p>';

        echo '<h2>' . esc_html__('Usage statistics', 'honeill-update-log') . '</h2>';
        Honeill_Update_Log_Telemetry::render_setting();

        echo '</div>';
    }

    /* -----------------------------------------------------------------------
     * Export / clear
     * --------------------------------------------------------------------- */

    public static function export(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'honeill-update-log'));
        }
        check_admin_referer('honeill_update_log_export');

        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table; no user input
        $rows = $wpdb->get_results("SELECT logged_at, event, item_name, item_slug, version_from, version_to, success, user_name FROM {$table} ORDER BY id DESC", ARRAY_A);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=honeill-update-log-' . gmdate('Ymd-His') . '.csv');

        // phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a CSV download to php://output is the standard approach; WP_Filesystem cannot stream to the client.
        $out = fopen('php://output', 'w');
        // Explicit separator/enclosure/escape: RFC 4180 output, and no PHP 8.4 deprecation notice.
        fputcsv($out, array('Logged at (UTC)', 'Event', 'Plugin', 'File', 'From', 'To', 'Success', 'By'), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array(
                $row['logged_at'],
                $row['event'],
                self::csv_safe($row['item_name']),
                self::csv_safe($row['item_slug']),
                self::csv_safe($row['version_from']),
                self::csv_safe($row['version_to']),
                $row['success'] ? 'yes' : 'no',
                self::csv_safe($row['user_name']),
            ), ',', '"', '');
        }
        fclose($out);
        // phpcs:enable WordPress.WP.AlternativeFunctions
        exit;
    }

    /** Neutralise spreadsheet formula injection in free-text cells (names and versions come from plugin headers). */
    private static function csv_safe($value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], array('=', '+', '-', '@', "\t", "\r"), true)) {
            return "'" . $value;
        }
        return $value;
    }

    public static function clear(): void
    {
        if (!self::can_clear()) {
            wp_die(esc_html__('You do not have permission to clear the log.', 'honeill-update-log'));
        }
        check_admin_referer('honeill_update_log_clear');

        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table; no user input
        $wpdb->query("DELETE FROM {$table}");

        // Record the clear itself, so the log can never be wiped silently — there is always a
        // "log cleared by X" entry left behind that only another (also-logged) clear can remove.
        self::insert('cleared', __('Audit log cleared', 'honeill-update-log'), '', '', '', true);

        wp_safe_redirect(add_query_arg(array('page' => 'honeill-update-log', 'honeill_cleared' => '1'), admin_url('tools.php')));
        exit;
    }
}

require_once __DIR__ . '/includes/telemetry.php';
Honeill_Update_Log_Telemetry::boot(__FILE__, '1.2.0', array('tools_page_honeill-update-log'));

register_activation_hook(__FILE__, array('Honeill_Update_Log', 'activate'));
register_deactivation_hook(__FILE__, array('Honeill_Update_Log', 'deactivate'));
Honeill_Update_Log::boot();
