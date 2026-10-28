<?php
/**
 * Server-side HTML for plan cards. Everything a search engine needs is in the
 * HTML; plans.js only turns the category sections into tabs.
 *
 * @package PlanSync
 */

namespace Arphost\PlanSync;

if (!defined('ABSPATH')) {
    exit;
}

final class Renderer
{
    /** @var array<string, int> */
    private static $used_ids = array();

    public static function block_defaults(): array
    {
        return array(
            'group'         => '',
            'layout'        => 'tabs',
            'outOfStock'    => 'show',
            'category'      => '',
            'buttonLabel'   => __('Configure', 'plansync-for-whmcs'),
            'categoryLevel' => 3,
            'planLevel'     => 4,
            'showSchema'    => true,
            'anchor'        => '',
            'className'     => '',
        );
    }

    /**
     * Block render callback: adds the block wrapper attributes (alignment,
     * custom classes) that WordPress manages for dynamic blocks.
     */
    public static function render_block(array $attributes): string
    {
        $wrapper = '';
        if (function_exists('get_block_wrapper_attributes') && class_exists('WP_Block_Supports') && !empty(\WP_Block_Supports::$block_to_render)) {
            $wrapper = get_block_wrapper_attributes();
        }
        return self::render($attributes, $wrapper);
    }

    /**
     * @param array<string, mixed> $attributes Block attributes or shortcode atts.
     * @param string               $wrapper    Pre-built wrapper attributes from get_block_wrapper_attributes().
     */
    public static function render(array $attributes, string $wrapper = ''): string
    {
        $a = array_merge(self::block_defaults(), array_filter($attributes, static function ($v) {
            return null !== $v;
        }));
        $group = Plans::sanitize_group((string) $a['group']);
        if ('' === $group) {
            return self::notice(__('Choose a WHMCS product group for this plans block.', 'plansync-for-whmcs'));
        }
        if ('' === trim((string) $a['buttonLabel'])) {
            $a['buttonLabel'] = __('Configure', 'plansync-for-whmcs');
        }

        $plans = Plans::plans_for_group($group);
        if ('' !== (string) $a['category']) {
            $wanted = array_map('trim', explode(',', strtolower((string) $a['category'])));
            $plans = array_values(array_filter($plans, static function ($p) use ($wanted) {
                return in_array(strtolower((string) $p['category']), $wanted, true);
            }));
        }
        if ('hide' === $a['outOfStock']) {
            $plans = array_values(array_filter($plans, static function ($p) {
                return !empty($p['in_stock']);
            }));
        } elseif ('last' === $a['outOfStock']) {
            usort($plans, static function ($x, $y) {
                return ((int) empty($x['in_stock']) <=> (int) empty($y['in_stock'])) ?: ($x['sort'] <=> $y['sort']);
            });
        }

        if (empty($plans)) {
            $status = Plans::group_status($group);
            if ($status['stale']) {
                return self::notice(__('These plans are hidden because the last successful refresh from WHMCS is older than the maximum age in the settings.', 'plansync-for-whmcs'));
            }
            return self::notice(__('No plans to show for this group yet. They appear after the first refresh from WHMCS.', 'plansync-for-whmcs'));
        }

        $categories = array();
        foreach ($plans as $plan) {
            $categories[(string) $plan['category']][] = $plan;
        }

        $base_id = self::unique_id('' !== (string) $a['anchor'] ? sanitize_title((string) $a['anchor']) : 'plansync-' . $group);
        $first_instance = $base_id === ('' !== (string) $a['anchor'] ? sanitize_title((string) $a['anchor']) : 'plansync-' . $group);
        $tabs = 'tabs' === $a['layout'] && count($categories) > 1;
        $cat_level = self::level($a['categoryLevel'], 3);
        $plan_level = self::level($a['planLevel'], 4);

        wp_enqueue_style('plansync-for-whmcs');
        if ($tabs) {
            wp_enqueue_script('plansync-for-whmcs');
        }

        $classes = array('plansync', $tabs ? 'is-tabs' : 'is-grid');
        if ('' === $wrapper) {
            foreach (preg_split('/\s+/', (string) $a['className']) as $class) {
                $class = sanitize_html_class($class);
                if ('' !== $class) {
                    $classes[] = $class;
                }
            }
            $wrapper = 'class="' . esc_attr(implode(' ', $classes)) . '"';
        } else {
            $wrapper = preg_replace('/\bclass="/', 'class="' . esc_attr(implode(' ', $classes)) . ' ', $wrapper, 1);
            if (false === strpos($wrapper, 'class="')) {
                $wrapper .= ' class="' . esc_attr(implode(' ', $classes)) . '"';
            }
            $wrapper = preg_replace('/\sid="[^"]*"/', '', $wrapper);
        }

        $html = '<div id="' . esc_attr($base_id) . '" ' . $wrapper . ' data-plansync>';

        $panel_ids = array();
        foreach (array_keys($categories) as $label) {
            $panel = $base_id . '-' . (sanitize_title((string) $label) ?: 'plans');
            $n = 2;
            $candidate = $panel;
            while (in_array($candidate, $panel_ids, true)) {
                $candidate = $panel . '-' . $n++;
            }
            $panel_ids[$label] = $candidate;
        }

        if ($tabs) {
            $html .= '<div class="plansync__tabs" role="tablist" aria-label="' . esc_attr__('Plan types', 'plansync-for-whmcs') . '" hidden>';
            $i = 0;
            foreach ($categories as $label => $items) {
                $panel = $panel_ids[$label];
                $html .= '<button type="button" role="tab" class="plansync__tab" id="' . esc_attr($panel . '-tab') . '" aria-controls="' . esc_attr($panel) . '" aria-selected="' . (0 === $i ? 'true' : 'false') . '"' . (0 === $i ? '' : ' tabindex="-1"') . '>'
                    . esc_html((string) $label) . ' <span class="plansync__count">' . count($items) . '</span></button>';
                $i++;
            }
            $html .= '</div>';
        }

        foreach ($categories as $label => $items) {
            $panel = $panel_ids[$label];
            $html .= '<section class="plansync__panel" id="' . esc_attr($panel) . '"'
                . ($tabs ? ' role="tabpanel" tabindex="0" aria-labelledby="' . esc_attr($panel . '-tab') . '"' : '') . '>';
            if (count($categories) > 1) {
                $html .= '<h' . $cat_level . ' class="plansync__category">' . esc_html((string) $label) . '</h' . $cat_level . '>';
            }
            $html .= '<div class="plansync__grid">';
            foreach ($items as $plan) {
                $html .= self::card($plan, $a, $plan_level, $first_instance ? '' : $base_id . '-');
            }
            $html .= '</div></section>';
        }

        if (!empty($a['showSchema']) && !empty(Plans::settings()['schema'])) {
            $html .= self::schema($plans);
        }

        $html .= '</div>';
        return $html;
    }

    private static function card(array $plan, array $a, int $level, string $id_prefix): string
    {
        $settings = Plans::settings();
        $in_stock = !empty($plan['in_stock']);
        $classes = 'wp-block-group pricing-card plansync-plan' . ($plan['featured'] ? ' is-featured popular' : '') . ($in_stock ? '' : ' is-out-of-stock');

        $html = '<article class="' . esc_attr($classes) . '" id="' . esc_attr(self::unique_id($id_prefix . $plan['anchor'])) . '" data-plan="' . esc_attr((string) $plan['key']) . '">';
        $html .= '<div class="wp-block-group__inner-container">';

        if ('' !== (string) $plan['badge']) {
            $html .= '<p class="plansync-plan__badge">' . esc_html($plan['badge']) . '</p>';
        }

        $html .= '<h' . $level . ' class="plansync-plan__name">' . esc_html($plan['title']) . '</h' . $level . '>';

        if (null !== $plan['price']) {
            $html .= '<p class="plansync-plan__price">';
            if ('' !== (string) $plan['label']) {
                $html .= '<span class="plansync-plan__from">' . esc_html($plan['label']) . '</span> ';
            }
            $html .= '<strong>' . esc_html($plan['price_display']) . '</strong>';
            $suffix = self::cycle_suffix((string) $plan['cycle'], (string) $plan['cycle_text']);
            if ('' !== $suffix) {
                $html .= '<span class="plansync-plan__cycle">' . esc_html($suffix) . '</span>';
            }
            $html .= '</p>';
        } elseif ('' !== (string) $plan['price_text']) {
            $html .= '<p class="plansync-plan__price"><strong>' . esc_html($plan['price_text']) . '</strong></p>';
        }

        if ('' !== (string) $plan['description']) {
            $html .= '<p class="plansync-plan__description">' . esc_html($plan['description']) . '</p>';
        }

        if (!empty($plan['specs'])) {
            $html .= '<ul class="plansync-plan__specs">';
            foreach ($plan['specs'] as $spec) {
                $html .= '<li>' . esc_html($spec) . '</li>';
            }
            $html .= '</ul>';
        }

        if (!$in_stock) {
            $html .= '<p class="plansync-plan__stock is-out">' . esc_html__('Out of stock', 'plansync-for-whmcs') . '</p>';
        } elseif (null !== $plan['stock']) {
            /* translators: %d: units available */
            $html .= '<p class="plansync-plan__stock is-low">' . esc_html(sprintf(_n('%d available', '%d available', (int) $plan['stock'], 'plansync-for-whmcs'), (int) $plan['stock'])) . '</p>';
        }

        $html .= '<div class="wp-block-buttons plansync-plan__actions">';
        if ($in_stock && '' !== (string) $plan['order_url']) {
            $html .= '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url($plan['order_url']) . '" aria-label="' . esc_attr(sprintf('%s %s', $a['buttonLabel'], $plan['title'])) . '">' . esc_html($a['buttonLabel']) . '</a></div>';
        } else {
            $html .= '<div class="wp-block-button is-disabled"><span class="wp-block-button__link wp-element-button" aria-disabled="true">' . esc_html($in_stock ? __('Unavailable', 'plansync-for-whmcs') : __('Out of Stock', 'plansync-for-whmcs')) . '</span></div>';
        }
        $html .= '</div>';

        if (!$in_stock && '' !== (string) $settings['contact_url']) {
            $html .= '<p class="plansync-plan__notify"><a href="' . esc_url($settings['contact_url']) . '">' . esc_html__('Ask about availability', 'plansync-for-whmcs') . '</a></p>';
        }

        $html .= '</div></article>';
        return $html;
    }

    private static function schema(array $plans): string
    {
        $settings = Plans::settings();
        $items = array();
        $position = 1;
        foreach ($plans as $plan) {
            $currency = '' !== (string) $plan['currency'] ? (string) $plan['currency'] : strtoupper(trim((string) $settings['currency']));
            if (null === $plan['price'] || !preg_match('/^[A-Z]{3}$/', $currency)) {
                continue;
            }
            $offer = array(
                '@type'         => 'Offer',
                'price'         => number_format((float) $plan['price'], 2, '.', ''),
                'priceCurrency' => $currency,
                'availability'  => !empty($plan['in_stock']) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            );
            if ('' !== (string) $plan['order_url']) {
                $offer['url'] = $plan['order_url'];
            }
            $units = array('monthly' => 'MON', 'annually' => 'ANN');
            if (isset($units[$plan['cycle']])) {
                $offer['priceSpecification'] = array(
                    '@type'             => 'UnitPriceSpecification',
                    'price'             => $offer['price'],
                    'priceCurrency'     => $currency,
                    'referenceQuantity' => array('@type' => 'QuantitativeValue', 'value' => 1, 'unitCode' => $units[$plan['cycle']]),
                );
            }
            $product = array(
                '@type'  => 'Product',
                'name'   => $plan['title'],
                'sku'    => 'whmcs-' . $plan['key'],
                'offers' => $offer,
            );
            if ('' !== trim((string) $settings['brand'])) {
                $product['brand'] = array('@type' => 'Brand', 'name' => $settings['brand']);
            }
            $description = '' !== (string) $plan['description'] ? $plan['description'] : implode(', ', (array) $plan['specs']);
            if ('' !== $description) {
                $product['description'] = $description;
            }
            $items[] = array('@type' => 'ListItem', 'position' => $position++, 'item' => $product);
        }
        if (empty($items)) {
            return '';
        }
        $data = array('@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items);
        return '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }

    /**
     * Lowest in-stock price for a group, for "Starting at" text.
     */
    public static function lowest_price(string $group): string
    {
        $best = null;
        foreach (Plans::plans_for_group(Plans::sanitize_group($group)) as $plan) {
            if (!empty($plan['in_stock']) && null !== $plan['price'] && (null === $best || $plan['price'] < $best['price'])) {
                $best = $plan;
            }
        }
        return $best ? (string) $best['price_display'] : '';
    }

    private static function cycle_suffix(string $cycle, string $text): string
    {
        $map = array(
            'monthly'      => __('/mo', 'plansync-for-whmcs'),
            'quarterly'    => __('/qtr', 'plansync-for-whmcs'),
            'semiannually' => __('/6 mo', 'plansync-for-whmcs'),
            'annually'     => __('/yr', 'plansync-for-whmcs'),
            'biennially'   => __('/2 yrs', 'plansync-for-whmcs'),
            'triennially'  => __('/3 yrs', 'plansync-for-whmcs'),
        );
        if (isset($map[$cycle])) {
            return $map[$cycle];
        }
        return '' !== $text ? ' ' . $text : '';
    }

    private static function unique_id(string $id): string
    {
        if (!isset(self::$used_ids[$id])) {
            self::$used_ids[$id] = 1;
            return $id;
        }
        self::$used_ids[$id]++;
        return $id . '-' . self::$used_ids[$id];
    }

    /**
     * For tests: forget ids used by earlier renders.
     */
    public static function reset(): void
    {
        self::$used_ids = array();
    }

    private static function level($value, int $fallback): int
    {
        $value = (int) $value;
        return ($value >= 2 && $value <= 6) ? $value : $fallback;
    }

    private static function notice(string $message): string
    {
        if (!current_user_can('edit_posts')) {
            return '';
        }
        return '<div class="plansync-notice">' . esc_html($message) . '</div>';
    }
}
