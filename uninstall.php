<?php
/**
 * Runs when the plugin is deleted. Drops the log table and removes options — on every site of a multisite network.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function honeill_update_log_uninstall_site()
{
    global $wpdb;
    $table = $wpdb->prefix . 'honeill_update_log';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping this plugin's own table on uninstall
    $wpdb->query("DROP TABLE IF EXISTS {$table}");

    delete_option('honeill_update_log_db_version');
    delete_option('honeill_update_log_versions');
    wp_clear_scheduled_hook('honeill_update_log_prune');
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $honeill_update_log_site_id) {
        switch_to_blog((int) $honeill_update_log_site_id);
        honeill_update_log_uninstall_site();
        restore_current_blog();
    }
} else {
    honeill_update_log_uninstall_site();
}
