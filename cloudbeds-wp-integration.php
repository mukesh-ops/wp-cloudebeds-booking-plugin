<?php
/*
Plugin Name: Cloudbeds WP Integration
Description: Fetches available rooms, shows a price calendar, and redirects to Cloudbeds booking engine.
Version: 5.3.2
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

define('CLOUD_BEDS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CLOUD_BEDS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CLOUD_BEDS_API_URL', 'https://api.cloudbeds.com/api/v1.3');

// Includes
require_once CLOUD_BEDS_PLUGIN_PATH . 'includes/admin-settings.php';
require_once CLOUD_BEDS_PLUGIN_PATH . 'includes/woocommerce-integration.php';
require_once CLOUD_BEDS_PLUGIN_PATH . 'includes/admin-shortcodes-page.php';
require_once CLOUD_BEDS_PLUGIN_PATH . 'includes/shortcode.php';
require_once CLOUD_BEDS_PLUGIN_PATH . 'includes/shortcode-room-details.php';
require_once CLOUD_BEDS_PLUGIN_PATH . 'includes/ajax-handler.php';

add_action('wp_enqueue_scripts', function () {
    // Flatpickr core + rangePlugin
    wp_enqueue_style('cloudbeds-flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', [], null);
    wp_enqueue_script('cloudbeds-flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr', [], null, true);
    wp_enqueue_script('cloudbeds-flatpickr-range', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/rangePlugin.js', ['cloudbeds-flatpickr-js'], null, true);

    // Styles (v5.3.2)
    wp_enqueue_style('cloudbeds-frontend-style', CLOUD_BEDS_PLUGIN_URL . 'assets/css/frontend.css', [], '5.3.2');
    wp_enqueue_style(
        'font-awesome-6','https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',array(), '6.4.0');
    // Frontend logic (v5.3.2)
    wp_enqueue_script('cloudbeds-frontend', CLOUD_BEDS_PLUGIN_URL . 'assets/js/frontend.js', ['jquery', 'cloudbeds-flatpickr-js', 'cloudbeds-flatpickr-range'], '5.3.2', true);

    // Build Booking Engine base from settings
    $unique_code = get_option('cloudbeds_unique_url_code');
    $booking_base = $unique_code ? "https://us2.cloudbeds.com/en/reservation/{$unique_code}/" : '';

    wp_localize_script('cloudbeds-frontend', 'cloudbeds_ajax_obj', [
        'ajax_url'     => admin_url('admin-ajax.php'),
        'currency'     => '£',
        'checkout_url' => wc_get_checkout_url(),
        'rooms_url'    => home_url('/')
    ]);
});

/**
 * Enqueue small checkout CSS to show remove icon for Cloudbeds items
 */
add_action('wp_enqueue_scripts', function(){
    // Inline style is small and safe; ensure it loads after main stylesheet
    $css = "
    .cloudbeds-remove-item{background:transparent;border:none;color:#c0392b;cursor:pointer;margin-left:8px}
    .cloudbeds-remove-item:disabled{opacity:.6;cursor:default}
    .woocommerce td.product-name dl.variation, .woocommerce td.product-name .variation{margin:6px 0 0 0}
    .woocommerce td.product-name .cloudbeds-remove-item-link{margin-left:10px;color:#c0392b;text-decoration:none;font-size:0.95em}
    .woocommerce td.product-name .cloudbeds-remove-item-link:hover{text-decoration:underline}
    ";
    wp_add_inline_style('cloudbeds-frontend-style', $css);
});

/**
 * Override WooCommerce Thank You template with Cloudbeds custom template
 */
add_filter('woocommerce_locate_template', function($template, $template_name, $template_path){
    // Thank you override
    if ($template_name === 'checkout/thankyou.php') {
        $custom = trailingslashit(plugin_dir_path(__FILE__)) . 'templates/checkout/thankyou-custom.php';
        if (file_exists($custom)) {
            return $custom;
        }
    }
    // Review order override
    if ($template_name === 'checkout/review-order.php') {
        $custom_review = trailingslashit(plugin_dir_path(__FILE__)) . 'templates/checkout/review-order.php';
        if (file_exists($custom_review)) {
            return $custom_review;
        }
    }
    return $template;
}, 10, 3);

// Manual test endpoint for reservation creation (remove in production)
add_action('wp_ajax_test_cloudbeds_reservation', 'test_cloudbeds_reservation');
add_action('wp_ajax_nopriv_test_cloudbeds_reservation', 'test_cloudbeds_reservation');
function test_cloudbeds_reservation() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if (!$order_id) {
        wp_die('Order ID required');
    }
    
    if (class_exists('Cloudbeds_Woo_Integration')) {
        Cloudbeds_Woo_Integration::instance()->manual_trigger_reservation($order_id);
        echo "Manual trigger executed for order #{$order_id}. Check logs for details.";
    } else {
        echo "Cloudbeds_Woo_Integration class not found.";
    }
    wp_die();
}

// Test endpoint to discover room types (remove in production)
add_action('wp_ajax_test_cloudbeds_room_types', 'test_cloudbeds_room_types');
add_action('wp_ajax_nopriv_test_cloudbeds_room_types', 'test_cloudbeds_room_types');
function test_cloudbeds_room_types() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $property_id = get_option('cloudbeds_property_id');
    if (!$property_id) {
        wp_die('Property ID not configured');
    }
    
    echo "<h2>Testing CloudBeds Room Types for Property: {$property_id}</h2>";
    
    // Test getAvailableRoomTypes
    echo "<h3>1. Testing getAvailableRoomTypes endpoint:</h3>";
    $response = cloudbeds_http_get('/getAvailableRoomTypes', [
        'propertyIDs' => $property_id,
        'startDate' => date('Y-m-d'),
        'endDate' => date('Y-m-d', strtotime('+1 day'))
    ]);
    
    if (is_wp_error($response)) {
        echo "<p style='color:red;'>Error: " . $response->get_error_message() . "</p>";
    } else {
        echo "<pre>" . json_encode($response, JSON_PRETTY_PRINT) . "</pre>";
        
        // Extract and display room type mappings
        echo "<h3>2. Available Room Type Mappings:</h3>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Short Name</th><th>Room Type ID</th><th>Full Name</th></tr>";
        
        if (!empty($response['data'])) {
            foreach ($response['data'] as $property) {
                if (!empty($property['propertyRooms'])) {
                    foreach ($property['propertyRooms'] as $rt) {
                        $short_name = $rt['roomTypeNameShort'] ?? 'N/A';
                        $room_id = $rt['roomTypeID'] ?? 'N/A';
                        $full_name = $rt['roomTypeName'] ?? 'N/A';
                        echo "<tr><td>{$short_name}</td><td>{$room_id}</td><td>{$full_name}</td></tr>";
                    }
                }
            }
        }
        echo "</table>";
    }
    
    // Test getRoomTypes
    echo "<h3>3. Testing getRoomTypes endpoint:</h3>";
    $response2 = cloudbeds_http_get('/getRoomTypes', [
        'propertyIDs' => $property_id
    ]);
    
    if (is_wp_error($response2)) {
        echo "<p style='color:red;'>Error: " . $response2->get_error_message() . "</p>";
    } else {
        echo "<pre>" . json_encode($response2, JSON_PRETTY_PRINT) . "</pre>";
    }
    
    wp_die();
}

// Test endpoint to test reservation creation with minimal parameters (remove in production)
add_action('wp_ajax_test_cloudbeds_reservation_create', 'test_cloudbeds_reservation_create');
add_action('wp_ajax_nopriv_test_cloudbeds_reservation_create', 'test_cloudbeds_reservation_create');
function test_cloudbeds_reservation_create() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $property_id = get_option('cloudbeds_property_id');
    if (!$property_id) {
        wp_die('Property ID not configured');
    }
    
    echo "<h2>Testing CloudBeds Reservation Creation</h2>";
    
    // Test with minimal required parameters - using exact structure from working code
    $test_data = [
        'startDate' => date('Y-m-d', strtotime('+1 day')),
        'endDate' => date('Y-m-d', strtotime('+2 days')),
        'guestFirstName' => 'Test',
        'guestLastName' => 'User',
        'guestEmail' => 'test@example.com',
        'guestCountry' => 'GB',
        'sendEmailConfirmation' => true,
        'thirdPartyIdentifier' => 'TEST-' . time(),
        'paymentMethod' => 'pay_pal', // Test with PayPal payment method
        'rooms' => [
            [
                'roomTypeID' => '116008102105282', // RM7 ID
                'quantity' => 1
            ]
        ],
        'adults' => [
            [
                'roomTypeID' => '116008102105282', // RM7 ID
                'quantity' => 1
            ]
        ],
        'children' => [
            [
                'roomTypeID' => '116008102105282', // RM7 ID
                'quantity' => 0
            ]
        ]
    ];
    
    echo "<h3>Test Data:</h3>";
    echo "<pre>" . json_encode($test_data, JSON_PRETTY_PRINT) . "</pre>";
    
    echo "<h3>API Response:</h3>";
    $response = cloudbeds_http_post('/postReservation', $test_data);
    
    if (is_wp_error($response)) {
        echo "<p style='color:red;'>Error: " . $response->get_error_message() . "</p>";
    } else {
        echo "<pre>" . json_encode($response, JSON_PRETTY_PRINT) . "</pre>";
    }
    
    wp_die();
}

// Test endpoint to test room type mapping function (remove in production)
add_action('wp_ajax_test_cloudbeds_room_mapping', 'test_cloudbeds_room_mapping');
add_action('wp_ajax_nopriv_test_cloudbeds_room_mapping', 'test_cloudbeds_room_mapping');
function test_cloudbeds_room_mapping() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $room_type_short = isset($_GET['room_type']) ? sanitize_text_field($_GET['room_type']) : 'RM8';
    
    echo "<h2>Testing Room Type Mapping for: {$room_type_short}</h2>";
    
    if (function_exists('cloudbeds_get_room_type_id_from_short_name')) {
        $room_type_id = cloudbeds_get_room_type_id_from_short_name($room_type_short);
        
        if ($room_type_id) {
            echo "<p style='color:green;'>✅ SUCCESS: Found room type ID: {$room_type_id}</p>";
        } else {
            echo "<p style='color:red;'>❌ FAILED: Room type ID not found</p>";
        }
        
        echo "<h3>Check the error logs for detailed information</h3>";
        echo "<p>Look for logs starting with 'CloudBeds:' in your WordPress error log</p>";
        
    } else {
        echo "<p style='color:red;'>Function cloudbeds_get_room_type_id_from_short_name not found</p>";
    }
    
    wp_die();
}
