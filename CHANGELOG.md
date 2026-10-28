# Changelog

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
