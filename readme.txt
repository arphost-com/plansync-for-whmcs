=== PlanSync for WHMCS ===
Contributors: arphost
Tags: whmcs, pricing table, hosting, plans, seo
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.3.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Show WHMCS product groups as fast, SEO-friendly plan cards with live prices and stock.

== Description ==

PlanSync reads the public store pages of your WHMCS install and turns each product group into plan cards on your WordPress site. Prices, billing cycles and stock come straight from WHMCS and refresh on a schedule, so your marketing pages always match your client area.

* Server-rendered cards: plan names, specs, prices and availability are in the page HTML for search engines.
* Out of stock plans are marked and cannot be ordered, with an optional "Ask about availability" link.
* Per-plan SEO title, description, category, badge, anchor, order and featured flag.
* Product and Offer structured data with stock state.
* Works with the stock WHMCS order form templates (Standard Cart, Premium, Pure and Supreme Comparison, Universal Slider, Cloud Slider, Modern) and templates built on them, in any language and currency.
* Works with friendly, index.php and basic WHMCS URLs, WHMCS installed in a folder, and numeric group ids.
* Gutenberg block, shortcodes and WP-CLI commands.
* No WHMCS API credentials needed.

Made by [ARPHost](https://arphost.com/).

== Installation ==

1. Upload the `plansync-for-whmcs` folder to `/wp-content/plugins/` and activate it.
2. Open PlanSync in the admin menu and enter your WHMCS URL.
3. Add a product group from the list, or type its store slug, and it is imported right away.
4. Add the PlanSync block to a page, or use `[plansync group="your-group-slug"]`.

== Frequently Asked Questions ==

= Do I need WHMCS API credentials? =

No. The plugin reads the same public store pages your customers see, so hidden products stay hidden and stock matches the order form.

= My group is not in the list =

Groups hidden from the store menu are not listed. Type the slug from the group link (the part after /store/), a full store URL, or the numeric group id.

= What happens if WHMCS is down? =

The last good copy is kept and shown. If WHMCS cannot be reached for longer than the maximum age you set, the plans are hidden so visitors never see stale prices.

== Changelog ==

= 1.3.0 =
* Optional ARPFerral affiliate links. Turn on "Add my ARPFerral affiliate code to order links" and enter your code, and order buttons and structured data links that go to the WHMCS site, or to an order page on your own site such as CartBridge, get ?ref= with your code. The code box only shows while the option is on. Links to other sites are left alone. A missing or bad code does not stop the rest of the settings from saving.

= 1.2.2 =
* The settings screen describes CartBridge for WHMCS, the companion order form plugin, with a link to ask ARPHost about it. It only shows while CartBridge is not installed.

= 1.2.1 =
* New Order buttons setting and plansync_order_url filter, so plan buttons and the structured data offer link can go to an order page on your own site such as CartBridge for WHMCS. Untick it to keep sending customers to the WHMCS cart.

= 1.1.0 =
* First public release.
