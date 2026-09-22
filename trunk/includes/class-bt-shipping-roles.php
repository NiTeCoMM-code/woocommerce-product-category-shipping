<?php
defined( 'ABSPATH' ) || exit;

/**
 * BT_Shipping_Roles
 *
 * Decides whether the current customer belongs to a role that gets the
 * cart-wide choice between:
 *
 *   1. Local Pickup (free)
 *   2. The normally configured tiered / flat-rate shipping charge
 *
 * Everyone else gets stock v1.0.4 behaviour: the cart is split into one
 * package per category and each category is charged its own rate.
 *
 * There is exactly ONE role list in this plugin (bt_shipping_settings →
 * pickup_roles). Role targeting deliberately does not exist at the
 * individual rule level — a single list is far harder to misconfigure.
 */
class BT_Shipping_Roles {

    /**
     * Synthetic role key for logged-out visitors. WordPress has no real
     * role for guests, so we invent one that can be selected in admin.
     */
    const GUEST = 'bt_guest';

    /**
     * Roles for the current request. Always returns at least one entry.
     *
     * @return string[]
     */
    public static function current_roles() {
        if ( ! is_user_logged_in() ) {
            return [ self::GUEST ];
        }

        $user  = wp_get_current_user();
        $roles = ( $user && ! empty( $user->roles ) ) ? array_values( (array) $user->roles ) : [];

        // A logged-in user with no role behaves like a guest for pricing.
        return ! empty( $roles ) ? $roles : [ self::GUEST ];
    }

    /**
     * Every role a merchant can pick in the admin UI, guest included.
     *
     * Custom roles created by plugins such as Meow Crew's "Role and Customer
     * Based Pricing for WooCommerce" are ordinary WordPress roles, so they
     * appear here automatically — but their KEYS are whatever that plugin
     * generated, which is frequently mixed case ("Distributor") rather than
     * the all-lowercase keys WordPress core ships with.
     *
     * @param string[] $include_extra Saved keys to keep in the list even if no
     *                                longer registered, so they round-trip
     *                                through the form instead of vanishing.
     * @return array<string,string> role_key => display name
     */
    public static function selectable_roles( $include_extra = [] ) {
        $out = [ self::GUEST => __( 'Guest (not logged in)', 'product-category-shipping-for-woocommerce' ) ];

        if ( function_exists( 'wp_roles' ) ) {
            foreach ( wp_roles()->get_names() as $key => $name ) {
                $out[ $key ] = translate_user_role( $name );
            }
        }

        foreach ( self::sanitize( $include_extra ) as $key ) {
            if ( ! isset( $out[ $key ] ) && null === self::find_registered( $key, $out ) ) {
                /* translators: %s: user role key */
                $out[ $key ] = sprintf( __( '%s (no longer registered)', 'product-category-shipping-for-woocommerce' ), $key );
            }
        }

        return $out;
    }

    /**
     * Find the real registered key matching $key, ignoring case.
     *
     * Needed because plugin versions up to 1.2.0 ran role keys through
     * sanitize_key(), which lowercases. Existing installs therefore have
     * "distributor" stored for a role actually keyed "Distributor".
     *
     * @return string|null
     */
    public static function find_registered( $key, $haystack = null ) {
        $key = (string) $key;

        if ( null === $haystack ) {
            $haystack = self::selectable_roles();
        }

        if ( isset( $haystack[ $key ] ) ) {
            return $key;
        }

        foreach ( array_keys( $haystack ) as $real ) {
            if ( 0 === strcasecmp( (string) $real, $key ) ) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Map a stored key onto the currently registered role key where possible,
     * preserving the original if the role no longer exists.
     */
    public static function resolve( $key ) {
        $found = self::find_registered( $key );
        return ( null !== $found ) ? $found : (string) $key;
    }

    /**
     * The configured pickup-eligible role list.
     *
     * @return string[]
     */
    public static function pickup_roles() {
        $settings = (array) get_option( 'bt_shipping_settings', [] );
        return self::sanitize( isset( $settings['pickup_roles'] ) ? $settings['pickup_roles'] : [] );
    }

    /**
     * Is this customer eligible for the pickup / combined-rate treatment?
     *
     * An EMPTY role list means "nobody" — never "everybody". That way an
     * unconfigured install behaves exactly like v1.0.4, and a merchant can
     * never accidentally hand free pickup to the entire internet by
     * clearing a field.
     *
     * @param string[]|null $roles Roles to test; defaults to current user.
     */
    public static function is_eligible( $roles = null ) {
        $allowed = self::pickup_roles();

        if ( empty( $allowed ) ) {
            return false;
        }

        $roles = ( null === $roles ) ? self::current_roles() : self::sanitize( $roles );

        /*
         * Compared case-insensitively on purpose. WordPress role keys are
         * arbitrary strings and custom-role plugins routinely use mixed case,
         * while installs saved under <= 1.2.0 hold a lowercased copy. Matching
         * on lowercase makes both old and new data work without a migration.
         */
        $allowed = array_map( 'strtolower', $allowed );
        $roles   = array_map( 'strtolower', $roles );

        return (bool) array_intersect( $allowed, $roles );
    }

    /**
     * Normalise a submitted or stored role list.
     *
     * IMPORTANT: this deliberately does NOT use sanitize_key().
     *
     * sanitize_key() lowercases its input. Role keys are case-sensitive
     * arbitrary strings, and plugins that let merchants create roles — such as
     * Meow Crew's Roles Management tool — commonly produce keys like
     * "Distributor" or "Retail". Running those through sanitize_key() stored
     * them as "distributor", which no longer matched the value rendered in the
     * admin <select>. The role therefore appeared unselected after saving, and
     * the next save dropped it entirely: the "role cannot be saved" bug.
     *
     * Validation is a conservative allow-list instead. Only users with
     * manage_woocommerce reach this code path.
     *
     * @return string[]
     */
    public static function sanitize( $roles ) {
        if ( ! is_array( $roles ) ) {
            $roles = ( '' === $roles || null === $roles ) ? [] : [ $roles ];
        }

        $clean = [];

        foreach ( $roles as $role ) {
            if ( ! is_scalar( $role ) ) {
                continue;
            }

            $role = trim( (string) $role );

            if ( '' === $role || ! preg_match( '/^[A-Za-z0-9 ._\-]{1,100}$/', $role ) ) {
                continue;
            }

            if ( ! in_array( $role, $clean, true ) ) {
                $clean[] = $role;
            }
        }

        return $clean;
    }

    /**
     * Human-readable list of role keys, for admin summaries.
     *
     * @return string[]
     */
    public static function labels( $roles ) {
        $all   = self::selectable_roles();
        $names = [];

        foreach ( self::sanitize( $roles ) as $key ) {
            $names[] = isset( $all[ $key ] ) ? $all[ $key ] : $key;
        }

        return $names;
    }
}
