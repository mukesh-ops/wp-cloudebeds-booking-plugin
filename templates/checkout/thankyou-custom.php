<?php
/**
 * Custom Thankyou page for Cloudbeds Bookings
 *
 * This template overrides the default WooCommerce thankyou page
 * to show Cloudbeds-specific information instead of order details.
 *
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woocommerce-order cloudbeds-thankyou">

	<?php
	if ( $order ) :

		do_action( 'woocommerce_before_thankyou', $order->get_id() );
		?>

		<?php if ( $order->has_status( 'failed' ) ) : ?>

			<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed"><?php esc_html_e( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce' ); ?></p>

			<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions">
				<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a>
				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="button pay"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a>
				<?php endif; ?>
			</p>

		<?php else : ?>

			<?php //wc_get_template( 'checkout/order-received.php', array( 'order' => $order ) ); ?>
				<?php
				// Get Cloudbeds reservation details
				$reservation_id = $order->get_meta('_cloudbeds_reservation_id');
				$reservation_status = $order->get_meta('_cloudbeds_reservation_status');
				$booking_data = $order->get_meta('_cloudbeds_booking_data');
				$reservation_response_raw = $order->get_meta('_cloudbeds_reservation_response');
				$reservation_response = [];
				if ( ! empty( $reservation_response_raw ) ) {
					$decoded = json_decode( $reservation_response_raw, true );
					if ( is_array( $decoded ) ) {
						$reservation_response = $decoded;
					}
				}
				?>
			<?php
                    $human_status = '';
                    $title = '';
                    $description = '';
                    
                    if ( $reservation_status ) {
                        $map = array(
                            'confirmed'      => array(
                                'title'       => __( 'Booking Confirmed!', 'cloudbeds' ),
                                'description' => __( 'Your reservation has been confirmed successfully. We look forward to hosting you.', 'cloudbeds' )
                            ),
                            'not_confirmed'  => array(
                                'title'       => __( 'Pending Confirmation', 'cloudbeds' ),
                                'description' => __( 'Your reservation has been received and is pending confirmation. You will be notified once it is confirmed.', 'cloudbeds' )
                            ),
                            'canceled'       => array(
                                'title'       => __( 'Reservation Canceled', 'cloudbeds' ),
                                'description' => __( 'This reservation has been canceled. Please contact us if you need further assistance.', 'cloudbeds' )
                            ),
                            'checked_in'     => array(
                                'title'       => __( 'Guest Checked In', 'cloudbeds' ),
                                'description' => __( 'The guest has successfully checked in. We hope you enjoy your stay.', 'cloudbeds' )
                            ),
                            'checked_out'    => array(
                                'title'       => __( 'Guest Checked Out', 'cloudbeds' ),
                                'description' => __( 'The guest has already checked out. Thank you for staying with us.', 'cloudbeds' )
                            ),
                            'no_show'        => array(
                                'title'       => __( 'Guest Did Not Arrive', 'cloudbeds' ),
                                'description' => __( 'The guest did not show up on the check-in date. Please contact us if you need support.', 'cloudbeds' )
                            ),
                        );
                    
                        if ( isset( $map[ $reservation_status ] ) ) {
                            $title = $map[ $reservation_status ]['title'];
                            $description = $map[ $reservation_status ]['description'];
                        }
                    }
                    
                    if ( $reservation_id ) :
                    ?>
    <div class="cloudbeds-confirmation-notice">
        <div class="success-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="notice-content">
            <h4><?php echo esc_html( $title ? $title : __( 'Booking Confirmed!', 'cloudbeds' ) ); ?></h4>
            <p><?php echo esc_html( $description ? $description : __( 'Your reservation has been confirmed successfully. We look forward to hosting you.', 'cloudbeds' ) ); ?></p>
        </div>
    </div>
				<?php else : ?>
					<div class="cloudbeds-processing-notice">
					    <div class="process-icon">
                            <i class="fa-solid fa-arrows-spin"></i>
                        </div>
						<div class="notice-content">
							<h4><?php esc_html_e( 'Processing Your Booking...', 'cloudbeds' ); ?></h4>
							<p><?php esc_html_e( 'Your booking is being processed. You will receive a confirmation email once your reservation is confirmed.', 'cloudbeds' ); ?></p>
						</div>
					</div>
				<?php endif; ?>

			<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details cloudbeds-booking-details">

			

				<li class="woocommerce-order-overview__reservation reservation">
					<?php esc_html_e( 'Reservation ID:', 'cloudbeds' ); ?>
					<strong>
						<?php if ( $reservation_id ) : ?>
							<?php echo esc_html( $reservation_id ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Processing...', 'cloudbeds' ); ?>
						<?php endif; ?>
					</strong>
				</li>

				<li class="woocommerce-order-overview__booked-date booked-date">
					<?php esc_html_e( 'Booked Date:', 'cloudbeds' ); ?>
					<strong><?php echo wc_format_datetime( $order->get_date_created() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
				</li>

				<?php if ( $booking_data && !empty($booking_data['checkin']) ) : ?>
					<li class="woocommerce-order-overview__checkin checkin">
						<?php esc_html_e( 'Check-in:', 'cloudbeds' ); ?>
						<strong><?php echo esc_html( date('F j, Y', strtotime($booking_data['checkin'])) ); ?></strong>
					</li>
				<?php endif; ?>

				<?php if ( $booking_data && !empty($booking_data['checkout']) ) : ?>
					<li class="woocommerce-order-overview__checkout checkout">
						<?php esc_html_e( 'Check-out:', 'cloudbeds' ); ?>
						<strong><?php echo esc_html( date('F j, Y', strtotime($booking_data['checkout'])) ); ?></strong>
					</li>
				<?php endif; ?>

				<li class="woocommerce-order-overview__total total">
					<?php esc_html_e( 'Total Price:', 'cloudbeds' ); ?>
					<strong><?php echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></strong>
				</li>

			</ul>

			<?php
			// Show additional booking details if available
			if ( $booking_data ) :
				$room_types = array_filter(array_map('trim', explode(';', $booking_data['room_type'] ?? '')));
				$adults = intval($booking_data['adults'] ?? 2);
				$children = intval($booking_data['children'] ?? 0);
				$nights = 1;
				if ( !empty($booking_data['checkin']) && !empty($booking_data['checkout']) ) {
					$checkin = new DateTime($booking_data['checkin']);
					$checkout = new DateTime($booking_data['checkout']);
					$nights = $checkin->diff($checkout)->days;
				}
				?>
				<div class="cloudbeds-booking-summary">
					<h3><?php esc_html_e( 'Booking Summary', 'cloudbeds' ); ?></h3>

					<?php if ( ! empty( $reservation_response ) && ! empty( $reservation_response['unassigned'] ) && is_array( $reservation_response['unassigned'] ) ) : ?>
						<?php
							$rooms_list = $reservation_response['unassigned'];
						?>
                        <div class="booking-rooms">
                            <?php
                            // Build a map of room name => [price, plan] from order items
                            $room_price_plan_map = array();
                            foreach ( $order->get_items() as $item ) {
                                $booking_item = $item->get_meta( '_cloudbeds_booking_item', true );
                                if ( is_array( $booking_item ) ) {
                                    $rname = trim( (string) ( $booking_item['room_type_name'] ?? '' ) );
                                    if ( $rname !== '' ) {
                                        $price_val = isset( $booking_item['price'] ) ? floatval( $booking_item['price'] ) : null;
                                        $plan_label = '';
                                        if ( ! empty( $booking_item['rate_plan_label'] ) ) {
                                            $plan_label = (string) $booking_item['rate_plan_label'];
                                        } elseif ( ! empty( $booking_item['rate_plan'] ) ) {
                                            $plan_label = ( $booking_item['rate_plan'] === 'promo' ) ? __( 'Discounted Rate', 'cloudbeds' ) : __( 'Standard Rate', 'cloudbeds' );
                                        }
                                        $room_price_plan_map[ $rname ] = array( 'price' => $price_val, 'plan' => $plan_label );
                                    }
                                }
                            }
                            $order_currency = $order->get_currency();
                            ?>
							<div class="rooms-grid" role="table" aria-label="Booked rooms summary">
								<div class="rooms-grid__header" role="rowgroup">
									<div class="rooms-grid__cell rooms-grid__head" role="columnheader"><?php esc_html_e( 'Room Name', 'cloudbeds' ); ?></div>
									<div class="rooms-grid__cell rooms-grid__head" role="columnheader"><?php esc_html_e( 'Guests', 'cloudbeds' ); ?></div>
                                    <div class="rooms-grid__cell rooms-grid__head" role="columnheader"><?php esc_html_e( 'Price (Rate Plan)', 'cloudbeds' ); ?></div>
								</div>
								<div class="rooms-grid__body" role="rowgroup">
									<?php foreach ( $rooms_list as $room ) : ?>
										<?php
											$room_name = $room['roomTypeName'] ?? __( 'Room', 'cloudbeds' );
											$adults = isset($room['adults']) ? intval($room['adults']) : 0;
											$children = isset($room['children']) ? intval($room['children']) : 0;
											$guest_text_parts = [];
											if ($adults > 0) { $guest_text_parts[] = sprintf(_n('%d Adult', '%d Adults', $adults, 'cloudbeds'), $adults); }
											if ($children > 0) { $guest_text_parts[] = sprintf(_n('%d Child', '%d Children', $children, 'cloudbeds'), $children); }
											$guest_text = !empty($guest_text_parts) ? implode(', ', $guest_text_parts) : __('—', 'cloudbeds');
											$room_type_id = isset($room['roomTypeID']) ? (string) $room['roomTypeID'] : '';
                                            $rate_plan = '';
											if ( isset($room['ratePlanName']) ) { $rate_plan = (string) $room['ratePlanName']; }
											elseif ( isset($room['ratePlanLabel']) ) { $rate_plan = (string) $room['ratePlanLabel']; }
											elseif ( isset($room['ratePlan']) ) { $rate_plan = (string) $room['ratePlan']; }
											if ( $rate_plan === '' ) { $rate_plan = __('—', 'cloudbeds'); }

                                            // Resolve price and final display from order items map by full room name
                                            $price_html = '';
                                            if ( isset( $room_price_plan_map[ $room_name ] ) && $room_price_plan_map[ $room_name ]['price'] !== null ) {
                                                $price_html = wc_price( $room_price_plan_map[ $room_name ]['price'], array( 'currency' => $order_currency ) );
                                            }
                                            // Prefer plan from order item if available
                                            if ( isset( $room_price_plan_map[ $room_name ] ) && ! empty( $room_price_plan_map[ $room_name ]['plan'] ) ) {
                                                $rate_plan = $room_price_plan_map[ $room_name ]['plan'];
                                            }
                                            $price_plan_display = __('—', 'cloudbeds');
                                            if ( $price_html && $rate_plan && $rate_plan !== '—' ) {
                                                $price_plan_display = $price_html . ' (' . esc_html( $rate_plan ) . ')';
                                            } elseif ( $price_html ) {
                                                $price_plan_display = $price_html;
                                            } elseif ( $rate_plan && $rate_plan !== '—' ) {
                                                $price_plan_display = esc_html( $rate_plan );
                                            }
										?>
										<div class="rooms-grid__row" role="row">
											<div class="rooms-grid__cell" role="cell">
												<div>
													<div><?php echo esc_html($room_name); ?></div>
												
												</div>
											</div>
											<div class="rooms-grid__cell" role="cell"><?php echo esc_html($guest_text); ?></div>
                                            <div class="rooms-grid__cell" role="cell"><?php echo wp_kses_post( $price_plan_display ); ?></div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
					<?php endif; ?>
				</div>
				
			
			<?php endif; ?>

		<?php endif; ?>

		<?php /* Default WooCommerce sections (order details, billing address) intentionally suppressed */ ?>

	<?php else : ?>

		<?php wc_get_template( 'checkout/order-received.php', array( 'order' => false ) ); ?>

	<?php endif; ?>

</div>
 <!-- Additional Information -->
        <div class="info-card">
            <div class="info-header">
                <i class="fas fa-info-circle"></i>
                <h3>Important Information</h3>
            </div>
            <ul class="info-list">
                <li>Please arrive at the hotel before 14:00 on your check-in date</li>
                <li>A valid photo ID and credit card will be required at check-in</li>
                <li>For any changes or inquiries, please contact us at <a href="mailto:stay@robroyberwick.com">stay@robroyberwick.com</a> or <a href="tel:01289 349449">01289 349449</a></li>
            </ul>
        </div>
<style>

.info-card {
    padding: 30px;
    background:#fff;
    border-radius:8px;
    border: 1px solid #e9ecef;
}

.info-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    color: #1e293b;
}

.info-header i {
    color: #f59e0b;
    font-size: 1.8rem;
}

.info-header h3 {
    font-size: 30px;
    font-weight: 600;
    margin:0;
}

.info-list {
    list-style: none;
    display: flex;
    list-style=none;
    padding:0;
    flex-direction: column;
    gap: 1rem;
}

.info-list li {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem;
    background: #F6F5ED;
    border-radius: 12px;
    border-left: 4px solid #CCCCCC;
}

/* Custom styles for Cloudbeds booking details */
.cloudbeds-thankyou .cloudbeds-booking-details {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
}

.cloudbeds-thankyou .cloudbeds-booking-details li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
}

.cloudbeds-thankyou .cloudbeds-booking-details li:last-child {
    border-bottom: none;
}

.cloudbeds-thankyou .cloudbeds-booking-details strong {
    color: #2c3e50;
    font-weight: 600;
}

.cloudbeds-booking-summary {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 30px;
    margin: 20px 0;
}

.cloudbeds-booking-summary h3 {
    margin: 0;
    color: #333334;
    font-size:30px;
    font-weight:bold;
}

.booking-details p {
    margin: 8px 0;
    color: #555;
}

/* Confirmation notices */
.cloudbeds-confirmation-notice {
    background: #0F1925;
    border: 1px solid #0F1925;
    border-radius: 8px;
    padding: 30px;
    margin: 20px 0;
    display: flex;
    align-items:center;
    gap: 20px;
}
.success-icon {
    font-size: 40px;
    color: #D0AB17;
}
.cloudbeds-processing-notice {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
}

.notice-content h4 {
    margin: 0;
    color: #D0AB17;
    font-size: 1.4em;
}

.cloudbeds-processing-notice .notice-content h4 {
    color: #A48713;
}

.notice-content p {
    margin: 0;
    color: #CCCCCC;
}

.cloudbeds-processing-notice .notice-content p {
    color: #CCCCCC;
}

/* Enhanced booking summary */
.booking-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
}

.booking-details p {
    margin: 0;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
}

.booking-details p:last-child {
    border-bottom: none;
}

/* Responsive design */
@media (max-width: 768px) {
    .cloudbeds-thankyou .cloudbeds-booking-details li {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
    
    .booking-details {
        grid-template-columns: 1fr;
    }
}
</style>
<style>
/* Rooms grid (accessible, responsive, no prices) */
.booking-rooms { margin-top: 20px; }
.rooms-grid { display: grid; gap: 0; border: 1px solid #e9ecef; border-radius: 8px; overflow: hidden; background: #fff; }
.rooms-grid__header { display: grid; grid-template-columns: 1.5fr 1fr 1fr; background: #0F1925; border-bottom: 1px solid #e9ecef; }
.rooms-grid__body { display: grid; grid-auto-rows: minmax(48px, auto); }
.rooms-grid__row { display: grid; grid-template-columns: 1.5fr 1fr 1fr; border-bottom: 1px solid #f0f0f0; }
.rooms-grid__row:last-child { border-bottom: 0; }
.rooms-grid__cell { padding: 12px 16px; display: flex; align-items: center; color: #2c3e50; }
.rooms-grid__head { font-weight: 600; color: #fff; padding: 12px 16px; }
.rooms-grid__sublabel { font-size: 12px; color: #6c7a89; margin-top: 2px; }
@media (max-width: 768px) {
	.rooms-grid__header { display: none; }
	.rooms-grid__row { grid-template-columns: 1fr; }
	.rooms-grid__cell { position: relative; padding: 10px 14px; }
	.rooms-grid__cell:nth-child(1)::before { content: 'Room Name'; font-weight: 600; display: block; color: #6c7a89; margin-bottom: 4px; }
	.rooms-grid__cell:nth-child(2)::before { content: 'Guests'; font-weight: 600; display: block; color: #6c7a89; margin-bottom: 4px; }
	.rooms-grid__cell:nth-child(3)::before { content: 'Price (Rate Plan)'; font-weight: 600; display: block; color: #6c7a89; margin-bottom: 4px; }
}
</style>

