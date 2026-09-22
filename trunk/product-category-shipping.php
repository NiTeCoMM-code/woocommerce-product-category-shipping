<?php
/**
 * Plugin Name:       Product Category Shipping for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/product-category-shipping-for-woocommerce/
 * Description:       WooCommerce shipping plugin that charges per product category using flat-rate or tiered-quantity rules. Selected user roles can be offered a cart-wide Free Local Pickup option alongside the configured shipping rates.
 * Version:           1.4.1
 * Author:            Braxton Moody
 * License:           GPL-2.0+
 * Text Domain:       product-category-shipping-for-woocommerce
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to:    9.3
 * Author URI:        https://techtankholdings.com/
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'BT_SHIPPING_VERSION', '1.4.1' );
define( 'BT_SHIPPING_FILE',    __FILE__ );
define( 'BT_SHIPPING_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BT_SHIPPING_URL',     plugin_dir_url( __FILE__ ) );

/* ---------------------------------------------------------------
 * 1. LOAD SHARED + ADMIN CLASSES
 *
 *    The roles helper is used on the front end (deciding which
 *    packaging mode applies) and in wp-admin (the role picker),
 *    so it loads unconditionally.
 * ------------------------------------------------------------- */
require_once BT_SHIPPING_DIR . 'includes/class-bt-shipping-roles.php';
require_once BT_SHIPPING_DIR . 'includes/class-bt-shipping-admin.php';

/* ---------------------------------------------------------------
 * 2. REGISTER SHIPPING METHOD WITH WOOCOMMERCE
 * ------------------------------------------------------------- */
add_action( 'woocommerce_shipping_init', function () {
    require_once BT_SHIPPING_DIR . 'includes/class-bt-shipping-method.php';
} );

add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
    $methods['bt_category_shipping'] = 'BT_Shipping_Method';
    return $methods;
} );

/* ---------------------------------------------------------------
 * 3. BOOT ADMIN UI
 * ------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
    if ( is_admin() ) {
        new BT_Shipping_Admin();
    }
} );

/* ---------------------------------------------------------------
 * 4. PACKAGING
 *
 *    TWO MODES, chosen by the customer's user role.
 *
 *    ── SPLIT MODE (default, unchanged from v1.0.4) ────────────
 *    Root cause of mixed cart failure: WooCommerce puts all cart
 *    items into a single package by default, and a customer may
 *    only choose ONE rate per package. Our method adds one rate
 *    per category, so in a single package the customer picks one
 *    and the other category ships for nothing.
 *
 *    Correct solution: split the cart into one package per BT
 *    rule category. WooCommerce then calculates shipping separately
 *    for each package and shows each as its own line item.
 *    Free shipping on package A never affects package B.
 *
 *    ── COMBINED MODE (roles listed in pickup_roles) ───────────
 *    These customers must be able to choose Local Pickup for the
 *    WHOLE cart. That is impossible to express reliably across
 *    several independent per-package radio groups: WooCommerce has
 *    no concept of a choice that spans packages, so the customer
 *    could pick up the seasoning and ship the shirt, or get wedged
 *    in a state they cannot back out of.
 *
 *    So for these roles we do NOT split. The cart stays one package
 *    and the method offers exactly two options:
 *        • Shipping     — the sum of the same tiered/flat rules
 *        • Local Pickup — free
 *    The total charged is identical to split mode; only the
 *    itemised per-category breakdown is not shown.
 *
 *    Both modes stamp 'bt_roles' onto the package. WooCommerce
 *    hashes the whole package array to key its shipping-rate cache,
 *    so including roles means a login, logout, or role change
 *    invalidates cached rates instead of serving a stale price.
 * ------------------------------------------------------------- */
add_filter( 'woocommerce_cart_shipping_packages', function( $packages ) {

    $rules      = get_option( 'bt_shipping_rules', [] );
    $role_rules = get_option( 'bt_shipping_role_rules', [] );

    if ( empty( $rules ) && empty( $role_rules ) ) {
        return $packages;
    }

    $bt_roles = BT_Shipping_Roles::current_roles();

    /* ---------- COMBINED MODE ----------
     * Eligibility is checked BEFORE any slug work. Combined mode never splits,
     * and the override set may target categories the default set does not
     * cover — so the default slug list is irrelevant here.
     */
    if ( BT_Shipping_Roles::is_eligible( $bt_roles ) ) {
        foreach ( $packages as $k => $pkg ) {
            $packages[ $k ]['bt_mode']  = 'combined';
            $packages[ $k ]['bt_roles'] = $bt_roles;
        }
        return $packages;
    }

    /* ---------- SPLIT MODE (v1.0.4 behaviour) ----------
     * Bucketing uses the DEFAULT rules only. Including override-only
     * categories here would create a package for a customer who has no rule
     * to price it, leaving that package rateless and blocking checkout.
     */
    $managed_slugs = [];
    foreach ( $rules as $rule ) {
        $slug = isset( $rule['category_slug'] ) ? sanitize_title( $rule['category_slug'] ) : '';
        if ( $slug && ! in_array( $slug, $managed_slugs, true ) ) {
            $managed_slugs[] = $slug;
        }
    }
    if ( empty( $managed_slugs ) ) {
        return $packages;
    }

    $cart_items = WC()->cart ? WC()->cart->get_cart() : [];
    if ( empty( $cart_items ) ) {
        return $packages;
    }

    // Bucket items by their BT category slug
    // Items that belong to multiple managed categories go into the first match
    // Items with no managed category go into an 'other' bucket
    $buckets = [];
    foreach ( $cart_items as $cart_item_key => $cart_item ) {
        $product_id = $cart_item['product_id'];
        $terms      = get_the_terms( $product_id, 'product_cat' );
        $matched    = false;

        if ( $terms && ! is_wp_error( $terms ) ) {
            $term_slugs = wp_list_pluck( $terms, 'slug' );
            foreach ( $managed_slugs as $slug ) {
                if ( in_array( $slug, $term_slugs, true ) ) {
                    $buckets[ $slug ][ $cart_item_key ] = $cart_item;
                    $matched = true;
                    break;
                }
            }
        }

        if ( ! $matched ) {
            $buckets['_other'][ $cart_item_key ] = $cart_item;
        }
    }

    if ( empty( $buckets ) ) {
        return $packages;
    }

    /*
     * Single-bucket carts need no split, but they still get the
     * bt_category / bt_roles stamps so the method follows the same code
     * path and the rate cache stays role-aware.
     */
    if ( 1 === count( $buckets ) && 1 === count( $packages ) ) {
        $only_slug = key( $buckets );
        foreach ( $packages as $k => $pkg ) {
            $packages[ $k ]['bt_mode']     = 'split';
            $packages[ $k ]['bt_category'] = $only_slug;
            $packages[ $k ]['bt_roles']    = $bt_roles;
        }
        return $packages;
    }

    // If everything landed in one bucket, no split needed — return as-is
    if ( count( $buckets ) <= 1 ) {
        return $packages;
    }

    // Build one WooCommerce package per bucket
    // Use the first original package as the template for destination etc.
    $template     = reset( $packages );
    $new_packages = [];

    foreach ( $buckets as $slug => $items ) {
        $new_packages[] = [
            'contents'        => $items,
            'contents_cost'   => array_sum( wp_list_pluck( $items, 'line_total' ) ),
            'applied_coupons' => $template['applied_coupons'] ?? [],
            'user'            => $template['user']            ?? [ 'ID' => get_current_user_id() ],
            'destination'     => $template['destination']     ?? [],
            'cart_subtotal'   => $template['cart_subtotal']   ?? 0,
            'bt_mode'         => 'split',
            'bt_category'     => $slug,
            'bt_roles'        => $bt_roles,
        ];
    }

    return $new_packages;

}, 10 );

/* ---------------------------------------------------------------
 * 5. FLAG PICKUP ORDERS
 *
 *    Records on the order that the customer chose Local Pickup, so
 *    fulfilment can see it without parsing the rate label.
 * ------------------------------------------------------------- */
add_action( 'woocommerce_checkout_create_order', function ( $order ) {

    if ( ! $order || ! WC()->session ) {
        return;
    }

    $chosen = (array) WC()->session->get( 'chosen_shipping_methods', [] );

    foreach ( $chosen as $method_id ) {
        if ( is_string( $method_id ) && false !== strpos( $method_id, '_bt_pickup' ) ) {
            $order->update_meta_data( '_bt_local_pickup', 'yes' );
            return;
        }
    }
}, 10, 1 );

/* ---------------------------------------------------------------
 * 6. INVALIDATE SHIPPING CACHE ON ROLE / LOGIN CHANGES
 *
 *    Which packaging mode applies depends on the customer's role, so
 *    a stale transient version can show the wrong options right after
 *    a role change or login.
 * ------------------------------------------------------------- */
foreach ( [ 'set_user_role', 'add_user_role', 'remove_user_role', 'wp_login', 'wp_logout' ] as $bt_shipping_clear_hook ) {
    add_action( $bt_shipping_clear_hook, function () {
        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }
    } );
}

/* ---------------------------------------------------------------
 * 7. ACTIVATION DEFAULTS
 * ------------------------------------------------------------- */
register_activation_hook( __FILE__, function () {

    if ( false === get_option( 'bt_shipping_rules' ) ) {
        $defaults = [
            [
                'category_slug' => 'cajun-seasoning',
                'rule_type'     => 'tiered',
                'label'         => 'Shipping',
                'taxable'       => '0',
                'tiers'         => [
                    [ 'qty_min' => 1,  'qty_max' => 5,  'price' => '10.20', 'free' => '0' ],
                    [ 'qty_min' => 6,  'qty_max' => 6,  'price' => '0.00',  'free' => '1' ],
                    [ 'qty_min' => 7,  'qty_max' => 11, 'price' => '18.50', 'free' => '0' ],
                    [ 'qty_min' => 12, 'qty_max' => 12, 'price' => '0.00',  'free' => '1' ],
                ],
            ],
            [
                'category_slug' => 'merchandise',
                'rule_type'     => 'flat',
                'label'         => 'Shipping',
                'taxable'       => '0',
                'flat_price'    => '5.99',
            ],
        ];
        update_option( 'bt_shipping_rules', $defaults );
    }

    // Override rules for the selected roles. Empty = selected roles simply
    // use the default rates above.
    if ( false === get_option( 'bt_shipping_role_rules' ) ) {
        update_option( 'bt_shipping_role_rules', [] );
    }

    // Empty pickup_roles = feature off = identical to v1.0.4.
    if ( false === get_option( 'bt_shipping_settings' ) ) {
        update_option( 'bt_shipping_settings', [
            'pickup_roles'      => [],
            'pickup_label'      => 'Local Pickup',
            'pickup_note'       => '',
            'combined_label'    => 'Shipping',
            'force_pickup_rate' => '1',
        ] );
    }
} );
