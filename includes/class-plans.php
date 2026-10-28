<?php
/**
 * Settings, cache and sync for WHMCS plans.
 *
 * @package PlanSync
 */

namespace Arphost\PlanSync;

if (!defined('ABSPATH')) {
    exit;
}

final class Plans
{
    public const SETTINGS_OPTION = 'plansync_settings';
    public const CACHE_OPTION = 'plansync_cache';
    public const CRON_HOOK = 'plansync_sync';
    public const CRON_SINGLE_HOOK = 'plansync_sync_group';
    public const CRON_SCHEDULE = 'plansync_interval';
    private const LOCK_TRANSIENT = 'plansync_sync_lock';
    private const LOCK_SECONDS = 600;
    private const LEGACY_OPTIONS = array(
        'arphost_whmcs_plans_settings' => self::SETTINGS_OPTION,
        'arphost_whmcs_plans_cache'    => self::CACHE_OPTION,
        'whmcs_plans_settings'         => self::SETTINGS_OPTION,
        'whmcs_plans_cache'            => self::CACHE_OPTION,
    );

    /** @var array{host: string, port: int, ip: string}|null Connection pin for the request in flight. */
    private static $pin = null;

    public static function defaults(): array
    {
        return array(
            'whmcs_url'     => '',
            'connect_ip'    => '',
            'sync_minutes'  => 60,
            'max_age_hours' => 48,
            'contact_url'   => '',
            'brand'         => '',
            'currency'      => '',
            'schema'        => true,
            'groups'        => array(),
            'plans'         => array(),
        );
    }

    /**
     * Move settings saved by the earlier "arphost-whmcs-plans" and "whmcs-plans" builds.
     */
    public static function migrate(): void
    {
        foreach (self::LEGACY_OPTIONS as $old => $new) {
            $value = get_option($old, null);
            if (null !== $value && null === get_option($new, null)) {
                update_option($new, $value, false);
            }
            if (null !== $value) {
                delete_option($old);
            }
        }
        wp_clear_scheduled_hook('arphost_whmcs_plans_sync');
        wp_clear_scheduled_hook('whmcs_plans_sync');
        wp_unschedule_hook('whmcs_plans_sync_group');
        delete_option('whmcs_plans_version');
        delete_transient('whmcs_plans_sync_lock');
    }

    public static function url_locked(): bool
    {
        return defined('PLANSYNC_WHMCS_URL') || defined('WHMCS_PLANS_URL') || defined('ARPHOST_WHMCS_PLANS_URL');
    }

    public static function ip_locked(): bool
    {
        return defined('PLANSYNC_CONNECT_IP') || defined('WHMCS_PLANS_CONNECT_IP') || defined('ARPHOST_WHMCS_PLANS_CONNECT_IP');
    }

    public static function settings(): array
    {
        $saved = get_option(self::SETTINGS_OPTION, array());
        $settings = array_merge(self::defaults(), is_array($saved) ? $saved : array());

        foreach (array('PLANSYNC_WHMCS_URL', 'WHMCS_PLANS_URL', 'ARPHOST_WHMCS_PLANS_URL') as $constant) {
            if (defined($constant) && constant($constant)) {
                $settings['whmcs_url'] = (string) constant($constant);
                break;
            }
        }
        foreach (array('PLANSYNC_CONNECT_IP', 'WHMCS_PLANS_CONNECT_IP', 'ARPHOST_WHMCS_PLANS_CONNECT_IP') as $constant) {
            if (defined($constant)) {
                $settings['connect_ip'] = (string) constant($constant);
                break;
            }
        }

        $settings['whmcs_url'] = rtrim((string) $settings['whmcs_url'], '/');
        $settings['sync_minutes'] = max(5, min(1440, (int) $settings['sync_minutes']));
        $settings['max_age_hours'] = max(0, min(8760, (int) $settings['max_age_hours']));
        $settings['groups'] = is_array($settings['groups']) ? $settings['groups'] : array();
        $settings['plans'] = is_array($settings['plans']) ? $settings['plans'] : array();
        if ('' === trim((string) $settings['brand'])) {
            $settings['brand'] = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
        }
        return $settings;
    }

    public static function save_settings(array $settings): void
    {
        update_option(self::SETTINGS_OPTION, $settings, false);
        self::reschedule();
    }

    public static function cache(): array
    {
        $cache = get_option(self::CACHE_OPTION, array());
        $cache = is_array($cache) ? $cache : array();
        $cache['groups'] = isset($cache['groups']) && is_array($cache['groups']) ? $cache['groups'] : array();
        $cache['discovered'] = isset($cache['discovered']) && is_array($cache['discovered']) ? $cache['discovered'] : array();
        return $cache;
    }

    private static function save_cache(array $cache): void
    {
        update_option(self::CACHE_OPTION, $cache, false);
    }

    /**
     * Normalize a group identifier: a store slug or a numeric WHMCS group id.
     */
    public static function sanitize_group(string $group): string
    {
        $group = strtolower(trim($group));
        if (preg_match('#/store/([a-z0-9][a-z0-9_-]*)#', $group, $m)) {
            $group = $m[1];
        } elseif (preg_match('#[?&]gid=(\d+)#', $group, $m)) {
            $group = $m[1];
        }
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $group) ? $group : '';
    }

    /**
     * Register a group so it is synced and selectable in the block.
     */
    public static function add_group(string $group, string $label = ''): bool
    {
        $group = self::sanitize_group($group);
        if ('' === $group) {
            return false;
        }
        $settings = self::settings();
        if (!isset($settings['groups'][$group])) {
            $settings['groups'][$group] = array(
                'label'    => '' !== $label ? sanitize_text_field($label) : ucwords(str_replace(array('-', '_'), ' ', $group)),
                'excluded' => array(),
            );
            self::save_settings($settings);
        }
        return true;
    }

    public static function remove_group(string $group): void
    {
        $settings = self::settings();
        unset($settings['groups'][$group]);
        self::save_settings($settings);

        $cache = self::cache();
        unset($cache['groups'][$group]);
        self::save_cache($cache);
    }

    /**
     * Fetch every configured group. Errors keep the last good copy.
     *
     * @return array<string, array{ok: bool, count: int, message: string}>|\WP_Error WP_Error when another refresh is running.
     */
    public static function sync_all()
    {
        if (!self::acquire_lock()) {
            return new \WP_Error('plansync_busy', __('Another refresh is already running. Try again in a few minutes.', 'plansync-for-whmcs'));
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $results = array();
        foreach (array_keys(self::settings()['groups']) as $group) {
            $results[(string) $group] = self::sync_group((string) $group, false);
        }
        self::discover_groups();
        self::release_lock();
        return $results;
    }

    /**
     * Clear full-page caches so new prices and stock show up right away.
     * Runs when a refresh changes a group, when settings are saved and on
     * activation. Return false from the plansync_purge_page_cache filter to
     * handle purging yourself (listen for plansync_plans_changed).
     */
    public static function purge_page_caches(): void
    {
        if (!apply_filters('plansync_purge_page_cache', true)) {
            return;
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache(true);
        }
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }
        do_action('litespeed_purge_all');
        do_action('breeze_clear_all_cache');
        do_action('cache_enabler_clear_complete_cache');
        do_action('plansync_purge_caches');
    }

    /**
     * Cron callback for a single group (used right after a group is added).
     */
    public static function cron_sync_group($group): void
    {
        self::sync_group((string) $group);
    }

    /**
     * @return array{ok: bool, count: int, message: string}
     */
    public static function sync_group(string $group, bool $lock = true): array
    {
        if ($lock && !self::acquire_lock()) {
            return array('ok' => false, 'count' => 0, 'message' => __('Another refresh is already running. Try again in a few minutes.', 'plansync-for-whmcs'));
        }

        $settings = self::settings();
        $cache = self::cache();
        $entry = isset($cache['groups'][$group]) ? $cache['groups'][$group] : array('plans' => array(), 'fetched_at' => 0);

        $result = self::fetch_group_page($group);
        if (is_wp_error($result)) {
            $message = $result->get_error_message();
        } else {
            $plans = Store_Parser::parse_products($result['body'], $settings['whmcs_url'], $group);
            if (empty($plans)) {
                $message = __('The store page loaded but no products were found. The group may be empty, or its order form template is not supported. The last good copy was kept.', 'plansync-for-whmcs');
            } else {
                $cache = self::cache();
                $changed = !isset($cache['groups'][$group]['plans']) || $cache['groups'][$group]['plans'] !== $plans;
                $cache['groups'][$group] = array(
                    'plans'      => $plans,
                    'fetched_at' => time(),
                    'url'        => $result['url'],
                    'error'      => '',
                    'error_at'   => 0,
                );
                $groups = Store_Parser::parse_groups($result['body'], $settings['whmcs_url']);
                if (!empty($groups)) {
                    $cache['discovered'] = $groups;
                    $cache['discovered_at'] = time();
                }
                self::save_cache($cache);
                if ($lock) {
                    self::release_lock();
                }
                do_action('plansync_synced', $group, $plans);
                if ($changed) {
                    do_action('plansync_plans_changed', $group, $plans);
                    self::purge_page_caches();
                }
                return array('ok' => true, 'count' => count($plans), 'message' => '');
            }
        }

        $cache = self::cache();
        $entry['error'] = $message;
        $entry['error_at'] = time();
        $cache['groups'][$group] = $entry;
        self::save_cache($cache);
        if ($lock) {
            self::release_lock();
        }
        return array('ok' => false, 'count' => count($entry['plans']), 'message' => $message);
    }

    /**
     * Refresh the list of product groups linked from the store.
     */
    public static function discover_groups(): array
    {
        $cache = self::cache();
        if (!empty($cache['discovered']) && !empty($cache['discovered_at']) && (time() - (int) $cache['discovered_at']) < DAY_IN_SECONDS) {
            return $cache['discovered'];
        }
        $base = self::settings()['whmcs_url'];
        if ('' === $base) {
            return $cache['discovered'];
        }
        foreach (array($base . '/store', $base . '/cart.php') as $url) {
            $response = self::request($url);
            if (is_wp_error($response) || 200 !== $response['code']) {
                continue;
            }
            $groups = Store_Parser::parse_groups($response['body'], $base);
            if (!empty($groups)) {
                $cache['discovered'] = $groups;
                $cache['discovered_at'] = time();
                self::save_cache($cache);
                break;
            }
        }
        return $cache['discovered'];
    }

    /**
     * Plans for a group with SEO overrides applied and exclusions removed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function plans_for_group(string $group, bool $include_excluded = false, bool $include_stale = false): array
    {
        $settings = self::settings();
        $cache = self::cache();

        if (!isset($cache['groups'][$group])) {
            if (isset($settings['groups'][$group])) {
                self::schedule_group_sync($group);
            }
            return array();
        }

        $entry = $cache['groups'][$group];
        if (!$include_stale && self::is_stale($entry, $settings)) {
            return array();
        }

        $plans = isset($entry['plans']) && is_array($entry['plans']) ? $entry['plans'] : array();
        $excluded = isset($settings['groups'][$group]['excluded']) ? array_map('strval', (array) $settings['groups'][$group]['excluded']) : array();

        $out = array();
        $has_custom_category = false;
        foreach ($plans as $plan) {
            $key = isset($plan['key']) ? (string) $plan['key'] : (string) ($plan['pid'] ?? '');
            $plan['key'] = $key;
            $meta = isset($settings['plans'][$key]) && is_array($settings['plans'][$key]) ? $settings['plans'][$key] : array();
            $plan['excluded'] = in_array($key, $excluded, true);
            if ($plan['excluded'] && !$include_excluded) {
                continue;
            }
            $plan += array('label' => '', 'cycle_text' => '', 'price_text' => '', 'bid' => null);
            $plan['title'] = !empty($meta['title']) ? $meta['title'] : $plan['name'];
            $plan['description'] = isset($meta['description']) ? (string) $meta['description'] : '';
            $plan['badge'] = isset($meta['badge']) ? (string) $meta['badge'] : '';
            $plan['custom_category'] = !empty($meta['category']) ? (string) $meta['category'] : '';
            $has_custom_category = $has_custom_category || '' !== $plan['custom_category'];
            $plan['anchor'] = !empty($meta['anchor']) ? (string) $meta['anchor'] : sanitize_title($plan['name']);
            $plan['featured'] = !empty($meta['featured']);
            $plan['sort'] = (isset($meta['order']) && '' !== $meta['order']) ? (int) $meta['order'] : 1000 + (int) $plan['position'];
            $out[] = $plan;
        }

        // Categories: use the ones set in the admin, else derive them from the
        // names, but only when that actually groups plans together.
        $derived = array();
        foreach ($out as $plan) {
            $derived[] = self::derive_category($plan['name']);
        }
        $counts = array_count_values($derived);
        $useful = !empty($counts) && max($counts) > 1 && count($counts) <= 8 && count($counts) < count($out);
        $fallback = isset($settings['groups'][$group]['label']) ? (string) $settings['groups'][$group]['label'] : '';
        foreach ($out as $i => $plan) {
            if ('' !== $plan['custom_category']) {
                $out[$i]['category'] = $plan['custom_category'];
            } elseif ($useful && !$has_custom_category) {
                $out[$i]['category'] = $derived[$i];
            } else {
                $out[$i]['category'] = $fallback;
            }
        }

        usort($out, static function ($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });
        return $out;
    }

    public static function is_stale(array $entry, ?array $settings = null): bool
    {
        $settings = $settings ?: self::settings();
        $max = (int) $settings['max_age_hours'];
        $fetched = isset($entry['fetched_at']) ? (int) $entry['fetched_at'] : 0;
        return $max > 0 && $fetched > 0 && (time() - $fetched) > $max * HOUR_IN_SECONDS;
    }

    public static function group_status(string $group): array
    {
        $cache = self::cache();
        $entry = isset($cache['groups'][$group]) ? $cache['groups'][$group] : array();
        $plans = isset($entry['plans']) ? $entry['plans'] : array();
        return array(
            'count'        => count($plans),
            'out_of_stock' => count(array_filter($plans, static function ($p) {
                return empty($p['in_stock']);
            })),
            'fetched_at'   => isset($entry['fetched_at']) ? (int) $entry['fetched_at'] : 0,
            'stale'        => !empty($entry) && self::is_stale($entry),
            'url'          => isset($entry['url']) ? (string) $entry['url'] : '',
            'error'        => isset($entry['error']) ? (string) $entry['error'] : '',
            'error_at'     => isset($entry['error_at']) ? (int) $entry['error_at'] : 0,
        );
    }

    /**
     * "Intel 4 vCPU LOCAL" becomes "Intel Local NVMe".
     */
    public static function derive_category(string $name): string
    {
        $label = preg_replace('/\b\d+\s*v?CPU(s)?\b/i', '', $name);
        $label = preg_replace('/\bLOCAL\b/', 'Local NVMe', $label);
        $label = preg_replace('/\bCEPH\b/', 'Ceph NVMe', $label);
        $label = trim(preg_replace('/\s+/', ' ', $label));
        return '' !== $label ? $label : $name;
    }

    /**
     * Load a group page, trying each WHMCS URL style. A redirect to another
     * page means the group is empty, hidden or misspelled, because WHMCS sends
     * unknown groups to its first group.
     *
     * @return array{body: string, url: string}|\WP_Error
     */
    private static function fetch_group_page(string $group)
    {
        $base = self::settings()['whmcs_url'];
        if ('' === $base) {
            return new \WP_Error('plansync_no_url', __('Set the WHMCS URL first.', 'plansync-for-whmcs'));
        }

        if (ctype_digit($group)) {
            $candidates = array($base . '/cart.php?gid=' . $group);
        } else {
            $candidates = array(
                $base . '/store/' . rawurlencode($group),
                $base . '/index.php?rp=/store/' . rawurlencode($group),
            );
        }

        $last_error = null;
        foreach ($candidates as $url) {
            $response = self::request($url);
            if (is_wp_error($response)) {
                $last_error = $response;
                continue;
            }
            if (200 === $response['code']) {
                return array('body' => $response['body'], 'url' => $url);
            }
            if ($response['code'] >= 300 && $response['code'] < 400) {
                $location = (string) $response['location'];
                $same = self::same_page($url, $location);
                if ($same) {
                    $follow = self::request($location);
                    if (!is_wp_error($follow) && 200 === $follow['code']) {
                        return array('body' => $follow['body'], 'url' => $location);
                    }
                }
                $last_error = new \WP_Error('plansync_redirect', self::redirect_message($url, $location));
                // A redirect away from the friendly URL can mean rewriting is off; try the next style.
                continue;
            }
            if ($last_error && 'plansync_redirect' === $last_error->get_error_code()) {
                continue; // A redirect explains more than a 404 from the next URL style.
            }
            $last_error = new \WP_Error('plansync_http', sprintf(
                /* translators: 1: HTTP status code, 2: URL */
                __('WHMCS returned HTTP %1$d for %2$s.', 'plansync-for-whmcs'),
                $response['code'],
                $url
            ));
        }
        return $last_error ?: new \WP_Error('plansync_http', __('The store page could not be loaded.', 'plansync-for-whmcs'));
    }

    private static function redirect_message(string $url, string $location): string
    {
        $from_host = (string) wp_parse_url($url, PHP_URL_HOST);
        $to_host = (string) wp_parse_url($location, PHP_URL_HOST);
        if ('' !== self::settings()['connect_ip'] && '' !== $to_host && $to_host !== $from_host) {
            return sprintf(
                /* translators: 1: requested URL, 2: redirect target */
                __('Requesting %1$s through the "Connect to IP" address redirected to %2$s. That server does not seem to host your WHMCS site. Clear "Connect to IP" or use the address of the WHMCS server.', 'plansync-for-whmcs'),
                $url,
                $location
            );
        }
        if (false !== strpos((string) wp_parse_url($location, PHP_URL_PATH) . '?' . (string) wp_parse_url($location, PHP_URL_QUERY), '/store/')) {
            return sprintf(
                /* translators: 1: requested URL, 2: redirect target */
                __('WHMCS redirected %1$s to %2$s. The group is empty, hidden from the store, or the slug is wrong.', 'plansync-for-whmcs'),
                $url,
                $location
            );
        }
        return sprintf(
            /* translators: 1: requested URL, 2: redirect target */
            __('WHMCS redirected %1$s to %2$s instead of showing the group.', 'plansync-for-whmcs'),
            $url,
            $location
        );
    }

    /**
     * True when a redirect only changes scheme, host case or a trailing slash.
     */
    private static function same_page(string $from, string $to): bool
    {
        if ('' === $to) {
            return false;
        }
        $a = wp_parse_url($from);
        $b = wp_parse_url($to);
        if (!$a || !$b || empty($b['host'])) {
            return false;
        }
        return strtolower($a['host'] ?? '') === strtolower($b['host'])
            && rtrim($a['path'] ?? '', '/') === rtrim($b['path'] ?? '', '/')
            && ($a['query'] ?? '') === ($b['query'] ?? '');
    }

    /**
     * GET a URL without following redirects, optionally pinning the WHMCS host
     * to a fixed IP so the request reaches the origin instead of a CDN.
     *
     * @return array{code: int, body: string, location: string}|\WP_Error
     */
    private static function request(string $url)
    {
        $settings = self::settings();
        $ip = trim((string) $settings['connect_ip']);
        $parts = wp_parse_url($url);
        self::$pin = null;
        if ('' !== $ip && filter_var($ip, FILTER_VALIDATE_IP) && !empty($parts['host'])) {
            self::$pin = array(
                'host' => (string) $parts['host'],
                'port' => isset($parts['port']) ? (int) $parts['port'] : ('http' === ($parts['scheme'] ?? 'https') ? 80 : 443),
                'ip'   => $ip,
            );
        }

        $response = wp_remote_get($url, array(
            'timeout'     => 20,
            'redirection' => 0,
            'user-agent'  => 'WHMCS-Plans/' . PLANSYNC_VERSION . '; ' . home_url('/'),
            'headers'     => array('Accept' => 'text/html'),
        ));
        self::$pin = null;

        if (is_wp_error($response)) {
            return $response;
        }
        $location = (string) wp_remote_retrieve_header($response, 'location');
        if ('' !== $location && !preg_match('#^https?://#i', $location)) {
            $location = \WP_Http::make_absolute_url($location, $url);
        }
        return array(
            'code'     => (int) wp_remote_retrieve_response_code($response),
            'body'     => (string) wp_remote_retrieve_body($response),
            'location' => $location,
        );
    }

    /**
     * cURL hook: resolve the WHMCS host to the configured IP for our request only.
     *
     * @param resource|\CurlHandle $handle
     */
    public static function pin_connection($handle, array $request, string $url): void
    {
        if (null === self::$pin || !defined('CURLOPT_RESOLVE')) {
            return;
        }
        if (strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== strtolower(self::$pin['host'])) {
            return;
        }
        $ip = false !== strpos(self::$pin['ip'], ':') ? '[' . self::$pin['ip'] . ']' : self::$pin['ip'];
        curl_setopt($handle, CURLOPT_RESOLVE, array(self::$pin['host'] . ':' . self::$pin['port'] . ':' . $ip));
    }

    private static function acquire_lock(): bool
    {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }
        set_transient(self::LOCK_TRANSIENT, time(), self::LOCK_SECONDS);
        return true;
    }

    private static function release_lock(): void
    {
        delete_transient(self::LOCK_TRANSIENT);
    }

    public static function schedule_group_sync(string $group): void
    {
        if (!wp_next_scheduled(self::CRON_SINGLE_HOOK, array($group))) {
            wp_schedule_single_event(time(), self::CRON_SINGLE_HOOK, array($group));
        }
    }

    public static function cron_schedules(array $schedules): array
    {
        $minutes = self::settings()['sync_minutes'];
        $schedules[self::CRON_SCHEDULE] = array(
            'interval' => $minutes * MINUTE_IN_SECONDS,
            /* translators: %d: minutes */
            'display'  => sprintf(__('Every %d minutes (PlanSync)', 'plansync-for-whmcs'), $minutes),
        );
        return $schedules;
    }

    public static function reschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        if (!empty(self::settings()['groups'])) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function ensure_scheduled(): void
    {
        if (!empty(self::settings()['groups']) && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }
}
