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

    // If this site opted in to usage statistics, ask for its data to be erased.
    $install_id = (string) get_option('honeill_update_log_install_id', '');
    if ($install_id !== '' && get_option('honeill_update_log_telemetry') === 'yes') {
        wp_safe_remote_post('https://honeill-plugin-telemetry.vercel.app/api/v1/forget', array(
            'timeout' => 5,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('install_id' => $install_id, 'plugin' => 'honeill-update-log')),
        ));
    }

    delete_option('honeill_update_log_db_version');
    delete_option('honeill_update_log_versions');
    delete_option('honeill_update_log_telemetry');
    delete_option('honeill_update_log_install_id');
    wp_clear_scheduled_hook('honeill_update_log_prune');
    wp_clear_scheduled_hook('honeill_update_log_telemetry_send');
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
