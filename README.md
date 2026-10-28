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
- Cached and refreshed on a schedule, and page caches are cleared when prices or stock change; the last good copy is kept when WHMCS is down, and hidden once it is older than the maximum age you set.
- Gutenberg block, shortcodes and WP-CLI.

## Install

1. Download the latest release zip and upload it under Plugins → Add New → Upload, or copy the `plansync-for-whmcs` folder to `wp-content/plugins/`.
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
