<?php
/**
 * Plugin Name:       PlanSync for WHMCS
 * Plugin URI:        https://arphost.com/
 * Description:       Show WHMCS product groups as fast, SEO-friendly plan cards with live prices and stock. Built and maintained by ARPHost.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            ARPHost
 * Author URI:        https://arphost.com/
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       plansync-for-whmcs
 * Domain Path:       /languages
 *
 * @package PlanSync
 */

namespace Arphost\PlanSync;

if (!defined('ABSPATH')) {
    exit;
}

define('PLANSYNC_VERSION', '1.1.0');
define('PLANSYNC_FILE', __FILE__);
define('PLANSYNC_DIR', plugin_dir_path(__FILE__));
define('PLANSYNC_URI', plugin_dir_url(__FILE__));
define('PLANSYNC_BRAND_URL', 'https://arphost.com/?utm_source=plansync&utm_medium=plugin&utm_campaign=wp-admin');

require_once PLANSYNC_DIR . 'includes/class-store-parser.php';
require_once PLANSYNC_DIR . 'includes/class-plans.php';
require_once PLANSYNC_DIR . 'includes/class-renderer.php';
require_once PLANSYNC_DIR . 'includes/class-admin.php';

add_filter('cron_schedules', array(Plans::class, 'cron_schedules'));
add_action(Plans::CRON_HOOK, array(Plans::class, 'sync_all'));
add_action(Plans::CRON_SINGLE_HOOK, array(Plans::class, 'cron_sync_group'));
add_action('http_api_curl', array(Plans::class, 'pin_connection'), 10, 3);
add_action('init', __NAMESPACE__ . '\\register');
add_action('admin_init', array(Plans::class, 'ensure_scheduled'));
add_action('plugins_loaded', __NAMESPACE__ . '\\maybe_upgrade');

if (is_admin()) {
    Admin::init();
}

if (defined('WP_CLI') && WP_CLI) {
    require_once PLANSYNC_DIR . 'includes/class-cli.php';
    \WP_CLI::add_command('plansync', CLI::class);
    \WP_CLI::add_command('whmcs-plans', CLI::class);
}

register_activation_hook(__FILE__, static function () {
    Plans::migrate();
    update_option('plansync_version', PLANSYNC_VERSION, false);
    Plans::ensure_scheduled();
    Plans::purge_page_caches();
});

add_action('update_option_' . Plans::SETTINGS_OPTION, array(Plans::class, 'purge_page_caches'), 10, 0);
add_action('add_option_' . Plans::SETTINGS_OPTION, array(Plans::class, 'purge_page_caches'), 10, 0);

register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook(Plans::CRON_HOOK);
    wp_unschedule_hook(Plans::CRON_SINGLE_HOOK);
});

function maybe_upgrade(): void
{
    if (PLANSYNC_VERSION !== get_option('plansync_version')) {
        Plans::migrate();
        update_option('plansync_version', PLANSYNC_VERSION, false);
    }
    load_plugin_textdomain('plansync-for-whmcs', false, dirname(plugin_basename(PLANSYNC_FILE)) . '/languages');
}

function register(): void
{
    wp_register_style('plansync-for-whmcs', PLANSYNC_URI . 'assets/plans.css', array(), PLANSYNC_VERSION);
    wp_register_script('plansync-for-whmcs', PLANSYNC_URI . 'assets/plans.js', array(), PLANSYNC_VERSION, array('in_footer' => true, 'strategy' => 'defer'));

    wp_register_script(
        'plansync-editor',
        PLANSYNC_URI . 'assets/block-editor.js',
        array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'),
        PLANSYNC_VERSION,
        true
    );

    if (is_admin()) {
        $groups = array();
        foreach (Plans::settings()['groups'] as $slug => $group) {
            $groups[] = array('value' => (string) $slug, 'label' => (string) $group['label']);
        }
        wp_localize_script('plansync-editor', 'whmcsPlansEditor', array(
            'groups'      => $groups,
            'settingsUrl' => admin_url('admin.php?page=' . Admin::PAGE),
            'iconUrl'     => PLANSYNC_URI . 'assets/images/arphost-icon.png',
        ));
    }

    $defaults = Renderer::block_defaults();
    register_block_type('arphost/whmcs-plans', array(
        'api_version'     => 3,
        'title'           => __('PlanSync', 'plansync-for-whmcs'),
        'category'        => 'arphost',
        'editor_script'   => 'plansync-editor',
        'style'           => 'plansync-for-whmcs',
        'render_callback' => static function ($attributes) {
            return Renderer::render_block(is_array($attributes) ? $attributes : array());
        },
        'supports'        => array('html' => false, 'align' => array('wide', 'full'), 'anchor' => true, 'customClassName' => true),
        'attributes'      => array(
            'group'         => array('type' => 'string', 'default' => $defaults['group']),
            'layout'        => array('type' => 'string', 'default' => $defaults['layout']),
            'outOfStock'    => array('type' => 'string', 'default' => $defaults['outOfStock']),
            'category'      => array('type' => 'string', 'default' => $defaults['category']),
            'buttonLabel'   => array('type' => 'string', 'default' => ''),
            'categoryLevel' => array('type' => 'number', 'default' => $defaults['categoryLevel']),
            'planLevel'     => array('type' => 'number', 'default' => $defaults['planLevel']),
            'showSchema'    => array('type' => 'boolean', 'default' => $defaults['showSchema']),
            'anchor'        => array('type' => 'string', 'default' => ''),
            'className'     => array('type' => 'string', 'default' => ''),
            'align'         => array('type' => 'string', 'default' => ''),
        ),
    ));

    $plans_shortcode = static function ($atts) {
        $atts = shortcode_atts(array(
            'group'          => '',
            'layout'         => 'tabs',
            'out_of_stock'   => 'show',
            'category'       => '',
            'button'         => '',
            'category_level' => 3,
            'plan_level'     => 4,
            'schema'         => 'yes',
            'id'             => '',
            'class'          => '',
        ), $atts, 'plansync');
        return Renderer::render(array(
            'group'         => $atts['group'],
            'layout'        => $atts['layout'],
            'outOfStock'    => $atts['out_of_stock'],
            'category'      => $atts['category'],
            'buttonLabel'   => $atts['button'],
            'categoryLevel' => (int) $atts['category_level'],
            'planLevel'     => (int) $atts['plan_level'],
            'showSchema'    => !in_array(strtolower((string) $atts['schema']), array('no', 'false', '0'), true),
            'anchor'        => $atts['id'],
            'className'     => $atts['class'],
        ));
    };
    add_shortcode('plansync', $plans_shortcode);
    add_shortcode('whmcs_plans', $plans_shortcode);
    add_shortcode('arphost_whmcs_plans', $plans_shortcode);

    $price_shortcode = static function ($atts) {
        $atts = shortcode_atts(array('group' => ''), $atts, 'plansync_price');
        return esc_html(Renderer::lowest_price((string) $atts['group']));
    };
    add_shortcode('plansync_price', $price_shortcode);
    add_shortcode('whmcs_plans_price', $price_shortcode);
    add_shortcode('arphost_whmcs_plans_price', $price_shortcode);
}

add_filter('block_categories_all', static function ($categories) {
    foreach ($categories as $category) {
        if ('arphost' === $category['slug']) {
            return $categories;
        }
    }
    array_unshift($categories, array('slug' => 'arphost', 'title' => 'ARPHost', 'icon' => null));
    return $categories;
});
