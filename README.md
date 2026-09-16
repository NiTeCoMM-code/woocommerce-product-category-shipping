# Product Category Shipping for WooCommerce

**Contributors:** NiTeCoMM, techtankholdings  
**Plugin Name:** Product Category Shipping for WooCommerce  
**Requires at least:** 6.4  
**Tested up to:** 6.6  
**Requires PHP:** 7.4  
**WC requires:** 8.0  
**License:** [GPL-2.0+](https://www.gnu.org/licenses/gpl-2.0.html)  

---

WooCommerce shipping that charges **per product category** — flat rate or tiered quantity — with an optional cart-wide **Free Local Pickup** for selected user roles.

---

## Overview

This plugin replaces the default per-order or per-product shipping model with **per-category rules**. The cart is split into one WooCommerce shipping package per managed category, so each category's charge is additive and the customer sees one shipping line per category.

For selected roles (e.g. wholesale/distributor accounts), the cart can alternatively stay combined and offer a single **Shipping vs. Free Local Pickup** choice covering the entire order.

---

## Rule Types

### Flat Rate
A base price for the first item + an optional per-additional-item charge.

### Tiered Quantity
Unlimited min/max quantity brackets, each with its own price or a **free** flag. Exact-match tiers (`min == max`) take priority over range tiers — this is what enables "free at 6" and "free at 12" thresholds.

Quantity is counted **per category**. Each cart item is attributed to at most one managed category (first match wins), so a product in two categories is never charged twice.

---

## Selected Roles — Combined Mode

When a customer's role is listed in the **Local Pickup & User Roles** settings, the cart is **not** split. Instead they see:

| Option | Behaviour |
|--------|-----------|
| **Shipping** | Sum of all applicable category rules |
| **Local Pickup** | Always free |

This lets trade accounts choose pickup for their whole order without requiring a multi-package workaround. The total charged is identical to split mode; only the presentation differs.

---

## Admin Screens

| Menu | Purpose |
|------|---------|
| **WooCommerce → Settings → Shipping → Product Category Shipping** | Per-category rule configuration |
| **WooCommerce → Cajun Shipping** | Local Pickup role selection, labels, and diagnostics |

The **Local Pickup & User Roles** section includes a **diagnostics table** listing every registered role, its exact role key, user count, and which checkout behaviour it gets.

---

## Requirements

- WordPress 6.4+
- PHP 7.4+
- WooCommerce 8.0+ (plugin must be active)

---

## Installation

```bash
# Option 1: Upload via WordPress admin
# Download the zip from the Releases page and upload via Plugins → Add New → Upload Plugin.

# Option 2: Clone into wp-content/plugins
git clone https://github.com/NiTeCoMM-code/woocommerce-product-category-shipping.git
cd woocommerce-product-category-shipping/trunk
# Move contents up one level so product-category-shipping.php is at:
# wp-content/plugins/product-category-shipping/product-category-shipping.php
```

Then activate via **Plugins → Installed Plugins** and configure at **WooCommerce → Settings → Shipping → Product Category Shipping**.

---

## Known Gaps

| Gap | Impact | Workaround |
|-----|--------|------------|
| Quantity outside all tier brackets (split mode) | Checkout **blocked** for that category | Set the top tier's Max Qty to 9999 |
| Quantity outside all tier brackets (combined mode) | Category contributes **$0**, undercharging | Same as above |
| Combined mode + another plugin creating extra packages | Each extra package gets its own Shipping + Pickup pair | Avoid combining with plugins that re-package the cart |
| Selected roles see no per-category breakdown | Design trade-off of combined mode | No current fix — per-category detail requires split mode |

---

## WordPress.org

This plugin is published on [wordpress.org/plugins/product-category-shipping-for-woocommerce/](https://wordpress.org/plugins/product-category-shipping-for-woocommerce/).

---

## Contributing

Bug reports and pull requests are welcome on [GitHub](https://github.com/NiTeCoMM-code/woocommerce-product-category-shipping).

---

## License

GPL-2.0+
