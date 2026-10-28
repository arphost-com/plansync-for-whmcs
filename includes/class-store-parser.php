<?php
/**
 * Parses a WHMCS store product group page into normalized plan records.
 *
 * WHMCS 8 order form templates (standard_cart, premium_comparison,
 * pure_comparison, supreme_comparison, universal_slider, legacy_modern) mark
 * each product with id="product{pid}" or id="bundle{bid}" and suffixes such as
 * -name, -price, -feature{n}, -unavailable and -order-button. Older and third
 * party templates often number products by position instead. The parser works
 * from those shared hooks rather than one template's layout, reads the
 * cloud_slider product JSON, and avoids depending on English wording so it
 * works with any WHMCS language.
 *
 * Pure PHP (DOM + XPath) with no WordPress dependency so it can be unit tested.
 *
 * @package ArphostPlanSync
 */

namespace Arphost\PlanSync;

if (!defined('ABSPATH') && !defined('PLANSYNC_TESTING')) {
    exit;
}

final class Store_Parser
{
    private const CYCLES = array(
        'monthly'      => 'monthly',
        'quarterly'    => 'quarterly',
        'semi-annually' => 'semiannually',
        'semiannually' => 'semiannually',
        'annually'     => 'annually',
        'biennially'   => 'biennially',
        'triennially'  => 'triennially',
        'one time'     => 'onetime',
        'onetime'      => 'onetime',
        'free'         => 'free',
    );

    /**
     * Parse the products on a WHMCS store group page.
     *
     * @param string $html     Full HTML of the store group page.
     * @param string $base_url WHMCS base URL, used to make order links absolute.
     * @param string $group    Group identifier the page was fetched for.
     * @return array<int, array<string, mixed>> Plans in store order.
     */
    public static function parse_products(string $html, string $base_url, string $group): array
    {
        $xpath = self::xpath($html);
        if (!$xpath) {
            return array();
        }
        $base_url = rtrim($base_url, '/');

        $plans = self::parse_id_marked($xpath, $base_url, $group);
        if (empty($plans)) {
            $plans = self::parse_slider_json($html, $base_url, $group);
        }

        // De-duplicate keys (a template may print a product twice, e.g. desktop and mobile).
        $seen = array();
        $out = array();
        foreach ($plans as $plan) {
            if (isset($seen[$plan['key']])) {
                continue;
            }
            $seen[$plan['key']] = true;
            $plan['position'] = count($out);
            $out[] = $plan;
        }
        return $out;
    }

    /**
     * Products marked with id="product{n}" / id="bundle{n}" (or -container).
     */
    private static function parse_id_marked(\DOMXPath $xpath, string $base_url, string $group): array
    {
        $nodes = $xpath->query("//*[@id and (starts-with(@id, 'product') or starts-with(@id, 'bundle'))]");
        $containers = array();
        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            if (!preg_match('/^(product|bundle)(\d+)(-container)?$/', (string) $node->getAttribute('id'), $m)) {
                continue;
            }
            $prefix = $m[1] . $m[2];
            if (isset($containers[$prefix]) && empty($m[3])) {
                continue;
            }
            $containers[$prefix] = array('node' => $node, 'type' => $m[1], 'number' => (int) $m[2]);
        }

        $raw = array();
        foreach ($containers as $prefix => $info) {
            $node = $info['node'];
            $scope = $node;
            // Some templates put -name/-price outside the -container element.
            $root = $node->ownerDocument;

            $name = self::text_by_id($xpath, $prefix . '-name');
            if ('' === $name) {
                $name_node = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' name ')]", $scope)->item(0);
                if ($name_node) {
                    $name = self::clean(self::text_without($xpath, $name_node, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' qty ')]"));
                }
            }
            $name_from_price = false;
            if ('' === $name) {
                $price_box = self::by_id($xpath, $prefix . '-price');
                if ($price_box) {
                    $name = self::clean(self::text_without($xpath, $price_box, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' price-cont ')] | .//a | .//*[contains(@id, '-unavailable')]"));
                    $name_from_price = '' !== $name;
                }
            }
            if ('' === $name) {
                continue;
            }

            // Stock.
            $stock = null;
            $qty_node = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' qty ')]", $scope)->item(0);
            if ($qty_node && preg_match('/(-?\d+)/', $qty_node->textContent, $q)) {
                $stock = max(0, (int) $q[1]);
            }
            $unavailable = (bool) self::by_id($xpath, $prefix . '-unavailable');

            // Price area.
            $price_node = $xpath->query(
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' price ') or contains(concat(' ', normalize-space(@class), ' '), ' price-cont ') or contains(concat(' ', normalize-space(@class), ' '), ' pricing ')][not(*[contains(concat(' ', normalize-space(@class), ' '), ' pricing ')])]",
                self::by_id($xpath, $prefix . '-price') ?: $scope
            )->item(0);
            $area = self::by_id($xpath, $prefix . '-price')
                ?: $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' product-pricing ') or contains(concat(' ', normalize-space(@class), ' '), ' price-area ') or contains(concat(' ', normalize-space(@class), ' '), ' pricing ')]", $scope)->item(0);
            $area_text = $area ? self::clean(self::text_without($xpath, $area, ".//small | .//a | .//*[contains(@id, '-unavailable')]")) : '';
            if ($name_from_price && '' !== $area_text && 0 === strpos($area_text, $name)) {
                $area_text = trim(substr($area_text, strlen($name)));
            }
            $price_text = $price_node ? self::clean(self::text_without($xpath, $price_node, './/small')) : $area_text;
            $price = self::parse_price('' !== $price_text ? $price_text : $area_text);
            $label = '';
            $cycle_text = '';
            if (null !== $price['amount'] && '' !== $area_text) {
                $pos = mb_strpos($area_text, $price['raw']);
                if (false !== $pos) {
                    $label = trim(mb_substr($area_text, 0, $pos));
                    $cycle_text = trim(mb_substr($area_text, $pos + mb_strlen($price['raw'])));
                }
            }
            if ($name_from_price && '' !== $label) {
                // universal_slider prints "{name} {Price}" before the amount.
                $label = '';
            }
            $cycle_text = trim(preg_replace('/^[A-Z]{3}\b/', '', $cycle_text));

            // Features / description lines.
            $specs = array();
            foreach ($xpath->query("//*[starts-with(@id, '" . $prefix . "-feature')]") as $li) {
                $id = (string) $li->getAttribute('id');
                if (!preg_match('/-feature\d+$/', $id)) {
                    continue;
                }
                $line = self::clean(self::text_without($xpath, $li, ".//*[contains(@class, 'progress')]"));
                if ('' !== $line) {
                    $specs[] = $line;
                }
            }
            if (empty($specs)) {
                $sources = array(self::by_id($xpath, $prefix . '-description'));
                foreach ($xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' product-desc ') or contains(concat(' ', normalize-space(@class), ' '), ' description ') or contains(concat(' ', normalize-space(@class), ' '), ' product-description ')]", $scope) as $candidate) {
                    $sources[] = $candidate;
                }
                foreach ($sources as $desc) {
                    if ($desc) {
                        $specs = self::lines($xpath, $desc);
                        if (!empty($specs)) {
                            break;
                        }
                    }
                }
            }
            if (empty($specs)) {
                foreach ($xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' prodfeature ')]", $scope) as $f) {
                    $line = self::clean($f->textContent);
                    if ('' !== $line) {
                        $specs[] = $line;
                    }
                }
            }

            // Order link.
            $order_url = '';
            $button = self::by_id($xpath, $prefix . '-order-button');
            if (!$button) {
                $button = $xpath->query(".//a[contains(@class, 'btn-order-now') or contains(@href, 'cart.php?a=add') or contains(@href, '/store/') or contains(@href, 'rp=/store/')]", $scope)->item(0);
            }
            if ($button) {
                $order_url = self::absolute((string) $button->getAttribute('href'), $base_url);
            } elseif (preg_match("/window\\.location\\s*=\\s*'([^']+)'/", (string) $node->getAttribute('onclick'), $loc)) {
                $order_url = self::absolute(html_entity_decode($loc[1]), $base_url);
            }

            // Product id: prefer the one in an add-to-cart link, else the element id.
            $pid = null;
            $bid = null;
            if (preg_match('/[?&]pid=(\d+)/', $order_url, $pm)) {
                $pid = (int) $pm[1];
            } elseif (preg_match('/[?&]bid=(\d+)/', $order_url, $bm)) {
                $bid = (int) $bm[1];
            } elseif ('bundle' === $info['type']) {
                $bid = $info['number'];
            } else {
                $pid = $info['number'];
            }

            $raw[] = array(
                'pid'           => $pid,
                'bid'           => $bid,
                'name'          => $name,
                'name_from_price' => $name_from_price,
                'specs'         => $specs,
                'price'         => $price['amount'],
                'price_display' => $price['display'],
                'price_text'    => null === $price['amount'] ? $area_text : '',
                'currency'      => $price['currency'],
                'cycle'         => self::cycle_key($cycle_text),
                'cycle_text'    => $cycle_text,
                'label'         => $label,
                'stock'         => $unavailable ? 0 : $stock,
                'unavailable'   => $unavailable,
                'order_url'     => $order_url,
            );
        }

        // universal_slider: names were read as "{name} {Price}"; drop the shared trailing word.
        $from_price = array_filter($raw, static function ($r) {
            return $r['name_from_price'];
        });
        if (count($from_price) > 1) {
            $suffix = self::common_suffix(array_column($from_price, 'name'), 1);
            if ('' !== $suffix) {
                foreach ($raw as $i => $r) {
                    if ($r['name_from_price'] && mb_strlen($r['name']) > mb_strlen($suffix)) {
                        $raw[$i]['name'] = trim(mb_substr($r['name'], 0, mb_strlen($r['name']) - mb_strlen($suffix)));
                    }
                }
            }
        }

        $plans = array();
        foreach ($raw as $r) {
            $plans[] = self::plan($r, $group);
        }
        return $plans;
    }

    /**
     * cloud_slider renders products from a JavaScript object.
     */
    private static function parse_slider_json(string $html, string $base_url, string $group): array
    {
        if (!preg_match('/var\s+allProducts\s*=\s*(\{.*?\})\s*;\s*(?:\n|<\/script>)/s', $html, $m)) {
            return array();
        }
        $js = $m[1];
        $js = preg_replace("/([{,]\\s*)([A-Za-z_][A-Za-z0-9_]*)\\s*:/", '$1"$2":', $js);
        $js = preg_replace_callback("/'((?:[^'\\\\]|\\\\.)*)'/", static function ($q) {
            return json_encode(stripcslashes($q[1]));
        }, $js);
        $js = preg_replace('/,\s*([}\]])/', '$1', $js);
        $data = json_decode($js, true);
        if (!is_array($data)) {
            return array();
        }

        $plans = array();
        foreach ($data as $item) {
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }
            $specs = array();
            foreach ((array) ($item['features'] ?? array()) as $feature => $value) {
                $specs[] = self::clean($value . ' ' . $feature);
            }
            if (empty($specs) && !empty($item['desc'])) {
                foreach (preg_split('/<br\s*\/?>|\n/i', (string) $item['desc']) as $line) {
                    $line = self::clean(strip_tags($line));
                    if ('' !== $line) {
                        $specs[] = $line;
                    }
                }
            }
            $price = self::parse_price((string) ($item['displayPrice'] ?? ''));
            $plans[] = self::plan(array(
                'pid'           => isset($item['pid']) && '' !== $item['pid'] ? (int) $item['pid'] : null,
                'bid'           => isset($item['bid']) && '' !== $item['bid'] ? (int) $item['bid'] : null,
                'name'          => self::clean(html_entity_decode((string) $item['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'specs'         => $specs,
                'price'         => $price['amount'],
                'price_display' => $price['display'],
                'price_text'    => null === $price['amount'] ? self::clean((string) ($item['displayPrice'] ?? '')) : '',
                'currency'      => $price['currency'],
                'cycle'         => self::cycle_key((string) ($item['displayCycle'] ?? '')),
                'cycle_text'    => ucfirst((string) ($item['displayCycle'] ?? '')),
                'label'         => '',
                'stock'         => null,
                'unavailable'   => false,
                'order_url'     => self::absolute((string) ($item['productUrl'] ?? ''), $base_url),
            ), $group);
        }
        return $plans;
    }

    private static function plan(array $r, string $group): array
    {
        if (null !== $r['pid']) {
            $key = (string) $r['pid'];
        } elseif (null !== $r['bid']) {
            $key = 'b' . $r['bid'];
        } else {
            $key = 'u' . substr(md5($r['order_url'] . '|' . $r['name']), 0, 12);
        }
        $stock = $r['stock'];
        return array(
            'key'           => $key,
            'pid'           => $r['pid'],
            'bid'           => $r['bid'],
            'group'         => $group,
            'position'      => 0,
            'name'          => $r['name'],
            'specs'         => array_values($r['specs']),
            'price'         => $r['price'],
            'price_display' => $r['price_display'],
            'price_text'    => $r['price_text'],
            'currency'      => $r['currency'],
            'cycle'         => $r['cycle'],
            'cycle_text'    => $r['cycle_text'],
            'label'         => $r['label'],
            'starting_from' => '' !== $r['label'],
            'stock'         => $stock,
            'in_stock'      => !$r['unavailable'] && (null === $stock || $stock > 0),
            'order_url'     => $r['order_url'],
        );
    }

    /**
     * Find product groups linked from the store menus.
     *
     * @return array<string, string> slug => display name.
     */
    public static function parse_groups(string $html, string $base_url = ''): array
    {
        $base_host = strtolower((string) parse_url($base_url, PHP_URL_HOST));
        $xpath = self::xpath($html);
        if (!$xpath) {
            return array();
        }

        $groups = array();
        foreach ($xpath->query('//a[@href]') as $link) {
            $href = html_entity_decode((string) $link->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ('' !== $host && '' !== $base_host && $host !== $base_host) {
                continue;
            }
            $path = (string) parse_url($href, PHP_URL_PATH);
            $query = (string) parse_url($href, PHP_URL_QUERY);
            $slug = '';
            if (preg_match('#/store/([a-z0-9][a-z0-9_-]*)/?$#i', $path, $m)) {
                $slug = $m[1];
            } elseif ('' !== $query) {
                parse_str($query, $q);
                if (isset($q['rp']) && preg_match('#^/store/([a-z0-9][a-z0-9_-]*)/?$#i', (string) $q['rp'], $m)) {
                    $slug = $m[1];
                } elseif (isset($q['gid']) && ctype_digit((string) $q['gid']) && preg_match('#cart\.php$#', $path)) {
                    $slug = (string) $q['gid'];
                }
            }
            $slug = strtolower($slug);
            if ('' === $slug || in_array($slug, array('order', 'cart', 'checkout', 'addons'), true)) {
                continue;
            }
            $label = self::clean((string) $link->getAttribute('menuItemName'));
            $text = self::clean($link->textContent);
            if ('' !== $text) {
                $label = $text;
            }
            if ('' === $label) {
                $label = $slug;
            }
            if (!isset($groups[$slug]) || mb_strlen($label) > mb_strlen($groups[$slug])) {
                $groups[$slug] = $label;
            }
        }
        ksort($groups);
        return $groups;
    }

    /**
     * Parse a WHMCS formatted price in any common locale.
     *
     * @return array{amount: float|null, display: string, currency: string, raw: string}
     */
    public static function parse_price(string $text): array
    {
        $text = self::clean($text);
        $out = array('amount' => null, 'display' => '', 'currency' => '', 'raw' => '');
        if (!preg_match('/(\p{Sc}|[A-Z]{1,3}\$)?\s*(\d{1,3}(?:[.,\x{00A0}\x{202F} ]\d{3})*(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)\s*(\p{Sc}|[A-Z]{3}\b)?/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        $number = $m[2][0];
        $digits = preg_replace('/[\x{00A0}\x{202F} ]/u', '', $number);
        $last_dot = strrpos($digits, '.');
        $last_comma = strrpos($digits, ',');
        if (false !== $last_dot && false !== $last_comma) {
            $decimal = $last_dot > $last_comma ? '.' : ',';
        } elseif (false !== $last_comma) {
            $decimal = preg_match('/,\d{1,2}$/', $digits) ? ',' : '';
        } elseif (false !== $last_dot) {
            $decimal = preg_match('/\.\d{1,2}$/', $digits) ? '.' : '';
        } else {
            $decimal = '';
        }
        $normalized = $digits;
        if ('' !== $decimal) {
            $thousands = '.' === $decimal ? ',' : '.';
            $normalized = str_replace($thousands, '', $normalized);
            $normalized = str_replace($decimal, '.', $normalized);
        } else {
            $normalized = str_replace(array(',', '.'), '', $normalized);
        }

        $prefix = isset($m[1][0]) ? trim($m[1][0]) : '';
        $suffix = isset($m[3][0]) ? trim($m[3][0]) : '';
        $currency = preg_match('/^[A-Z]{3}$/', $suffix) ? $suffix : '';
        $symbol_after = ('' === $currency && '' !== $suffix) ? $suffix : '';

        $out['amount'] = (float) $normalized;
        $out['display'] = $prefix . $number . ('' !== $symbol_after ? ' ' . $symbol_after : '');
        $out['currency'] = $currency;
        $out['raw'] = trim($m[0][0]);
        return $out;
    }

    private static function cycle_key(string $text): string
    {
        $t = strtolower(trim($text));
        foreach (self::CYCLES as $word => $key) {
            if ('' !== $t && 0 === strpos($t, $word)) {
                return $key;
            }
        }
        return '';
    }

    private static function lines(\DOMXPath $xpath, \DOMNode $node): array
    {
        $lines = array();
        $items = $xpath->query('.//li', $node);
        if ($items->length > 0) {
            foreach ($items as $li) {
                $line = self::clean($li->textContent);
                if ('' !== $line) {
                    $lines[] = $line;
                }
            }
            return $lines;
        }
        $html = $node->ownerDocument->saveHTML($node);
        foreach (preg_split('/<br\s*\/?>|<\/p>|<\/div>|\n/i', $html) as $part) {
            $line = self::clean(strip_tags($part));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * Words shared at the end of every name, returned with a leading space.
     */
    private static function common_suffix(array $names, int $max_words = 3): string
    {
        $lists = array_map(static function ($n) {
            return preg_split('/\s+/', trim($n));
        }, array_values($names));
        $shortest = min(array_map('count', $lists));
        $suffix = array();
        for ($i = 1; $i < $shortest && $i <= $max_words; $i++) {
            $word = $lists[0][count($lists[0]) - $i];
            foreach ($lists as $words) {
                if ($words[count($words) - $i] !== $word) {
                    break 2;
                }
            }
            array_unshift($suffix, $word);
        }
        return $suffix ? ' ' . implode(' ', $suffix) : '';
    }

    private static function by_id(\DOMXPath $xpath, string $id): ?\DOMElement
    {
        $node = $xpath->query("//*[@id='" . $id . "']")->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    private static function text_by_id(\DOMXPath $xpath, string $id): string
    {
        $node = self::by_id($xpath, $id);
        return $node ? self::clean($node->textContent) : '';
    }

    /**
     * Text of a node with some descendants removed (works on a clone).
     */
    private static function text_without(\DOMXPath $xpath, \DOMNode $node, string $exclude): string
    {
        $clone = $node->cloneNode(true);
        $doc = $node->ownerDocument;
        $wrapper = $doc->createElement('div');
        $wrapper->appendChild($clone);
        $local = new \DOMXPath($doc);
        $remove = array();
        foreach ($local->query($exclude, $clone) as $child) {
            $remove[] = $child;
        }
        foreach ($remove as $child) {
            if ($child->parentNode) {
                $child->parentNode->removeChild($child);
            }
        }
        return $clone->textContent;
    }

    private static function xpath(string $html): ?\DOMXPath
    {
        if ('' === trim($html)) {
            return null;
        }
        // Treat line breaks as spaces so "RAM<br />2 GB" reads as "RAM 2 GB".
        $html = preg_replace('/<br\s*\/?>/i', '$0 ', $html);
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $loaded ? new \DOMXPath($doc) : null;
    }

    private static function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(array("\xC2\xA0", "\xE2\x80\xAF"), ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function absolute(string $href, string $base_url): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ('' === $href || '#' === $href[0] || 0 === stripos($href, 'javascript:')) {
            return '';
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (0 === strpos($href, '//')) {
            return 'https:' . $href;
        }
        $parts = parse_url($base_url);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ('/' === $href[0]) {
            return $origin . $href;
        }
        return rtrim($base_url, '/') . '/' . $href;
    }
}
