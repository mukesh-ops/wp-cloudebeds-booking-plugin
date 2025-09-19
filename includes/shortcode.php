<?php
if (!defined('ABSPATH')) exit;
add_shortcode('cloudbeds_rooms', function () {
    ob_start(); ?>
    <div class="wp-cloudbeds-search-box">
        <form id="wp-cloudbeds-room-form">
            <div class="wp-cloudbeds-field">
                <label>CHECK-IN DATE</label>
                <div class="wp-cloudbeds-date-wrapper">
                    <img src="<?php echo plugin_dir_url(__FILE__); ?>../assets/img/cal-icon.svg" alt="Calendar" class="wp-cloudbeds-date-icon">
                    <input type="text" name="startDate" class="wp-cloudbeds-date" placeholder="Select date" required>
                </div>
            </div>
            <div class="wp-cloudbeds-field">
                <label>CHECK-OUT DATE</label>
                <div class="wp-cloudbeds-date-wrapper">
                    <img src="<?php echo plugin_dir_url(__FILE__); ?>../assets/img/cal-icon.svg" alt="Calendar" class="wp-cloudbeds-date-icon">
                    <input type="text" name="endDate" class="wp-cloudbeds-date" placeholder="Select date" required>
                </div>
            </div>
            <?php if ((int) get_option('cloudbeds_enable_promo_field', 1) === 1): ?>
            <div class="wp-cloudbeds-field">
                <label>PROMO CODE</label>
                <div class="wp-cloudbeds-date-wrapper">
                    <input type="text" name="promo" class="wp-cloudbeds-date" placeholder="Enter promo code">
                </div>
            </div>
            <?php endif; ?>
            <div class="wp-cloudbeds-field">
                <button type="submit" class="wp-cloudbeds-check-btn">Check Availability</button>
            </div>
        </form>
        <div id="wp-cloudbeds-room-results"></div>
    </div>
<?php return ob_get_clean();
});
