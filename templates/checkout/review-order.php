<?php
/**
 * Review order div layout with dual classes (Cloudbeds + WooCommerce default)
 *
 * @package WooCommerce\Templates
 * @version 5.2.0
 */

defined('ABSPATH') || exit;

// Pull first booking's dates
$first_checkin  = '';
$first_checkout = '';
foreach (WC()->cart->get_cart() as $ci_key => $ci_item) {
    if (!empty($ci_item['cloudbeds_booking'])) {
        $first_checkin  = $ci_item['cloudbeds_booking']['checkin']  ?? '';
        $first_checkout = $ci_item['cloudbeds_booking']['checkout'] ?? '';
        break;
    }
}
$ci_fmt = $first_checkin  ? date_i18n('M d, Y', strtotime($first_checkin)) : '';
$co_fmt = $first_checkout ? date_i18n('M d, Y', strtotime($first_checkout)) : '';
$nights = '';
if ($first_checkin && $first_checkout) {
    $diff   = (new DateTime($first_checkin))->diff(new DateTime($first_checkout));
    $nights = max(1, (int)$diff->days);
}
?>

<!-- Reservation Summary Section -->
<div class="summary-section woocommerce-checkout-review-order-table">

    <!-- Dates -->
    <div class="booking-dates">
        <div class="date-range">
            <div class="date-item">
                <span class="date-label"><?php esc_html_e('Check-in', 'woocommerce'); ?></span>
                <span class="date-value"><?php echo esc_html($ci_fmt); ?></span>
            </div>
            <div class="date-arrow">
                <i class="fas fa-arrow-right"></i>
            </div>
            <div class="date-item">
                <span class="date-label"><?php esc_html_e('Check-out', 'woocommerce'); ?></span>
                <span class="date-value"><?php echo esc_html($co_fmt); ?></span>
            </div>
        </div>
        <?php if ($nights !== ''): ?>
            <div class="night-info cb-pill-nights">
                <i class="fas fa-moon"></i>
                <span><?php echo esc_html(sprintf(_n('%d Night', '%d Nights', $nights, 'woocommerce'), $nights)); ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rooms -->
    <div class="room-details">
        <strong class="room-section-title"><?php esc_html_e('Rooms', 'woocommerce'); ?></strong>

        <?php
        do_action('woocommerce_review_order_before_cart_contents');

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);

            if ($_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters('woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key)) {

                $booking  = !empty($cart_item['cloudbeds_booking']) ? $cart_item['cloudbeds_booking'] : array();
                $adults   = isset($booking['adults']) ? intval($booking['adults']) : 0;
                $children = isset($booking['children']) ? intval($booking['children']) : 0;
                $guests   = max(0, $adults + $children);

                $remove_url = wc_get_cart_remove_url($cart_item_key);
                $item_name  = apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key);
                $item_subt  = apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key);
                $rate_plan_label = !empty($booking['rate_plan_label']) ? $booking['rate_plan_label'] : '';
                ?>
                
                <div class="room-item cart_item <?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cb-line', $cart_item, $cart_item_key)); ?>">
                    <div class="room-info product-name">
                        <div class="room-name"><?php echo wp_kses_post($item_name); ?></div>
                        <?php if ($rate_plan_label): ?>
                            <div class="room-detail"><?php echo esc_html($rate_plan_label); ?></div>
                        <?php endif; ?>
                        <div class="occupancy cb-guests">
                            <i class="fas fa-users"></i>
                            <span><?php echo esc_html($guests); ?> <?php esc_html_e('guests', 'woocommerce'); ?></span>
                        </div>
                    </div>
                    <div class="room-actions product-total">
                        <span class="room-price"><?php echo wp_kses_post($item_subt); ?></span>
                        <a href="#"
                           class="remove-room cloudbeds-remove-item-link cb-remove-btn remove"
                           aria-label="<?php esc_attr_e('Remove room', 'woocommerce'); ?>"
                           data-cart-key="<?php echo esc_attr($cart_item_key); ?>"
                           data-remove-url="<?php echo esc_url($remove_url); ?>">
                            <i class="fas fa-trash-alt"></i>
                        </a>
                    </div>
                </div>
                
                <?php
            }
        }

        do_action('woocommerce_review_order_after_cart_contents');
        ?>
    </div>

    <!-- Price Breakdown -->
    <div class="price-breakdown">
        <div class="price-line cart-subtotal">
            <span><?php esc_html_e('Subtotal', 'woocommerce'); ?></span>
            <span class="sub-total"><?php wc_cart_totals_subtotal_html(); ?></span>
        </div>

        <?php
        // Combined "Taxes and fees"
        $fees_total = 0.0;
        foreach (WC()->cart->get_fees() as $fee) {
            $fees_total += (float) $fee->amount;
        }
        $tax_total = wc_tax_enabled() ? (float) WC()->cart->get_total_tax() : 0.0;
        $taxes_and_fees = max(0, $fees_total + $tax_total);
        ?>
        <div class="price-line cb-taxes-fees">
            <span><?php esc_html_e('Taxes and fees', 'woocommerce'); ?></span>
            <span class="tax-and-fee"><?php echo wc_price($taxes_and_fees); ?></span>
        </div>

        <div class="price-line total order-total">
            <span class="final-total"><?php esc_html_e('Total', 'woocommerce'); ?></span>
            <span class="order-total-with-tax"><?php wc_cart_totals_order_total_html(); ?></span>
        </div>
    </div>
</div>