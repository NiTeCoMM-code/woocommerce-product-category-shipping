<?php
defined( 'ABSPATH' ) || exit;

/**
 * BT_Shipping_Method
 *
 * Extends WC_Shipping_Method to inject per-category shipping rates
 * at checkout. Reads rules from the bt_shipping_rules option set via
 * the admin settings page.
 *
 * Two modes, selected by the packaging filter in the main plugin file
 * based on the customer's user role:
 *
 *   split    — v1.0.4 behaviour. One package per category, one rate each.
 *   combined — pickup-eligible roles. One package, two rates:
 *              a single summed "Shipping" charge, or free Local Pickup.
 */
class BT_Shipping_Method extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'bt_category_shipping';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Category Shipping', 'bt-shipping' );
        $this->method_description = __( 'WooCommerce shipping that charges per product category using flat-rate or tiered-quantity rules, with optional cart-wide Free Local Pickup for selected roles.', 'bt-shipping' );
        $this->supports           = [ 'shipping-zones', 'instance-settings' ];
        $this->enabled            = 'yes';
        $this->title              = $this->method_title;
        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );

        /*
         * REMOVED IN 1.2.0: preserve_all_rates().
         *
         * Every branch of that filter returned $rates completely untouched,
         * so it never had any effect. The real fix for a mixed cart is the
         * per-category package split in the main plugin file — a customer can
         * only pick ONE rate per package, so separate packages are what make
         * two category charges additive. Leaving a no-op filter in place only
         * invited future debugging at the wrong layer.
         *
         * What IS needed is the opposite guarantee: in combined mode the
         * customer must always see BOTH choices. Any plugin, theme, or snippet
         * can strip rates via woocommerce_package_rates, and "hide the other
         * methods when free shipping is available" is a very common one — it
         * would delete either Local Pickup or the Shipping charge and leave a
         * single unexplained line. Running last puts ours back.
         */
        add_filter( 'woocommerce_package_rates', [ $this, 'ensure_combined_rates' ], 99999, 2 );
    }

    /* ------------------------------------------------------------------
     * KEEP BOTH COMBINED-MODE RATES ON THE TABLE
     *
     * WooCommerce resets $this->rates before calculating each package and
     * fires woocommerce_package_rates immediately afterwards, so $this->rates
     * still holds exactly what we added for THIS package.
     * ------------------------------------------------------------------ */
    public function ensure_combined_rates( $rates, $package ) {

        if ( ! isset( $package['bt_mode'] ) || 'combined' !== $package['bt_mode'] ) {
            return $rates;
        }

        $settings = (array) get_option( 'bt_shipping_settings', [] );
        $force    = isset( $settings['force_pickup_rate'] ) ? $settings['force_pickup_rate'] : '1';

        if ( '1' !== $force || empty( $this->rates ) ) {
            return $rates;
        }

        foreach ( $this->rates as $rate ) {
            $id = method_exists( $rate, 'get_id' ) ? $rate->get_id() : null;
            if ( $id && ! isset( $rates[ $id ] ) ) {
                $rates[ $id ] = $rate;
            }
        }

        return $rates;
    }

    /* ------------------------------------------------------------------
     * CALCULATE SHIPPING
     * Called by WooCommerce for each package in the cart.
     * ------------------------------------------------------------------ */
    public function calculate_shipping( $package = [] ) {
        $rules = get_option( 'bt_shipping_rules', [] );
        if ( empty( $rules ) ) {
            return;
        }

        $mode = isset( $package['bt_mode'] ) ? $package['bt_mode'] : 'split';

        if ( 'combined' === $mode ) {
            $this->calculate_combined( $package, $rules );
            return;
        }

        $this->calculate_split( $package, $rules );
    }

    /* ------------------------------------------------------------------
     * SPLIT MODE — v1.0.4 behaviour
     * One rate per configured category present in this package.
     * ------------------------------------------------------------------ */
    protected function calculate_split( $package, $rules ) {

        $pkg_category = isset( $package['bt_category'] ) ? $package['bt_category'] : null;

        // Items outside every managed category get no BT rates.
        if ( '_other' === $pkg_category ) {
            return;
        }

        $managed_slugs = $this->get_managed_slugs( $rules );
        $category_qty  = $this->get_category_quantities( $package, $managed_slugs );

        if ( empty( $category_qty ) ) {
            return;
        }

        foreach ( $rules as $idx => $rule ) {
            $slug = isset( $rule['category_slug'] ) ? sanitize_title( $rule['category_slug'] ) : '';
            if ( ! $slug || ! isset( $category_qty[ $slug ] ) ) {
                continue;
            }

            // When the split ran, a package only handles its own category.
            if ( null !== $pkg_category && $slug !== $pkg_category ) {
                continue;
            }

            $amount = $this->calc_rule_amount( $rule, (int) $category_qty[ $slug ] );
            if ( ! $amount['matched'] ) {
                continue;
            }

            $label      = ! empty( $rule['label'] ) ? $rule['label'] : __( 'Shipping', 'bt-shipping' );
            $taxable    = ! empty( $rule['taxable'] ) && '1' === $rule['taxable'];
            $tax_status = $taxable ? 'taxable' : 'none';
            $suffix     = ( 'flat' === $amount['type'] ) ? '_flat' : '_tier';

            $this->add_rate( [
                // Rule index is part of the ID as of 1.2.0 so two rules on the
                // same category no longer overwrite each other's rate.
                'id'         => $this->bt_get_rate_id( $idx . '_' . $slug . $suffix ),
                'label'      => $amount['price'] > 0 ? $label : __( 'Free Shipping', 'bt-shipping' ),
                'cost'       => $amount['price'],
                'tax_status' => $tax_status,
                'meta_data'  => [ 'bt_category' => $slug ],
            ] );
        }
    }

    /* ------------------------------------------------------------------
     * COMBINED MODE — pickup-eligible roles
     *
     * The cart was deliberately NOT split for these customers, so this
     * package holds everything. Offer exactly two cart-wide choices.
     * ------------------------------------------------------------------ */
    protected function calculate_combined( $package, $rules ) {

        $settings   = (array) get_option( 'bt_shipping_settings', [] );
        $role_rules = (array) get_option( 'bt_shipping_role_rules', [] );

        /*
         * Group both rule sets by category slug. For any category the customer
         * actually has in the cart, the override set wins outright; categories
         * with no override fall back to the default rules untouched.
         */
        $by_slug      = $this->group_by_slug( $rules );
        $override     = $this->group_by_slug( $role_rules );
        $effective    = array_merge( $by_slug, $override ); // override replaces per key
        $managed_slugs = array_keys( $effective );

        $category_qty = $this->get_category_quantities( $package, $managed_slugs );

        $total       = 0.0;
        $matched_any = false;
        $taxable     = false;

        foreach ( $effective as $slug => $slug_rules ) {
            if ( ! isset( $category_qty[ $slug ] ) ) {
                continue;
            }

            foreach ( $slug_rules as $rule ) {
                $amount = $this->calc_rule_amount( $rule, (int) $category_qty[ $slug ] );

                /*
                 * KNOWN GAP: a tiered rule whose brackets do not cover the cart
                 * quantity contributes nothing here, so that category ships free.
                 * In split mode the same situation instead leaves its package with
                 * no rates at all and blocks checkout. Neither is good — the fix in
                 * both cases is to make the top tier's Max Qty large enough to
                 * cover any realistic order. The admin screen warns about this.
                 */
                if ( ! $amount['matched'] ) {
                    continue;
                }

                $total      += $amount['price'];
                $matched_any = true;

                if ( ! empty( $rule['taxable'] ) && '1' === $rule['taxable'] ) {
                    $taxable = true;
                }
            }
        }

        /*
         * One rate line can carry only one tax status. If the merchant mixes
         * taxable and non-taxable categories, the combined line is taxed —
         * under-charging tax is the worse of the two errors. The admin screen
         * flags this configuration.
         */
        $tax_status = $taxable ? 'taxable' : 'none';

        if ( $matched_any ) {
            $combined_label = ! empty( $settings['combined_label'] )
                ? $settings['combined_label']
                : __( 'Shipping', 'bt-shipping' );

            $this->add_rate( [
                'id'         => $this->bt_get_rate_id( 'combined' ),
                'label'      => $total > 0 ? $combined_label : __( 'Free Shipping', 'bt-shipping' ),
                'cost'       => $total,
                'tax_status' => $tax_status,
                'meta_data'  => [ 'bt_mode' => 'combined' ],
            ] );
        }

        /*
         * Local Pickup is always free and always offered to an eligible
         * customer, even if no category rule matched anything in the cart.
         * The rate ID suffix '_bt_pickup' is what the order-flagging hook in
         * the main plugin file looks for — keep them in sync.
         */
        $pickup_label = ! empty( $settings['pickup_label'] )
            ? $settings['pickup_label']
            : __( 'Local Pickup', 'bt-shipping' );

        if ( ! empty( $settings['pickup_note'] ) ) {
            $pickup_label .= ' — ' . $settings['pickup_note'];
        }

        $this->add_rate( [
            'id'         => $this->bt_get_rate_id( 'bt_pickup' ),
            'label'      => $pickup_label,
            'cost'       => 0.0,
            'tax_status' => 'none',
            'meta_data'  => [ 'bt_mode' => 'pickup' ],
        ] );
    }

    /* ------------------------------------------------------------------
     * HELPERS
     * ------------------------------------------------------------------ */

    /**
     * Price a single rule against a quantity. Shared by both modes so the
     * combined total can never drift from the per-category charges.
     *
     * @return array{price:float,matched:bool,type:string}
     */
    protected function calc_rule_amount( $rule, $qty ) {
        $type = isset( $rule['rule_type'] ) ? $rule['rule_type'] : 'flat';

        if ( 'flat' === $type ) {
            $base_price = isset( $rule['flat_price'] )      ? (float) $rule['flat_price']      : 0.0;
            $add_price  = isset( $rule['flat_additional'] ) ? (float) $rule['flat_additional'] : 0.0;
            // First item: base_price. Each additional item beyond the first adds add_price.
            $price      = $base_price + ( $qty > 1 ? ( $qty - 1 ) * $add_price : 0.0 );

            return [ 'price' => $price, 'matched' => true, 'type' => 'flat' ];
        }

        $matched = $this->match_tier( $qty, isset( $rule['tiers'] ) ? $rule['tiers'] : [] );

        if ( null === $matched ) {
            return [ 'price' => 0.0, 'matched' => false, 'type' => 'tiered' ];
        }

        $is_free = ! empty( $matched['free'] ) && '1' === $matched['free'];
        $price   = $is_free ? 0.0 : (float) $matched['price'];

        return [ 'price' => $price, 'matched' => true, 'type' => 'tiered' ];
    }

    /**
     * Group a rule set by category slug, preserving order.
     *
     * Returned as slug => array-of-rules rather than slug => rule so that an
     * override set replaces ALL default rules for a category atomically. If a
     * category had two default rules and one override, mixing them would
     * silently double-charge.
     *
     * @return array<string,array>
     */
    protected function group_by_slug( $rules ) {
        $out = [];

        foreach ( (array) $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $slug = isset( $rule['category_slug'] ) ? sanitize_title( $rule['category_slug'] ) : '';
            if ( ! $slug ) {
                continue;
            }
            $out[ $slug ][] = $rule;
        }

        return $out;
    }

    /**
     * Ordered, de-duplicated list of category slugs the plugin manages.
     * Order matters: it is the tie-breaker for products sitting in more
     * than one managed category, and must match the bucketing order used
     * by the packaging filter in the main plugin file.
     *
     * @return string[]
     */
    protected function get_managed_slugs( $rules ) {
        $slugs = [];
        foreach ( $rules as $rule ) {
            $slug = isset( $rule['category_slug'] ) ? sanitize_title( $rule['category_slug'] ) : '';
            if ( $slug && ! in_array( $slug, $slugs, true ) ) {
                $slugs[] = $slug;
            }
        }
        return $slugs;
    }

    /**
     * Returns [ 'category-slug' => total_qty ] for items in the package.
     *
     * Each item is attributed to at most ONE managed category — the first
     * match in $managed_slugs order. Before 1.2.0 an item sitting in two
     * managed categories was counted under both, so a single product could
     * generate (and be charged for) two shipping rates.
     */
    protected function get_category_quantities( $package, $managed_slugs = [] ) {
        $map = [];

        if ( empty( $package['contents'] ) ) {
            return $map;
        }

        foreach ( $package['contents'] as $item ) {
            $product_id = isset( $item['product_id'] ) ? $item['product_id'] : 0;
            $qty        = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
            if ( ! $product_id || $qty < 1 ) {
                continue;
            }

            $terms = get_the_terms( $product_id, 'product_cat' );
            if ( ! $terms || is_wp_error( $terms ) ) {
                continue;
            }

            $term_slugs = wp_list_pluck( $terms, 'slug' );

            if ( ! empty( $managed_slugs ) ) {
                foreach ( $managed_slugs as $slug ) {
                    if ( in_array( $slug, $term_slugs, true ) ) {
                        $map[ $slug ] = ( isset( $map[ $slug ] ) ? $map[ $slug ] : 0 ) + $qty;
                        break; // first match only
                    }
                }
            } else {
                foreach ( $term_slugs as $slug ) {
                    $map[ $slug ] = ( isset( $map[ $slug ] ) ? $map[ $slug ] : 0 ) + $qty;
                }
            }
        }

        return $map;
    }

    /**
     * Finds the best matching tier for a given quantity.
     * Exact-match tiers (qty_min === qty_max) take priority over range tiers.
     */
    protected function match_tier( $qty, $tiers ) {
        // First pass: exact match (e.g. qty_min == qty_max == 6 → free)
        foreach ( $tiers as $tier ) {
            $min = (int) $tier['qty_min'];
            $max = (int) $tier['qty_max'];
            if ( $min === $max && $qty === $min ) {
                return $tier;
            }
        }
        // Second pass: range match
        foreach ( $tiers as $tier ) {
            $min = (int) $tier['qty_min'];
            $max = (int) $tier['qty_max'];
            if ( $min === $max ) {
                continue;
            }
            if ( $qty >= $min && $qty <= $max ) {
                return $tier;
            }
        }
        return null;
    }

    /**
     * Stable rate ID scoped to this method instance + suffix.
     */
    public function bt_get_rate_id( $suffix = '' ) {
        return $this->id . '_' . $this->instance_id . '_' . $suffix;
    }
}
