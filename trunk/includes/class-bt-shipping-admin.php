<?php
defined( 'ABSPATH' ) || exit;

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

    public function __construct() {
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
            __( 'Category Shipping Rules', 'product-category-shipping' ),
            __( 'Category Shipping', 'product-category-shipping' ),
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
        // Inline CSS + JS — no external files needed
        add_action( 'admin_head', [ $this, 'inline_styles' ] );
        add_action( 'admin_footer', [ $this, 'inline_scripts' ] );
    }

    public function inline_styles() { ?>
<style>
/* ===== Category Shipping Admin Styles ===== */
:root {
    --bt-red:    #dd291e;
    --bt-yellow: #fce122;
    --bt-green:  #235937;
    --bt-light:  #fafafa;
    --bt-border: #ddd;
    --bt-shadow: 0 2px 8px rgba(0,0,0,.08);
}
#bt-shipping-wrap { max-width: 900px; margin: 24px 0; font-family: -apple-system,sans-serif; }
#bt-shipping-wrap h1 { display:flex; align-items:center; gap:12px; font-size:1.5rem; color:#1d2327; margin-bottom:4px; }
#bt-shipping-wrap h1 span.bt-badge {
    background:var(--bt-red); color:#fff; font-size:.65rem;
    padding:2px 8px; border-radius:99px; font-weight:700; letter-spacing:.05em; vertical-align:middle;
}
.bt-desc { color:#666; margin-bottom:28px; font-size:.9rem; }

/* Cards */
.bt-rule-card {
    background:#fff; border:1px solid var(--bt-border); border-radius:8px;
    box-shadow:var(--bt-shadow); margin-bottom:20px; overflow:hidden;
}
.bt-rule-header {
    display:flex; align-items:center; justify-content:space-between;
    background:var(--bt-green); color:#fff; padding:12px 18px; cursor:pointer;
}
.bt-rule-header h3 { margin:0; font-size:.95rem; font-weight:600; }
.bt-rule-header .bt-rule-type-badge {
    font-size:.7rem; background:rgba(255,255,255,.2); padding:2px 10px;
    border-radius:99px; margin-left:10px; font-weight:600; letter-spacing:.04em;
}
.bt-rule-body { padding:20px 24px; }
.bt-rule-body label { display:block; font-weight:600; font-size:.82rem; color:#444; margin-bottom:4px; margin-top:14px; }
.bt-rule-body label:first-child { margin-top:0; }
.bt-rule-body input[type=text],
.bt-rule-body input[type=number],
.bt-rule-body select { width:100%; max-width:340px; padding:7px 10px; border:1px solid var(--bt-border); border-radius:5px; font-size:.9rem; }
.bt-rule-body .bt-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.bt-rule-body .bt-row > div label { margin-top:0; }

/* Tiers table */
.bt-tiers-wrap { margin-top:18px; }
.bt-tiers-wrap h4 { font-size:.85rem; color:#333; margin:0 0 10px; }
.bt-tier-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.bt-tier-table th { background:#f5f5f5; text-align:left; padding:7px 10px; border:1px solid #e0e0e0; font-weight:600; color:#555; }
.bt-tier-table td { padding:6px 8px; border:1px solid #e8e8e8; vertical-align:middle; }
.bt-tier-table input[type=number] { width:80px; max-width:80px; padding:4px 6px; font-size:.85rem; }
.bt-tier-table input[type=checkbox] { width:18px; height:18px; cursor:pointer; }
.bt-tier-table .bt-free-price { color:#aaa; font-style:italic; font-size:.8rem; }
.bt-add-tier { margin-top:8px; background:none; border:1px dashed var(--bt-green); color:var(--bt-green);
    padding:5px 14px; border-radius:5px; cursor:pointer; font-size:.82rem; font-weight:600; }
.bt-add-tier:hover { background:var(--bt-green); color:#fff; }
.bt-remove-tier { background:none; border:none; color:#c00; cursor:pointer; font-size:1.1rem; line-height:1; padding:0 4px; }
.bt-remove-tier:hover { color:#900; }

/* Rule actions */
.bt-rule-footer { display:flex; justify-content:flex-end; padding:10px 24px 14px; border-top:1px solid #f0f0f0; }
.bt-remove-rule { background:none; border:1px solid #c00; color:#c00; padding:5px 14px; border-radius:5px; cursor:pointer; font-size:.82rem; font-weight:600; }
.bt-remove-rule:hover { background:#c00; color:#fff; }

/* Add rule button */
#bt-add-rule {
    background:var(--bt-yellow); color:#1d2327; border:none; padding:10px 22px;
    border-radius:6px; font-weight:700; font-size:.9rem; cursor:pointer; margin-bottom:24px;
    box-shadow:0 2px 6px rgba(0,0,0,.1);
}
#bt-add-rule:hover { background:#e8ce00; }

/* Save button */
.bt-save-bar {
    background:#fff; border:1px solid var(--bt-border); border-radius:8px;
    padding:16px 24px; display:flex; align-items:center; gap:16px;
    box-shadow:var(--bt-shadow);
}
.bt-save-bar input[type=submit] {
    background:var(--bt-red); color:#fff; border:none; padding:10px 28px;
    border-radius:6px; font-size:.95rem; font-weight:700; cursor:pointer;
}
.bt-save-bar input[type=submit]:hover { background:#b5201a; }
.bt-save-bar .bt-saved-msg { color:var(--bt-green); font-weight:600; font-size:.9rem; display:none; }

/* Notices */
.bt-notice { border-left:4px solid var(--bt-red); background:#fff8f8; padding:10px 14px; margin-bottom:16px; border-radius:4px; font-size:.88rem; }
.bt-notice.success { border-color:var(--bt-green); background:#f0fff4; }

/* Toggle */
.bt-toggle-row { display:flex; align-items:center; gap:10px; margin-top:14px; }
.bt-toggle-row label { margin:0; font-size:.88rem; color:#555; font-weight:400; }
input[type=checkbox].bt-toggle { width:18px; height:18px; }

/* Rule type selector */
.bt-type-selector { display:flex; gap:10px; margin-top:6px; }
.bt-type-selector label {
    flex:1; border:2px solid var(--bt-border); border-radius:7px; padding:10px 14px;
    cursor:pointer; text-align:center; font-weight:600; font-size:.85rem; color:#555;
    transition: all .15s;
}
.bt-type-selector input[type=radio] { display:none; }
.bt-type-selector input[type=radio]:checked + label {
    border-color:var(--bt-green); background:#f0fff4; color:var(--bt-green);
}

/* ===== v1.2.0: local pickup roles ===== */
.bt-role-select {
    width:100% !important; max-width:340px; min-height:112px;
    padding:6px !important; border:1px solid var(--bt-border); border-radius:5px;
    font-size:.85rem; background:#fff;
}
.bt-role-select option { padding:3px 6px; border-radius:3px; }
.bt-role-select option:checked { background:var(--bt-green) linear-gradient(0deg,var(--bt-green),var(--bt-green)); color:#fff; }
.bt-hint { margin:5px 0 0; font-size:.78rem; color:#888; line-height:1.5; max-width:560px; }
.bt-hint strong { color:#555; }

#bt-pickup-card { border-left:4px solid var(--bt-yellow); }
#bt-pickup-card .bt-rule-header { background:#1d2327; }

.bt-callout {
    background:#f7fbff; border:1px solid #cfe3f5; border-radius:6px;
    padding:12px 16px; margin-top:16px; font-size:.82rem; color:#31536e; line-height:1.6; max-width:600px;
}
.bt-callout strong { color:#1d3d5c; }
.bt-callout.warn { background:#fffdf3; border-color:#f0dca0; color:#6b5400; }
.bt-callout.warn strong { color:#4d3d00; }
.bt-callout ul { margin:8px 0 0 18px; padding:0; }
.bt-callout li { margin:3px 0; }

.bt-status-pill {
    display:inline-block; font-size:.7rem; font-weight:700; letter-spacing:.04em;
    padding:3px 10px; border-radius:99px; margin-left:10px;
}
.bt-status-pill.on  { background:#d8f3e0; color:var(--bt-green); }
.bt-status-pill.off { background:#eee;    color:#777; }

/* ===== v1.3.0: selected-role override rule set ===== */
.bt-section-head {
    display:flex; align-items:baseline; gap:12px; flex-wrap:wrap;
    font-size:1.05rem; font-weight:700; color:#1d2327;
    margin:34px 0 14px; padding-bottom:8px; border-bottom:2px solid var(--bt-green);
}
.bt-section-head.role { border-bottom-color:var(--bt-red); margin-top:42px; }
.bt-section-sub { font-size:.78rem; font-weight:600; color:#777; letter-spacing:.02em; }

/* Override cards read red so they are never confused with the defaults */
#bt-role-rules-container .bt-rule-header { background:var(--bt-red); }
#bt-role-rules-container .bt-add-tier { border-color:var(--bt-red); color:var(--bt-red); }
#bt-role-rules-container .bt-add-tier:hover { background:var(--bt-red); color:#fff; }
#bt-role-rules-container .bt-type-selector input[type=radio]:checked + label {
    border-color:var(--bt-red); background:#fff5f4; color:var(--bt-red);
}
button.bt-add-role { background:#f3d2cf !important; }
button.bt-add-role:hover { background:#e9b8b4 !important; }
</style>
<?php }

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
        echo esc_html( ob_get_clean() );
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
                    : esc_html__( 'No roles selected yet — these rules are inactive', 'product-category-shipping' );
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

                <label style="margin-top:0;"><?php esc_html_e( 'Roles that get the Local Pickup option', 'product-category-shipping' ); ?></label>
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
                        <label style="margin-top:0;"><?php esc_html_e( 'Pickup label', 'product-category-shipping' ); ?></label>
                        <input type="text" name="bt_settings[pickup_label]"
                               value="<?php echo esc_attr( $pickup_label ); ?>"
                               placeholder="Local Pickup">
                    </div>
                    <div>
                        <label style="margin-top:0;"><?php esc_html_e( 'Combined shipping label', 'product-category-shipping' ); ?></label>
                        <input type="text" name="bt_settings[combined_label]"
                               value="<?php echo esc_attr( $combined_label ); ?>"
                               placeholder="Shipping">
                        <p class="bt-hint">Replaced with "Free Shipping" when the total is $0.</p>
                    </div>
                </div>

                <label><?php esc_html_e( 'Pickup note (optional)', 'product-category-shipping' ); ?></label>
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
                    <h4><?php esc_html_e( 'Diagnostics — what each role actually gets', 'product-category-shipping' ); ?></h4>
                    <table class="bt-tier-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Role', 'product-category-shipping' ); ?></th>
                                <th><?php esc_html_e( 'Role key (what is matched)', 'product-category-shipping' ); ?></th>
                                <th><?php esc_html_e( 'Users', 'product-category-shipping' ); ?></th>
                                <th><?php esc_html_e( 'Checkout behaviour', 'product-category-shipping' ); ?></th>
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

                <label><?php esc_html_e( 'Product Category', 'product-category-shipping' ); ?></label>
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

                <label><?php esc_html_e( 'Shipping Label (shown to customer)', 'product-category-shipping' ); ?></label>
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

                <label style="margin-top:16px;"><?php esc_html_e( 'Rule Type', 'product-category-shipping' ); ?></label>
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
                            <label><?php esc_html_e( 'Base Shipping Price ($)', 'product-category-shipping' ); ?></label>
                            <input type="number" step="0.01" min="0"
                                   name="<?php echo esc_attr( $base ); ?>[flat_price]"
                                   value="<?php echo esc_attr( $flat_price ); ?>"
                                   placeholder="5.99">
                            <p style="margin:2px 0 0;font-size:.78rem;color:#888;">Charged for the first item.</p>
                        </div>
                        <div>
                            <label><?php esc_html_e( 'Additional Item Price ($)', 'product-category-shipping' ); ?></label>
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

    /* ------------------------------------------------------------------
     * INLINE JAVASCRIPT
     * ------------------------------------------------------------------ */
    public function inline_scripts() {
        ?>
        <script>
        // ── Card toggle ──────────────────────────────────────────────
        function btToggleCard(header) {
            const body = header.nextElementSibling;
            const footer = body.nextElementSibling;
            const isHidden = body.style.display === 'none';
            body.style.display   = isHidden ? '' : 'none';
            footer.style.display = isHidden ? '' : 'none';
        }

        // ── Update card header when category changes ─────────────────
        function btUpdateHeader(select) {
            const card = select.closest('.bt-rule-card');
            const h3   = card.querySelector('.bt-rule-header h3');
            const badge = h3.querySelector('.bt-rule-type-badge');
            const badgeText = badge ? badge.outerHTML : '';
            const chosen = select.options[select.selectedIndex].text;
            h3.innerHTML = (chosen && select.value ? chosen : 'New Rule') + badgeText;
        }

        // ── Switch between flat / tiered ─────────────────────────────
        function btSwitchType(radio, type) {
            const card = radio.closest('.bt-rule-card');
            card.querySelector('.bt-flat-section').style.display   = type === 'flat'   ? '' : 'none';
            card.querySelector('.bt-tiered-section').style.display = type === 'tiered' ? '' : 'none';
            // Update badge
            const badge = card.querySelector('.bt-rule-type-badge');
            if (badge) badge.textContent = type === 'flat' ? 'Flat Rate' : 'Tiered';
        }

        // ── Add a new rule card ───────────────────────────────────────
        // Both rule sets (default + selected-role override) share this handler.
        // Each button carries its own container, template and running index.
        document.querySelectorAll('#bt-add-rule, #bt-add-role-rule').forEach(function (btn) {
            btn.addEventListener('click', function () {
            const template = document.getElementById(btn.dataset.template);
            let count = parseInt(btn.dataset.count || '0', 10);
            const html = template.innerHTML.replace(/__IDX__/g, count);
            btn.dataset.count = count + 1;
            const container = document.getElementById(btn.dataset.container);
            // Remove "no rules" message if present.
            // Must be a DIRECT child — rule cards contain their own <p> hints,
            // and querySelector('p') would happily delete one of those instead.
            const noRules = container.querySelector(':scope > p');
            if (noRules) noRules.remove();
            container.insertAdjacentHTML('beforeend', html);
            });
        });

        // ── Remove a rule card ────────────────────────────────────────
        function btRemoveRule(btn) {
            if (!confirm('Remove this shipping rule?')) return;
            btn.closest('.bt-rule-card').remove();
        }

        // ── Add a tier row ────────────────────────────────────────────
        // `base` is the full field prefix, e.g. "rules[0]" or "role_rules[2]",
        // so the same function serves both rule sets.
        function btAddTier(btn, base) {
            const tbody = btn.previousElementSibling.querySelector('.bt-tier-tbody');
            const tierIdx = tbody.querySelectorAll('tr').length;
            const prefix = `${base}[tiers][${tierIdx}]`;
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><input type="number" min="1" name="${prefix}[qty_min]" placeholder="1"></td>
                <td><input type="number" min="1" name="${prefix}[qty_max]" placeholder="6"></td>
                <td><input type="number" step="0.01" min="0" name="${prefix}[price]" placeholder="10.20" class="bt-price-input"></td>
                <td style="text-align:center;">
                    <input type="checkbox" name="${prefix}[free]" value="1"
                           class="bt-free-check" onchange="btToggleFree(this)">
                </td>
                <td><button type="button" class="bt-remove-tier" onclick="btRemoveTier(this)">✕</button></td>
            `;
            tbody.appendChild(row);
        }

        // ── Remove a tier row ─────────────────────────────────────────
        function btRemoveTier(btn) {
            btn.closest('tr').remove();
        }

        // ── Toggle price field when "Free" is checked ─────────────────
        function btToggleFree(checkbox) {
            const row   = checkbox.closest('tr');
            const price = row.querySelector('.bt-price-input');
            if (!price) return;
            price.disabled = checkbox.checked;
            price.style.opacity = checkbox.checked ? '0.4' : '1';
        }
        </script>
        <?php
    }
}
