<?php
/**
 * PlanSync admin screen (ARPHost branded).
 *
 * @package PlanSync
 */

namespace Arphost\PlanSync;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin
{
    public const PAGE = 'plansync-for-whmcs';
    private const ACTION = 'plansync_admin';

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_filter('plugin_action_links_' . plugin_basename(PLANSYNC_FILE), array(__CLASS__, 'action_links'));
        add_filter('plugin_row_meta', array(__CLASS__, 'row_meta'), 10, 2);
    }

    public static function menu(): void
    {
        add_menu_page(
            __('PlanSync for WHMCS', 'plansync-for-whmcs'),
            __('PlanSync', 'plansync-for-whmcs'),
            'manage_options',
            self::PAGE,
            array(__CLASS__, 'page'),
            self::menu_icon(),
            81
        );
    }

    /**
     * The ARPHost "A" mark as a monochrome SVG so WordPress can tint it.
     */
    private static function menu_icon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 372 360"><path fill="black" d="M125 118 L173 212 L98 360 L0 360 Z M138 92 L186 0 L372 360 L272 360 Z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function assets(string $hook): void
    {
        if ('toplevel_page_' . self::PAGE !== $hook) {
            return;
        }
        wp_enqueue_style('plansync-admin', PLANSYNC_URI . 'assets/admin.css', array(), PLANSYNC_VERSION);
    }

    public static function action_links(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">' . esc_html__('Settings', 'plansync-for-whmcs') . '</a>');
        return $links;
    }

    public static function row_meta(array $meta, string $file): array
    {
        if (plugin_basename(PLANSYNC_FILE) === $file) {
            $meta[] = '<a href="' . esc_url(PLANSYNC_BRAND_URL) . '" target="_blank" rel="noopener">' . esc_html__('Made by ARPHost', 'plansync-for-whmcs') . '</a>';
        }
        return $meta;
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change PlanSync.', 'plansync-for-whmcs'), 403);
        }
        check_admin_referer(self::ACTION);

        $post = wp_unslash($_POST);
        $task = sanitize_key((string) ($post['task'] ?? ''));
        $settings = Plans::settings();

        switch ($task) {
            case 'connection':
                if (!Plans::url_locked()) {
                    $settings['whmcs_url'] = esc_url_raw(trim((string) ($post['whmcs_url'] ?? '')), array('http', 'https'));
                }
                if (!Plans::ip_locked()) {
                    $ip = trim((string) ($post['connect_ip'] ?? ''));
                    if ('' !== $ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
                        self::redirect('bad_ip');
                    }
                    $settings['connect_ip'] = $ip;
                }
                $settings['sync_minutes'] = max(5, min(1440, (int) ($post['sync_minutes'] ?? 60)));
                $settings['max_age_hours'] = max(0, min(8760, (int) ($post['max_age_hours'] ?? 48)));
                $settings['contact_url'] = esc_url_raw(trim((string) ($post['contact_url'] ?? '')));
                $settings['brand'] = sanitize_text_field((string) ($post['brand'] ?? ''));
                $currency = strtoupper(sanitize_text_field((string) ($post['currency'] ?? '')));
                $settings['currency'] = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : '';
                $settings['schema'] = !empty($post['schema']);
                Plans::save_settings($settings);
                self::redirect('saved');
                break;

            case 'add_group':
                $group = Plans::sanitize_group((string) ($post['add_group_custom'] ?? ''));
                if ('' === $group) {
                    $group = Plans::sanitize_group((string) ($post['add_group'] ?? ''));
                }
                if ('' === $group) {
                    self::redirect('bad_group');
                }
                $discovered = Plans::cache()['discovered'];
                Plans::add_group($group, isset($discovered[$group]) ? (string) $discovered[$group] : '');
                $result = Plans::sync_group($group);
                self::redirect($result['ok'] ? 'added' : 'add_failed', $group);
                break;

            case 'refresh_all':
                $results = Plans::sync_all();
                if (is_wp_error($results)) {
                    self::redirect('busy');
                }
                self::redirect(in_array(false, array_column($results, 'ok'), true) ? 'refresh_partial' : 'refreshed');
                break;

            case 'group':
                $group = Plans::sanitize_group((string) ($post['group'] ?? ''));
                if ('' === $group || !isset($settings['groups'][$group])) {
                    self::redirect('bad_group');
                }
                if (!empty($post['remove'])) {
                    Plans::remove_group($group);
                    self::redirect('removed');
                }
                self::save_group($settings, $group, $post);
                if (!empty($post['refresh'])) {
                    $result = Plans::sync_group($group);
                    self::redirect($result['ok'] ? 'refreshed' : 'refresh_failed', $group);
                }
                self::redirect('saved', $group);
                break;
        }
        self::redirect('');
    }

    private static function save_group(array $settings, string $group, array $post): void
    {
        $settings['groups'][$group]['label'] = sanitize_text_field((string) ($post['group_label'] ?? $settings['groups'][$group]['label']));

        $seen = array_map('strval', (array) ($post['seen'] ?? array()));
        $shown = array_map('strval', (array) ($post['show'] ?? array()));
        $previous = array_map('strval', (array) ($settings['groups'][$group]['excluded'] ?? array()));
        // Keep exclusions for plans not on screen (for example retired ones).
        $excluded = array_diff($previous, $seen);
        foreach ($seen as $key) {
            if (!in_array($key, $shown, true)) {
                $excluded[] = $key;
            }
        }
        $settings['groups'][$group]['excluded'] = array_values(array_unique($excluded));

        foreach ((array) ($post['plan'] ?? array()) as $key => $fields) {
            $key = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $key);
            if ('' === $key || !is_array($fields)) {
                continue;
            }
            $meta = array(
                'title'       => sanitize_text_field((string) ($fields['title'] ?? '')),
                'description' => sanitize_textarea_field((string) ($fields['description'] ?? '')),
                'category'    => sanitize_text_field((string) ($fields['category'] ?? '')),
                'badge'       => sanitize_text_field((string) ($fields['badge'] ?? '')),
                'anchor'      => sanitize_title((string) ($fields['anchor'] ?? '')),
                'order'       => ('' === trim((string) ($fields['order'] ?? ''))) ? '' : (int) $fields['order'],
                'featured'    => !empty($fields['featured']),
            );
            if (!$meta['featured'] && '' === implode('', array_map('strval', array_diff_key($meta, array('featured' => 1))))) {
                unset($settings['plans'][$key]);
            } else {
                $settings['plans'][$key] = $meta;
            }
        }
        Plans::save_settings($settings);
    }

    private static function redirect(string $notice, string $group = ''): void
    {
        $args = array('page' => self::PAGE, 'notice' => $notice);
        if ('' !== $group) {
            $args['group'] = $group;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . ('' !== $group ? '#plansync-group-' . $group : ''));
        exit;
    }

    private static function form_open(string $task, array $hidden = array()): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="task" value="' . esc_attr($task) . '">';
        foreach ($hidden as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        // One form per section, so build the nonce without the shared id="_wpnonce".
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::ACTION)) . '">';
        wp_referer_field();
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = Plans::settings();
        $cache = Plans::cache();
        $notice = isset($_GET['notice']) ? sanitize_key((string) $_GET['notice']) : '';
        $messages = array(
            'saved'           => array('success', __('Settings saved.', 'plansync-for-whmcs')),
            'refreshed'       => array('success', __('Plans refreshed from WHMCS.', 'plansync-for-whmcs')),
            'refresh_partial' => array('warning', __('Some groups could not be refreshed. See the errors below.', 'plansync-for-whmcs')),
            'refresh_failed'  => array('warning', __('The group could not be refreshed. See the error below.', 'plansync-for-whmcs')),
            'busy'            => array('warning', __('Another refresh is already running. Try again in a few minutes.', 'plansync-for-whmcs')),
            'added'           => array('success', __('Product group added and imported.', 'plansync-for-whmcs')),
            'add_failed'      => array('warning', __('The group was added, but importing it failed. See the error below.', 'plansync-for-whmcs')),
            'removed'         => array('success', __('Product group removed.', 'plansync-for-whmcs')),
            'bad_group'       => array('error', __('Enter a store group slug such as "web-hosting", a store URL, or a numeric group id.', 'plansync-for-whmcs')),
            'bad_ip'          => array('error', __('"Connect to IP" must be a valid IPv4 or IPv6 address, or empty.', 'plansync-for-whmcs')),
        );
        $url_locked = Plans::url_locked();
        $ip_locked = Plans::ip_locked();
        ?>
        <div class="wrap plansync-admin">
            <header class="plansync-admin__brand">
                <a href="<?php echo esc_url(PLANSYNC_BRAND_URL); ?>" target="_blank" rel="noopener" class="plansync-admin__logo">
                    <img src="<?php echo esc_url(PLANSYNC_URI . 'assets/images/arphost-logo.png'); ?>" alt="ARPHost" width="175" height="50">
                </a>
                <div>
                    <h1><?php esc_html_e('PlanSync', 'plansync-for-whmcs'); ?></h1>
                    <p><?php esc_html_e('Plans, prices and stock from your WHMCS store, rendered as SEO-friendly cards.', 'plansync-for-whmcs'); ?></p>
                </div>
            </header>

            <?php if (isset($messages[$notice])) : ?>
                <div class="notice notice-<?php echo esc_attr($messages[$notice][0]); ?> is-dismissible"><p><?php echo esc_html($messages[$notice][1]); ?></p></div>
            <?php endif; ?>

            <div class="plansync-admin__card">
                <p><?php echo wp_kses(sprintf(
                    /* translators: 1: block name, 2: shortcode */
                    __('Add the %1$s block to any page, or use %2$s.', 'plansync-for-whmcs'),
                    '<strong>' . esc_html__('PlanSync', 'plansync-for-whmcs') . '</strong>',
                    '<code>[plansync group="your-group-slug"]</code>'
                ), array('strong' => array(), 'code' => array())); ?></p>
            </div>

            <div class="plansync-admin__card">
                <h2><?php esc_html_e('Connection', 'plansync-for-whmcs'); ?></h2>
                <?php self::form_open('connection'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wp-url"><?php esc_html_e('WHMCS URL', 'plansync-for-whmcs'); ?></label></th>
                        <td><input id="wp-url" name="whmcs_url" type="url" class="regular-text" value="<?php echo esc_attr($settings['whmcs_url']); ?>" placeholder="https://billing.example.com" <?php disabled($url_locked); ?>>
                            <p class="description"><?php esc_html_e('Base URL of your WHMCS install, including any folder such as /billing. Plans are read from its public store pages.', 'plansync-for-whmcs'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wp-ip"><?php esc_html_e('Connect to IP', 'plansync-for-whmcs'); ?></label></th>
                        <td><input id="wp-ip" name="connect_ip" type="text" class="regular-text" value="<?php echo esc_attr($settings['connect_ip']); ?>" placeholder="<?php esc_attr_e('Leave empty for normal DNS', 'plansync-for-whmcs'); ?>" <?php disabled($ip_locked); ?>>
                            <p class="description"><?php esc_html_e('Optional. Sends requests to this IP instead of the address DNS returns, for example to skip a CDN. Only use it when that IP actually serves your WHMCS site.', 'plansync-for-whmcs'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wp-min"><?php esc_html_e('Refresh every', 'plansync-for-whmcs'); ?></label></th>
                        <td><input id="wp-min" name="sync_minutes" type="number" min="5" max="1440" value="<?php echo (int) $settings['sync_minutes']; ?>"> <?php esc_html_e('minutes', 'plansync-for-whmcs'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wp-age"><?php esc_html_e('Hide plans older than', 'plansync-for-whmcs'); ?></label></th>
                        <td><input id="wp-age" name="max_age_hours" type="number" min="0" max="8760" value="<?php echo (int) $settings['max_age_hours']; ?>"> <?php esc_html_e('hours', 'plansync-for-whmcs'); ?>
                            <p class="description"><?php esc_html_e('If WHMCS cannot be reached for this long, plans are hidden so visitors never see stale prices or stock. 0 keeps the last copy forever.', 'plansync-for-whmcs'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wp-contact"><?php esc_html_e('Out of stock link', 'plansync-for-whmcs'); ?></label></th>
                        <td><input id="wp-contact" name="contact_url" type="text" class="regular-text" value="<?php echo esc_attr($settings['contact_url']); ?>" placeholder="/contact/">
                            <p class="description"><?php esc_html_e('Shown as "Ask about availability" under out of stock plans. Leave empty to hide it.', 'plansync-for-whmcs'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Structured data', 'plansync-for-whmcs'); ?></th>
                        <td>
                            <label><input type="checkbox" name="schema" value="1" <?php checked(!empty($settings['schema'])); ?>> <?php esc_html_e('Add Product and Offer structured data', 'plansync-for-whmcs'); ?></label><br>
                            <label for="wp-brand"><?php esc_html_e('Brand', 'plansync-for-whmcs'); ?></label>
                            <input id="wp-brand" name="brand" type="text" value="<?php echo esc_attr((string) (get_option(Plans::SETTINGS_OPTION)['brand'] ?? '')); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                            <label for="wp-cur"><?php esc_html_e('Currency if WHMCS shows none', 'plansync-for-whmcs'); ?></label>
                            <input id="wp-cur" name="currency" type="text" size="4" maxlength="3" value="<?php echo esc_attr($settings['currency']); ?>" placeholder="USD">
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save connection', 'plansync-for-whmcs'), 'primary', 'submit', true, array('id' => 'plansync-save-connection')); ?>
                </form>
            </div>

            <div class="plansync-admin__card">
                <h2><?php esc_html_e('Product groups', 'plansync-for-whmcs'); ?></h2>
                <?php self::form_open('add_group'); ?>
                <p class="plansync-admin__add">
                    <label for="wp-add" class="screen-reader-text"><?php esc_html_e('Add product group', 'plansync-for-whmcs'); ?></label>
                    <select id="wp-add" name="add_group">
                        <option value=""><?php esc_html_e('Add a group from the store menu…', 'plansync-for-whmcs'); ?></option>
                        <?php foreach ($cache['discovered'] as $slug => $label) : ?>
                            <?php if (!isset($settings['groups'][$slug])) : ?>
                                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label . ' (' . $slug . ')'); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <label for="wp-add-custom" class="screen-reader-text"><?php esc_html_e('Or type a group slug', 'plansync-for-whmcs'); ?></label>
                    <input id="wp-add-custom" name="add_group_custom" type="text" placeholder="<?php esc_attr_e('or a slug, store URL or group id', 'plansync-for-whmcs'); ?>">
                    <?php submit_button(__('Add and import', 'plansync-for-whmcs'), 'primary', 'submit', false, array('id' => 'plansync-add')); ?>
                </p>
                <p class="description"><?php esc_html_e('Hidden groups are not in the store menu. Add them by slug, for example the part after /store/ in the group link.', 'plansync-for-whmcs'); ?></p>
                </form>
                <?php if (!empty($settings['groups'])) : ?>
                    <?php self::form_open('refresh_all'); ?>
                    <?php submit_button(__('Refresh all groups now', 'plansync-for-whmcs'), 'secondary', 'submit', false, array('id' => 'plansync-refresh-all')); ?>
                    </form>
                <?php else : ?>
                    <p><em><?php esc_html_e('No product groups yet.', 'plansync-for-whmcs'); ?></em></p>
                <?php endif; ?>
            </div>

            <?php foreach ($settings['groups'] as $slug => $group) :
                $slug = (string) $slug;
                $status = Plans::group_status($slug);
                $plans = Plans::plans_for_group($slug, true, true);
                ?>
                <div class="plansync-admin__card" id="<?php echo esc_attr('plansync-group-' . $slug); ?>">
                    <?php self::form_open('group', array('group' => $slug)); ?>
                    <h2 class="plansync-admin__group-title">
                        <label class="screen-reader-text" for="<?php echo esc_attr('wp-label-' . $slug); ?>"><?php esc_html_e('Group label', 'plansync-for-whmcs'); ?></label>
                        <input id="<?php echo esc_attr('wp-label-' . $slug); ?>" name="group_label" type="text" value="<?php echo esc_attr($group['label']); ?>">
                        <code><?php echo esc_html($slug); ?></code>
                    </h2>
                    <p>
                        <?php echo esc_html(sprintf(
                            /* translators: 1: plan count, 2: out of stock count */
                            __('%1$d plans, %2$d out of stock.', 'plansync-for-whmcs'),
                            $status['count'],
                            $status['out_of_stock']
                        )); ?>
                        <?php if ($status['fetched_at']) : ?>
                            <?php echo esc_html(sprintf(
                                /* translators: %s: time ago */
                                __('Last refreshed %s ago.', 'plansync-for-whmcs'),
                                human_time_diff($status['fetched_at'])
                            )); ?>
                        <?php endif; ?>
                        <?php if ($status['stale']) : ?>
                            <br><strong class="plansync-admin__error"><?php esc_html_e('Plans are hidden on the site because the last good refresh is older than the maximum age.', 'plansync-for-whmcs'); ?></strong>
                        <?php endif; ?>
                        <?php if ('' !== $status['error']) : ?>
                            <br><strong class="plansync-admin__error"><?php echo esc_html(__('Last refresh failed:', 'plansync-for-whmcs') . ' ' . $status['error']); ?></strong>
                        <?php endif; ?>
                    </p>

                    <?php if (!empty($plans)) : ?>
                        <div class="plansync-admin__table-wrap">
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php esc_html_e('Show', 'plansync-for-whmcs'); ?></th>
                                <th><?php esc_html_e('WHMCS plan', 'plansync-for-whmcs'); ?></th>
                                <th><?php esc_html_e('Display title', 'plansync-for-whmcs'); ?></th>
                                <th><?php esc_html_e('SEO description', 'plansync-for-whmcs'); ?></th>
                                <th><?php esc_html_e('Category (tab)', 'plansync-for-whmcs'); ?></th>
                                <th><?php esc_html_e('Badge', 'plansync-for-whmcs'); ?></th>
                                <th><?php esc_html_e('Anchor', 'plansync-for-whmcs'); ?></th>
                                <th><?php esc_html_e('Order', 'plansync-for-whmcs'); ?></th>
                                <th><?php esc_html_e('Featured', 'plansync-for-whmcs'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($plans as $plan) :
                                $key = (string) $plan['key'];
                                $meta = isset($settings['plans'][$key]) ? $settings['plans'][$key] : array();
                                $field = static function ($name) use ($meta) {
                                    return isset($meta[$name]) ? (string) $meta[$name] : '';
                                };
                                ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="seen[]" value="<?php echo esc_attr($key); ?>">
                                        <input type="checkbox" name="show[]" value="<?php echo esc_attr($key); ?>" <?php checked(empty($plan['excluded'])); ?> aria-label="<?php echo esc_attr(sprintf(__('Show %s', 'plansync-for-whmcs'), $plan['name'])); ?>">
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($plan['name']); ?></strong><br>
                                        <?php echo esc_html(trim(($plan['price_display'] ?: $plan['price_text']) . ' ' . $plan['cycle_text'])); ?>
                                        <?php if (empty($plan['in_stock'])) : ?><br><span class="plansync-admin__error"><?php esc_html_e('Out of stock', 'plansync-for-whmcs'); ?></span><?php endif; ?>
                                        <br><small><?php echo esc_html(null !== $plan['pid'] ? sprintf(__('Product ID %d', 'plansync-for-whmcs'), $plan['pid']) : $key); ?></small>
                                    </td>
                                    <td><input type="text" name="plan[<?php echo esc_attr($key); ?>][title]" value="<?php echo esc_attr($field('title')); ?>" placeholder="<?php echo esc_attr($plan['name']); ?>"></td>
                                    <td><textarea name="plan[<?php echo esc_attr($key); ?>][description]" rows="2" cols="28" placeholder="<?php esc_attr_e('Short description for the card and structured data', 'plansync-for-whmcs'); ?>"><?php echo esc_textarea($field('description')); ?></textarea></td>
                                    <td><input type="text" name="plan[<?php echo esc_attr($key); ?>][category]" value="<?php echo esc_attr($field('category')); ?>" placeholder="<?php echo esc_attr((string) $plan['category']); ?>"></td>
                                    <td><input type="text" name="plan[<?php echo esc_attr($key); ?>][badge]" value="<?php echo esc_attr($field('badge')); ?>" size="10" placeholder="<?php esc_attr_e('Popular', 'plansync-for-whmcs'); ?>"></td>
                                    <td><input type="text" name="plan[<?php echo esc_attr($key); ?>][anchor]" value="<?php echo esc_attr($field('anchor')); ?>" size="14" placeholder="<?php echo esc_attr(sanitize_title($plan['name'])); ?>"></td>
                                    <td><input type="number" name="plan[<?php echo esc_attr($key); ?>][order]" value="<?php echo esc_attr($field('order')); ?>" class="small-text"></td>
                                    <td><input type="checkbox" name="plan[<?php echo esc_attr($key); ?>][featured]" value="1" <?php checked(!empty($meta['featured'])); ?> aria-label="<?php echo esc_attr(sprintf(__('Feature %s', 'plansync-for-whmcs'), $plan['name'])); ?>"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php else : ?>
                        <p><em><?php esc_html_e('No plans imported yet. Use "Refresh this group".', 'plansync-for-whmcs'); ?></em></p>
                    <?php endif; ?>

                    <p class="plansync-admin__actions">
                        <?php submit_button(__('Save group', 'plansync-for-whmcs'), 'primary', 'submit', false, array('id' => 'plansync-save-' . $slug)); ?>
                        <button type="submit" name="refresh" value="1" class="button"><?php esc_html_e('Save and refresh this group', 'plansync-for-whmcs'); ?></button>
                        <button type="submit" name="remove" value="1" class="button button-link-delete" onclick="return window.confirm(<?php echo esc_attr(wp_json_encode(__('Remove this group and its plan settings?', 'plansync-for-whmcs'))); ?>);"><?php esc_html_e('Remove group', 'plansync-for-whmcs'); ?></button>
                    </p>
                    </form>
                </div>
            <?php endforeach; ?>

            <footer class="plansync-admin__footer">
                <?php echo wp_kses(sprintf(
                    /* translators: %s: ARPHost link */
                    __('PlanSync is free and open source, made by %s.', 'plansync-for-whmcs'),
                    '<a href="' . esc_url(PLANSYNC_BRAND_URL) . '" target="_blank" rel="noopener">ARPHost</a>'
                ), array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))); ?>
            </footer>
        </div>
        <?php
    }
}
