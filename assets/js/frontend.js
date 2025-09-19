jQuery(document).ready(function ($) {

	// ==== Lightweight animated popup replacement for alert() ====
	(function initCloudbedsPopup(){
		if (document.getElementById('cb-popup-style')) return;
		const style = document.createElement('style');
		style.id = 'cb-popup-style';
		style.textContent = "\n.cb-popup-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.35);display:none;align-items:center;justify-content:center;z-index:9999;}\n.cb-popup{max-width:420px;width:92%;background:#ffffff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.15);transform:translateY(12px) scale(0.98);opacity:0;transition:all .22s ease;overflow:hidden;}\n.cb-popup__header{padding:14px 18px;background:#0F1925;color:#fff;font-weight:600;display:flex;align-items:center;justify-content:space-between;}\n.cb-popup__title{margin:0;font-size:15px;color:#fff!important;}\n.cb-popup__close{background:transparent;border:0;color:#fff;font-size:18px;cursor:pointer;line-height:1;}\n.cb-popup__body{padding:18px;color:#0F1925;font-size:14px;}\n.cb-popup--show .cb-popup{transform:translateY(0) scale(1);opacity:1;}\n.cb-popup--show{display:flex;}\n.cb-popup--success .cb-popup__header{background:#1b7f4b;}\n.cb-popup--error .cb-popup__header{background:#a48712;}\n.cb-popup--info .cb-popup__header{background:#0F1925;}\n@media (prefers-reduced-motion: reduce){.cb-popup{transition:none}}\n";
		document.head.appendChild(style);
		const overlay = document.createElement('div');
		overlay.className = 'cb-popup-overlay';
		overlay.innerHTML = "<div class=\"cb-popup\" role=\"dialog\" aria-modal=\"true\" aria-live=\"polite\"><div class=\"cb-popup__header\"><h6 class=\"cb-popup__title\">Notice</h6><button type=\"button\" class=\"cb-popup__close\" aria-label=\"Close\">×</button></div><div class=\"cb-popup__body\"></div></div>";
		document.body.appendChild(overlay);
		function hide(){ overlay.classList.remove('cb-popup--show','cb-popup--success','cb-popup--error','cb-popup--info'); }
		function onEsc(e){ if(e.key === 'Escape'){ hide(); document.removeEventListener('keydown', onEsc, true); } }
		overlay.addEventListener('click', function(e){ if(e.target === overlay){ hide(); } });
		overlay.querySelector('.cb-popup__close').addEventListener('click', hide);
		window.cloudbedsNotify = function(message, type){
			try { if (typeof message !== 'string') message = String(message); } catch(e) {}
			const titleEl = overlay.querySelector('.cb-popup__title');
			const bodyEl = overlay.querySelector('.cb-popup__body');
			const popup = overlay.querySelector('.cb-popup');
			overlay.classList.remove('cb-popup--success','cb-popup--error','cb-popup--info');
			let mode = (type === 'success' || type === 'error' || type === 'info') ? type : 'info';
			overlay.classList.add('cb-popup--' + mode);
			titleEl.textContent = mode === 'error' ? 'Error' : (mode === 'success' ? 'Success' : 'Notice');
			bodyEl.textContent = message || '';
			overlay.classList.add('cb-popup--show');
			document.addEventListener('keydown', onEsc, true);
			setTimeout(function(){ popup.focus && popup.focus(); }, 0);
			// Auto-dismiss after 3.5s for info/success
			if (mode !== 'error') { setTimeout(hide, 3500); }
		};
	})();

    // ---- Availability list form ----
    $('#wp-cloudbeds-room-form').on('submit', function (e) {
        e.preventDefault();
        let isValid = true;
        $(this).find('input[required]').each(function () {
            const $field = $(this);
            const $wrapper = $field.closest('.wp-cloudbeds-field');
            const $error = $wrapper.find('.cloudbeds-error-text');
            if (!$field.val().trim()) {
                $field.addClass('cloudbeds-error-border');
                if ($error.length === 0) { $wrapper.append('<small class="cloudbeds-error-text">Required</small>'); }
                else { $error.show(); }
                isValid = false;
            } else {
                $field.removeClass('cloudbeds-error-border');
                if ($error.length > 0) $error.hide();
            }
        });
        if (!isValid) return false;

        let formData = {
            action: 'get_cloudbeds_rooms',
            startDate: $('input[name="startDate"]').val(),
            endDate: $('input[name="endDate"]').val(),
            adults: $('input[name="adults"]').val(),
            kids: $('input[name="kids"]').val(),
            promo: $('input[name="promo"]').val() || ''
        };
        $('#wp-cloudbeds-room-results').html('<div class="loading-text"><div class="spinner-loader"></div>Finding the best options for you...</div>');
        $.post(cloudbeds_ajax_obj.ajax_url, formData, function (response) {
            if (response.success) {
                $('#wp-cloudbeds-room-results').html(response.data);
                // re-wire after rendering
                setTimeout(wireResultsInteractions, 0);
            } else {
                $('#wp-cloudbeds-room-results').html('<p class="cloudbeds-error">' + response.data + '</p>');
            }
        });
    });

    // ---------- Price Calendar + Cache/Prefetch ----------
    const CB_MONTH_CACHE = {};
    function cacheKey(y,m,rid){ return y + "-" + ("0"+m).slice(-2) + "-" + (rid||"all"); }
    function fetchMonth(y,m,rid){
        const key = cacheKey(y,m,rid);
        if(CB_MONTH_CACHE[key]){
            return $.Deferred().resolve({success:true, data: CB_MONTH_CACHE[key]}).promise();
        }
        const payload = { action:'cloudbeds_get_month_prices', year:y, month:m };
        if (rid) payload.roomTypeID = rid;
        return $.post(cloudbeds_ajax_obj.ajax_url, payload)
            .then(function(resp){
                if(resp && resp.success && resp.data){ CB_MONTH_CACHE[key] = resp.data; }
                return resp;
            });
    }
    function nextMonth(year, month){ return (month === 12) ? {year:year+1, month:1} : {year:year, month:month+1}; }
    function prefetchTwoMonths(y,m,rid){ const n = nextMonth(y,m); fetchMonth(y,m,rid); fetchMonth(n.year, n.month, rid); }

    function mountLinkedPriceRange($startInput, $endInput, roomTypeID) {
        if(!$startInput.length || !$endInput.length) return;
        function showMonthsForViewport(){ return (window.innerWidth >= 768) ? 2 : 1; }

        const fp = $startInput.flatpickr({
            dateFormat: "Y-m-d",
            minDate: "today",
            allowInput: false,
            disableMobile: true,
            showMonths: showMonthsForViewport(),
            plugins: [ new rangePlugin({ input: $endInput[0] }) ],
            onReady: function(selectedDates, dateStr, instance){
                addCurrencyNote(instance);
                decorateFromCacheOrFetch(instance, roomTypeID);
                prefetchTwoMonths(instance.currentYear, instance.currentMonth + 1, roomTypeID);
                forceCalendarColors(instance);
                if(!instance._cb_resize){
                    instance._cb_resize = true;
                    window.addEventListener('resize', function(){
                        const desired = showMonthsForViewport();
                        if(instance.config.showMonths !== desired){
                            instance.set('showMonths', desired);
                            decorateFromCacheOrFetch(instance, roomTypeID);
                        }
                    });
                }
            },
            onMonthChange: function(sel, str, instance){
                decorateFromCacheOrFetch(instance, roomTypeID);
                prefetchTwoMonths(instance.currentYear, instance.currentMonth + 1, roomTypeID);
                forceCalendarColors(instance);
            },
            onYearChange: function(sel, str, instance){
                decorateFromCacheOrFetch(instance, roomTypeID);
                prefetchTwoMonths(instance.currentYear, instance.currentMonth + 1, roomTypeID);
                forceCalendarColors(instance);
            },
            onOpen: function(sel, str, instance){ 
                decorateFromCacheOrFetch(instance, roomTypeID); 
                forceCalendarColors(instance);
            },
            onValueUpdate: function(sel, str, instance){ 
                decorateFromCacheOrFetch(instance, roomTypeID); 
                forceCalendarColors(instance);
            }
        });
    }

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

    function addCurrencyNote(instance){
        const prev = instance.calendarContainer.querySelector('.cb-currency-note');
        if(!prev){
            const note = document.createElement('div');
            note.className = 'cb-currency-note';
            note.textContent = "Price in GBP";
            instance.calendarContainer.appendChild(note);
        }
    }

    function decorateFromCacheOrFetch(instance, roomTypeID){
        if(!instance) return;
        const y = instance.currentYear;
        const m = instance.currentMonth + 1;
        const n = nextMonth(y, m);

        const merged = Object.assign({}, CB_MONTH_CACHE[cacheKey(y,m,roomTypeID)] || {}, CB_MONTH_CACHE[cacheKey(n.year,n.month,roomTypeID)] || {});
        if(Object.keys(merged).length){ decorateDays(instance, merged); }

        $.when(fetchMonth(y,m,roomTypeID), fetchMonth(n.year, n.month, roomTypeID)).done(function(a,b){
            const res1 = a[0] || a, res2 = b[0] || b;
            const map = {};
            if(res1 && res1.success && res1.data) Object.assign(map, res1.data);
            if(res2 && res2.success && res2.data) Object.assign(map, res2.data);
            decorateDays(instance, map);
        });
    }

    function decorateDays(instance, map){
        if(!instance.daysContainer) return;
        const dayNodes = instance.daysContainer.querySelectorAll('.flatpickr-day');
        dayNodes.forEach(function(dayEl){
            const date = dayEl.dateObj ? instance.formatDate(dayEl.dateObj, "Y-m-d") : null;
            const old = dayEl.querySelector('.cb-price'); if(old) old.remove();
            dayEl.classList.remove('has-lowest','cb-unavailable','cb-unavailable-start');
            dayEl.removeAttribute('data-cb-avail');
            dayEl.removeAttribute('aria-disabled');
            dayEl.classList.remove('flatpickr-disabled');
            dayEl.removeAttribute('tabindex');

            if(!date || !map[date]) return;
            const info = map[date];
            const available = !!info.available;
            dayEl.dataset.cbAvail = available ? "1" : "0";

            if(available){ dayEl.classList.add('has-lowest'); }
            else {
                dayEl.classList.add('cb-unavailable');
                dayEl.classList.add('flatpickr-disabled');
                dayEl.setAttribute('aria-disabled','true');
                dayEl.setAttribute('tabindex','-1');
            }

            if(info.price !== null && typeof info.price !== 'undefined'){
                const span = document.createElement('span');
                span.className = 'cb-price';
                span.textContent = cloudbeds_ajax_obj.currency + (parseFloat(info.price).toFixed(0));
                dayEl.appendChild(span);
            }
        });

        // Guard: cannot start on unavailable day (but can end on it)
        if(!instance._cb_guard){
            instance._cb_guard = true;
            const intercept = function(e){
                const el = e.target.closest('.flatpickr-day');
                if(!el || !instance.daysContainer.contains(el)) return;
                const isStartSelection = (instance.selectedDates.length === 0);
                const avail = el.getAttribute('data-cb-avail');
                if(isStartSelection && avail === "0"){
                    e.preventDefault(); e.stopPropagation();
                    el.classList.add('cb-unavailable-start');
                    setTimeout(()=>el.classList.remove('cb-unavailable-start'), 250);
                }
            };
            instance.daysContainer.addEventListener('mousedown', intercept, true);
            instance.daysContainer.addEventListener('click', intercept, true);
            instance.daysContainer.addEventListener('keydown', function(e){ if(e.key === 'Enter' || e.key === ' '){ intercept(e); } }, true);
        }
    }

    // Init calendars
    mountLinkedPriceRange($('input[name="startDate"]'), $('input[name="endDate"]'), null);
    const roomFormEl = $('#wp-cloudbeds-room-form2');
    const roomTypeIDPage = roomFormEl.length ? (roomFormEl.data('room-id') || null) : null;
    mountLinkedPriceRange($('input[name="checkin"]'), $('input[name="checkout"]'), roomTypeIDPage);

    // ---- spinners ----
    document.querySelectorAll('.input-spinner').forEach(spinner => {
        const input = spinner.querySelector('input');
        spinner.querySelector('.btn-up')?.addEventListener('click', () => {
            const max = input.max ? parseInt(input.max) : Infinity;
            if (parseInt(input.value || "0") < max) input.value = String((parseInt(input.value || "0") + 1));
        });
        spinner.querySelector('.btn-down')?.addEventListener('click', () => {
            const min = input.min ? parseInt(input.min) : -Infinity;
            if (parseInt(input.value || "0") > min) input.value = String((parseInt(input.value || "0") - 1));
        });
    });

    // ================== Multi-room cart (home search) ==================
    function getCurrentSearchKey(){
      const ci = $('input[name="startDate"]').val() || '';
      const co = $('input[name="endDate"]').val() || '';
      return ci + '|' + co;
    }
    function loadCart(){
      try { const raw = localStorage.getItem('cb_cart_rooms'); return raw ? JSON.parse(raw) : {}; } catch(e){ return {}; }
    }
    function saveCart(obj){
      try { localStorage.setItem('cb_cart_rooms', JSON.stringify(obj)); } catch(e){}
    }
    function getRoomsForCurrentSearch(){
      const cart = loadCart(); return new Set(cart[getCurrentSearchKey()] || []);
    }
    function setRoomsForCurrentSearch(set){
      const cart = loadCart(); cart[getCurrentSearchKey()] = Array.from(set); saveCart(cart);
    }
    // Persist chosen plans per search key
    function loadPlanMap(){
      try { const raw = localStorage.getItem('cb_cart_plans'); return raw ? JSON.parse(raw) : {}; } catch(e){ return {}; }
    }
    function savePlanMap(obj){
      try { localStorage.setItem('cb_cart_plans', JSON.stringify(obj)); } catch(e){}
    }
    function getPlansForCurrentSearch(){
      const all = loadPlanMap(); return all[getCurrentSearchKey()] || {};
    }
    function setPlansForCurrentSearch(map){
      const all = loadPlanMap(); all[getCurrentSearchKey()] = map; savePlanMap(all);
    }
    function refreshCartUI(){
      const holder = $('#cb-cart-actions');
      const set = getRoomsForCurrentSearch();
      if(set.size > 0){
        holder.show();
        $('#cb-cart-counter').text(`Selected: ${set.size} room type${set.size>1?'s':''}`);
      } else {
        holder.hide();
        $('#cb-cart-counter').text('');
      }
    }
    function wireResultsInteractions(){
      const set = getRoomsForCurrentSearch();
      const planMap = getPlansForCurrentSearch();
      // Hydrate volatile cache from stored map
      if(!window._cbChosenPlans){ window._cbChosenPlans = {}; }
      Object.assign(window._cbChosenPlans, planMap);
      
      // Wire rate plan radio buttons
      $('#wp-cloudbeds-room-results input[type="radio"]').each(function(){
        const $radio = $(this);
        const $tr = $radio.closest('tr');
        const code = $tr.data('room-short');
        const plan = ($radio.data('plan') || 'base').toString();
        const price = parseFloat(($radio.data('price') || '0').toString()) || 0;
        if(!code) return;
        
        // Restore previously chosen plan from storage/cache (even if room not currently added)
        const storedPlans = getPlansForCurrentSearch();
        const chosen = (window._cbChosenPlans && window._cbChosenPlans[String(code)]) || storedPlans[String(code)] || null;
        if(chosen === plan){ $radio.prop('checked', true); }

        // Reflect added state on button if room selected in cart set
        if(set.has(code)){
          $tr.find('.cb-add-room').addClass('is-added').text('Remove');
        }
        
        $radio.off('change').on('change', function(){
          // Update the chosen plan for this room
          if(!window._cbChosenPlans){ window._cbChosenPlans = {}; }
          window._cbChosenPlans[String(code)] = plan;
          const map = getPlansForCurrentSearch();
          map[String(code)] = plan;
          setPlansForCurrentSearch(map);
        });
      });

      // Wire Add Room buttons
      $('#wp-cloudbeds-room-results .cb-add-room').each(function(){
        const $btn = $(this);
        const $tr = $btn.closest('tr');
        const code = $tr.data('room-short');
        if(!code) return;
        
        // Set initial state if room is already selected
        if(set.has(code)){
          $btn.addClass('is-added').text('Remove');
        }
        
		$btn.off('click').on('click', function(){
          const s = getRoomsForCurrentSearch();
          const $selectedRadio = $tr.find('input[type="radio"]:checked');
          
			if(!$selectedRadio.length) {
				window.cloudbedsNotify ? cloudbedsNotify('Please select your rate first.','error') : alert('Please select your rate first.');
				return;
			}
          
          const plan = $selectedRadio.data('plan');
          const price = parseFloat($selectedRadio.data('price') || '0');
          
          if(s.has(code)){
            // Remove room
            s.delete(code);
            if(window._cbChosenPlans){ delete window._cbChosenPlans[String(code)]; }
            const map = getPlansForCurrentSearch();
            if(map && map.hasOwnProperty(String(code))){ delete map[String(code)]; setPlansForCurrentSearch(map); }
            $btn.removeClass('is-added').text('Add Room');
          } else {
            // Add room with selected plan
            s.add(code);
            if(!window._cbChosenPlans){ window._cbChosenPlans = {}; }
            window._cbChosenPlans[String(code)] = plan;
            const map = getPlansForCurrentSearch();
            map[String(code)] = plan;
            setPlansForCurrentSearch(map);
            $btn.addClass('is-added').text('Remove');
          }
          
          setRoomsForCurrentSearch(s);
          refreshCartUI();
        });
      });

      // Wire plan selection (per room): update displayed price and totals
      $('#wp-cloudbeds-room-results .cb-row').each(function(){
        const $row = $(this);
        const $final = $row.find('.cb-price-final');
        const currency = (typeof cloudbeds_ajax_obj !== 'undefined' && cloudbeds_ajax_obj.currency) ? cloudbeds_ajax_obj.currency : '';
        const base = parseFloat(($final.data('price-base') || '').toString());
        const promo = parseFloat(($final.data('price-promo') || '').toString());
        const $radios = $row.find('.cb-plan-choices input[type="radio"]');
        if($radios.length){
          $radios.off('change').on('change', function(){
            const chosen = $row.find('.cb-plan-choices input[type="radio"]:checked').val();
            if(chosen === 'base' && !isNaN(base)){
              $final.text(currency + base.toFixed(2));
            } else if(chosen === 'promo' && !isNaN(promo)){
              $final.text(currency + promo.toFixed(2));
            }
          });
        }
      });
      function getSelectedTotalPrice() {
        let total = 0;
        const selectedRooms = getRoomsForCurrentSearch();
        // Only calculate price for selected rooms
        jQuery('.cb-row').each(function () {
          const $row = jQuery(this);
          const roomShort = $row.data('room-short');
          if (selectedRooms.has(roomShort)) {
            const $priceElement = $row.find('.cb-price-final');
            if ($priceElement.length) {
              let priceText = $priceElement.text().replace(/[^\d.]/g, '');
              let price = parseFloat(priceText) || 0;
              total += price;
            }
          }
        });
        return total.toFixed(2);
      }

      function getSelectedPriceMap(){
        const map = {};
        const selectedRooms = getRoomsForCurrentSearch();
        jQuery('.cb-row').each(function(){
          const $row = jQuery(this);
          const roomShort = $row.data('room-short');
          if (!selectedRooms.has(roomShort)) return;
          // Get the selected radio button for this room
          const $selectedRadio = $row.find('input[type="radio"]:checked');
          let chosen = null;
          let price = 0;
          let labelText = '';
          
          if($selectedRadio.length) {
            chosen = $selectedRadio.data('plan');
            price = parseFloat($selectedRadio.data('price') || '0');
            
            // Get the label from the selected plan
            const $planRow = $selectedRadio.closest('.cb-plan-row');
            if($planRow.length) {
              const $planLabel = $planRow.find('.cb-plan-left');
              labelText = ($planLabel.text() || '').trim();
            }
          }
          
          // Fallback to stored chosen plan if radio not found
          if(!chosen) {
            const stored = getPlansForCurrentSearch();
            chosen = (window._cbChosenPlans && window._cbChosenPlans[String(roomShort)]) || stored[String(roomShort)] || 'base';
          }
          
          // Store the chosen plan
          if(!window._cbChosenPlans){ window._cbChosenPlans = {}; }
          window._cbChosenPlans[String(roomShort)] = chosen;
          const storedMap = getPlansForCurrentSearch();
          storedMap[String(roomShort)] = chosen;
          setPlansForCurrentSearch(storedMap);
          
          map[String(roomShort)] = price;

          // Store label text for checkout
          if(!labelText) {
            labelText = (chosen === 'promo' ? 'Discounted Rate' : 'Standard Rate');
          }
          if(!window._cbChosenPlanLabels){ window._cbChosenPlanLabels = {}; }
          window._cbChosenPlanLabels[String(roomShort)] = labelText;
        });
        return map;
      }

      // Build BE link using QUERY params (works with your environment)
      $('#cb-cart-book').off('click').on('click', function(){
        // Remember where user initiated the booking from
        try { localStorage.setItem('cb_last_search_url', window.location.href); } catch(e){}
        const s = getRoomsForCurrentSearch();
        if(s.size === 0){ return; }
        const ci = $('input[name="startDate"]').val();
        const co = $('input[name="endDate"]').val();
		if(!ci || !co){ window.cloudbedsNotify ? cloudbedsNotify('Please select dates first.','error') : alert('Please select dates first.'); return; }

        const selected = Array.from(s);
        const adults = parseInt($('input[name="adults"]').val() || '2', 10);
        const kids = parseInt($('input[name="kids"]').val() || '0', 10);
        const currency = 'gbp';
        const promo = ($('input[name="promo"]').val() || '').trim();

        // Build booking payload for session
        const calculatedPrice = parseFloat(getSelectedTotalPrice()) || 0;
        const priceMap = getSelectedPriceMap();
        console.log('Selected rooms:', selected);
        console.log('Calculated price:', calculatedPrice);
        
        const payload = {
            action: 'cloudbeds_set_booking',
            room_type: selected.join(';'),
            checkin: ci,
            checkout: co,
            adults: adults,
            children: kids,
            price: calculatedPrice,
            room_prices: JSON.stringify(priceMap),
            room_plans: JSON.stringify(window._cbChosenPlans || {}),
            room_plan_labels: JSON.stringify(window._cbChosenPlanLabels || {})
        };

        // Add loading state to button
        const $btn = $(this);
        $btn.addClass('loading').prop('disabled', true);

        // Send to server to store in WC session, then redirect to checkout
        $.post(cloudbeds_ajax_obj.ajax_url, payload, function(resp){
			if (resp && resp.success) {
                window.location = resp.data.checkout || cloudbeds_ajax_obj.checkout_url;
            } else {
				window.cloudbedsNotify ? cloudbedsNotify('Could not start checkout. Please try again.','error') : alert('Could not start checkout. Please try again.');
                // Remove loading state on error
                $btn.removeClass('loading').prop('disabled', false);
            }
        });
    });
      refreshCartUI();
    }
    
    // ----- Checkout: add remove icon wiring -----
    // Listen for remove clicks on checkout line items (delegated)
    $(document).on('click', '.cloudbeds-remove-item', function(e){
      e.preventDefault();
      e.stopPropagation();
      const $btn = $(this);
      const cartKey = $btn.data('cart-key');
      const fallbackUrl = $btn.data('remove-url');
      if(!cartKey) return;
      $btn.prop('disabled', true).text('Removing...');

      function hardReload(){ window.location.href = window.location.href; }

      $.post(cloudbeds_ajax_obj.ajax_url, { action: 'cloudbeds_remove_cart_item', cart_item_key: cartKey })
        .done(function(resp){
          if(resp && resp.success){
            // Optimistically remove the row and refresh WC fragments/checkout without full reload
            const $row = $btn.closest('tr, .cart_item');
            if($row.length){ $row.slideUp(150, function(){ $(this).remove(); }); }
            // If on checkout, let WooCommerce recalc totals
            if (jQuery('form.checkout').length) {
              jQuery(document.body).trigger('update_checkout');
            }
            // Refresh mini-cart/fragments if available
            jQuery(document.body).trigger('wc_fragment_refresh');
            // If cart is now empty, redirect back to starting rooms page
            try {
              if (resp.data && resp.data.cart_empty) {
                const lastUrl = localStorage.getItem('cb_last_search_url');
                const fallback = (typeof cloudbeds_ajax_obj !== 'undefined' && cloudbeds_ajax_obj.rooms_url) ? cloudbeds_ajax_obj.rooms_url : '/';
                window.location.href = lastUrl || fallback;
                return;
              }
            } catch(e){}
            return;
          } else {
            // Fallback to WooCommerce native remove URL if available
            const $native = $btn.closest('tr, .cart_item').find('.remove');
				if($native.length){ $native[0].click(); return; }
				if (fallbackUrl) { window.location.href = fallbackUrl; return; }
				var m1 = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not remove item';
				window.cloudbedsNotify ? cloudbedsNotify(m1,'error') : alert(m1);
            $btn.prop('disabled', false).text('Remove');
          }
        }).fail(function(){
			const $native = $btn.closest('tr, .cart_item').find('.remove');
			if($native.length){ $native[0].click(); return; }
			if (fallbackUrl) { window.location.href = fallbackUrl; return; }
			window.cloudbedsNotify ? cloudbedsNotify('Request failed','error') : alert('Request failed');
          $btn.prop('disabled', false).text('Remove');
        });
    });

    // No layout repositioning needed when using child theme template

    // Proxy clicks from the meta "Remove" link to the hidden actionable button in the same row
    $(document).on('click', '.cloudbeds-remove-item-link', function(e){
      e.preventDefault();
      const $link = $(this);
      const key = $link.data('cart-key');
      // Find the corresponding hidden button in the same cart row
      const $row = $link.closest('tr, .cart_item');
      const $hiddenBtn = $row.find('.cloudbeds-remove-item[data-cart-key="' + key + '"]');
      if($hiddenBtn.length){ $hiddenBtn.trigger('click'); return; }
      // Fallback: use AJAX directly if hidden button not found
      const fallbackUrl = $link.data('remove-url');
      $.post(cloudbeds_ajax_obj.ajax_url, { action: 'cloudbeds_remove_cart_item', cart_item_key: key })
        .done(function(resp){
          if(resp && resp.success){
            const $row2 = $link.closest('tr, .cart_item');
            if($row2.length){ $row2.slideUp(150, function(){ $(this).remove(); }); }
            if (jQuery('form.checkout').length) { jQuery(document.body).trigger('update_checkout'); }
            jQuery(document.body).trigger('wc_fragment_refresh');
            try {
              if (resp.data && resp.data.cart_empty) {
                const lastUrl = localStorage.getItem('cb_last_search_url');
                const fallback = (typeof cloudbeds_ajax_obj !== 'undefined' && cloudbeds_ajax_obj.rooms_url) ? cloudbeds_ajax_obj.rooms_url : '/';
                window.location.href = lastUrl || fallback;
                return;
              }
            } catch(e){}
          } else {
			if (fallbackUrl) { window.location.href = fallbackUrl; return; }
			const $native = $link.closest('tr, .cart_item').find('.remove');
			if($native.length){ $native[0].click(); return; }
			var m2 = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not remove item';
			window.cloudbedsNotify ? cloudbedsNotify(m2,'error') : alert(m2);
          }
        }).fail(function(){
			if (fallbackUrl) { window.location.href = fallbackUrl; return; }
			const $native = $link.closest('tr, .cart_item').find('.remove');
			if($native.length){ $native[0].click(); return; }
			window.cloudbedsNotify ? cloudbedsNotify('Request failed','error') : alert('Request failed');
        });
    });
    // Initial try in case results already present
    setTimeout(wireResultsInteractions, 0);
    // ================== End multi-room cart ==================
});
