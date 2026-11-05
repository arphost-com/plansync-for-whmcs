# PlanSync for WHMCS

A WordPress plugin that shows WHMCS product groups as fast, SEO-friendly plan cards with live prices and stock.

Made by [ARPHost](https://arphost.com/). Released under the MIT license.

## Why

Most WHMCS pricing widgets load plans in the browser with JavaScript, so search engines never see them. PlanSync renders the cards on the server: plan names, specs, prices and availability are in the HTML, with Product and Offer structured data, and every plan can have its own SEO title and description.

## Features

- Reads the public WHMCS store pages, so no API credentials are needed and hidden products stay hidden.
- Works with the stock WHMCS order form templates (Standard Cart, Premium, Pure and Supreme Comparison, Universal Slider, Cloud Slider, Modern) and templates built on them, in any language and currency.
- Friendly, `index.php` and basic WHMCS URLs, WHMCS in a folder, and numeric group ids.
- Out of stock plans are marked, cannot be ordered, and carry `OutOfStock` structured data.
- Per-plan exclusion, SEO title, description, category (tab), badge, anchor, order and featured flag.
- Category tabs that work without JavaScript and follow the WAI-ARIA tabs pattern.
- Cached and refreshed on a schedule, and page caches are cleared when prices or stock change; the last good copy is always shown when WHMCS is down, and the settings screen warns you when it gets old. Plans refresh after a page view when they are due, so they stay current even without WP-Cron.
- Gutenberg block, shortcodes and WP-CLI.

## Order buttons and site order pages

By default each plan button links to the WHMCS cart. Another plugin can send those buttons to an order page on your own site with the `plansync_order_url` filter. It receives the WHMCS order URL and the plan, and returns the URL to use. The same URL goes in the Product structured data.

```php
add_filter('plansync_order_url', function (string $url, array $plan): string {
    return $plan['pid'] === 42 ? home_url('/order/?plan=42') : $url;
}, 10, 2);
```

The **Order buttons** checkbox on the settings screen turns this on and off. While it is ticked the filter is applied. Untick it to send every button straight to the WHMCS cart without turning the other plugin off. The settings screen notes when a plugin is using the filter.

CartBridge for WHMCS, by ARPHost, uses this filter to take customers through the whole VPS order on the WordPress site. The settings screen has a short description and a contact link while CartBridge is not installed.

## ARPFerral affiliate links

ARPFerral is the ARPHost affiliate program. Affiliates can use PlanSync on their own site to show ARPHost plans and get credit for the orders. Tick **Add my ARPFerral affiliate code to order links** on the settings screen and enter your affiliate code, 4 to 20 letters and numbers, from the Affiliates page in your ARPHost client area. Order buttons and structured data links that go to the WHMCS site, or to an order page on your own site such as CartBridge, then get `?ref=` with your code. Links to any other site are left alone, and a link that already has a `ref` keeps it. The code box only shows while the option is on. If the code is missing or not valid when you save, your other settings are still saved and the affiliate setting stays as it was.

## Install

1. Download `plansync-for-whmcs-<version>.zip` from the [latest release](https://github.com/arphost-com/plansync-for-whmcs/releases/latest) and upload it under Plugins → Add New → Upload Plugin. If an older version is installed, WordPress offers to replace it and keeps your settings. You can also copy the `plansync-for-whmcs` folder to `wp-content/plugins/`.
2. Activate **PlanSync for WHMCS**.
3. Open **PlanSync** in the admin menu, enter your WHMCS URL and add a product group.
4. Add the **PlanSync** block to a page.

## Shortcodes

```
[plansync group="web-hosting"]
[plansync group="web-hosting" layout="grid" out_of_stock="last" category="Linux"]
[plansync group="7"]                       numeric WHMCS group id
[plansync_price group="web-hosting"]       lowest in-stock price
```

## WP-CLI

```
wp plansync sync [--group=<group>]
wp plansync status
wp plansync add-group <group> [--label=<label>]
```

## Configuration constants

Optional, in `wp-config.php`. They override the admin fields.

```php
define('PLANSYNC_WHMCS_URL', 'https://billing.example.com');
define('PLANSYNC_CONNECT_IP', '203.0.113.10'); // send requests to this IP instead of DNS
```

## Development

```
php tests/run.php
```

The tests run offline against store pages in `tests/fixtures/templates`, which mirror the markup of each stock WHMCS order form template with made-up data.

## License

MIT. See [LICENSE](LICENSE).
