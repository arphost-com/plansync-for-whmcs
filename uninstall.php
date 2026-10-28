<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package PlanSync
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$plansync_cleanup = static function () {
    foreach (array('plansync_settings', 'plansync_cache', 'plansync_version', 'arphost_whmcs_plans_settings', 'arphost_whmcs_plans_cache', 'whmcs_plans_settings', 'whmcs_plans_cache', 'whmcs_plans_version') as $option) {
        delete_option($option);
    }
    delete_transient('plansync_sync_lock');
    delete_transient('arphost_whmcs_plans_sync_lock');
    wp_clear_scheduled_hook('plansync_sync');
    wp_clear_scheduled_hook('arphost_whmcs_plans_sync');
    wp_clear_scheduled_hook('whmcs_plans_sync');
    wp_unschedule_hook('whmcs_plans_sync_group');
    delete_transient('whmcs_plans_sync_lock');
    wp_unschedule_hook('plansync_sync_group');
};

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $plansync_site) {
        switch_to_blog($plansync_site);
        $plansync_cleanup();
        restore_current_blog();
    }
} else {
    $plansync_cleanup();
}
