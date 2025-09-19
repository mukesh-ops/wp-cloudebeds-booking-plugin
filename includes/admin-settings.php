<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        'Cloudbeds Settings',
        'Cloudbeds',
        'manage_options',
        'cloudbeds-settings',
        'cloudbeds_settings_page',
        'dashicons-admin-generic'
    );
    add_submenu_page(
        'cloudbeds-settings',
        'Cloudbeds Shortcodes',
        'Shortcodes',
        'manage_options',
        'cloudbeds-shortcodes',
        'cloudbeds_shortcodes_admin_page'
    );
});

add_action('admin_init', function () {
    register_setting('cloudbeds_settings_group', 'cloudbeds_api_key');
    register_setting('cloudbeds_settings_group', 'cloudbeds_unique_url_code');
    register_setting('cloudbeds_settings_group', 'cloudbeds_property_id');
    register_setting('cloudbeds_settings_group', 'cloudbeds_debug_enabled');
    register_setting('cloudbeds_settings_group', 'cloudbeds_source_id');
    register_setting('cloudbeds_settings_group', 'cloudbeds_enable_promo_field', [
        'type' => 'boolean',
        'sanitize_callback' => function($v){ return $v ? 1 : 0; },
        'default' => 1,
    ]);

    // ✅ New option for Placeholder Product
    register_setting('cloudbeds_settings_group', 'woocommerce_placeholder_product_id', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ]);

    // ✅ New option for Room Type Mappings
    register_setting('cloudbeds_settings_group', 'cloudbeds_room_type_mappings', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'default'           => '',
    ]);
});

function cloudbeds_settings_page() { ?>
    <div class="wrap">
        <h1>Cloudbeds WP Integration</h1>
        <form method="post" action="options.php">
            <?php settings_fields('cloudbeds_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Cloudbeds API Key</th>
                    <td>
                        <input type="text" name="cloudbeds_api_key"
                               value="<?php echo esc_attr(get_option('cloudbeds_api_key')); ?>"
                               style="width:400px;">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Cloudbeds Unique URL Code</th>
                    <td>
                        <input type="text" name="cloudbeds_unique_url_code"
                               value="<?php echo esc_attr(get_option('cloudbeds_unique_url_code')); ?>"
                               style="width:400px;">
                        <p class="description">Example: <code>3OMCs0</code> (used in booking URL).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Cloudbeds Property ID</th>
                    <td>
                        <input type="text" name="cloudbeds_property_id"
                               value="<?php echo esc_attr(get_option('cloudbeds_property_id')); ?>"
                               style="width:400px;">
                        <p class="description">Example: <code>111100088602755</code> (used in API calls).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Cloudbeds Source ID</th>
                    <td>
                        <input type="text" name="cloudbeds_source_id"
                               value="<?php echo esc_attr(get_option('cloudbeds_source_id','s-2-1')); ?>"
                               style="width:400px;">
                        <p class="description">Source identifier used in reservations (e.g. <code>s-2-1</code>).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Enable Reservation Debug Log</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cloudbeds_debug_enabled" value="1"
                                <?php checked(1, get_option('cloudbeds_debug_enabled')); ?>>
                            Enable logging to <code>wp-content/uploads/cloudbeds-debug.log</code>
                        </label>
                    </td>
                </tr>
                <!-- ✅ New field -->
                <tr>
                    <th scope="row">Show Promo Code Field</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cloudbeds_enable_promo_field" value="1" <?php checked(1, (int) get_option('cloudbeds_enable_promo_field', 1)); ?>>
                            Enable promo/coupon input on search and room pages
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Woo Placeholder Product ID</th>
                    <td>
                        <input type="number" min="0" name="woocommerce_placeholder_product_id"
                               value="<?php echo esc_attr(get_option('woocommerce_placeholder_product_id', 0)); ?>"
                               style="width:200px;">
                        <p class="description">
                            Create a hidden WooCommerce product (price 0) and paste its ID here.  
                            Used for building payment-only orders. Leave empty to auto-generate one.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Room Type Mappings</th>
                    <td>
                        <textarea name="cloudbeds_room_type_mappings" rows="5" cols="50"
                                  style="width:400px; font-family: monospace;"><?php echo esc_textarea(get_option('cloudbeds_room_type_mappings', '')); ?></textarea>
                        <p class="description">
                            Map room type short names to CloudBeds room type IDs. Format: <code>SHORT_NAME=ROOM_TYPE_ID</code><br>
                            Example: <code>RM7=113787270561933</code><br>
                            One mapping per line.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
<?php } ?>