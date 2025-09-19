<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_get_cloudbeds_rooms', 'handle_get_cloudbeds_rooms');
add_action('wp_ajax_nopriv_get_cloudbeds_rooms', 'handle_get_cloudbeds_rooms');

function cloudbeds_http_get($endpoint, $args = []){
    $api_key     = get_option('cloudbeds_api_key');
    if (!$api_key) return new WP_Error('missing_key', 'API key missing');
    $url = CLOUD_BEDS_API_URL . $endpoint;
    $response = wp_remote_get(add_query_arg($args, $url), [
        'headers' => [
            'x-api-key' => $api_key,
            'accept'    => 'application/json'
        ],
        'timeout' => 20
    ]);
    if (is_wp_error($response)) return $response;
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) return new WP_Error('bad_status', 'Bad status: '.$code, $response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body;
}

function handle_get_cloudbeds_rooms() {
    $property_id = get_option('cloudbeds_property_id');
    if (!$property_id) wp_send_json_error('Property ID not set.');

    $start  = sanitize_text_field($_POST['startDate']);
    $end    = sanitize_text_field($_POST['endDate']);
    $adults = isset($_POST['adults']) ? (int)$_POST['adults'] : 2;
    $kids   = isset($_POST['kids']) ? (int)$_POST['kids'] : 0;
    $promo  = isset($_POST['promo']) ? sanitize_text_field($_POST['promo']) : '';

    // Base rates
    $base_resp = cloudbeds_http_get('/getAvailableRoomTypes', [
        'propertyIDs' => $property_id,
        'startDate'   => $start,
        'endDate'     => $end,
        'rooms'       => 1,
        'adults'      => max(1, $adults),
        'children'    => max(0, $kids)
    ]);
    if (is_wp_error($base_resp)) wp_send_json_error('Please try again.');
    if (empty($base_resp['data'])) {
        wp_send_json_success('<p class="cloudbeds-no-rooms">No rooms available for the selected dates.</p>');
    }

    // Promo rates (optional)
    $promo_map = [];
    if (!empty($promo)) {
        $promo_resp = cloudbeds_http_get('/getAvailableRoomTypes', [
            'propertyIDs' => $property_id,
            'startDate'   => $start,
            'endDate'     => $end,
            'rooms'       => 1,
            'adults'      => max(1, $adults),
            'children'    => max(0, $kids),
            'promoCode'   => $promo
        ]);
        if (!is_wp_error($promo_resp) && !empty($promo_resp['data'])) {
            foreach ($promo_resp['data'] as $prop) {
                if (empty($prop['propertyRooms'])) continue;
                foreach ($prop['propertyRooms'] as $rt) {
                    $promo_map[(string)($rt['roomTypeID'] ?? '')] = $rt;
                }
            }
        }
    }

    $currency_symbol = '£';
    if (!empty($base_resp['data'][0]['propertyCurrency']['currencySymbol'])) {
        $currency_symbol = $base_resp['data'][0]['propertyCurrency']['currencySymbol'];
    }

    $rows = [];
    $has_rooms = false;

    // Aggregate API results by roomTypeID to avoid duplicates across rate plans
    $agg = [];
    foreach ($base_resp['data'] as $property) {
        if (empty($property['propertyRooms'])) continue;
        foreach ($property['propertyRooms'] as $rt) {
            $roomsAvailable = isset($rt['roomsAvailable']) ? intval($rt['roomsAvailable']) : 0;
            if ($roomsAvailable <= 0) continue;
            $roomTypeID    = isset($rt['roomTypeID']) ? (string)$rt['roomTypeID'] : '';
            if ($roomTypeID === '') continue;
            $roomName      = esc_html($rt['roomTypeName'] ?? '');
            $roomTypeShort = esc_attr($rt['roomTypeNameShort'] ?? '');
            $maxGuests     = isset($rt['maxGuests']) ? intval($rt['maxGuests']) : ( (intval($rt['adultsIncluded'] ?? 0)) + (intval($rt['childrenIncluded'] ?? 0)) );
            $baseRateVal   = isset($rt['roomRate']) ? floatval($rt['roomRate']) : null;
            $planName      = trim((string)($rt['ratePlanNamePublic'] ?? ''));
            if ($planName === '' || strtolower($planName) === 'default') { $planName = 'Standard Rate'; }

            if (!isset($agg[$roomTypeID])) {
                $agg[$roomTypeID] = [
                    'name'            => $roomName,
                    'room_type_short' => $roomTypeShort,
                    'max_guests'      => $maxGuests,
                    'rates'           => [],
                ];
            } else {
                // no-op
            }
            if ($baseRateVal !== null) { $agg[$roomTypeID]['rates'][] = ['price'=>$baseRateVal, 'name'=>$planName]; }
        }
    }

    // Build rows from aggregated data and WP post mapping
    foreach ($agg as $roomTypeID => $info) {
        // Determine base (standard) and promo (discount) from collected rates
        $entries = isset($info['rates']) ? $info['rates'] : [];
        $base_price = null; // Standard
        $promo_price = null; // Discount if lower than standard
        $base_label = 'Standard Rate';
        $promo_label = '';
        if (!empty($entries)) {
            // Determine max/min by price
            $maxEntry = null; $minEntry = null;
            foreach ($entries as $ent) {
                if ($maxEntry === null || $ent['price'] > $maxEntry['price']) { $maxEntry = $ent; }
                if ($minEntry === null || $ent['price'] < $minEntry['price']) { $minEntry = $ent; }
            }
            if ($maxEntry) { $base_price = $maxEntry['price']; $base_label = $maxEntry['name'] ?: 'Standard Rate'; }
            if ($minEntry && $maxEntry && $minEntry['price'] < $maxEntry['price']) { $promo_price = $minEntry['price']; $promo_label = $minEntry['name']; }
            // Normalize base label to Standard Rate when API sends default
            if (strtolower($base_label) === 'default') { $base_label = 'Standard Rate'; }
        }
            // Match WP post by 'room_id' meta == roomTypeID
            $query = new WP_Query([
                'post_type'      => 'room',
                'posts_per_page' => 1,
                'meta_key'       => 'room_id',
                'meta_value'     => $roomTypeID
            ]);

            if ($query->have_posts()) {
                $has_rooms = true;
                $room_post = $query->posts[0];

            $promoRate = $promo_price;

                $args = [
                    'checkin'    => $start,
                    'checkout'   => $end,
                    'adults'     => $adults,
                    'kids'       => $kids,
                'room_type'  => $info['room_type_short']
                ];
                if (!empty($promo)) { $args['promo'] = $promo; }
                $room_url = add_query_arg($args, get_permalink($room_post->ID));

                $rows[] = [
                'name'             => $info['name'],
                'max_guests'       => $info['max_guests'],
                'base'             => $base_price,
                    'promo'            => $promoRate,
                'base_label'       => $base_label,
                'promo_label'      => $promo_label,
                    'currency'         => $currency_symbol,
                    'url'              => $room_url,
                'room_type_short'  => $info['room_type_short'],
                'room_type_id'     => $roomTypeID,
                ];
            }
            wp_reset_postdata();
    }

    if (!$has_rooms) {
        wp_send_json_success('<p class="cloudbeds-no-rooms">No rooms available for the selected dates.</p>');
    }

    ob_start();
    echo '<div class="cb-availability-table-wrap">';
    echo '<table class="cb-availability-table" role="table" aria-label="Available rooms">';
    echo '<thead><tr><th>Room</th><th>Price</th><th class="cb-actions-col">Action</th></tr></thead><tbody>';
    
foreach($rows as $r){
    $room_post_id = url_to_postid($r['url']);
    $img = $room_post_id ? get_the_post_thumbnail_url($room_post_id, 'medium') : '';
    $img_html = $img ? '<img src="'.esc_url($img).'" alt="'.esc_attr($r['name']).'" class="cb-thumb" />' : '';

    echo '<tr class="cb-row" data-room-short="'.esc_attr($r['room_type_short']).'" data-room-id="'.esc_attr($r['room_type_id']).'" data-room-url="'.esc_url($r['url']).'">';

    // Col 1: Room
    echo '<td data-label="Room">';
    echo '  <div class="cb-room-flex">';
    echo        $img_html;
    echo '    <div class="cb-info">';
    echo '      <div class="cb-title">'. $r['name'] .'</div>';
    echo '    <div class="cb-meta"><span class="cb-chip">Max '. esc_html($r['max_guests']) .' guests</span></div>';
    echo '    </div>';
    echo '  </div>';
    echo '</td>';

    // Col 2: Details with plan rows and per-plan Add buttons
    echo '<td data-label="Details">';
    echo '  <div class="cb-details">';

    // Show promo row if a cheaper plan exists (regardless of promo code input)
    $has_discount = ($r['promo'] !== null && $r['base'] !== null && $r['promo'] < $r['base']);
    if ($has_discount) {
        echo '    <div class="cb-plan-row cb-plan-promo">';
        $label = !empty($r['promo_label']) ? $r['promo_label'] : 'Discounted Rate';
        echo '      <div class="cb-plan-left">'. esc_html($label) .' <span class="cb-tip" data-tip="Non-Refundable Rate" aria-label="Non-Refundable Rate">i</span></div>';
        echo '      <div class="cb-plan-right">';
        echo '        <span class="cb-plan-price" data-plan="promo">'. esc_html($r['currency']) . number_format($r['promo'], 2) .'</span>';
        echo '        <label class="cb-plan-radio-label">';
        echo '          <input type="radio" name="cb_rate_plan_'. esc_attr($r['room_type_short']) .'" value="promo" data-plan="promo" data-price="'. esc_attr(number_format($r['promo'], 2, '.', '')) .'" data-room="'. esc_attr($r['room_type_short']) .'">';
        echo '          <span class="cb-radio-custom"></span>';
        echo '        </label>';
        echo '      </div>';
        echo '    </div>';
    }

    // Base plan row
    if ($r['base'] !== null) {
        echo '    <div class="cb-plan-row cb-plan-base">';
        $baseLabel = !empty($r['base_label']) ? $r['base_label'] : 'Standard Rate';
        echo '      <div class="cb-plan-left">'. esc_html($baseLabel) .'</div>';
        echo '      <div class="cb-plan-right">';
        echo '        <span class="cb-plan-price" data-plan="base">'. esc_html($r['currency']) . number_format($r['base'], 2) .'</span>';
        echo '        <label class="cb-plan-radio-label">';
        $checked = $has_discount ? '' : ' checked';
        echo '          <input type="radio" name="cb_rate_plan_'. esc_attr($r['room_type_short']) .'" value="base" data-plan="base" data-price="'. esc_attr(number_format($r['base'], 2, '.', '')) .'" data-room="'. esc_attr($r['room_type_short']) .'"'. $checked .'>';
        echo '          <span class="cb-radio-custom"></span>';
        echo '        </label>';
        echo '      </div>';
    echo '    </div>';
    }

    echo '  </div>';
   
    echo '</td>';

    // Col 3: Actions
    echo '<td data-label="Actions" class="cb-actions">';
    echo '<a class="wp-cloudbeds-book-btn cb-ghost cb-show-room" href="'. esc_url($r['url']) .'" target="_blank" rel="noopener">Show Room</a> ';
     echo '  <div class="cb-room-actions">';
    echo '    <button type="button" class="wp-cloudbeds-book-btn cb-add-room" data-room="'. esc_attr($r['room_type_short']) .'">Add Room</button>';
    echo '  </div>';
    echo '</td>';

    echo '</tr>';
}
echo '</tbody></table>';

    // Cart action area (kept subtle, no design overhaul)
    echo '<div id="cb-cart-actions" style="margin-top:12px;display:none">';
    echo '  <button type="button" class="wp-cloudbeds-check-btn" id="cb-cart-book">Book Now</button>';
    echo '  <span id="cb-cart-counter" style="margin-left:10px;opacity:.85"></span>';
    echo '</div>';

    echo '</div>';
    $html = ob_get_clean();
    wp_send_json_success($html);
}

add_action('wp_ajax_check_room_availability', 'cloudbeds_room_booking_check_handler');
add_action('wp_ajax_nopriv_check_room_availability', 'cloudbeds_room_booking_check_handler');

function cloudbeds_room_booking_check_handler() {
    $property_id = get_option('cloudbeds_property_id');
    $unique_code = get_option('cloudbeds_unique_url_code');
    if (!$property_id || !$unique_code) {
        wp_send_json([ 'success' => false, 'data' => ['message' => 'Cloudbeds settings missing.'] ]);
    }

    $checkin = sanitize_text_field($_POST['checkin']);
    $checkout = sanitize_text_field($_POST['checkout']);
    $adults = intval($_POST['adults']);
    $kids = intval($_POST['kids']);
    $room_id = sanitize_text_field($_POST['room_id']);
    $promo    = isset($_POST['promo']) ? sanitize_text_field($_POST['promo']) : '';
    $currency = 'gbp';

    $resp = cloudbeds_http_get('/getAvailableRoomTypes', [
        'propertyIDs' => $property_id,
        'startDate'   => $checkin,
        'endDate'     => $checkout
    ]);
    if (is_wp_error($resp)) {
        wp_send_json([ 'success' => false, 'data' => ['message' => 'API error.'] ]);
    }
    if (empty($resp['data'])) {
        wp_send_json([ 'success' => false, 'data' => ['message' => 'No rooms available for selected dates.'] ]);
    }

    $room_type = '';
    $found_room = false;
    foreach ($resp['data'] as $property) {
        if (!empty($property['propertyRooms'])) {
            foreach ($property['propertyRooms'] as $rt) {
                if (!empty($rt['roomTypeID']) && $rt['roomTypeID'] == $room_id && intval($rt['roomsAvailable'] ?? 0) > 0) {
                    $found_room = true;
                    $room_type = $rt['roomTypeNameShort'] ?? '';
                    break 2;
                }
            }
        }
    }
    if (!$found_room) {
        wp_send_json([ 'success' => false, 'data' => ['message' => 'Room is not available for selected dates.'] ]);
    }

    $booking_url = "https://us2.cloudbeds.com/en/reservation/{$unique_code}/";
    $params = [
        'checkin'    => $checkin,
        'checkout'   => $checkout,
        'adults'     => $adults,
        'kids'       => $kids,
        'room_type'  => $room_type,
        'currency'   => $currency,
    ];
    if (!empty($promo)) { $params['promo'] = $promo; }
    $booking_url .= '?' . http_build_query($params);

    wp_send_json([ 'success' => true, 'data' => ['booking_url' => $booking_url] ]);
}

/**
 * Get detailed pricing including taxes and fees for a specific room and dates
 */
add_action('wp_ajax_get_room_detailed_pricing', 'cloudbeds_get_room_detailed_pricing');
add_action('wp_ajax_nopriv_get_room_detailed_pricing', 'cloudbeds_get_room_detailed_pricing');
function cloudbeds_get_room_detailed_pricing() {
    $property_id = get_option('cloudbeds_property_id');
    if (!$property_id) {
        wp_send_json_error('Property ID not set.');
    }

    $room_type_id = sanitize_text_field($_POST['roomTypeID']);
    $start_date = sanitize_text_field($_POST['startDate']);
    $end_date = sanitize_text_field($_POST['endDate']);
    $adults = isset($_POST['adults']) ? intval($_POST['adults']) : 2;
    $children = isset($_POST['children']) ? intval($_POST['children']) : 0;
    $promo_code = isset($_POST['promoCode']) ? sanitize_text_field($_POST['promoCode']) : '';

    // Get base rate
    $base_rate_resp = cloudbeds_http_get('/getAvailableRoomTypes', [
        'propertyIDs' => $property_id,
        'startDate'   => $start_date,
        'endDate'     => $end_date,
        'rooms'       => 1,
        'adults'      => $adults,
        'children'    => $children
    ]);

    if (is_wp_error($base_rate_resp) || empty($base_rate_resp['data'])) {
        wp_send_json_error('Could not get base rate.');
    }

    $base_rate = null;
    $room_type_name = '';
    foreach ($base_rate_resp['data'] as $property) {
        if (empty($property['propertyRooms'])) continue;
        foreach ($property['propertyRooms'] as $rt) {
            if (isset($rt['roomTypeID']) && (string)$rt['roomTypeID'] === $room_type_id) {
                $base_rate = isset($rt['roomRate']) ? floatval($rt['roomRate']) : null;
                $room_type_name = $rt['roomTypeName'] ?? '';
                break 2;
            }
        }
    }

    if ($base_rate === null) {
        wp_send_json_error('Room rate not found.');
    }

    // Get rate plans
    $rate_plans_resp = cloudbeds_http_get('/getRatePlans', [
        'propertyIDs' => $property_id,
        'roomTypeIDs' => $room_type_id
    ]);

    $rate_plans = [];
    if (!is_wp_error($rate_plans_resp) && !empty($rate_plans_resp['data'])) {
        foreach ($rate_plans_resp['data'] as $rate_plan) {
            if (isset($rate_plan['ratePlanID'])) {
                $rate_plans[] = [
                    'id' => $rate_plan['ratePlanID'],
                    'name' => $rate_plan['ratePlanName'] ?? '',
                    'description' => $rate_plan['description'] ?? ''
                ];
            }
        }
    }

    // Get taxes and fees
    $taxes_fees_resp = cloudbeds_http_get('/getTaxesAndFees', [
        'propertyIDs' => $property_id,
        'startDate'   => $start_date,
        'endDate'     => $end_date,
        'roomTypeIDs' => $room_type_id
    ]);

    $taxes_fees = [];
    $total_taxes_fees = 0;
    if (!is_wp_error($taxes_fees_resp) && !empty($taxes_fees_resp['data'])) {
        foreach ($taxes_fees_resp['data'] as $tax_fee) {
            if (isset($tax_fee['amount'])) {
                $amount = floatval($tax_fee['amount']);
                $taxes_fees[] = [
                    'name' => $tax_fee['name'] ?? 'Tax/Fee',
                    'amount' => $amount,
                    'type' => $tax_fee['type'] ?? 'tax',
                    'description' => $tax_fee['description'] ?? ''
                ];
                $total_taxes_fees += $amount;
            }
        }
    }

    // Calculate total
    $subtotal = $base_rate;
    $total = $subtotal + $total_taxes_fees;

    $response = [
        'success' => true,
        'data' => [
            'roomTypeName' => $room_type_name,
            'baseRate' => $base_rate,
            'subtotal' => $subtotal,
            'taxesAndFees' => $taxes_fees,
            'totalTaxesAndFees' => $total_taxes_fees,
            'total' => $total,
            'ratePlans' => $rate_plans,
            'currency' => 'GBP'
        ]
    ];

    wp_send_json($response);
}

/**
 * Month price map for calendar
 * Returns map YYYY-MM-DD => ['price'=>lowest_roomRate_or_null, 'available'=>bool]
 * Caches results with a transient for 30 minutes.
 * If roomTypeID is provided, only that room type is considered.
 */
add_action('wp_ajax_cloudbeds_get_month_prices', 'cloudbeds_get_month_prices');
add_action('wp_ajax_nopriv_cloudbeds_get_month_prices', 'cloudbeds_get_month_prices');

function cloudbeds_get_month_prices(){
    $year  = max(2000, intval($_POST['year'] ?? 0));
    $month = max(1, min(12, intval($_POST['month'] ?? 0)));
    $room_filter = isset($_POST['roomTypeID']) ? trim(sanitize_text_field($_POST['roomTypeID'])) : '';
    $property_id = get_option('cloudbeds_property_id');
    if(!$property_id || !$year || !$month){
        wp_send_json([ 'success'=>false, 'data'=>[] ]);
    }

    // If roomTypeID filter is provided, restrict to that one; else build allow list of all mapped rooms
    if(!empty($room_filter)){
        $allowed_ids = [ (string)$room_filter ];
    } else {
        $room_posts = get_posts([
            'post_type' => 'room',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        $allowed_ids = [];
        foreach($room_posts as $pid){
            $rid = get_post_meta($pid, 'room_id', true);
            if(!empty($rid)) $allowed_ids[] = (string)$rid;
        }
        $allowed_ids = array_unique($allowed_ids);
    }

    $cache_key = sprintf('cb_month_prices_%s_%04d_%02d_%s', $property_id, $year, $month, empty($room_filter)?'all':md5($room_filter));
    $cached = get_transient($cache_key);
    if($cached){
        wp_send_json([ 'success'=>true, 'data'=>$cached ]);
    }

    $days_in_month = (int) date('t', strtotime("$year-$month-01"));
    $map = [];

    for($day = 1; $day <= $days_in_month; $day++){
        $start = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $end   = date('Y-m-d', strtotime($start.' +1 day'));

        $resp = cloudbeds_http_get('/getAvailableRoomTypes', [
            'propertyIDs' => $property_id,
            'startDate'   => $start,
            'endDate'     => $end
        ]);
        if (is_wp_error($resp) || empty($resp['data'])) {
            $map[$start] = ['price'=>null, 'available'=>false];
            continue;
        }

        $lowest = null;
        $any_available = false;

        foreach($resp['data'] as $prop){
            if(!empty($prop['propertyRooms'])){
                foreach($prop['propertyRooms'] as $rt){
                    $rt_id = isset($rt['roomTypeID']) ? (string)$rt['roomTypeID'] : '';
                    if(!in_array($rt_id, $allowed_ids, true)) continue;
                    $available = intval($rt['roomsAvailable'] ?? 0) > 0;
                    if(!$available) continue;
                    $any_available = true;
                    if(isset($rt['roomRate'])){
                        $rate = floatval($rt['roomRate']);
                        if($lowest === null || $rate < $lowest){
                            $lowest = $rate;
                        }
                    }
                    if(!empty($room_filter) && $any_available){
                        // When a single room is requested, break early once we have a value
                        break 2;
                    }
                }
            }
        }
        $map[$start] = ['price'=>$lowest, 'available'=> $any_available ];
    }

    set_transient($cache_key, $map, 30 * MINUTE_IN_SECONDS);
    wp_send_json([ 'success'=>true, 'data'=>$map ]);
}


/** POST helper (shared) */
if (!function_exists('cloudbeds_http_post')) {
function cloudbeds_http_post($endpoint, $body = []){
    $api_key     = get_option('cloudbeds_api_key');
    if (!$api_key) return new WP_Error('missing_key', 'API key missing');
    $url = CLOUD_BEDS_API_URL . $endpoint;
    $response = wp_remote_post($url, [
        'headers' => [
            'x-api-key' => $api_key,
            'accept'    => 'application/json'
        ],
        'timeout' => 30,
        'body'    => $body
    ]);
    if (is_wp_error($response)) return $response;
    $code = wp_remote_retrieve_response_code($response);
    $json = json_decode(wp_remote_retrieve_body($response), true);
    return ($code >= 200 && $code < 300) ? $json : new WP_Error('http_error','Cloudbeds error',['code'=>$code,'body'=>$json]);
}
}


/** -----------------------------
 * Cloudbeds direct checkout integration (hidden placeholder product)
 * - AJAX endpoint: cloudbeds_set_booking (stores booking in WC session)
 * - On checkout page load, if booking in session, add hidden product to cart with booking meta
 * - Display booking meta on checkout and save to order line item
 * ----------------------------- */

add_action('wp_ajax_cloudbeds_set_booking', 'cloudbeds_set_booking');
add_action('wp_ajax_nopriv_cloudbeds_set_booking', 'cloudbeds_set_booking');
function cloudbeds_set_booking() {
    if (!function_exists('WC') || !WC()->session) {
        wp_send_json_error(['message' => 'WooCommerce session not available']);
    }
    // Ensure a customer session cookie exists for guests so data persists to checkout
    if (method_exists(WC()->session, 'set_customer_session_cookie')) {
        WC()->session->set_customer_session_cookie(true);
    }
    $room_type = isset($_POST['room_type']) ? sanitize_text_field($_POST['room_type']) : '';
    $checkin   = isset($_POST['checkin']) ? sanitize_text_field($_POST['checkin']) : '';
    $checkout  = isset($_POST['checkout']) ? sanitize_text_field($_POST['checkout']) : '';
    $adults    = isset($_POST['adults']) ? intval($_POST['adults']) : 1;
    $children  = isset($_POST['children']) ? intval($_POST['children']) : 0;
    $price     = isset($_POST['price']) ? floatval($_POST['price']) : 0.0;
    $room_prices_json = isset($_POST['room_prices']) ? wp_unslash($_POST['room_prices']) : '';
    $room_plans_json  = isset($_POST['room_plans']) ? wp_unslash($_POST['room_plans']) : '';
    $room_plan_labels_json = isset($_POST['room_plan_labels']) ? wp_unslash($_POST['room_plan_labels']) : '';
    $room_prices = [];
    $room_plans  = [];
    $room_plan_labels = [];
    if (!empty($room_prices_json)) {
        $decoded = json_decode($room_prices_json, true);
        if (is_array($decoded)) { $room_prices = $decoded; }
    }
    if (!empty($room_plans_json)) {
        $decodedPlans = json_decode($room_plans_json, true);
        if (is_array($decodedPlans)) { $room_plans = $decodedPlans; }
    }
    if (!empty($room_plan_labels_json)) {
        $decodedLabels = json_decode($room_plan_labels_json, true);
        if (is_array($decodedLabels)) { $room_plan_labels = $decodedLabels; }
    }
    
    $booking = [
        'room_type' => $room_type,
        'checkin'   => $checkin,
        'checkout'  => $checkout,
        'adults'    => $adults,
        'children'  => $children,
        'price'     => $price,
        'room_prices' => $room_prices,
        'room_plans'  => $room_plans,
        'room_plan_labels' => $room_plan_labels,
    ];

    // Resolve and store full roomTypeName for checkout line item title
    try {
        $codes = array_filter(array_map('trim', explode(';', $room_type)));
        if (!empty($codes)) {
            $property_id = get_option('cloudbeds_property_id');
            if ($property_id && $checkin && $checkout) {
                $resp = cloudbeds_http_get('/getAvailableRoomTypes', [
                    'propertyIDs' => $property_id,
                    'startDate'   => $checkin,
                    'endDate'     => $checkout
                ]);
                if (!is_wp_error($resp) && !empty($resp['data'])) {
                    $short_to_full = [];
                    foreach ($resp['data'] as $prop) {
                        if (empty($prop['propertyRooms'])) continue;
                        foreach ($prop['propertyRooms'] as $rt) {
                            $short = isset($rt['roomTypeNameShort']) ? (string)$rt['roomTypeNameShort'] : '';
                            $full  = isset($rt['roomTypeName']) ? (string)$rt['roomTypeName'] : '';
                            if ($short !== '' && $full !== '') { $short_to_full[$short] = $full; }
                        }
                    }
                    $names = [];
                    foreach ($codes as $code) { if (isset($short_to_full[$code])) { $names[] = $short_to_full[$code]; } }
                    if (!empty($names)) { $booking['room_type_name'] = implode(', ', array_unique($names)); }
                }
            }
        }
        
        // No tax calculations - use base price as-is from Cloudbeds API
    } catch (\Throwable $e) {
        // non-fatal
    }

    WC()->session->set('cloudbeds_booking', $booking);
    // unset flag in case left over
    WC()->session->set('cloudbeds_booking_added', false);

    // Debug log
    error_log("CloudBeds Booking Set - Price: " . $price . ", Room Type: " . $room_type);

    wp_send_json_success([ 'checkout' => wc_get_checkout_url() ]);
}

/** AJAX: Remove a Cloudbeds cart item by cart_item_key (checkout page) */
add_action('wp_ajax_cloudbeds_remove_cart_item', 'cloudbeds_remove_cart_item');
add_action('wp_ajax_nopriv_cloudbeds_remove_cart_item', 'cloudbeds_remove_cart_item');
function cloudbeds_remove_cart_item() {
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => 'Cart not available']);
    }
    $key = isset($_POST['cart_item_key']) ? sanitize_text_field($_POST['cart_item_key']) : '';
    if (empty($key)) {
        wp_send_json_error(['message' => 'Missing cart item key']);
    }

    // Try to remove the item
    $removed = WC()->cart->remove_cart_item($key);
    if ($removed) {
        // Clean up any stored session price for this cart key
        WC()->session->__unset('cloudbeds_cart_price_' . $key);
        $is_empty = WC()->cart->is_empty();
        wp_send_json_success(['message' => 'Item removed', 'cart_empty' => $is_empty]);
    }

    wp_send_json_error(['message' => 'Could not remove item']);
}

/**
 * Internal function to get detailed pricing (same logic as AJAX endpoint but for internal use)
 */
function cloudbeds_get_room_detailed_pricing_internal($room_type_id, $start_date, $end_date, $adults = 2, $children = 0) {
    $property_id = get_option('cloudbeds_property_id');
    if (!$property_id) {
        return false;
    }

    // Get base rate
    $base_rate_resp = cloudbeds_http_get('/getAvailableRoomTypes', [
        'propertyIDs' => $property_id,
        'startDate'   => $start_date,
        'endDate'     => $end_date,
        'rooms'       => 1,
        'adults'      => $adults,
        'children'    => $children
    ]);

    if (is_wp_error($base_rate_resp) || empty($base_rate_resp['data'])) {
        return false;
    }

    $base_rate = null;
    foreach ($base_rate_resp['data'] as $property) {
        if (empty($property['propertyRooms'])) continue;
        foreach ($property['propertyRooms'] as $rt) {
            if (isset($rt['roomTypeID']) && (string)$rt['roomTypeID'] === $room_type_id) {
                $base_rate = isset($rt['roomRate']) ? floatval($rt['roomRate']) : null;
                break 2;
            }
        }
    }

    if ($base_rate === null) {
        return false;
    }

    // Get taxes and fees
    $taxes_fees_resp = cloudbeds_http_get('/getTaxesAndFees', [
        'propertyIDs' => $property_id,
        'startDate'   => $start_date,
        'endDate'     => $end_date,
        'roomTypeIDs' => $room_type_id
    ]);

    $taxes_fees = [];
    if (!is_wp_error($taxes_fees_resp) && !empty($taxes_fees_resp['data'])) {
        foreach ($taxes_fees_resp['data'] as $tax_fee) {
            if (isset($tax_fee['amount'])) {
                $amount = floatval($tax_fee['amount']);
                $taxes_fees[] = [
                    'name' => $tax_fee['name'] ?? 'Tax/Fee',
                    'amount' => $amount,
                    'type' => $tax_fee['type'] ?? 'tax'
                ];
            }
        }
    }

    return [
        'baseRate' => $base_rate,
        'taxesAndFees' => $taxes_fees
    ];
}

/**
 * Helper function to get room type short name from room ID
 */
function cloudbeds_get_room_type_short_from_id($room_id) {
    $property_id = get_option('cloudbeds_property_id');
    if (!$property_id) {
        return '';
    }

    // Try to get room type short name from getRoomTypes endpoint
    $response = cloudbeds_http_get('/getRoomTypes', [
        'propertyIDs' => $property_id
    ]);
    
    if (!is_wp_error($response) && !empty($response['data'])) {
        foreach ($response['data'] as $rt) {
            if (isset($rt['roomTypeID']) && (string)$rt['roomTypeID'] === (string)$room_id) {
                return $rt['roomTypeNameShort'] ?? '';
            }
        }
    }
    
    return '';
}

/** Ensure hidden placeholder product exists (create once) */
add_action('init', 'cloudbeds_ensure_booking_product');
function cloudbeds_ensure_booking_product() {
    if (!function_exists('wc_get_product_id_by_sku')) return;
    $sku = 'cloudbeds-booking-placeholder';
    $product_id = wc_get_product_id_by_sku($sku);
    if ($product_id) {
        update_option('woocommerce_placeholder_product_id', $product_id);
        return;
    }
    // create product
    $product = new WC_Product_Simple();
    $product->set_name('Cloudbeds Booking');
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_regular_price(0);
    $product->set_price(0);
    $product->set_sku($sku);
    $pid = $product->save();
    if ($pid) update_option('woocommerce_placeholder_product_id', $pid);
}

/** On checkout page load, add placeholder product with booking meta (if session exists)
 *  Run very early so it happens before any empty-cart redirect handlers.
 */
add_action('template_redirect', 'cloudbeds_maybe_add_booking_to_cart', 1);
function cloudbeds_maybe_add_booking_to_cart() {
    if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url('order-received')) return;
    if (!function_exists('WC') || !WC()->session) return;

    $booking = WC()->session->get('cloudbeds_booking');
    $already = WC()->session->get('cloudbeds_booking_added');
    if (empty($booking) || $already) return;

    $product_id = get_option('woocommerce_placeholder_product_id');
    if (!$product_id) {
        error_log("CloudBeds: Placeholder product ID not found");
        return;
    }
    
    error_log("CloudBeds: Using placeholder product ID: " . $product_id);

    // Empty cart to ensure only booking exists
    WC()->cart->empty_cart();

    // Handle multiple rooms - split by semicolon and create separate line items
    $room_types = array_filter(array_map('trim', explode(';', $booking['room_type'])));
    $room_names = !empty($booking['room_type_name']) ? array_filter(array_map('trim', explode(',', $booking['room_type_name']))) : [];
    $total_price = floatval($booking['price']);
    $adults_per_room = intval($booking['adults']);
    $children_per_room = intval($booking['children']);
    
    // Determine per-room prices. Prefer explicit map from frontend; fallback to equal split.
    $price_per_room = null;
    if (is_array($booking['room_prices']) && !empty($booking['room_prices'])) {
        // normalize keys to strings
        $normalized_map = [];
        foreach ($booking['room_prices'] as $rk => $rv) { $normalized_map[(string)$rk] = floatval($rv); }
    } else {
        $normalized_map = [];
        $price_per_room = count($room_types) > 0 ? $total_price / count($room_types) : $total_price;
    }
    
    foreach ($room_types as $index => $room_type) {
        $room_name = isset($room_names[$index]) ? trim($room_names[$index]) : '';
        
        // Create individual booking data for this room
        $individual_booking = $booking;
        $individual_booking['room_type'] = $room_type;
        $individual_booking['room_type_name'] = $room_name;
        // Assign price: use mapped value if provided for this room code; else fallback to average
        $room_code = (string)$room_type;
        $room_price = isset($normalized_map[$room_code]) ? floatval($normalized_map[$room_code]) : $price_per_room;
        $individual_booking['price'] = $room_price;
        // Attach selected plan (promo/base) per room if provided
        if (!empty($booking['room_plans']) && isset($booking['room_plans'][$room_code])) {
            $individual_booking['rate_plan'] = $booking['room_plans'][$room_code];
        }
        if (!empty($booking['room_plan_labels']) && isset($booking['room_plan_labels'][$room_code])) {
            $individual_booking['rate_plan_label'] = $booking['room_plan_labels'][$room_code];
        }
        
        // No tax calculations - let WooCommerce handle taxes naturally
        
        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, array(), array(
            'cloudbeds_booking' => $individual_booking,
            'cloudbeds_room_index' => $index // Unique identifier for each room
        ));
        
        error_log("CloudBeds: Cart item key for room {$room_type}: " . ($cart_item_key ?: 'failed'));

        // Force price for this specific cart item
        if ($cart_item_key) {
            WC()->session->set('cloudbeds_cart_price_' . $cart_item_key, $room_price);
            error_log("CloudBeds: Stored price " . $room_price . " for cart key " . $cart_item_key);
        }
    }

    WC()->session->set('cloudbeds_booking_added', true);
}

/** Set the correct price for CloudBeds booking items */
add_filter('woocommerce_before_calculate_totals', 'cloudbeds_set_cart_item_price', 10, 1);
function cloudbeds_set_cart_item_price($cart) {
    if (!function_exists('WC') || !WC()->session) return;
    
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (!empty($cart_item['cloudbeds_booking'])) {
            $stored_price = WC()->session->get('cloudbeds_cart_price_' . $cart_item_key);
            if ($stored_price !== null) {
                $price = floatval($stored_price);
                $cart_item['data']->set_price($price);
                error_log("CloudBeds Cart Price Set - Key: " . $cart_item_key . ", Price: " . $price);
            } else {
                error_log("CloudBeds Cart Price Missing - Key: " . $cart_item_key);
            }
        }
    }
}

/** Display booking data on checkout line item */
add_filter('woocommerce_get_item_data', 'cloudbeds_show_booking_on_checkout', 10, 2);
function cloudbeds_show_booking_on_checkout($item_data, $cart_item) {
    if (!empty($cart_item['cloudbeds_booking'])) {
        $b = $cart_item['cloudbeds_booking'];
        $guests = intval($b['adults']) + intval($b['children']);

        // On checkout, the child theme template renders dates and Remove link.
        if (function_exists('is_checkout') && is_checkout()) {
            // Show chosen rate plan under the item name
            if (!empty($b['rate_plan_label'])) {
                $item_data[] = array('name' => 'Plan', 'value' => $b['rate_plan_label'], 'display' => esc_html($b['rate_plan_label']));
            } elseif (!empty($b['rate_plan'])) {
                $label = ($b['rate_plan'] === 'promo') ? 'Discounted Rate' : 'Standard Rate';
                $item_data[] = array('name' => 'Plan', 'value' => $label, 'display' => esc_html($label));
            }
            return $item_data;
        }

        // Non-checkout contexts: show a simple Guest meta only.
        $item_data[] = array('name' => 'Guest', 'value' => $guests, 'display' => esc_html($guests));
        
        // Don't show individual taxes and fees per room - only show in cart totals
    }
    return $item_data;
}
/** Replace cart/checkout line item title with Cloudbeds roomTypeName */
add_filter('woocommerce_cart_item_name', 'cloudbeds_cart_item_title', 10, 3);
function cloudbeds_cart_item_title($title, $cart_item, $cart_item_key){
    if (!empty($cart_item['cloudbeds_booking'])) {
        $b = $cart_item['cloudbeds_booking'];
        if (!empty($b['room_type_name'])) {
            // Return the room name and append a remove button that frontend JS will handle on cart/checkout pages
            $room_name = esc_html($b['room_type_name']);
            // Only show room name; the Remove link is rendered in item meta next to Guest count
            return $room_name;
        }
    }
    return $title;
}

// Removed tax display function - WooCommerce handles taxes naturally

/** Store booking data into order line item on checkout */
add_action('woocommerce_checkout_create_order_line_item', 'cloudbeds_save_booking_order_meta', 10, 4);
function cloudbeds_save_booking_order_meta($item, $cart_item_key, $values, $order) {
    if (!empty($values['cloudbeds_booking'])) {
        $b = $values['cloudbeds_booking'];
        // Store only Guest meta for cleaner checkout display
        $guests = intval($b['adults']) + intval($b['children']);
        $item->add_meta_data('Guest', $guests, true);
        // Store full booking payload on the line item so each room keeps its own room_type/adults/children
        $item->add_meta_data('_cloudbeds_booking_item', $b, true);
        if (!empty($b['rate_plan_label'])) {
            $item->add_meta_data('Plan', $b['rate_plan_label'], true);
        } elseif (!empty($b['rate_plan'])) {
            $item->add_meta_data('Plan', $b['rate_plan'] === 'promo' ? 'Discounted Rate' : 'Standard Rate', true);
        }
        
        // Also store the complete booking data in order meta for the WooCommerce integration
        $order->update_meta_data('_cloudbeds_booking_data', $b);
        error_log("CloudBeds: Stored booking data in order meta for order #" . $order->get_id());
    }
}

/** Ensure order meta is saved after checkout */
add_action('woocommerce_checkout_order_processed', 'cloudbeds_save_order_meta', 10, 3);
function cloudbeds_save_order_meta($order_id, $posted_data, $order) {
    // This ensures the order meta is saved after checkout
    if ($order && $order->get_meta('_cloudbeds_booking_data')) {
        $order->save();
        error_log("CloudBeds: Order #{$order_id} saved with booking data");
    }
}

/** Helper function to get room type ID from short name using the same logic as AJAX handler */
function cloudbeds_get_room_type_id_from_short_name($room_type_short) {
    $property_id = get_option('cloudbeds_property_id');
    if (!$property_id) {
        error_log("CloudBeds: Property ID missing for room type mapping");
        return null;
    }

    error_log("CloudBeds: Looking for room type ID for short name: {$room_type_short}");

    // First try getRoomTypes endpoint (returns ALL room types regardless of availability)
    $response = cloudbeds_http_get('/getRoomTypes', [
        'propertyIDs' => $property_id
    ]);
    
    error_log("CloudBeds: getRoomTypes API response: " . json_encode($response));

    if (!is_wp_error($response) && !empty($response['data'])) {
        error_log("CloudBeds: Found " . count($response['data']) . " room types in getRoomTypes");
        
        foreach ($response['data'] as $rt) {
            $roomTypeShort = esc_attr($rt['roomTypeNameShort'] ?? '');
            error_log("CloudBeds: Checking room: '{$roomTypeShort}' vs '{$room_type_short}'");
            
            if ($roomTypeShort === $room_type_short) {
                $roomTypeID = isset($rt['roomTypeID']) ? (string)$rt['roomTypeID'] : '';
                error_log("CloudBeds: Found room type ID {$roomTypeID} for short name {$room_type_short}");
                return $roomTypeID;
            }
        }
    } else {
        error_log("CloudBeds: getRoomTypes failed or empty, trying getAvailableRoomTypes as fallback");
        
        // Fallback to getAvailableRoomTypes (same as AJAX handler)
        $response = cloudbeds_http_get('/getAvailableRoomTypes', [
            'propertyIDs' => $property_id,
            'startDate' => date('Y-m-d'),
            'endDate' => date('Y-m-d', strtotime('+1 day'))
        ]);
        
        error_log("CloudBeds: getAvailableRoomTypes API response: " . json_encode($response));

        if (!is_wp_error($response) && !empty($response['data'])) {
            error_log("CloudBeds: Found " . count($response['data']) . " properties in getAvailableRoomTypes");
            
            foreach ($response['data'] as $property) {
                if (empty($property['propertyRooms'])) continue;
                error_log("CloudBeds: Found " . count($property['propertyRooms']) . " rooms in property");
                foreach ($property['propertyRooms'] as $rt) {
                    $roomTypeShort = esc_attr($rt['roomTypeNameShort'] ?? '');
                    error_log("CloudBeds: Checking room: '{$roomTypeShort}' vs '{$room_type_short}'");
                    
                    if ($roomTypeShort === $room_type_short) {
                        $roomTypeID = isset($rt['roomTypeID']) ? (string)$rt['roomTypeID'] : '';
                        error_log("CloudBeds: Found room type ID {$roomTypeID} for short name {$room_type_short}");
                        return $roomTypeID;
                    }
                }
            }
        }
    }

    error_log("CloudBeds: Room type ID not found for short name: {$room_type_short}");
    
    // Fallback: Try configured mappings from admin settings
    $configured_mappings = get_option('cloudbeds_room_type_mappings', '');
    if (!empty($configured_mappings)) {
        error_log("CloudBeds: Trying configured mappings: " . $configured_mappings);
        $lines = explode("\n", $configured_mappings);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '=') === false) continue;
            
            list($short_name, $room_id) = explode('=', $line, 2);
            $short_name = trim($short_name);
            $room_id = trim($room_id);
            
            error_log("CloudBeds: Checking configured mapping: '{$short_name}' vs '{$room_type_short}'");
            if ($short_name === $room_type_short && !empty($room_id)) {
                error_log("CloudBeds: Using configured fallback mapping: {$short_name} => {$room_id}");
                return $room_id;
            }
        }
    } else {
        error_log("CloudBeds: No configured mappings found in admin settings");
    }
    
    // Final hardcoded fallback for known working room types
    $hardcoded_fallbacks = [
        'RM7' => '116008102105282', // Updated with correct ID from API response
        'RM8' => '116025179291849', // Added RM8 with correct ID from API response
        'RM9' => '116025401716958', // Updated with correct ID from API response
    ];
    
    if (isset($hardcoded_fallbacks[$room_type_short])) {
        error_log("CloudBeds: Using hardcoded fallback: {$room_type_short} => {$hardcoded_fallbacks[$room_type_short]}");
        return $hardcoded_fallbacks[$room_type_short];
    }
    
    error_log("CloudBeds: No fallback found for room type: {$room_type_short}");
    return null;
}
