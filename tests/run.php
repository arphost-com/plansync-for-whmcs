<?php
/**
 * Offline tests for the parser, sync logic and renderer. Run: php tests/run.php
 * WordPress functions are replaced by small shims; no network access is used.
 */

declare(strict_types=1);

define('PLANSYNC_TESTING', true);
define('ABSPATH', __DIR__ . '/');
define('PLANSYNC_VERSION', 'test');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['wp_options'] = array();
$GLOBALS['wp_transients'] = array();
$GLOBALS['wp_http'] = array();
$GLOBALS['wp_http_log'] = array();
$GLOBALS['wp_scheduled'] = array();

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
class WP_Http
{
    public static function make_absolute_url($maybe, $url)
    {
        $p = parse_url($url);
        return $p['scheme'] . '://' . $p['host'] . ('/' === $maybe[0] ? $maybe : rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $maybe);
    }
}

function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$name] : $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['wp_options'][$name]); return true; }
function get_transient($name) { return $GLOBALS['wp_transients'][$name] ?? false; }
function set_transient($name, $value, $ttl = 0) { $GLOBALS['wp_transients'][$name] = $value; return true; }
function delete_transient($name) { unset($GLOBALS['wp_transients'][$name]); return true; }
function wp_clear_scheduled_hook($hook) { return 0; }
function wp_unschedule_hook($hook) { return 0; }
function wp_schedule_event($ts, $rec, $hook) { return true; }
function wp_schedule_single_event($ts, $hook, $args = array()) { $GLOBALS['wp_scheduled'][] = array($hook, $args); return true; }
function wp_next_scheduled($hook, $args = array()) { return false; }
function do_action(...$args) { $GLOBALS['wp_actions_fired'][] = $args[0]; }
function apply_filters($tag, $value, ...$args) { return $GLOBALS['wp_filters'][$tag] ?? $value; }
function w3tc_flush_all() { $GLOBALS['w3tc_flushes'] = ($GLOBALS['w3tc_flushes'] ?? 0) + 1; }
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return esc_html($t); }
function esc_attr__($t, $d = null) { return esc_attr($t); }
function _n($s, $p, $n, $d = null) { return 1 === (int) $n ? $s : $p; }
function sanitize_title($text) { return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $text), '-')); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function sanitize_html_class($c) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $c); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function current_user_can($cap) { return !empty($GLOBALS['wp_can_edit']); }
function wp_enqueue_style($h) {}
function wp_enqueue_script($h) {}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function get_bloginfo($k) { return 'Example Hosting'; }
function home_url($p = '') { return 'https://www.example.com' . $p; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_remote_get($url, $args = array())
{
    $GLOBALS['wp_http_log'][] = $url;
    if (!isset($GLOBALS['wp_http'][$url])) {
        return array('code' => 404, 'body' => 'not found', 'headers' => array());
    }
    $r = $GLOBALS['wp_http'][$url];
    return is_string($r) ? array('code' => 200, 'body' => $r, 'headers' => array()) : $r;
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_header($r, $h) { return $r['headers'][$h] ?? ''; }

require __DIR__ . '/../includes/class-store-parser.php';
require __DIR__ . '/../includes/class-plans.php';
require __DIR__ . '/../includes/class-renderer.php';

use Arphost\PlanSync\Plans;
use Arphost\PlanSync\Renderer;
use Arphost\PlanSync\Store_Parser;

$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
}
function fixture(string $name): string
{
    return file_get_contents(__DIR__ . '/fixtures/templates/' . $name . '.html');
}
function reset_wp(): void
{
    $GLOBALS['wp_options'] = array();
    $GLOBALS['wp_transients'] = array();
    $GLOBALS['wp_http'] = array();
    $GLOBALS['wp_http_log'] = array();
    $GLOBALS['w3tc_flushes'] = 0;
    $GLOBALS['wp_actions_fired'] = array();
    $GLOBALS['wp_scheduled'] = array();
    Renderer::reset();
}

$base = 'https://billing.example.com/client';

// ---------------------------------------------------------------- Parser: every stock template.
$expect_out = array('standard_cart' => 1, 'premium_comparison' => 1, 'pure_comparison' => 1, 'supreme_comparison' => 1, 'universal_slider' => 1, 'legacy_modern' => 1, 'iteration_ids' => 1, 'cloud_slider' => 0);
foreach ($expect_out as $template => $out_count) {
    $plans = Store_Parser::parse_products(fixture($template), $base, 'cloud-servers');
    $by = array_column($plans, null, 'key');
    $names = array_column($plans, 'name');
    check(array_slice($names, 0, 3) === array('Starter Cloud', 'Pro Cloud', 'Max Cloud'), "$template: names in store order");
    check(isset($by['11']) && 5.0 === $by['11']['price'] && 'USD' === $by['11']['currency'] && 'monthly' === $by['11']['cycle'], "$template: price, currency and cycle");
    check(isset($by['13']) && 1200.0 === $by['13']['price'] && 'annually' === $by['13']['cycle'], "$template: thousands separator and annual cycle");
    check(3 === count($by['12']['specs'] ?? array()), "$template: three spec lines");
    check($out_count === count(array_filter($plans, static fn ($p) => !$p['in_stock'])), "$template: out of stock count");
    if ($by['11']['order_url'] ?? '') {
        check(0 === strpos($by['11']['order_url'], 'https://billing.example.com/client/'), "$template: order link keeps the WHMCS folder");
    }
}
$std = array_column(Store_Parser::parse_products(fixture('standard_cart'), $base, 'cloud-servers'), null, 'key');
check(2 === $std['12']['stock'] && $std['12']['in_stock'], 'standard_cart: low stock count is kept');
check('Starting from' === $std['11']['label'] && '' === $std['12']['label'], 'standard_cart: WHMCS "Starting from" label is kept as written');
check(isset($std['b3']) && 'Starter Bundle' === $std['b3']['name'] && 99.0 === $std['b3']['price'] && array('Hosting plus domain', 'Free SSL') === $std['b3']['specs'], 'standard_cart: bundles are read');
check('https://billing.example.com/client/cart.php?a=add&bid=3' === $std['b3']['order_url'], 'standard_cart: bundle link decoded');
$iter = array_column(Store_Parser::parse_products(fixture('iteration_ids'), $base, 'c'), null, 'key');
check(isset($iter['11'], $iter['12'], $iter['13']), 'templates numbering by position use the pid from the cart link');
$uni = Store_Parser::parse_products(fixture('universal_slider'), $base, 'c');
check('Pro Cloud' === $uni[1]['name'] && 'vCPU 2' === $uni[1]['specs'][0], 'universal_slider: name without the "Price" word, features without progress text');
$legacy = Store_Parser::parse_products(fixture('legacy_modern'), $base, 'c');
check('RAM 4 GB' === $legacy[1]['specs'][1] && 2 === $legacy[1]['stock'], 'legacy_modern: line breaks become spaces, qty read from name');

$es = Store_Parser::parse_products(fixture('localized_eur'), $base, 'alojamiento');
check(4.5 === $es[0]['price'] && 'EUR' === $es[0]['currency'] && '€4,50' === $es[0]['price_display'], 'decimal comma price with currency code');
check(1299.0 === $es[1]['price'] && '1.299,00 €' === $es[1]['price_display'] && !$es[1]['in_stock'], 'dot thousands with symbol after the amount');
check('Desde' === $es[0]['label'] && 'Mensual' === $es[0]['cycle_text'] && '' === $es[0]['cycle'], 'non-English labels are shown as WHMCS prints them');
check('https://billing.example.com/index.php/store/alojamiento/21' === $es[0]['order_url'], 'root-relative links resolve against the host');

check(array('amount' => 1299.5, 'display' => '£1,299.50', 'currency' => 'GBP', 'raw' => '£1,299.50 GBP') === Store_Parser::parse_price('£1,299.50 GBP'), 'parse_price: pound with code');
check(10.0 === Store_Parser::parse_price('R$ 10,00 BRL')['amount'], 'parse_price: real with decimal comma');
check(null === Store_Parser::parse_price('Free')['amount'], 'parse_price: free has no amount');
check(array() === Store_Parser::parse_products('<html><body>maintenance</body></html>', $base, 'g'), 'no products on a page without products');
check(array() === Store_Parser::parse_products('<p>Never out of stock guarantee</p>', $base, 'g'), 'marketing text is not a product');

$groups = Store_Parser::parse_groups(fixture('standard_cart'), $base);
check(array('7' => 'Dedicated', 'cloud-servers' => 'Cloud Servers', 'web-hosting' => 'Web Hosting') === $groups, 'groups: friendly, rp= and gid links; other hosts and /store/order ignored');


// ---------------------------------------------------------------- Plans: categories, keys, staleness.
reset_wp();
$cloud = Store_Parser::parse_products(fixture('standard_cart'), $base, 'cloud-servers');
update_option(Plans::SETTINGS_OPTION, array(
    'whmcs_url' => $base,
    'groups'    => array('cloud-servers' => array('label' => 'Cloud Servers', 'excluded' => array('b3'))),
    'plans'     => array('12' => array('title' => 'Pro <b>', 'description' => 'For "busy" sites.', 'badge' => 'Popular', 'anchor' => 'pro', 'featured' => true, 'order' => '')),
));
update_option(Plans::CACHE_OPTION, array('groups' => array('cloud-servers' => array('plans' => $cloud, 'fetched_at' => time()))));
$visible = Plans::plans_for_group('cloud-servers');
check(3 === count($visible) && !in_array('b3', array_column($visible, 'key'), true), 'string keys exclude a bundle');
check(array('Cloud Servers') === array_values(array_unique(array_column($visible, 'category'))), 'names without a shared pattern collapse into one category');

$vps = array();
foreach (array('Intel 1 vCPU LOCAL', 'Intel 2 vCPU LOCAL', 'AMD 1 vCPU LOCAL', 'AMD 2 vCPU LOCAL') as $i => $name) {
    $vps[] = array('key' => (string) (100 + $i), 'pid' => 100 + $i, 'name' => $name, 'specs' => array(), 'price' => 5.0 + $i, 'price_display' => '$' . (5 + $i) . '.00', 'currency' => 'USD', 'cycle' => 'monthly', 'cycle_text' => 'Monthly', 'label' => '', 'stock' => null, 'in_stock' => true, 'order_url' => 'https://billing.example.com/client/store/vps/' . $i, 'position' => $i, 'price_text' => '');
}
$settings = get_option(Plans::SETTINGS_OPTION);
$settings['groups']['vps'] = array('label' => 'VPS', 'excluded' => array());
update_option(Plans::SETTINGS_OPTION, $settings);
$cache = get_option(Plans::CACHE_OPTION);
$cache['groups']['vps'] = array('plans' => $vps, 'fetched_at' => time());
update_option(Plans::CACHE_OPTION, $cache);
check(array('Intel Local NVMe', 'AMD Local NVMe') === array_values(array_unique(array_column(Plans::plans_for_group('vps'), 'category'))), 'shared naming patterns become category tabs');

$cache['groups']['vps']['fetched_at'] = time() - 49 * HOUR_IN_SECONDS;
update_option(Plans::CACHE_OPTION, $cache);
check(array() === Plans::plans_for_group('vps') && 4 === count(Plans::plans_for_group('vps', false, true)), 'stale cache is hidden from visitors but kept for the admin');
check(Plans::group_status('vps')['stale'], 'group status reports stale');
$cache['groups']['vps']['fetched_at'] = time();
update_option(Plans::CACHE_OPTION, $cache);

$settings['groups']['new-group'] = array('label' => 'New', 'excluded' => array());
update_option(Plans::SETTINGS_OPTION, $settings);
check(array() === Plans::plans_for_group('new-group') && array('plansync_sync_group', array('new-group')) === ($GLOBALS['wp_scheduled'][0] ?? null) && array() === $GLOBALS['wp_http_log'], 'a group without a cache schedules a refresh instead of fetching during the page view');

check('vps' === Plans::sanitize_group('https://billing.example.com/store/vps') && '7' === Plans::sanitize_group('cart.php?gid=7') && '' === Plans::sanitize_group('bad slug!'), 'group identifiers from slugs, store URLs and gids');

// ---------------------------------------------------------------- Renderer.
$out = Renderer::render(array('group' => 'cloud-servers'));
check(3 === substr_count($out, '<article '), 'renders the visible plans');
check(1 === substr_count($out, 'is-out-of-stock') && 1 === substr_count($out, 'aria-disabled="true"'), 'out of stock card cannot be ordered');
check(false !== strpos($out, 'Pro &lt;b&gt;') && false === strpos($out, 'Pro <b>'), 'SEO title is escaped');
check(false !== strpos($out, 'id="pro"') && false !== strpos($out, 'Popular') && false !== strpos($out, 'is-featured'), 'anchor, badge and featured flag');
check(false === strpos($out, 'role="tab"') && false === strpos($out, 'plansync__category'), 'one category: no tabs and no category heading');
check(false !== strpos($out, '<span class="plansync-plan__from">Starting from</span>'), 'shows the store label instead of hard-coded English');
preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $out, $m);
$ld = json_decode($m[1] ?? 'null', true);
check(is_array($ld) && 3 === count($ld['itemListElement']), 'ItemList structured data');
$offers = array_map(static fn ($i) => $i['item']['offers'], $ld['itemListElement'] ?? array());
check(1 === count(array_filter($offers, static fn ($o) => 'https://schema.org/OutOfStock' === $o['availability'])), 'structured data carries stock');
check(!in_array(true, array_map(static fn ($o) => array_key_exists('url', $o) && '' === $o['url'], $offers), true), 'no empty offer URLs');
check('Example Hosting' === ($ld['itemListElement'][0]['item']['brand']['name'] ?? ''), 'brand defaults to the site name');

$second = Renderer::render(array('group' => 'cloud-servers'));
check(false !== strpos($second, 'id="plansync-cloud-servers-2"') && false !== strpos($second, 'id="plansync-cloud-servers-2-pro"'), 'a second block for the same group gets unique ids');

$tabs = Renderer::render(array('group' => 'vps', 'className' => 'is-style-dark my-extra'));
check(2 === substr_count($tabs, 'role="tab"') && 2 === substr_count($tabs, 'tabindex="0"'), 'tabs per category with focusable panels');
check(false !== strpos($tabs, 'class="plansync is-tabs is-style-dark my-extra"'), 'custom classes are kept separate');
$wrapped = Renderer::render(array('group' => 'vps'), 'class="wp-block-arphost-whmcs-plans alignwide" id="x"');
$opening = substr($wrapped, 0, (int) strpos($wrapped, '>'));
check(false !== strpos($opening, 'class="plansync is-tabs wp-block-arphost-whmcs-plans alignwide"') && 1 === substr_count($opening, ' id="'), 'block wrapper classes and alignment are kept, without a duplicate id');

$hidden = Renderer::render(array('group' => 'cloud-servers', 'outOfStock' => 'hide', 'showSchema' => false));
check(2 === substr_count($hidden, '<article ') && false === strpos($hidden, 'ld+json'), 'hide out of stock and schema toggle');
check('$5.00' === Renderer::lowest_price('cloud-servers'), 'lowest in-stock price');
check('' === Renderer::render(array('group' => 'missing')), 'unknown group renders nothing for visitors');
$GLOBALS['wp_can_edit'] = true;
check(false !== strpos(Renderer::render(array('group' => 'missing')), 'plansync-notice'), 'editors get a notice instead');
$GLOBALS['wp_can_edit'] = false;

$eur = Store_Parser::parse_products(fixture('localized_eur'), $base, 'alojamiento');
$cache = get_option(Plans::CACHE_OPTION);
$cache['groups']['alojamiento'] = array('plans' => $eur, 'fetched_at' => time());
update_option(Plans::CACHE_OPTION, $cache);
$settings = get_option(Plans::SETTINGS_OPTION);
$settings['groups']['alojamiento'] = array('label' => 'Alojamiento', 'excluded' => array());
update_option(Plans::SETTINGS_OPTION, $settings);
$es_out = Renderer::render(array('group' => 'alojamiento'));
preg_match('#ld\+json">(.*?)</script>#s', $es_out, $m);
$es_ld = json_decode($m[1] ?? 'null', true);
check(1 === count($es_ld['itemListElement'] ?? array()) && 'EUR' === $es_ld['itemListElement'][0]['item']['offers']['priceCurrency'], 'plans without a known currency are left out of structured data');
check(false !== strpos($es_out, '<span class="plansync-plan__cycle"> Mensual</span>'), 'unknown billing cycle words are shown as written');

// ---------------------------------------------------------------- Fetching: URL styles and redirects.
reset_wp();
update_option(Plans::SETTINGS_OPTION, array('whmcs_url' => $base, 'groups' => array('cloud-servers' => array('label' => 'Cloud', 'excluded' => array()))));
$GLOBALS['wp_http'][$base . '/index.php?rp=/store/cloud-servers'] = fixture('premium_comparison');
$r = Plans::sync_group('cloud-servers');
check($r['ok'] && 3 === $r['count'] && array($base . '/store/cloud-servers', $base . '/index.php?rp=/store/cloud-servers') === $GLOBALS['wp_http_log'], 'falls back to index.php?rp= when friendly URLs are off');
check(1 === ($GLOBALS['w3tc_flushes'] ?? 0) && in_array('plansync_plans_changed', $GLOBALS['wp_actions_fired'] ?? array(), true), 'a refresh that changes plans purges the page cache');
$GLOBALS['wp_http_log'] = array();
Plans::sync_group('cloud-servers');
check(1 === $GLOBALS['w3tc_flushes'], 'a refresh with no changes leaves the page cache alone');
$GLOBALS['wp_filters']['plansync_purge_page_cache'] = false;
Plans::purge_page_caches();
check(1 === $GLOBALS['w3tc_flushes'], 'the purge can be turned off with a filter');
unset($GLOBALS['wp_filters']['plansync_purge_page_cache']);

reset_wp();
update_option(Plans::SETTINGS_OPTION, array('whmcs_url' => $base, 'groups' => array('empty' => array('label' => 'Empty', 'excluded' => array()))));
$GLOBALS['wp_http'][$base . '/store/empty'] = array('code' => 302, 'body' => '', 'headers' => array('location' => $base . '/store/cloud-servers'));
$GLOBALS['wp_http'][$base . '/index.php?rp=/store/empty'] = array('code' => 302, 'body' => '', 'headers' => array('location' => $base . '/store/cloud-servers'));
$GLOBALS['wp_http'][$base . '/store/cloud-servers'] = fixture('standard_cart');
$r = Plans::sync_group('empty');
check(!$r['ok'] && false !== strpos($r['message'], 'empty, hidden from the store') && 0 === Plans::group_status('empty')['count'], 'a redirect to another group is an error, not that group\'s plans');

reset_wp();
update_option(Plans::SETTINGS_OPTION, array('whmcs_url' => $base, 'connect_ip' => '127.0.0.1', 'groups' => array('cloud-servers' => array('label' => 'Cloud', 'excluded' => array()))));
$GLOBALS['wp_http'][$base . '/store/cloud-servers'] = array('code' => 301, 'body' => '', 'headers' => array('location' => 'https://dev.example.net/'));
$r = Plans::sync_group('cloud-servers');
check(!$r['ok'] && false !== strpos($r['message'], 'Connect to IP'), 'a redirect to another host explains the Connect to IP setting');

reset_wp();
update_option(Plans::SETTINGS_OPTION, array('whmcs_url' => $base, 'groups' => array('cloud-servers' => array('label' => 'Cloud', 'excluded' => array()))));
$GLOBALS['wp_http'][$base . '/store/cloud-servers'] = array('code' => 301, 'body' => '', 'headers' => array('location' => $base . '/store/cloud-servers/'));
$GLOBALS['wp_http'][$base . '/store/cloud-servers/'] = fixture('pure_comparison');
check(Plans::sync_group('cloud-servers')['ok'], 'a trailing-slash redirect is followed');

$GLOBALS['wp_http'][$base . '/store/cloud-servers'] = fixture('supreme_comparison');
Plans::sync_group('cloud-servers');
$GLOBALS['wp_http'][$base . '/store/cloud-servers'] = '<html><body>Down for maintenance</body></html>';
$r = Plans::sync_group('cloud-servers');
check(!$r['ok'] && 3 === Plans::group_status('cloud-servers')['count'] && '' !== Plans::group_status('cloud-servers')['error'], 'a page without products keeps the last good copy and records the error');

reset_wp();
update_option(Plans::SETTINGS_OPTION, array('whmcs_url' => $base, 'groups' => array('7' => array('label' => 'Dedicated', 'excluded' => array()))));
$GLOBALS['wp_http'][$base . '/cart.php?gid=7'] = fixture('cloud_slider');
check(Plans::sync_group('7')['ok'] && 3 === Plans::group_status('7')['count'], 'numeric group ids use cart.php?gid=');

set_transient('plansync_sync_lock', time());
$busy = Plans::sync_all();
check(is_wp_error($busy) && 'plansync_busy' === $busy->get_error_code(), 'a refresh while another runs reports busy');
delete_transient('plansync_sync_lock');

// ---------------------------------------------------------------- Migration from the pre-release name.
reset_wp();
update_option('arphost_whmcs_plans_settings', array('whmcs_url' => 'https://billing.example.com', 'groups' => array('vps' => array('label' => 'VPS', 'excluded' => array(5)))));
Plans::migrate();
check('https://billing.example.com' === Plans::settings()['whmcs_url'] && false === get_option('arphost_whmcs_plans_settings'), 'settings move from the old option name');
check(array('5') === array_map('strval', Plans::settings()['groups']['vps']['excluded']), 'old numeric exclusions still match string keys');
reset_wp();
update_option('whmcs_plans_settings', array('whmcs_url' => 'https://store.example.org', 'groups' => array('web' => array('label' => 'Web'))));
update_option('whmcs_plans_cache', array('web' => array('plans' => array(), 'fetched_at' => 1)));
update_option('whmcs_plans_version', '1.1.0');
Plans::migrate();
check('https://store.example.org' === Plans::settings()['whmcs_url'] && false === get_option('whmcs_plans_settings') && false === get_option('whmcs_plans_version') && is_array(get_option('plansync_cache')), 'settings move from the whmcs-plans build');

echo $failures ? "\n{$failures} test(s) failed\n" : "\nAll tests passed\n";
exit($failures ? 1 : 0);
