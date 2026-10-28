<?php
/**
 * WP-CLI commands: wp plansync <command>.
 *
 * @package PlanSync
 */

namespace Arphost\PlanSync;

if (!defined('ABSPATH')) {
    exit;
}

final class CLI
{
    /**
     * Refresh plans from WHMCS.
     *
     * ## OPTIONS
     *
     * [--group=<group>]
     * : Only refresh this product group.
     */
    public function sync($args, $assoc): void
    {
        if (!empty($assoc['group'])) {
            $group = Plans::sanitize_group((string) $assoc['group']);
            if ('' === $group) {
                \WP_CLI::error('That is not a valid group slug or id.');
            }
            $results = array($group => Plans::sync_group($group));
        } else {
            $results = Plans::sync_all();
            if (is_wp_error($results)) {
                \WP_CLI::error($results->get_error_message());
            }
        }
        if (empty($results)) {
            \WP_CLI::warning('No product groups are configured.');
            return;
        }
        $failed = false;
        foreach ($results as $group => $result) {
            if ($result['ok']) {
                \WP_CLI::log(sprintf('%s: %d plans', $group, $result['count']));
            } else {
                $failed = true;
                \WP_CLI::warning(sprintf('%s: %s', $group, $result['message']));
            }
        }
        $failed ? \WP_CLI::error('One or more groups failed to refresh.') : \WP_CLI::success('Plans refreshed.');
    }

    /**
     * Show configured groups and their cache state.
     */
    public function status(): void
    {
        $rows = array();
        foreach (Plans::settings()['groups'] as $group => $info) {
            $s = Plans::group_status((string) $group);
            $rows[] = array(
                'group'        => $group,
                'label'        => $info['label'],
                'plans'        => $s['count'],
                'out_of_stock' => $s['out_of_stock'],
                'refreshed'    => $s['fetched_at'] ? gmdate('c', $s['fetched_at']) : 'never',
                'stale'        => $s['stale'] ? 'yes' : 'no',
                'error'        => $s['error'],
            );
        }
        \WP_CLI\Utils\format_items('table', $rows, array('group', 'label', 'plans', 'out_of_stock', 'refreshed', 'stale', 'error'));
    }

    /**
     * Add a product group and import it.
     *
     * ## OPTIONS
     *
     * <group>
     * : Store slug (for example web-hosting), store URL or numeric group id.
     *
     * [--label=<label>]
     * : Display label.
     */
    public function add_group($args, $assoc): void
    {
        $group = Plans::sanitize_group((string) $args[0]);
        if ('' === $group || !Plans::add_group($group, (string) ($assoc['label'] ?? ''))) {
            \WP_CLI::error('That is not a valid group slug, store URL or id.');
        }
        $result = Plans::sync_group($group);
        $result['ok']
            ? \WP_CLI::success(sprintf('Added %s with %d plans.', $group, $result['count']))
            : \WP_CLI::warning(sprintf('Added %s, but the import failed: %s', $group, $result['message']));
    }
}
