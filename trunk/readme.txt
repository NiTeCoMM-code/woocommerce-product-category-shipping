=== Product Category Shipping for WooCommerce ===
Contributors:      Braxton Moody, techtankholdings
Donate link:       https://techtankholdings.com/
Tags:              woocommerce, shipping, category, flat rate, tiered shipping
Requires at least: 6.4
Requires PHP:      7.4
Tested up to:      6.6
WC requires at least: 8.0
WC tested up to:   9.3
Stable tag:        1.4.0
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce shipping that charges per product category — flat rate or tiered quantity — with an optional cart-wide Free Local Pickup for selected user roles.

== Description ==

Product Category Shipping lets WooCommerce store owners define shipping rules **per product category** instead of per order or per product. Two rule types are available:

* **Flat Rate** — a base charge for the first item, plus an optional per-additional-item charge.
* **Tiered Quantity** — unlimited min/max quantity brackets, each with its own price or a "free" flag.

The plugin splits the cart into one WooCommerce shipping package per managed category, so charges are additive and customers see one shipping line per category.

**Selected roles** (e.g. distributors, wholesale accounts) can additionally be offered a cart-wide choice between **Free Local Pickup** and the configured shipping charge. When this mode is active the cart is not split — the customer sees a single combined shipping total alongside the free pickup option.

= Features =

* Per-category shipping rules (flat rate or tiered quantity)
* Tiered rules support free-shipping thresholds (e.g. "free when quantity ≥ 6")
* Split-cart mode: one shipping line per category, additive charges
* Combined-cart mode for selected roles: Shipping + Free Local Pickup as two cart-wide choices
* Role diagnostics table in wp-admin showing exact role keys and user counts
* Rate guard prevents third-party "hide other methods when free shipping applies" filters from accidentally removing the Local Pickup option
* No server round-trips; all calculations run locally on checkout

= Requirements =

* WordPress 6.4+
* PHP 7.4+
* WooCommerce 8.0+ (plugin must be active)

== Installation ==

1. Upload the `product-category-shipping` folder to `/wp-content/plugins/` or install via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce → Settings → Shipping → Product Category Shipping** and add rules for each category you manage.
4. Optionally, go to **WooCommerce → Cajun Shipping** to configure Local Pickup roles and labels.

== Frequently Asked Questions ==

= Can I use different rule types for different categories? =

Yes. Each category is configured independently and may use Flat Rate or Tiered Quantity rules.

= How does the tiered quantity mode work? =

You define quantity brackets such as 1–5 = $8, 6–11 = $5, 12+ = free. Quantities are counted **per category**, not per order. Exact-match tiers (e.g. "exactly 6") take priority over range tiers.

= What happens if a customer's cart quantity falls outside all defined tiers? =

Split mode (default): that category package will have no applicable rate and **checkout will be blocked** until the customer adjusts quantity or the admin extends the top tier. Combined mode: that category contributes $0 to the total.

= How do I enable Free Local Pickup for specific roles? =

Go to **WooCommerce → Cajun Shipping → Local Pickup & User Roles** and add the desired role keys to the **Pickup Roles** field. Role keys are case-sensitive (e.g. `Distributor`, not `distributor`).

= Does the plugin work with WPML / Polylang? =

The plugin loads its own text domain. Translation files (.po/.mo) can be placed in `wp-content/languages/plugins/bt-shipping-{locale}.mo`.

== Screenshots ==

1. Default Shipping Rules — green cards, one per managed category.
2. Selected-Role Override Rules — red cards, inert until roles are configured.
3. Local Pickup & User Roles — role picker, labels, diagnostics table.

== Changelog ==

= 1.4.0 =
* Rebranded to "Product Category Shipping for WooCommerce"
* Updated header fields for WordPress.org compatibility (Tested up to 6.6, WC 9.3)
* Added `Requires at least`, `Requires PHP`, `WC requires at least`, `WC tested up to` header fields
* Added translation infrastructure (`load_plugin_textdomain`, languages directory)
* Internal: renamed main plugin file; no functional changes

= 1.3.0 =
* Added Selected-Role Override Rules — a second, independent rule set that fully replaces the default rules for a category when the customer's role is in `pickup_roles`.

= 1.2.1 =
* Fixed role keys being lowercased by `sanitize_key()`, which silently dropped custom roles on every save.
* Added role diagnostics table to the admin screen.
* Added rate guard at priority 99999 to re-insert both combined-mode rates so a third-party "hide other methods when free shipping exists" filter cannot remove one.

= 1.2.0 =
* Role-gated free Local Pickup with combined cart-wide rate for selected roles.
* Fixed double-counting for products assigned to two managed categories.
* Made shipping rate IDs unique across all categories.

= 1.0.4 =
* Per-category rules, cart split, admin settings page.

== Upgrade Notice ==

= 1.4.0 =
Mandatory update for WordPress.org distribution. No behaviour changes from 1.3.0.
