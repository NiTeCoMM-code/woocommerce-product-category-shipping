<?php
/**
 * Plugin Name:       Product Category Shipping for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/product-category-shipping-for-woocommerce/
 * Description:       WooCommerce shipping plugin that charges per product category using flat-rate or tiered-quantity rules. Selected user roles can be offered a cart-wide Free Local Pickup option alongside the configured shipping rates.
 * Version:           1.4.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Tested up to:      6.6
 * WC requires at least: 8.0
 * WC tested up to:    9.3
 * Author:            Braxton Moody
 * Author URI:        https://techtankholdings.com/
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bt-shipping
 * Domain Path:       /languages
 *
 * Copyright 2026 Braxton Moody / TechTank Holdings, Inc.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'BT_SHIPPING_VERSION', '1.4.0' );
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
 *    presentation differs.
 * ------------------------------------------------------------- */

/* ---------------------------------------------------------------
 * 5. TRANSLATION
 * ------------------------------------------------------------- */
add_action( 'init', function () {
	load_plugin_textdomain(
		'bt-shipping',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
} );
