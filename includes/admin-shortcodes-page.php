<?php
if (!defined('ABSPATH')) exit;
function cloudbeds_shortcodes_admin_page() { ?>
    <div class="wrap">
        <h1>Cloudbeds WP Integration - Shortcodes</h1>
        <p>Copy and paste the following shortcodes into any page or post:</p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width: 200px;">Shortcode</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>[cloudbeds_rooms]</code></td>
                    <td>Displays the availability search form and lists available rooms with multi-room selection.</td>
                </tr>
                <tr>
                    <td><code>[cloudbeds_room_booking]</code></td>
                    <td>Displays a booking form on single room pages, pre-filled based on URL parameters.</td>
                </tr>
            </tbody>
        </table>
    </div>
<?php } ?>
