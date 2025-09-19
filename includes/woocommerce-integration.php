<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce payment-only integration for Cloudbeds
 * - Creates programmatic orders with a hidden placeholder product
 * - Stores booking data in order meta
 * - Auto-completes COD orders
 * - On payment complete, creates Cloudbeds reservation
 * - Shows Reservation ID on Thank You page and Orders list
 * - Logs to wp-content/uploads/cloudbeds-woo.log with [Cloudbeds-Woo] prefix
 */
if ( ! class_exists( 'Cloudbeds_Woo_Integration' ) ) {

    class Cloudbeds_Woo_Integration {

        public function __construct() {
            // Public helper (call from your booking flow)
            add_action('init', [$this, 'maybe_handle_redirect_request']);

            // Payment success / completion
            add_action('woocommerce_payment_complete', [$this, 'handle_payment_complete'], 10, 1);

            // Order status changes (for COD and other payment methods)
            add_action('woocommerce_order_status_completed', [$this, 'handle_order_completed'], 10, 1);

            // COD auto-complete so flow finishes immediately
            add_action('woocommerce_thankyou_cod', [$this, 'maybe_autocomplete_cod']);

            // Thank you page output handled by custom template override; suppress extra output
            // add_action('woocommerce_thankyou', [$this, 'thankyou_reservation_id'], 20);

            // Admin column
            add_filter('manage_edit-shop_order_columns', [$this, 'add_admin_column'], 20);
            add_action('manage_shop_order_posts_custom_column', [$this, 'render_admin_column'], 20, 2);

            // Hide placeholder product
            add_action('pre_get_posts', [$this, 'hide_placeholder_in_queries']);
            add_action('template_redirect', [$this, 'block_placeholder_single']);
            add_filter('woocommerce_add_to_cart_validation', [$this, 'prevent_manual_cart_add'], 10, 2);

            // Ensure guests can reach and use checkout (bypass login/cart redirect)
            add_filter('woocommerce_checkout_registration_required', '__return_false');
            add_filter('woocommerce_checkout_login_required', '__return_false');
            // Force-enable guest checkout regardless of Woo settings
            add_filter('pre_option_woocommerce_enable_guest_checkout', function($value){ return 'yes'; });

            // Safety: prevent WooCommerce from bouncing guests away from checkout when our session is set
            add_filter('woocommerce_checkout_redirect_empty_cart', function($redirect_url){
                if (function_exists('WC') && WC()->session && WC()->session->get('cloudbeds_booking')) {
                    // Stay on checkout; returning checkout URL avoids redirect to cart
                    return wc_get_checkout_url();
                }
                return $redirect_url;
            }, 10, 1);

            // Validate Cloudbeds availability right before payment
            add_action('woocommerce_after_checkout_validation', [$this, 'validate_availability_before_payment'], 10, 2);
        }

        /**
         * Before processing payment, confirm rooms are still available for the selected dates.
         * Blocks checkout with an error if any selected room is unavailable.
         */
        public function validate_availability_before_payment( $data, $errors ) {
            if ( ! function_exists('WC') || ! WC()->cart ) { return; }
            $cart = WC()->cart->get_cart();
            if ( empty($cart) ) { return; }

            // Collect unique date ranges and requested room short codes per range
            $by_range = [];
            foreach ( $cart as $cart_item ) {
                if ( empty($cart_item['cloudbeds_booking']) ) { continue; }
                $b = $cart_item['cloudbeds_booking'];
                $checkin  = isset($b['checkin']) ? trim($b['checkin']) : '';
                $checkout = isset($b['checkout']) ? trim($b['checkout']) : '';
                $code     = isset($b['room_type']) ? trim((string)$b['room_type']) : '';
                if ( $checkin === '' || $checkout === '' || $code === '' ) { continue; }
                $key = $checkin . '|' . $checkout;
                if ( ! isset($by_range[$key]) ) { $by_range[$key] = [ 'checkin' => $checkin, 'checkout' => $checkout, 'codes' => [] ]; }
                $by_range[$key]['codes'][] = $code;
            }
            if ( empty($by_range) ) { return; }

            // Verify each range against Cloudbeds API
            foreach ( $by_range as $group ) {
                $checkin  = $group['checkin'];
                $checkout = $group['checkout'];
                $requested_codes = array_unique(array_map('strval', $group['codes']));

                // Use shared helper from ajax-handler.php
                if ( ! function_exists('cloudbeds_http_get') ) { continue; }
                $property_id = get_option('cloudbeds_property_id');
                if ( ! $property_id ) {
                    $errors->add('cloudbeds_avail_error', __('Availability check failed: property not configured.', 'cloudbeds'));
                    return;
                }

                $resp = cloudbeds_http_get('/getAvailableRoomTypes', [
                    'propertyIDs' => $property_id,
                    'startDate'   => $checkin,
                    'endDate'     => $checkout
                ]);

                if ( is_wp_error($resp) ) {
                    $errors->add('cloudbeds_avail_error', __('Could not verify availability. Please try again.', 'cloudbeds'));
                    return;
                }

                // Build available short-name set
                $available_short = [];
                if ( ! empty($resp['data']) ) {
                    foreach ( $resp['data'] as $prop ) {
                        if ( empty($prop['propertyRooms']) ) { continue; }
                        foreach ( $prop['propertyRooms'] as $rt ) {
                            $roomsAvailable = isset($rt['roomsAvailable']) ? intval($rt['roomsAvailable']) : 0;
                            if ( $roomsAvailable <= 0 ) { continue; }
                            $short = isset($rt['roomTypeNameShort']) ? (string)$rt['roomTypeNameShort'] : '';
                            if ( $short !== '' ) { $available_short[$short] = true; }
                        }
                    }
                }

                // Any requested code not available => block checkout
                foreach ( $requested_codes as $code ) {
                    if ( ! isset($available_short[$code]) ) {
                        $errors->add('cloudbeds_unavailable', sprintf( __('The selected room (%s) is no longer available for your dates. Please update your booking.', 'cloudbeds'), esc_html($code) ));
                    }
                }
            }
        }

        /** =====================
         *  Public redirect helper
         *  =====================
         * Trigger: send a GET request to ?cloudbeds_wc_checkout=1&payload=... (base64 json)
         * Or call Cloudbeds_Woo_Integration::create_order_and_get_checkout_url( $data );
         */
        public function maybe_handle_redirect_request() {
            if ( isset($_GET['cloudbeds_wc_checkout']) ) {
                $payload = isset($_GET['payload']) ? sanitize_text_field($_GET['payload']) : '';
                $data = $this->decode_payload($payload);
                if ( empty($data) ) {
                    $this->log("[Cloudbeds-Woo] Invalid or empty payload for redirect");
                    wp_die(__('Invalid checkout payload.', 'cloudbeds'));
                }
                $url = $this->create_order_and_get_checkout_url($data);
                if ( $url ) {
                    wp_safe_redirect($url);
                    exit;
                }
                wp_die(__('Could not create checkout session.', 'cloudbeds'));
            }
        }

        private function decode_payload($payload) {
            if (!$payload) return [];
            $json = base64_decode($payload);
            $data = json_decode($json, true);
            return is_array($data) ? $data : [];
        }

        /**
         * Main helper you can call:
         *   Cloudbeds_Woo_Integration::instance()->create_order_and_get_checkout_url([
         *       'room_id' => 123, 'dates' => ['checkin'=>'2025-08-29','checkout'=>'2025-08-30'],
         *       'guest' => ['name'=>'John Doe','email'=>'john@example.com'],
         *       'price' => 150.00, 'country' => 'GB', 'payment' => 'COD'
         *   ]);
         */
        public function create_order_and_get_checkout_url( $data ) {
            if ( ! class_exists('WooCommerce') ) {
                $this->log("[Cloudbeds-Woo] WooCommerce missing while creating order");
                return false;
            }

            $placeholder_id = get_option('woocommerce_placeholder_product_id');
            if ( ! $placeholder_id ) {
                $this->log("[Cloudbeds-Woo] Placeholder product ID missing (set it in plugin settings)");
                return false;
            }

            $order = wc_create_order();
            $product = wc_get_product( $placeholder_id );
            if ( ! $product ) {
                $this->log("[Cloudbeds-Woo] Placeholder product not found (ID: $placeholder_id)");
                return false;
            }

            // Add placeholder product
            $order->add_product( $product, 1 );

            // Set totals
            $price = isset($data['price']) ? floatval($data['price']) : 0;
            $order->set_total( $price );

            // Store booking data
            $order->update_meta_data('_cloudbeds_booking_data', $data);
            $order->save();

            $this->log("[Cloudbeds-Woo] Created order #{$order->get_id()} for price {$price}");

            return $order->get_checkout_payment_url();
        }

        /** =====================
         *  Woo events / handlers
         *  ===================== */

        public function maybe_autocomplete_cod( $order_id ) {
            $order = wc_get_order($order_id);
            if ( $order && ( $order->has_status('processing') || $order->has_status('on-hold') ) ) {
                $this->log("[Cloudbeds-Woo] Auto-completing COD order #{$order_id}");
                $order->update_status('completed');
                $this->log("[Cloudbeds-Woo] COD order #{$order_id} auto-completed");
            } else {
                $this->log("[Cloudbeds-Woo] COD order #{$order_id} not auto-completed - status: " . ($order ? $order->get_status() : 'order not found'));
            }
        }

        public function handle_payment_complete( $order_id ) {
            $this->log("[Cloudbeds-Woo] Payment complete hook triggered for order #{$order_id}");
            $this->process_reservation_creation($order_id, 'payment_complete');
        }

        public function handle_order_completed( $order_id ) {
            $this->log("[Cloudbeds-Woo] Order completed hook triggered for order #{$order_id}");
            $this->process_reservation_creation($order_id, 'order_completed');
        }

        private function process_reservation_creation($order_id, $trigger) {
            $order = wc_get_order($order_id);
            if ( ! $order ) {
                $this->log("[Cloudbeds-Woo] Order missing (#{$order_id}) from {$trigger}");
                return;
            }

            // Check if reservation already exists to avoid duplicates
            $existing_reservation = $order->get_meta('_cloudbeds_reservation_id');
            if ($existing_reservation) {
                $this->log("[Cloudbeds-Woo] Reservation already exists for order #{$order_id} => {$existing_reservation}");
                return;
            }

            $this->log("[Cloudbeds-Woo] Processing reservation creation for order #{$order_id} from {$trigger}");

            // Handle multiple rooms by collecting all booking data from line items
            $line_items = $order->get_items();
            $rooms_data = [];
            $common_data = null;
            
            foreach ($line_items as $item) {
                $guest_count = $item->get_meta('Guest');
                // Prefer per-item booking payload to avoid duplicate room types
                $item_booking = $item->get_meta('_cloudbeds_booking_item');
                if ($item_booking && is_array($item_booking)) {
                    if (!$common_data) {
                        // copy shared fields once
                        $common_data = [
                            'checkin'  => $item_booking['checkin'] ?? '',
                            'checkout' => $item_booking['checkout'] ?? '',
                        ];
                    }
                    $rooms_data[] = [
                        'room_name' => $item->get_name(),
                        'room_type' => $item_booking['room_type'] ?? '',
                        'guests'    => intval(($item_booking['adults'] ?? 0) + ($item_booking['children'] ?? 0) ?: $guest_count),
                        'adults'    => intval($item_booking['adults'] ?? 2),
                        'children'  => intval($item_booking['children'] ?? 0),
                        'price'     => floatval($item->get_total())
                    ];
                    continue;
                }

                if ($guest_count) {
                    // Fallback to order-level booking data (legacy)
                    $booking_data = $order->get_meta('_cloudbeds_booking_data');
                    if (!empty($booking_data)) {
                        if (!$common_data) {
                            $common_data = $booking_data;
                        }
                        $rooms_data[] = [
                            'room_name' => $item->get_name(),
                            'room_type' => $booking_data['room_type'] ?? '',
                            'guests'    => intval($guest_count),
                            'adults'    => $booking_data['adults'] ?? 2,
                            'children'  => $booking_data['children'] ?? 0,
                            'price'     => floatval($item->get_total())
                        ];
                    }
                }
            }
            
            if (empty($rooms_data) && empty($common_data)) {
                $this->log("[Cloudbeds-Woo] No booking data found for order #{$order_id}");
                return;
            }

            try {
                $this->log("[Cloudbeds-Woo] Starting reservation creation for order #{$order_id} with " . count($rooms_data) . " rooms");
                
                // Create CloudBeds reservation with multiple rooms
                $reservation_id = $this->create_cloudbeds_reservation_multi($rooms_data, $common_data, $order_id);

                if ($reservation_id) {
                    // Save + note
                    $order->update_meta_data('_cloudbeds_reservation_id', $reservation_id);
                    $order->add_order_note( sprintf(__('Cloudbeds Reservation ID: %s', 'cloudbeds'), $reservation_id) );
                    $order->save();

                    $this->log("[Cloudbeds-Woo] Reservation created successfully for order #{$order_id} => {$reservation_id}");
                } else {
                    $this->log("[Cloudbeds-Woo] Failed to create reservation for order #{$order_id} - reservation_id is false");
                    $order->add_order_note( __('Cloudbeds reservation creation failed', 'cloudbeds') );
                    $order->save();
                }

            } catch (\Throwable $e) {
                $this->log("[Cloudbeds-Woo] Error creating reservation for order #{$order_id}: " . $e->getMessage());
                $order->add_order_note( sprintf(__('Cloudbeds reservation error: %s', 'cloudbeds'), $e->getMessage()) );
                $order->save();
            }
        }

        private function create_cloudbeds_reservation($booking, $order_id) {
            $property_id = get_option('cloudbeds_property_id');
            if (!$property_id) {
                $this->log("[Cloudbeds-Woo] Property ID missing for order #{$order_id}");
                return false;
            }

            // Get order details
            $order = wc_get_order($order_id);
            if (!$order) {
                $this->log("[Cloudbeds-Woo] Order not found for order #{$order_id}");
                return false;
            }

            // Handle multiple room types - split by semicolon
            $room_types = array_filter(array_map('trim', explode(';', $booking['room_type'])));
            
            if (empty($room_types)) {
                $this->log("[Cloudbeds-Woo] No room types found in booking data for order #{$order_id}");
                return false;
            }

            $this->log("[Cloudbeds-Woo] Processing " . count($room_types) . " room types for order #{$order_id}: " . implode(', ', $room_types));
            $this->log("[Cloudbeds-Woo] Room types array: " . json_encode($room_types));

            // Prepare reservation data according to CloudBeds API
            $reservation_data = [
                'startDate' => $booking['checkin'],
                'endDate' => $booking['checkout'],
                'guestFirstName' => trim($order->get_billing_first_name()) ?: 'Guest',
                'guestLastName' => trim($order->get_billing_last_name()) ?: 'Checkout',
                'guestEmail' => trim($order->get_billing_email()) ?: 'guest@example.com',
                'sendEmailConfirmation' => true,
                'thirdPartyIdentifier' => 'WC-' . $order_id, // WooCommerce order identifier
                'guestCountry' => trim($order->get_billing_country()) ?: 'GB',
            ];
            
            // Build rooms, adults, and children arrays for multiple room types
            $reservation_data['rooms'] = [];
            $reservation_data['adults'] = [];
            $reservation_data['children'] = [];
            
            foreach ($room_types as $room_type_short) {
                $room_type_id = cloudbeds_get_room_type_id_from_short_name($room_type_short);
                if (!$room_type_id) {
                    $this->log("[Cloudbeds-Woo] Could not find room type ID for: " . $room_type_short);
                    continue;
                }
                
                // Add room
                $reservation_data['rooms'][] = [
                    'roomTypeID' => $room_type_id,
                    'quantity' => 1
                ];
                
                // Add adults for this room type
                $reservation_data['adults'][] = [
                    'roomTypeID' => $room_type_id,
                    'quantity' => intval($booking['adults'])
                ];
                
                // Add children for this room type
                $reservation_data['children'][] = [
                    'roomTypeID' => $room_type_id,
                    'quantity' => intval($booking['children'])
                ];
            }
            
            // Check if we have at least one valid room type
            if (empty($reservation_data['rooms'])) {
                $this->log("[Cloudbeds-Woo] No valid room types found for order #{$order_id}");
                return false;
            }
            
            // Add payment method mapping for all payment types
            $payment_method = $order->get_payment_method();
            if ($payment_method) {
                $cloudbeds_payment_method = $this->map_payment_method_to_cloudbeds($payment_method);
                if ($cloudbeds_payment_method) {
                    $reservation_data['paymentMethod'] = $cloudbeds_payment_method;
                }
            }
            
            // Clean up any empty values that might cause format issues
            $reservation_data = array_filter($reservation_data, function($value) {
                return $value !== '' && $value !== null;
            });

            $this->log("[Cloudbeds-Woo] Creating reservation for order #{$order_id} with data: " . json_encode($reservation_data));
            $this->log("[Cloudbeds-Woo] Final rooms array: " . json_encode($reservation_data['rooms']));
            $this->log("[Cloudbeds-Woo] Final adults array: " . json_encode($reservation_data['adults']));
            $this->log("[Cloudbeds-Woo] Final children array: " . json_encode($reservation_data['children']));

            // Make API call to CloudBeds
            $response = cloudbeds_http_post('/postReservation', $reservation_data);
            
            if (is_wp_error($response)) {
                $this->log("[Cloudbeds-Woo] API error for order #{$order_id}: " . $response->get_error_message());
                return false;
            }

            if (isset($response['success']) && $response['success'] && isset($response['reservationID'])) {
                $reservation_id = $response['reservationID'];
                // Persist reservation status if provided
                try {
                    $status = isset($response['status']) ? (string)$response['status'] : '';
                    if ($status) {
                        $order->update_meta_data('_cloudbeds_reservation_status', $status);
                        $order->save();
                    }
                    // Store raw response JSON for debugging if needed
                    $order->update_meta_data('_cloudbeds_reservation_response', wp_json_encode($response));
                    $order->save();
                } catch (\Throwable $e) { /* ignore */ }
                $this->log("[Cloudbeds-Woo] Reservation created successfully for order #{$order_id} => {$reservation_id}");
                return $reservation_id;
            } else {
                // Check if we have a reservationID even if success is not explicitly true
                $reservation_id = $response['reservationID'] ?? null;
                if ($reservation_id) {
                    try {
                        $status = isset($response['status']) ? (string)$response['status'] : '';
                        if ($status) {
                            $order->update_meta_data('_cloudbeds_reservation_status', $status);
                            $order->save();
                        }
                        $order->update_meta_data('_cloudbeds_reservation_response', wp_json_encode($response));
                        $order->save();
                    } catch (\Throwable $e) { /* ignore */ }
                    $this->log("[Cloudbeds-Woo] Reservation created successfully for order #{$order_id} with ID: {$reservation_id} (success field not present)");
                    return $reservation_id;
                }
                
                $this->log("[Cloudbeds-Woo] API response error for order #{$order_id}: " . json_encode($response));
                
                // Log more details about the request that failed
                $this->log("[Cloudbeds-Woo] Failed request data: " . json_encode($reservation_data));
                
                return false;
            }
        }

        private function create_cloudbeds_reservation_multi($rooms_data, $common_data, $order_id) {
            $property_id = get_option('cloudbeds_property_id');
            if (!$property_id) {
                $this->log("[Cloudbeds-Woo] Property ID missing for order #{$order_id}");
                return false;
            }

            // Get order details
            $order = wc_get_order($order_id);
            if (!$order) {
                $this->log("[Cloudbeds-Woo] Order not found for order #{$order_id}");
                return false;
            }

            // Prepare reservation data according to CloudBeds API
            $reservation_data = [
                'startDate' => $common_data['checkin'],
                'endDate' => $common_data['checkout'],
                'guestFirstName' => trim($order->get_billing_first_name()) ?: 'Guest',
                'guestLastName' => trim($order->get_billing_last_name()) ?: 'Checkout',
                'guestEmail' => trim($order->get_billing_email()) ?: 'guest@example.com',
                'sendEmailConfirmation' => true,
                'thirdPartyIdentifier' => 'WC-' . $order_id,
                'guestCountry' => trim($order->get_billing_country()) ?: 'GB',
            ];
            
            // Build rooms, adults, and children arrays for multiple rooms
            $reservation_data['rooms'] = [];
            $reservation_data['adults'] = [];
            $reservation_data['children'] = [];
            
            foreach ($rooms_data as $room_data) {
                // Get room type ID for each room
                $room_type_id = cloudbeds_get_room_type_id_from_short_name($room_data['room_type']);
                if (!$room_type_id) {
                    $this->log("[Cloudbeds-Woo] Could not find room type ID for: " . $room_data['room_type']);
                    continue; // Skip this room but continue with others
                }
                
                $reservation_data['rooms'][] = [
                    'roomTypeID' => $room_type_id,
                    'quantity' => 1
                ];
                
                $reservation_data['adults'][] = [
                    'roomTypeID' => $room_type_id,
                    'quantity' => intval($room_data['adults'])
                ];
                
                $reservation_data['children'][] = [
                    'roomTypeID' => $room_type_id,
                    'quantity' => intval($room_data['children'])
                ];
            }
            
            if (empty($reservation_data['rooms'])) {
                $this->log("[Cloudbeds-Woo] No valid rooms found for order #{$order_id}");
                return false;
            }
            
            // Add payment method mapping
            $payment_method = $order->get_payment_method();
            if ($payment_method) {
                $cloudbeds_payment_method = $this->map_payment_method_to_cloudbeds($payment_method);
                if ($cloudbeds_payment_method) {
                    $reservation_data['paymentMethod'] = $cloudbeds_payment_method;
                }
            }
            
            // Clean up any empty values
            $reservation_data = array_filter($reservation_data, function($value) {
                return $value !== '' && $value !== null;
            });

            $this->log("[Cloudbeds-Woo] Creating multi-room reservation for order #{$order_id} with data: " . json_encode($reservation_data));

            // Make API call to CloudBeds
            $response = cloudbeds_http_post('/postReservation', $reservation_data);
            
            if (is_wp_error($response)) {
                $this->log("[Cloudbeds-Woo] API error for order #{$order_id}: " . $response->get_error_message());
                return false;
            }

            if (isset($response['success']) && $response['success'] && isset($response['reservationID'])) {
                $reservation_id = $response['reservationID'];
                // Persist reservation status if provided
                try {
                    $status = isset($response['status']) ? (string)$response['status'] : '';
                    if ($status) {
                        $order->update_meta_data('_cloudbeds_reservation_status', $status);
                    }
                    $order->update_meta_data('_cloudbeds_reservation_response', wp_json_encode($response));
                    $order->save();
                } catch (\Throwable $e) { /* ignore */ }
                $this->log("[Cloudbeds-Woo] Multi-room reservation created successfully for order #{$order_id} => {$reservation_id}");
                return $reservation_id;
            } else {
                // Check if we have a reservationID even if success is not explicitly true
                $reservation_id = $response['reservationID'] ?? null;
                if ($reservation_id) {
                    try {
                        $status = isset($response['status']) ? (string)$response['status'] : '';
                        if ($status) {
                            $order->update_meta_data('_cloudbeds_reservation_status', $status);
                        }
                        $order->update_meta_data('_cloudbeds_reservation_response', wp_json_encode($response));
                        $order->save();
                    } catch (\Throwable $e) { /* ignore */ }
                    $this->log("[Cloudbeds-Woo] Multi-room reservation created successfully for order #{$order_id} with ID: {$reservation_id} (success field not present)");
                    return $reservation_id;
                }
                
                $this->log("[Cloudbeds-Woo] API response error for order #{$order_id}: " . json_encode($response));
                $this->log("[Cloudbeds-Woo] Failed request data: " . json_encode($reservation_data));
                
                return false;
            }
        }

        /**
         * Map WooCommerce payment methods to Cloudbeds payment methods
         */
        private function map_payment_method_to_cloudbeds($wc_payment_method) {
            $mapping = [
                'cod' => 'cash',
                'paypal' => 'pay_pal',
                'stripe' => 'credit',
                'square' => 'credit',
                'bank_transfer' => 'ebanking',
                'cheque' => 'cash',
                'bacs' => 'ebanking',
                'cheque' => 'cash',
                'mijireh_checkout' => 'credit',
                'sage' => 'credit',
                'worldpay' => 'credit',
                'paypal_express' => 'pay_pal',
                'paypal_plus' => 'pay_pal',
                'stripe_apple_pay' => 'credit',
                'stripe_google_pay' => 'credit',
            ];
            
            $method = strtolower($wc_payment_method);
            return isset($mapping[$method]) ? $mapping[$method] : 'credit'; // Default to credit if unknown
        }

        // Room type mapping is now handled by cloudbeds_get_room_type_id_from_short_name() in ajax-handler.php

        public function thankyou_reservation_id( $order_id ) {
            if ( ! $order_id ) return;
            $order = wc_get_order($order_id);
            if ( ! $order ) return;
            $rid = $order->get_meta('_cloudbeds_reservation_id');
            if ( $rid ) {
                echo '<p><strong>' . esc_html__('Your Reservation ID:', 'cloudbeds') . '</strong> ' . esc_html($rid) . '</p>';
            }
        }

        /** =====================
         *  Admin column
         *  ===================== */
        public function add_admin_column( $columns ) {
            $new = [];
            foreach ( $columns as $key => $label ) {
                $new[$key] = $label;
                if ( 'order_status' === $key ) {
                    $new['cloudbeds_reservation_id'] = __('Cloudbeds Res ID', 'cloudbeds');
                }
            }
            return $new;
        }

        public function render_admin_column( $column, $post_id ) {
            if ( 'cloudbeds_reservation_id' === $column ) {
                $order = wc_get_order($post_id);
                $rid = $order ? $order->get_meta('_cloudbeds_reservation_id') : '';
                echo $rid ? '<strong>'.esc_html($rid).'</strong>' : '<span style="color:#888;">—</span>';
            }
        }

        /** =====================
         *  Hide placeholder product
         *  ===================== */
        public function hide_placeholder_in_queries( $q ) {
            if ( is_admin() || ! $q->is_main_query() ) return;
            if ( is_shop() || is_product_category() || is_search() ) {
                $pid = get_option('woocommerce_placeholder_product_id');
                if ( $pid ) {
                    $q->set( 'post__not_in', array_merge( (array) $q->get('post__not_in'), [ (int) $pid ] ) );
                }
            }
        }

        public function block_placeholder_single() {
            if ( ! is_product() ) return;
            global $post;
            $pid = get_option('woocommerce_placeholder_product_id');
            if ( $post && $pid && (int)$post->ID === (int)$pid ) {
                wp_redirect( home_url() );
                exit;
            }
        }

        public function prevent_manual_cart_add( $passed, $product_id ) {
            $pid = get_option('woocommerce_placeholder_product_id');
            if ( $pid && (int)$product_id === (int)$pid ) {
                wc_add_notice( __('This product cannot be purchased directly.', 'cloudbeds'), 'error' );
                return false;
            }
            return $passed;
        }

        /** =====================
         *  Logging
         *  ===================== */
        private function log( $message ) {
            $upload = wp_upload_dir();
            $file = trailingslashit($upload['basedir']) . 'cloudbeds-woo.log';
            $date = date('Y-m-d H:i:s');
            error_log("[$date] [Cloudbeds-Woo] $message\n", 3, $file);
        }

        /** Singleton */
        public static function instance() {
            static $inst = null;
            if ( null === $inst ) $inst = new self();
            return $inst;
        }

        /** Manual trigger for testing reservation creation */
        public function manual_trigger_reservation($order_id) {
            $this->log("[Cloudbeds-Woo] Manual trigger for order #{$order_id}");
            $this->process_reservation_creation($order_id, 'manual_trigger');
        }
    }

    // Boot
    add_action('plugins_loaded', function() {
        if ( class_exists('WooCommerce') ) {
            Cloudbeds_Woo_Integration::instance();
        }
    });
}