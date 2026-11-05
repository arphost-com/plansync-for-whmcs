# Changelog

## 1.3.2

- The last good copy of the plans is always shown, however old it is, so visitors never see an error when WHMCS cannot be reached. The old maximum age setting is now only a warning on the settings screen. Plans also refresh after a page view once they are due, so they stay current on sites where WP-Cron does not run. Editors now get a clear note when every plan in a group is turned off, and the settings forms no longer let the browser restore old checkbox states, which could save plans as hidden by mistake.

## 1.3.1

- Out of stock plans say so once, on the greyed out button, instead of also showing a badge above it. Plan cards in the same row are the same height, even when the theme gives cards a fixed height, so an out of stock card no longer stands taller than its neighbours.

## 1.3.0

- Optional ARPFerral affiliate links. Turn on "Add my ARPFerral affiliate code to order links" and enter your code, and order buttons and structured data links that go to the WHMCS site, or to an order page on your own site such as CartBridge, get ?ref= with your code. The code box only shows while the option is on. Links to other sites are left alone. A missing or bad code does not stop the rest of the settings from saving.

## 1.2.2

- The settings screen describes CartBridge for WHMCS, the companion order form plugin, with a link to ask ARPHost about it. It only shows while CartBridge is not installed.

## 1.2.1

- New "Order buttons" checkbox (on by default). While it is ticked, the new `plansync_order_url` filter can send plan buttons to an order page on the site, such as CartBridge for WHMCS. Untick it to keep the WHMCS cart links.
- The `plansync_order_url` filter It receives the WHMCS order link and the plan (including its WHMCS product id as `pid`), and its result is used for the order button and the structured data offer link.

## 1.1.0

First public release, as PlanSync for WHMCS (slug plansync-for-whmcs).

- Settings, shortcodes and constants from the earlier whmcs-plans and arphost-whmcs-plans builds carry over.

- Works with the stock WHMCS order form templates and templates built on them, in any language and currency.
- Friendly, index.php and basic WHMCS URLs, WHMCS in a folder, and numeric group ids.
- Detects WHMCS redirecting an empty or hidden group to another group instead of importing the wrong plans.
- Category tabs only when plan names share a pattern; otherwise one section.
- Maximum cache age so stale prices are never shown.
- Clears W3 Total Cache, WP Super Cache, WP Rocket, LiteSpeed, WP Fastest Cache, SiteGround, Breeze and Cache Enabler page caches when a refresh changes prices or stock, or settings are saved.
- Unique ids when the same group is shown twice, block alignment and custom classes.
- Separate save forms per group so large catalogs stay under PHP's input limits.
- ARPHost branded admin screen.
