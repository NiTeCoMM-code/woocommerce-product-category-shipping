<?php
defined( 'ABSPATH' ) || exit;

/*
 * TextDomainMismatch suppression: Plugin Check infers the expected text domain
 * from the plugin folder name on the server (e.g. product-category-shipping-v1-4-2/).
 * The canonical Text Domain header is product-category-shipping-v1-4-2,
 * matching the wordpress.org slug. Folder names vary by install/auto-update version.
 * The declared domain is correct; the mismatch is a false positive.
 */
/* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */

/**
 * BT_Shipping_Admin
 *
 * Adds a "Category Shipping" menu page under WooCommerce in wp-admin.
 * Lets the merchant:
 *   - Add/remove category-based shipping rules
 *   - Choose rule type: flat rate or tiered quantity brackets
 *   - Set label, taxable toggle, and prices per rule
 *   - Add unlimited tiers per tiered rule
 *   - Pick which user roles get the cart-wide Local Pickup option
 */
class BT_Shipping_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_bt_shipping_save', [ $this, 'save_rules' ] );
        add_action( 'wp_ajax_bt_get_categories', [ $this, 'ajax_get_categories' ] );
    }

    /* ------------------------------------------------------------------
     * MENU
     * ------------------------------------------------------------------ */
    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
            __( 'Category Shipping Rules', 'product-category-shipping-v1-4-2' ),
            __( 'Category Shipping', 'product-category-shipping-v1-4-2' ),
            'manage_woocommerce',
            'bt-shipping-rules',
            [ $this, 'render_page' ]
        );
    }

    /* ------------------------------------------------------------------
     * ASSETS
     * ------------------------------------------------------------------ */
    public function enqueue_assets( $hook ) {
        if ( $hook !== 'woocommerce_page_bt-shipping-rules' ) {
            return;
        }
        wp_enqueue_style(
            'bt-shipping-admin',
            BT_SHIPPING_URL . 'admin.css',
            [],
            BT_SHIPPING_VERSION
        );
        wp_enqueue_script(
            'bt-shipping-admin',
            BT_SHIPPING_URL . 'admin.js',
            [],
            BT_SHIPPING_VERSION,
            true
        );
    }

    /* ------------------------------------------------------------------
     * RENDER PAGE
     * ------------------------------------------------------------------ */
    public function render_page() {
        $saved      = get_option( 'bt_shipping_rules', [] );
        $role_saved = get_option( 'bt_shipping_role_rules', [] );
        $settings   = (array) get_option( 'bt_shipping_settings', [] );
        $message    = '';

        if ( isset( $_GET['bt_saved'], $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'bt_saved' )
        ) {
            $saved_msg = sanitize_text_field( wp_unslash( $_GET['bt_saved'] ) );
            $message   = $saved_msg === '1'
                ? '<div class="bt-notice success">✓ Shipping rules saved successfully.</div>'
                : '<div class="bt-notice">Something went wrong. Please try again.</div>';
        }

        // Fetch all WooCommerce product categories
        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
        ] );

        ob_start();
        ?>
        <div id="bt-shipping-wrap">
            <h1>
                Category Shipping Rules
                <span class="bt-badge">v<?php echo esc_html( BT_SHIPPING_VERSION ); ?></span>
            </h1>
            <p class="bt-desc">
                Configure per-category shipping rates. Assign <strong>Flat Rate</strong> or <strong>Tiered Quantity</strong> rules to any product category.
                Rules apply at checkout based on item category counts in the cart.
            </p>

            <?php echo wp_kses_post( $message ); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bt-shipping-form">
                <?php wp_nonce_field( 'bt_shipping_save', 'bt_nonce' ); ?>
                <input type="hidden" name="action" value="bt_shipping_save">

                <?php $this->render_pickup_card( $settings, array_merge( (array) $saved, (array) $role_saved ) ); ?>

                <h2 class="bt-section-head">
                    Default Shipping Rules
                    <span class="bt-section-sub">Apply to every customer</span>
                </h2>

                <div id="bt-rules-container">
                    <?php
                    if ( empty( $saved ) ) {
                        echo '<p style="color:#888;font-size:.9rem;">No rules yet. Click "Add Shipping Rule" to get started.</p>';
                    } else {
                        foreach ( $saved as $i => $rule ) {
                            $this->render_rule_card( $i, $rule, $categories, 'rules' );
                        }
                    }
                    ?>
                </div>

                <button type="button" id="bt-add-rule"
                        data-container="bt-rules-container"
                        data-template="bt-rule-template"
                        data-count="<?php echo esc_attr( count( (array) $saved ) ); ?>">+ Add Shipping Rule</button>

                <?php $this->render_role_rules_intro( $settings ); ?>

                <div id="bt-role-rules-container">
                    <?php
                    if ( empty( $role_saved ) ) {
                        echo '<p style="color:#888;font-size:.9rem;">No override rules. Selected roles are charged the default rates above.</p>';
                    } else {
                        foreach ( $role_saved as $i => $rule ) {
                            $this->render_rule_card( $i, $rule, $categories, 'role_rules' );
                        }
                    }
                    ?>
                </div>

                <button type="button" id="bt-add-role-rule" class="bt-add-role"
                        data-container="bt-role-rules-container"
                        data-template="bt-role-rule-template"
                        data-count="<?php echo esc_attr( count( (array) $role_saved ) ); ?>">+ Add Override Rule</button>

                <div class="bt-save-bar">
                    <input type="submit" value="Save Shipping Rules">
                    <span class="bt-saved-msg">Rules saved!</span>
                </div>
            </form>

            <!-- Hidden templates for new rules (rendered server-side, cloned by JS) -->
            <template id="bt-rule-template">
                <?php $this->render_rule_card( '__IDX__', [], $categories, 'rules' ); ?>
            </template>
            <template id="bt-role-rule-template">
                <?php $this->render_rule_card( '__IDX__', [], $categories, 'role_rules' ); ?>
            </template>
        </div>
        <?php
        /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */
        echo ob_get_clean();  // content pre-escaped at field level; buffer is not user input
    }

    /* ------------------------------------------------------------------
     * RENDER THE OVERRIDE-SECTION HEADING
     * ------------------------------------------------------------------ */
    private function render_role_rules_intro( $settings ) {
        $pickup_roles = BT_Shipping_Roles::sanitize( $settings['pickup_roles'] ?? [] );
        $names        = BT_Shipping_Roles::labels( $pickup_roles );
        ?>
        <h2 class="bt-section-head role">
            Selected-Role Override Rules
            <span class="bt-section-sub">
                <?php
                echo $names
                    ? esc_html( implode( ', ', $names ) )
                    /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                    : esc_html__( 'No roles selected yet — these rules are inactive', 'product-category-shipping-v1-4-2' );
                ?>
            </span>
        </h2>
        <div class="bt-callout" style="margin-top:0;margin-bottom:20px;">
            Rules here <strong>replace</strong> the default rule for the same product category, but only for the
            roles chosen in the Local Pickup card above. Configure them exactly like the default rules —
            Flat Rate or Tiered Quantity, per category.
            <ul>
                <li><strong>Category with an override rule</strong> → the override price is used.</li>
                <li><strong>Category without one</strong> → falls back to the default rule, unchanged.</li>
            </ul>
            Selected roles still see a single combined <em>Shipping</em> line (the sum of whichever rules apply
            to their cart) alongside free <em>Local Pickup</em>. Everyone else is unaffected by this section.
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * RENDER LOCAL PICKUP CARD (global — applies to the whole cart)
     * ------------------------------------------------------------------ */
    private function render_pickup_card( $settings, $rules ) {

        $pickup_roles   = BT_Shipping_Roles::sanitize( $settings['pickup_roles'] ?? [] );
        $roles          = BT_Shipping_Roles::selectable_roles( $pickup_roles );
        $pickup_label   = $settings['pickup_label']   ?? 'Local Pickup';
        $pickup_note    = $settings['pickup_note']    ?? '';
        $combined_label = $settings['combined_label'] ?? 'Shipping';
        $force_rate     = $settings['force_pickup_rate'] ?? '1';
        $is_on          = ! empty( $pickup_roles );

        // Case-insensitive lookup set. Roles saved by <= 1.2.0 were lowercased
        // by sanitize_key(), so a strict comparison would render a genuinely
        // saved role as unselected — the original "cannot be saved" bug.
        $selected_lc = array_map( 'strtolower', $pickup_roles );

        // Config warnings that only bite once combined mode is live.
        $tax_states = [];
        $open_tiers = [];
        foreach ( (array) $rules as $rule ) {
            $tax_states[] = ! empty( $rule['taxable'] ) && '1' === $rule['taxable'] ? 'yes' : 'no';
            if ( ( $rule['rule_type'] ?? 'flat' ) === 'tiered' ) {
                $max = 0;
                foreach ( ( $rule['tiers'] ?? [] ) as $tier ) {
                    $max = max( $max, (int) ( $tier['qty_max'] ?? 0 ) );
                }
                if ( $max > 0 && $max < 100 ) {
                    $open_tiers[] = ( $rule['category_slug'] ?? '?' ) . ' (max qty ' . $max . ')';
                }
            }
        }
        $mixed_tax = count( array_unique( $tax_states ) ) > 1;
        ?>
        <div class="bt-rule-card" id="bt-pickup-card">
            <div class="bt-rule-header" onclick="btToggleCard(this)">
                <h3>
                    🏪 Local Pickup &amp; User Roles
                    <span class="bt-status-pill <?php echo $is_on ? 'on' : 'off'; ?>">
                        <?php echo $is_on ? esc_html( strtoupper( 'Enabled for ' . count( $pickup_roles ) . ' role(s)' ) ) : 'OFF'; ?>
                    </span>
                </h3>
                <span>▾</span>
            </div>
            <div class="bt-rule-body">

                /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                <label style="margin-top:0;"><?php esc_html_e( 'Roles that get the Local Pickup option', 'product-category-shipping-v1-4-2' ); ?></label>
                <select class="bt-role-select" name="bt_settings[pickup_roles][]" multiple size="8">
                    <?php foreach ( $roles as $key => $display ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"
                            <?php echo in_array( strtolower( (string) $key ), $selected_lc, true ) ? 'selected' : ''; ?>>
                            <?php echo esc_html( $display ); ?> — <?php echo esc_html( $key ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="bt-hint">
                    Ctrl/Cmd-click to select more than one. Each entry shows the role's display name followed by
                    its actual <strong>role key</strong> — custom roles from plugins like Meow Crew's Roles
                    Management tool often use a capitalised key such as <code>Distributor</code>, and the key is
                    what actually gets matched.
                    <strong>Leave empty to switch the feature off entirely</strong> — the plugin then behaves
                    exactly as it did in v1.0.4. Empty never means "everyone".
                </p>

                <?php if ( $is_on ) : ?>
                    <div class="bt-callout" style="border-color:#c9e2c9;background:#f4fbf4;color:#2c4a2c;">
                        <strong>Currently saved:</strong>
                        <?php echo esc_html( implode( ', ', $pickup_roles ) ); ?>
                        <br>
                        Any customer holding one of these role keys gets the Local Pickup / Shipping choice.
                        Matching ignores capitalisation, so <code>Distributor</code> and <code>distributor</code>
                        both work.
                    </div>
                <?php endif; ?>

                <div class="bt-callout">
                    <strong>What a selected role sees at checkout</strong>
                    <ul>
                        <li><strong>Shipping</strong> — one line, the total of every category rule that applies
                            to their cart. Same tiers, same flat rates, same free-at-6-and-12 logic, same total.</li>
                        <li><strong>Local Pickup</strong> — free, covers the entire order.</li>
                    </ul>
                    These two are cart-wide alternatives, so the customer makes one decision for the whole order
                    and cannot end up picking up part of it and shipping the rest.
                    <br><br>
                    <strong>Trade-off:</strong> selected roles see a single combined shipping line instead of
                    one line per category. The amount charged is identical — only the breakdown is hidden.
                    Everyone <em>not</em> in a selected role keeps the itemised per-category lines exactly as before.
                </div>

                <div class="bt-row" style="margin-top:20px;">
                    <div>
                        /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                        <label style="margin-top:0;"><?php esc_html_e( 'Pickup label', 'product-category-shipping-v1-4-2' ); ?></label>
                        <input type="text" name="bt_settings[pickup_label]"
                               value="<?php echo esc_attr( $pickup_label ); ?>"
                               placeholder="Local Pickup">
                    </div>
                    <div>
                        /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                        <label style="margin-top:0;"><?php esc_html_e( 'Combined shipping label', 'product-category-shipping-v1-4-2' ); ?></label>
                        <input type="text" name="bt_settings[combined_label]"
                               value="<?php echo esc_attr( $combined_label ); ?>"
                               placeholder="Shipping">
                        <p class="bt-hint">Replaced with "Free Shipping" when the total is $0.</p>
                    </div>
                </div>

                /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                <label><?php esc_html_e( 'Pickup note (optional)', 'product-category-shipping-v1-4-2' ); ?></label>
                <input type="text" name="bt_settings[pickup_note]"
                       value="<?php echo esc_attr( $pickup_note ); ?>"
                       placeholder="e.g. 1234 Main St — Mon–Fri 9am–5pm">
                <p class="bt-hint">Appended to the pickup label at checkout.</p>

                <div class="bt-toggle-row" style="margin-top:22px;">
                    <input type="checkbox" class="bt-toggle"
                           name="bt_settings[force_pickup_rate]" id="bt_force_pickup_rate"
                           value="1" <?php checked( $force_rate, '1' ); ?>>
                    <label for="bt_force_pickup_rate">
                        Always show both options, even if another plugin tries to remove one
                    </label>
                </div>
                <p class="bt-hint">
                    WooCommerce lets any plugin or snippet filter shipping rates away, and hiding paid rates
                    whenever a free rate exists is a common one — which would eat either Local Pickup or the
                    Shipping charge. With this on, both are re-inserted after every other filter has run.
                    Turn it off if you deliberately want something else to be able to hide pickup
                    (for example a postcode restriction).
                </p>

                <?php if ( $is_on && $mixed_tax ) : ?>
                    <div class="bt-callout warn">
                        <strong>Mixed tax settings detected.</strong>
                        Your rules do not agree on whether shipping is taxable. A combined line can carry only
                        one tax status, so for selected roles the whole shipping charge will be treated as
                        <strong>taxable</strong> (over-collecting is the safer error). Set every rule the same
                        way to remove the ambiguity.
                    </div>
                <?php endif; ?>

                <?php if ( $is_on && ! empty( $open_tiers ) ) : ?>
                    <div class="bt-callout warn">
                        <strong>Check your top tier.</strong>
                        These tiered rules stop at a low quantity: <?php echo esc_html( implode( ', ', $open_tiers ) ); ?>.
                        If a cart exceeds the highest tier, no tier matches — that category then contributes
                        <strong>$0</strong> to the combined rate, so the customer is under-charged. Raise the
                        top tier's Max Qty (e.g. 9999) so every cart size is covered.
                    </div>
                <?php endif; ?>

                <div class="bt-tiers-wrap" style="margin-top:26px;">
                    /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                    <h4><?php esc_html_e( 'Diagnostics — what each role actually gets', 'product-category-shipping-v1-4-2' ); ?></h4>
                    <table class="bt-tier-table">
                        <thead>
                            <tr>
                                /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                                <th><?php esc_html_e( 'Role', 'product-category-shipping-v1-4-2' ); ?></th>
                                <th><?php esc_html_e( 'Role key (what is matched)', 'product-category-shipping-v1-4-2' ); ?></th>
                                <th><?php esc_html_e( 'Users', 'product-category-shipping-v1-4-2' ); ?></th>
                                /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                                <th><?php esc_html_e( 'Checkout behaviour', 'product-category-shipping-v1-4-2' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $counts = function_exists( 'count_users' ) ? count_users() : [ 'avail_roles' => [] ];
                        foreach ( $roles as $key => $display ) :
                            $eligible = in_array( strtolower( (string) $key ), $selected_lc, true );
                            $n        = ( BT_Shipping_Roles::GUEST === $key )
                                ? '—'
                                : (string) ( $counts['avail_roles'][ $key ] ?? 0 );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $display ); ?></td>
                                <td><code><?php echo esc_html( $key ); ?></code></td>
                                <td><?php echo esc_html( $n ); ?></td>
                                <td>
                                    <?php if ( $eligible ) : ?>
                                        <strong style="color:var(--bt-green);">Pickup or Shipping</strong>
                                        <span style="color:#888;">— one combined charge, or free pickup</span>
                                    <?php else : ?>
                                        <span style="color:#777;">Per-category rates only (v1.0.4 behaviour)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="bt-hint">
                        If a role you expect is missing from this table, it is not registered as a WordPress
                        user role and this plugin cannot see it. If it is listed but the key looks different
                        from what you expected, the key column is the value that matters.
                    </p>
                </div>

            </div>
            <div class="bt-rule-footer" style="justify-content:flex-start;">
                <span style="font-size:.78rem;color:#888;">Saved together with the shipping rules below.</span>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * RENDER A SINGLE RULE CARD
     * ------------------------------------------------------------------ */
    private function render_rule_card( $i, $rule, $categories, $ns = 'rules' ) {
        $type       = $rule['rule_type']     ?? 'flat';
        $slug       = $rule['category_slug'] ?? '';
        $label      = $rule['label']         ?? 'Shipping';
        $taxable    = $rule['taxable']       ?? '0';
        $flat_price       = $rule['flat_price']       ?? '';
        $flat_additional  = $rule['flat_additional']  ?? '';
        $tiers      = $rule['tiers']         ?? [];
        $cat_name   = $slug;
        foreach ( (array) $categories as $cat ) {
            if ( $cat->slug === $slug ) {
                $cat_name = $cat->name;
                break;
            }
        }
        $header_label = $slug ? esc_html( $cat_name ) : 'New Rule';
        $type_badge   = $type === 'flat' ? 'Flat Rate' : 'Tiered';

        /*
         * $ns is both the POST field prefix ('rules' | 'role_rules') and an id
         * namespace. Without the id namespace the two rule sets would emit
         * duplicate element ids such as taxable_0, and clicking a label in one
         * set would toggle the matching control in the other.
         */
        $ns    = ( 'role_rules' === $ns ) ? 'role_rules' : 'rules';
        $uid   = ( 'role_rules' === $ns ? 'r' : 'd' ) . '_' . $i;
        $base  = $ns . '[' . $i . ']';
        ?>
        <div class="bt-rule-card" data-index="<?php echo esc_attr( $i ); ?>">
            <div class="bt-rule-header" onclick="btToggleCard(this)">
                <h3>
                    <?php echo esc_html( $header_label ); ?>
                    <span class="bt-rule-type-badge"><?php echo esc_html( $type_badge ); ?></span>
                </h3>
                <span>▾</span>
            </div>
            <div class="bt-rule-body">

                /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                <label><?php esc_html_e( 'Product Category', 'product-category-shipping-v1-4-2' ); ?></label>
                <select name="<?php echo esc_attr( $base ); ?>[category_slug]"
                        onchange="btUpdateHeader(this)">
                    <option value="">— Select a category —</option>
                    <?php foreach ( (array) $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->slug ); ?>"
                            <?php selected( $cat->slug, $slug ); ?>>
                            <?php echo esc_html( $cat->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                <label><?php esc_html_e( 'Shipping Label (shown to customer)', 'product-category-shipping-v1-4-2' ); ?></label>
                <input type="text" name="<?php echo esc_attr( $base ); ?>[label]"
                       value="<?php echo esc_attr( $label ); ?>"
                       placeholder="e.g. Shipping">
                <p style="margin:2px 0 0;font-size:.78rem;color:#888;">
                    <?php if ( 'role_rules' === $ns ) : ?>
                        Selected roles see one combined "Shipping" line, so this label is only used if this is
                        the sole rule that applies to their cart.
                    <?php else : ?>
                        When price is $0, label is automatically replaced with "Free Shipping".
                    <?php endif; ?>
                </p>

                /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                <label style="margin-top:16px;"><?php esc_html_e( 'Rule Type', 'product-category-shipping-v1-4-2' ); ?></label>
                <div class="bt-type-selector">
                    <span>
                        <input type="radio" name="<?php echo esc_attr( $base ); ?>[rule_type]"
                               id="type_flat_<?php echo esc_attr( $uid ); ?>" value="flat"
                               <?php checked( $type, 'flat' ); ?>
                               onchange="btSwitchType(this,'flat')">
                        <label for="type_flat_<?php echo esc_attr( $uid ); ?>">💵 Flat Rate</label>
                    </span>
                    <span>
                        <input type="radio" name="<?php echo esc_attr( $base ); ?>[rule_type]"
                               id="type_tiered_<?php echo esc_attr( $uid ); ?>" value="tiered"
                               <?php checked( $type, 'tiered' ); ?>
                               onchange="btSwitchType(this,'tiered')">
                        <label for="type_tiered_<?php echo esc_attr( $uid ); ?>">📊 Tiered Quantity</label>
                    </span>
                </div>

                <!-- FLAT RATE SECTION -->
                <div class="bt-flat-section" style="<?php echo $type !== 'flat' ? 'display:none;' : ''; ?>">
                    <div class="bt-row">
                        <div>
                            /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                            <label><?php esc_html_e( 'Base Shipping Price ($)', 'product-category-shipping-v1-4-2' ); ?></label>
                            <input type="number" step="0.01" min="0"
                                   name="<?php echo esc_attr( $base ); ?>[flat_price]"
                                   value="<?php echo esc_attr( $flat_price ); ?>"
                                   placeholder="5.99">
                            <p style="margin:2px 0 0;font-size:.78rem;color:#888;">Charged for the first item.</p>
                        </div>
                        <div>
                            /* phpcs:ignore WordPress.WP.I18n.TextDomainMismatch */
                            <label><?php esc_html_e( 'Additional Item Price ($)', 'product-category-shipping-v1-4-2' ); ?></label>
                            <input type="number" step="0.01" min="0"
                                   name="<?php echo esc_attr( $base ); ?>[flat_additional]"
                                   value="<?php echo esc_attr( $flat_additional ); ?>"
                                   placeholder="0.00">
                            <p style="margin:2px 0 0;font-size:.78rem;color:#888;">Added per item beyond the first. Leave blank or $0.00 for no additional charge.</p>
                        </div>
                    </div>
                </div>

                <!-- TIERED SECTION -->
                <div class="bt-tiered-section" style="<?php echo $type !== 'tiered' ? 'display:none;' : ''; ?>">
                    <div class="bt-tiers-wrap">
                        <h4>Quantity Tiers</h4>
                        <table class="bt-tier-table">
                            <thead>
                                <tr>
                                    <th>Min Qty</th>
                                    <th>Max Qty</th>
                                    <th>Price ($)</th>
                                    <th>Free?</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody class="bt-tier-tbody">
                                <?php
                                if ( ! empty( $tiers ) ) {
                                    foreach ( $tiers as $ti => $tier ) {
                                        $this->render_tier_row( $base, $ti, $tier );
                                    }
                                } else {
                                    $this->render_tier_row( $base, 0, [] );
                                }
                                ?>
                            </tbody>
                        </table>
                        <button type="button" class="bt-add-tier"
                                onclick="btAddTier(this, '<?php echo esc_js( $base ); ?>')">
                            + Add Tier
                        </button>
                    </div>
                </div>

                <!-- TAX TOGGLE -->
                <div class="bt-toggle-row" style="margin-top:18px;">
                    <input type="checkbox" class="bt-toggle"
                           name="<?php echo esc_attr( $base ); ?>[taxable]"
                           id="taxable_<?php echo esc_attr( $uid ); ?>"
                           value="1"
                           <?php checked( $taxable, '1' ); ?>>
                    <label for="taxable_<?php echo esc_attr( $uid ); ?>">
                        Apply tax to shipping for this category
                    </label>
                </div>

            </div><!-- .bt-rule-body -->
            <div class="bt-rule-footer">
                <button type="button" class="bt-remove-rule"
                        onclick="btRemoveRule(this)">Remove Rule</button>
            </div>
        </div><!-- .bt-rule-card -->
        <?php
    }

    /* ------------------------------------------------------------------
     * RENDER A SINGLE TIER ROW
     * ------------------------------------------------------------------ */
    private function render_tier_row( $base, $tier_idx, $tier ) {
        $min   = $tier['qty_min'] ?? '';
        $max   = $tier['qty_max'] ?? '';
        $price = $tier['price']   ?? '';
        $free  = ! empty( $tier['free'] ) && $tier['free'] === '1';
        // $base is already the full field prefix, e.g. "role_rules[2]".
        $prefix = "{$base}[tiers][{$tier_idx}]";
        ?>
        <tr>
            <td><input type="number" min="1" name="<?php echo esc_attr( $prefix ); ?>[qty_min]"
                       value="<?php echo esc_attr( $min ); ?>" placeholder="1"></td>
            <td><input type="number" min="1" name="<?php echo esc_attr( $prefix ); ?>[qty_max]"
                       value="<?php echo esc_attr( $max ); ?>" placeholder="6"></td>
            <td>
                <input type="number" step="0.01" min="0"
                       name="<?php echo esc_attr( $prefix ); ?>[price]"
                       value="<?php echo esc_attr( $price ); ?>"
                       placeholder="10.20"
                       class="bt-price-input"
                       <?php echo $free ? 'disabled style="opacity:.4"' : ''; ?>>
                <?php if ( $free ) : ?>
                    <span class="bt-free-price">Free</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[free]"
                       value="1" class="bt-free-check"
                       onchange="btToggleFree(this)"
                       <?php checked( $free ); ?>>
            </td>
            <td><button type="button" class="bt-remove-tier" onclick="btRemoveTier(this)">✕</button></td>
        </tr>
        <?php
    }

    /* ------------------------------------------------------------------
     * SANITISE ONE SET OF CATEGORY RULES
     *
     * Shared by the default rules and the selected-role override rules —
     * they have an identical shape, so they must not drift apart.
     * ------------------------------------------------------------------ */
    private function sanitize_rule_set( $raw ) {
        $clean = [];

        foreach ( (array) $raw as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            $type  = sanitize_text_field( $rule['rule_type'] ?? 'flat' );
            $entry = [
                'category_slug' => sanitize_title( $rule['category_slug'] ?? '' ),
                'rule_type'     => in_array( $type, [ 'flat', 'tiered' ], true ) ? $type : 'flat',
                'label'         => sanitize_text_field( $rule['label'] ?? 'Shipping' ),
                'taxable'       => ! empty( $rule['taxable'] ) ? '1' : '0',
            ];

            if ( $entry['rule_type'] === 'flat' ) {
                $entry['flat_price']      = number_format( (float) ( $rule['flat_price']      ?? 0 ), 2, '.', '' );
                $entry['flat_additional'] = number_format( (float) ( $rule['flat_additional'] ?? 0 ), 2, '.', '' );
            } else {
                $tiers = [];
                foreach ( ( $rule['tiers'] ?? [] ) as $tier ) {
                    $tiers[] = [
                        'qty_min' => absint( $tier['qty_min'] ?? 1 ),
                        'qty_max' => absint( $tier['qty_max'] ?? 1 ),
                        'price'   => number_format( (float) ( $tier['price'] ?? 0 ), 2, '.', '' ),
                        'free'    => ! empty( $tier['free'] ) ? '1' : '0',
                    ];
                }
                $entry['tiers'] = $tiers;
            }

            if ( $entry['category_slug'] ) {
                $clean[] = $entry;
            }
        }

        return $clean;
    }

    /* ------------------------------------------------------------------
     * SAVE HANDLER
     * ------------------------------------------------------------------ */
    public function save_rules() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $bt_nonce = isset( $_POST['bt_nonce'] ) ? wp_unslash( $_POST['bt_nonce'] ) : '';
        if (
            ! wp_verify_nonce( $bt_nonce, 'bt_shipping_save' ) ||
            ! current_user_can( 'manage_woocommerce' )
        ) {
            wp_die( 'Unauthorized', 403 );
        }

        /* ---- GLOBAL LOCAL PICKUP SETTINGS ---- */
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $bt_post_settings = isset( $_POST['bt_settings'] ) ? $_POST['bt_settings'] : [];
        $raw_settings     = wp_unslash( (array) $bt_post_settings );

        /*
         * Resolve each submitted key against the roles actually registered
         * right now. This preserves the real capitalisation ("Distributor")
         * and quietly heals installs whose stored keys were lowercased by
         * sanitize_key() in versions up to 1.2.0.
         */
        $posted_roles = BT_Shipping_Roles::sanitize( $raw_settings['pickup_roles'] ?? [] );
        $pickup_roles = [];
        foreach ( $posted_roles as $role_key ) {
            $resolved = BT_Shipping_Roles::resolve( $role_key );
            if ( ! in_array( $resolved, $pickup_roles, true ) ) {
                $pickup_roles[] = $resolved;
            }
        }

        update_option( 'bt_shipping_settings', [
            // Empty = feature off. Never treated as "all roles".
            'pickup_roles'      => $pickup_roles,
            'pickup_label'      => sanitize_text_field( $raw_settings['pickup_label']   ?? 'Local Pickup' ) ?: 'Local Pickup',
            'pickup_note'       => sanitize_text_field( $raw_settings['pickup_note']    ?? '' ),
            'combined_label'    => sanitize_text_field( $raw_settings['combined_label'] ?? 'Shipping' ) ?: 'Shipping',
            'force_pickup_rate' => ! empty( $raw_settings['force_pickup_rate'] ) ? '1' : '0',
        ] );

        /* ---- CATEGORY RULES (default + selected-role override) ---- */
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $bt_rules_input = isset( $_POST['rules'] ) ? (array) $_POST['rules'] : [];
        update_option( 'bt_shipping_rules', $this->sanitize_rule_set( $bt_rules_input ) );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $bt_role_rules_input = isset( $_POST['role_rules'] ) ? (array) $_POST['role_rules'] : [];
        update_option( 'bt_shipping_role_rules', $this->sanitize_rule_set( $bt_role_rules_input ) );

        // Clear WooCommerce shipping cache
        WC_Cache_Helper::get_transient_version( 'shipping', true );

        wp_safe_redirect( add_query_arg( [
            'page'     => 'bt-shipping-rules',
            'bt_saved' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }
}

