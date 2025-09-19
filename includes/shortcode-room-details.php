<?php
if (!defined('ABSPATH')) exit;

add_shortcode('cloudbeds_room_booking', function () {
    $checkin   = isset($_GET['checkin']) ? sanitize_text_field($_GET['checkin']) : '';
    $checkout  = isset($_GET['checkout']) ? sanitize_text_field($_GET['checkout']) : '';
    $adults    = isset($_GET['adults']) ? (int)$_GET['adults'] : 2;
    $kids      = isset($_GET['kids']) ? (int)$_GET['kids'] : 0;
    $promo     = isset($_GET['promo']) ? sanitize_text_field($_GET['promo']) : '';
    $max_adults = get_post_meta(get_the_ID(), 'max_adults', true);
    $max_children = get_post_meta(get_the_ID(), 'max_children', true);
    $room_id = get_post_meta(get_the_ID(), 'room_id', true);
    
    // Get room type short name from URL parameters or try to find it
    $room_type_short = '';
    if (isset($_GET['room_type'])) {
        $room_type_short = sanitize_text_field($_GET['room_type']);
    } else {
        // Try to get room type short name from the room_id
        $room_type_short = cloudbeds_get_room_type_short_from_id($room_id);
    }

    ob_start(); ?>
    <div class="wp-cloudbeds-search-box">
        <form id="wp-cloudbeds-room-form2" data-room-id="<?php echo esc_attr($room_id); ?>"
              data-room-type="<?php echo esc_attr($room_type_short); ?>"
              data-max-adults="<?php echo esc_attr($max_adults); ?>"
              data-max-children="<?php echo esc_attr($max_children); ?>">
            <div class="wp-cloudbeds-field">
                <label>CHECK-IN DATE</label>
                <div class="wp-cloudbeds-date-wrapper">
                    <img src="<?php echo CLOUD_BEDS_PLUGIN_URL; ?>assets/img/cal-icon.svg" alt="Calendar" class="wp-cloudbeds-date-icon">
                    <input type="text" name="checkin" class="wp-cloudbeds-date"
                        value="<?php echo esc_attr($checkin); ?>" placeholder="Select date" required>
                </div>
            </div>
            <div class="wp-cloudbeds-field">
                <label>CHECK-OUT DATE</label>
                <div class="wp-cloudbeds-date-wrapper">
                    <img src="<?php echo CLOUD_BEDS_PLUGIN_URL; ?>assets/img/cal-icon.svg" alt="Calendar" class="wp-cloudbeds-date-icon">
                    <input type="text" name="checkout" class="wp-cloudbeds-date"
                        value="<?php echo esc_attr($checkout); ?>" placeholder="Select date" required>
                </div>
            </div>
            <div class="wp-cloudbeds-field">
                <label>ADULTS</label>
                <div class="input-spinner">
                    <input type="number" name="adults" value="<?php echo esc_attr($adults); ?>" min="1" max="<?php echo esc_attr($max_adults ?: 5); ?>" required>
                    <button type="button" class="btn-up">+</button>
                    <button type="button" class="btn-down">−</button>
                </div>
            </div>
            <?php if (intval($max_children) > 0) : ?>
            <div class="wp-cloudbeds-field" id="cb-children-field">
                <label>CHILDREN</label>
                <div class="input-spinner">
                    <input type="number" name="kids" value="<?php echo esc_attr($kids); ?>" min="0" max="<?php echo esc_attr($max_children ?: 5); ?>">
                    <button type="button" class="btn-up">+</button>
                    <button type="button" class="btn-down">−</button>
                </div>
            </div>
            <?php endif; ?>
            <?php if ((int) get_option('cloudbeds_enable_promo_field', 1) === 1): ?>
            <div class="wp-cloudbeds-field">
                <label>PROMO CODE</label>
                <div class="wp-cloudbeds-date-wrapper">
                    <input type="text" name="promo" class="wp-cloudbeds-date" value="<?php echo esc_attr($promo); ?>" placeholder="Enter promo code">
                </div>
            </div>
            <?php endif; ?>
            <input type="hidden" name="room_id" value="<?php echo esc_attr($room_id); ?>">
            <button type="submit" class="wp-cloudbeds-check-btn">Book Now</button>
        </form>
        <div id="cloudbeds-room-message" style="margin-top:20px;"></div>
        <div id="cloudbeds-plan-options" style="margin-top:16px;"></div>
    </div>
    <script>
    (function($){
        // Clear stale plan options whenever dates change
        $(document).on('change', '#wp-cloudbeds-room-form2 input[name="checkin"], #wp-cloudbeds-room-form2 input[name="checkout"]', function(){
            var form = $('#wp-cloudbeds-room-form2');
            form.data('plans-ready', 0);
            $('#cloudbeds-plan-options').empty();
            form.find('button[type="submit"]').text('Book Now');
        });

        $('#wp-cloudbeds-room-form2').on('submit', function(e){
            e.preventDefault();
            var form = $(this);
            // Basic required validation like homepage
            var isValid = true;
            form.find('input[required]').each(function(){
                var $field = $(this);
                var $wrapper = $field.closest('.wp-cloudbeds-field');
                var $error = $wrapper.find('.cloudbeds-error-text');
                if(!$field.val().trim()){
                    $field.addClass('cloudbeds-error-border');
                    if($error.length === 0){ $wrapper.append('<small class="cloudbeds-error-text">Required</small>'); }
                    else { $error.show(); }
                    isValid = false;
                } else {
                    $field.removeClass('cloudbeds-error-border');
                    if($error.length > 0){ $error.hide(); }
                }
            });
            if(!isValid){ return false; }
            var adults = parseInt(form.find('input[name="adults"]').val());
            var kidsInput = form.find('input[name="kids"]');
            var kids = kidsInput.length ? parseInt(kidsInput.val()) : 0;
            var maxAdults = parseInt(form.data('max-adults'));
            var maxChildren = parseInt(form.data('max-children')) || 0;
            if(adults > maxAdults) {
                $('#cloudbeds-room-message').html('<span style="color:red;">Number of adults cannot exceed ' + maxAdults + '.</span>');
                return false;
            }
            if(kidsInput.length && kids > maxChildren) {
                $('#cloudbeds-room-message').html('<span style="color:red;">Number of children cannot exceed ' + maxChildren + '.</span>');
                return false;
            }
            
            var $btn = form.find('button[type="submit"]');
            var $status = $('#cloudbeds-room-message');
            if(!$status.length){
                $status = $('<div id="cloudbeds-room-message" style="margin-top:20px;"></div>');
                $btn.after($status);
            }
            
            // If plans already rendered, proceed to checkout with selected plan
            if (form.data('plans-ready') === 1) {
                var selected = $('input[name="cb_plan_choice"]:checked');
                if (!selected.length) {
                    $('#cloudbeds-room-message').html('<span style="color:red;">Please select a rate plan.</span>').show();
                    return false;
                }
                var chosenPrice = parseFloat((selected.data('price') || '0').toString()) || 0;
                var chosenPlan = (selected.val() || 'base');
                var chosenLabel = (selected.data('label') || '').toString() || (chosenPlan === 'promo' ? 'Discounted Rate' : 'Standard Rate');
                if (chosenPrice <= 0) {
                    $('#cloudbeds-room-message').html('<span style="color:red;">Invalid plan price.</span>').show();
                    return false;
                }
                $btn.prop('disabled', true);
                $status.html('<div class="loading-text"><div class="spinner-loader"></div>Proceeding to checkout</div>').show();
                var payload2 = {
                    action: 'cloudbeds_set_booking',
                    room_type: form.data('room-type'),
                    checkin: form.find('input[name="checkin"]').val(),
                    checkout: form.find('input[name="checkout"]').val(),
                    adults: adults,
                    children: kids,
                    price: chosenPrice,
                    room_prices: JSON.stringify((function(){ var o={}; o[String(form.data('room-type'))]=chosenPrice; return o; })()),
                    room_plans: JSON.stringify((function(){ var o={}; o[String(form.data('room-type'))]=chosenPlan; return o; })()),
                    room_plan_labels: JSON.stringify((function(){ var o={}; o[String(form.data('room-type'))]=chosenLabel; return o; })())
                };
                $.post(cloudbeds_ajax_obj.ajax_url, payload2, function(bookingResp){
                    if (bookingResp && bookingResp.success) {
                        $status.hide().html('');
                        try { localStorage.setItem('cb_last_search_url', window.location.href); } catch(e){}
                        window.location = bookingResp.data.checkout || cloudbeds_ajax_obj.checkout_url;
                    } else {
                        $status.html('<span style="color:red;">Could not start checkout. Please try again.</span>').show();
                        $btn.prop('disabled', false);
                    }
                });
                return false;
            }
            
            // First step: fetch availability and render plan options for this room
            $btn.prop('disabled', true);
            $status.html('<div class="loading-text"><div class="spinner-loader"></div>We are fetching the room rate plans</div>').show();
            $.ajax({
                url: cloudbeds_ajax_obj.ajax_url,
                method: 'POST',
                data: {
                    action: 'get_cloudbeds_rooms',
                    startDate: form.find('input[name="checkin"]').val(),
                    endDate: form.find('input[name="checkout"]').val(),
                    adults: adults,
                    kids: kids,
                    promo: form.find('input[name="promo"]').val() || ''
                },
                success: function(resp) {
                    if(resp.success && resp.data) {
                        var tempDiv = $('<div>').html(resp.data);
                        var roomRow = tempDiv.find('[data-room-short="' + form.data('room-type') + '"]');
                        if (roomRow.length > 0) {
                            var promoRow = roomRow.find('.cb-plan-row.cb-plan-promo');
                            var baseRow  = roomRow.find('.cb-plan-row.cb-plan-base');
                            var optsHtml = '';
                            if (promoRow.length) {
                                var pText = promoRow.find('.cb-plan-price').text().replace(/[^\d.]/g, '');
                                var pPrice = parseFloat(pText) || 0;
                                var pLbl = (promoRow.find('.cb-plan-left').text() || '').trim() || 'Discounted Rate';
                                if (pPrice > 0) {
                                    optsHtml += '<div class="cb-plan-row cb-plan-promo">' +
                                      '<div class="cb-plan-left">' + pLbl + '</div>' +
                                      '<div class="cb-plan-right">' +
                                        '<label class="cb-plan-radio-label">' +
                                          '<input type="radio" name="cb_plan_choice" value="promo" data-price="' + pPrice + '" data-label="' + pLbl.replace(/"/g,'&quot;') + '" checked>' +
                                          '<span class="cb-radio-custom"></span>' +
                                        '</label>' +
                                        '<div class="cb-plan-price">' + (cloudbeds_ajax_obj.currency || '') + pPrice.toFixed(2) + '</div>' +
                                      '</div>' +
                                    '</div>';
                                }
                            }
                            if (baseRow.length) {
                                var bText = baseRow.find('.cb-plan-price').text().replace(/[^\d.]/g, '');
                                var bPrice = parseFloat(bText) || 0;
                                var bLbl = (baseRow.find('.cb-plan-left').text() || '').trim() || 'Standard Rate';
                                if (bPrice > 0) {
                                    var checked = optsHtml ? '' : ' checked';
                                    optsHtml += '<div class="cb-plan-row cb-plan-base">' +
                                      '<div class="cb-plan-left">' + bLbl + '</div>' +
                                      '<div class="cb-plan-right">' +
                                        '<label class="cb-plan-radio-label">' +
                                          '<input type="radio" name="cb_plan_choice" value="base" data-price="' + bPrice + '" data-label="' + bLbl.replace(/"/g,'&quot;') + '"' + checked + '>' +
                                          '<span class="cb-radio-custom"></span>' +
                                        '</label>' +
                                        '<div class="cb-plan-price">' + (cloudbeds_ajax_obj.currency || '') + bPrice.toFixed(2) + '</div>' +
                                      '</div>' +
                                    '</div>';
                                }
                            }
                            if (optsHtml) {
                                $('#cloudbeds-plan-options').html('<div class="cb-single-plan-choices">' +
                                  '<div style="margin-bottom:8px;"><strong>Select your rate:</strong></div>' + optsHtml + '</div>');
                                // Restore previously chosen plan for this room (if any)
                                try {
                                  var storageKey = 'cb_single_plan_' + String(form.data('room-type')) + '|' + String(form.find('input[name="checkin"]').val()) + '|' + String(form.find('input[name="checkout"]').val());
                                  var prev = localStorage.getItem(storageKey);
                                  if (prev === 'promo') {
                                    $('input[name="cb_plan_choice"][value="promo"]').prop('checked', true);
                                  } else if (prev === 'base') {
                                    $('input[name="cb_plan_choice"][value="base"]').prop('checked', true);
                                  }
                                  // Persist on change
                                  $('input[name="cb_plan_choice"]').off('change.cb_single').on('change.cb_single', function(){
                                    var val = $('input[name="cb_plan_choice"]:checked').val() || '';
                                    try { localStorage.setItem(storageKey, val); } catch(e){}
                                  });
                                } catch(e){}
                                form.data('plans-ready', 1);
                                $btn.text('Continue to Checkout');
                                $status.hide().html('');
                                $btn.prop('disabled', false);
                                return;
                            }
                        }
                        $status.html('<span style="color:red;">Room is not available for selected dates.</span>').show();
                        $btn.prop('disabled', false);
                    } else {
                        $status.html('<span style="color:red;">Room is not available for selected dates.</span>').show();
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    $status.html('<span style="color:red;">Something went wrong. Please try again.</span>');
                    $btn.prop('disabled', false);
                }
            });
        });
    })(jQuery);
    </script>
    <script>
    (function($){
      // Auto-open price calendar on single room page
      $(function(){
        var $in = $('#wp-cloudbeds-room-form2 input[name="checkin"]');
        if($in.length && $in[0]._flatpickr){
          setTimeout(function(){ 
            try{ 
              $in[0]._flatpickr.open(); 
              // Force calendar colors after opening
              forceCalendarColors($in[0]._flatpickr);
            }catch(e){} 
          }, 400);
        }
      });

      // Force calendar colors to override Flatpickr defaults
      function forceCalendarColors(instance) {
          if (!instance || !instance.calendarContainer) return;
          
          setTimeout(() => {
              const days = instance.calendarContainer.querySelectorAll('.flatpickr-day');
              days.forEach(day => {
                  if (day.classList.contains('selected') || 
                      day.classList.contains('startRange') || 
                      day.classList.contains('endRange') ||
                      day.classList.contains('inRange')) {
                      day.style.setProperty('background-color', '#d0ab17', 'important');
                      day.style.setProperty('border-color', '#d0ab17', 'important');
                      day.style.setProperty('color', '#fff', 'important');
                  } else if (day.classList.contains('inRange')) {
                      day.style.setProperty('background-color', 'rgba(208, 171, 23, 0.2)', 'important');
                      day.style.setProperty('border-color', 'rgba(208, 171, 23, 0.3)', 'important');
                      day.style.setProperty('color', '#0F1925', 'important');
                  }
              });
          }, 100);
      }
    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
});
