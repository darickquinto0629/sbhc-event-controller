<?php
add_action('wp_footer', 'event_submit_js', 100);
function event_submit_js() {
?>
<script>
(function($) {
  $(function() {

let isSubmitting = false; // Flag to prevent duplicate submissions

    // Default start/end values
    let startdate = moment().format('YYYY-MM-DD');
    let enddate = moment().format('YYYY-MM-DD');

    // Helper: Generate time options dynamically (24 hours, 15-minute intervals)
    function generateTimeOptions(intervalMinutes = 15) {
      const options = [];
      for (let hour = 0; hour < 24; hour++) {
        for (let minute = 0; minute < 60; minute += intervalMinutes) {
          const timeStr = String(hour).padStart(2, '0') + ':' + String(minute).padStart(2, '0') + ':00';
          const ampm = hour < 12 ? 'AM' : 'PM';
          const displayHour = hour === 0 ? 12 : hour > 12 ? hour - 12 : hour;
          const displayTime = displayHour + ':' + String(minute).padStart(2, '0') + ' ' + ampm;
          options.push({ value: timeStr, display: displayTime });
        }
      }
      return options;
    }

    // Helper: Populate time select with generated options
    function populateTimeSelect(selectSelector, intervalMinutes = 15) {
      const timeOptions = generateTimeOptions(intervalMinutes);
      const $select = $(selectSelector);
      $select.empty();
      timeOptions.forEach(opt => {
        $select.append(`<option value="${opt.value}">${opt.display}</option>`);
      });
    }

    // Initialize time selects on page load
    populateTimeSelect('select[name="starttime"]');
    populateTimeSelect('select[name="endtime"]');

    // Handle location type change (show/hide physical location fields)
    $('select[name="event_location_type"]').on('change', function() {
      if ($(this).val() === 'Physical') {
        $('#physical-location-section').slideDown();
        console.log('📍 Physical location section shown');
      } else {
        $('#physical-location-section').slideUp();
        $('[data-required-if-physical="true"]').removeClass('is-invalid');
        console.log('📍 Physical location section hidden');
      }
    });

    // Auto-update end time when start time changes (+1 hour)
    $('select[name="starttime"]').on('change', function() {
      const selectedTime = $(this).val();
      if (!selectedTime) return;
      
      // Parse the start time (format: HH:MM:00)
      const [hours, minutes] = selectedTime.split(':');
      let endHour = parseInt(hours) + 1;
      const endMinute = minutes;
      
      // Handle day rollover (23:xx -> 00:xx)
      if (endHour >= 24) {
        endHour = 0;
      }
      
      // Format the end time
      const endTime = String(endHour).padStart(2, '0') + ':' + endMinute + ':00';
      
      // Set the end time select
      $('select[name="endtime"]').val(endTime);
    });

    // Helper: Initialize date picker with callback
    function initDatePicker(inputSelector, onDateSelected) {
      $(inputSelector).daterangepicker({
        minDate: moment(),
        timePicker: false,
        singleDatePicker: true,
        startDate: moment(),
        autoApply: true
      }, onDateSelected);
    }

    // Initialize start date picker
    initDatePicker('input[name="startdate"]', function(start) {
      startdate = start.format('YYYY-MM-DD');
    });

    // When selecting a start date, auto-fill the end date
    $('input[name="startdate"]').on('apply.daterangepicker', function() {
      $('input[name="enddate"]').val($(this).val());
      enddate = startdate;
    });

    // Initialize end date picker
    initDatePicker('input[name="enddate"]', function(end) {
      enddate = end.format('YYYY-MM-DD');
    });

    // Helper: Validate required form fields
    function validateRequiredFields(fields) {
      $('.is-invalid').removeClass('is-invalid');
      let hasErrors = false;

      fields.forEach(field => {
        const input = $(`[name="${field}"]`);
        if (!input.val().trim()) {
          input.addClass('is-invalid');
          hasErrors = true;
          window.scrollTo(0, 0);
        }
      });

      return hasErrors;
    }

    // Helper: Validate conditional physical location fields
    function validatePhysicalLocationFields() {
      if ($('select[name="event_location_type"]').val() !== 'Physical') {
        return false; // No validation needed for Virtual
      }

      let hasErrors = false;
      const requiredPhysicalFields = [
        'location_address_1',
        'location_city',
        'location_state',
        'location_postcode'
      ];

      $('[data-required-if-physical="true"]').removeClass('is-invalid');

      requiredPhysicalFields.forEach(field => {
        const input = $(`[name="${field}"]`);
        if (!input.val().trim()) {
          input.addClass('is-invalid');
          hasErrors = true;
          window.scrollTo(0, 0);
        }
      });

      return hasErrors;
    }

    // Helper: Collect objectives from form
    function collectObjectives() {
      const objectives = [];
      $('input[name="objectives"]').each(function() {
        const val = $(this).val().trim();
        if (val !== '') objectives.push(val);
      });
      return objectives;
    }

    // Helper: Collect all form fields into data object
    function collectEventData(objectives, starttime, endtime) {
      const eventLocationType = $('select[name="event_location_type"]').val();
      
      return {
        "event_title": $('input[name="event_title"]').val(),
        "presenter": $('input[name="presenter"]').val(),
        "ticket_price": $('input[name="ticketprice"]').val(),
        "event_location": $('input[name="eventlocation"]').val(),
        "event_type": eventLocationType,
        "summary": $('textarea#summary').val(),
        "short_summary": $('textarea#short_summary').val(),
        "contact_name": $('input[name="contact_name"]').val(),
        "contact_number": $('input[name="contact_number"]').val(),
        "contact_address": $('input[name="contact_address"]').val(),
        "registration_link": $('input[name="registration_link"]').val(),
        "objectives": objectives,
        "location_name": $('input[name="location_name"]').val() || '',
        "location_address_1": $('input[name="location_address_1"]').val() || '',
        "location_address_2": $('input[name="location_address_2"]').val() || '',
        "location_city": $('input[name="location_city"]').val() || '',
        "location_state": $('input[name="location_state"]').val() || '',
        "location_postcode": $('input[name="location_postcode"]').val() || '',
        "meta_input": {
          "event_start_time": starttime,
          "event_end_time": endtime,
          "event_start": startdate + ' ' + starttime,
          "event_end": enddate + ' ' + endtime,
          "event_start_date": startdate,
          "event_end_date": enddate,
          "event_start_local": startdate + ' ' + starttime,
          "event_end_local": enddate + ' ' + endtime,
          "event_time_zone": $('select[name="event_timezone"]').val(),
          "event_location_type": eventLocationType,
          "event_location_url": $('input[name="registration_link"]').val(),
          "event_location_url_text": "Register Here"
        }
      };
    }

    // Helper: Collect selected site checkboxes
    function collectSelectedSites() {
      const selectedSites = [];
      $('.form-check input[type="checkbox"]:checked').each(function() {
        selectedSites.push($(this).attr('id'));
      });
      return selectedSites;
    }

    // Helper: Reset form after successful submission
    function resetForm() {
      $('#ec-form')[0].reset();
      $('.objective-wrap .row:not(:first)').remove();
      tinymce.get('summary')?.setContent('');
      tinymce.get('short_summary')?.setContent('');
      $('.is-invalid').removeClass('is-invalid');
      // Hide physical location section after reset
      $('#physical-location-section').slideUp();
    }

    // Helper: Handle successful AJAX response
    function handleSubmissionSuccess(response) {
      console.log('✓ AJAX REQUEST SUCCESS - Response received');
      console.log('Raw Response:', response);
      
      const result = typeof response === 'string' ? JSON.parse(response) : response;
      console.log('Parsed Result:', result);
      
      const modalBody = $('#sendingData .modal-body');
      
      isSubmitting = false;
      $('.loader').hide(500);
      $('#submit_event').removeAttr('disabled');

      // Check for partial success (new feature - backward compatible)
      const isPartialSuccess = result.data?.partial_success === true;
      const hasErrors = result.data?.errors && result.data.errors.length > 0;
      const hasSuccesses = result.data?.responses && result.data.responses.length > 0;
      const stats = result.data?.stats || {};

      console.log('📊 Response Analysis:');
      console.log('  - Overall Success:', result.success);
      console.log('  - Partial Success:', isPartialSuccess);
      console.log('  - Has Errors:', hasErrors, `(${hasErrors ? (result.data?.errors?.length || 0) + ' errors' : 'none'})`);
      console.log('  - Has Successes:', hasSuccesses, `(${hasSuccesses ? (result.data?.responses?.length || 0) + ' succeeded' : 'none'})`);
      console.log('  - Stats:', stats);

      if (result.success && !hasErrors) {
        console.log('✅ SCENARIO: ALL SITES SUCCEEDED');
        // All sites succeeded
        $('.success-message').addClass('succeed');
        $('.success-message span').html('- Success!');  
        $('#post_event_status').text(`All ${stats.total} sites processed successfully.`);
        
        // Update each site in the list
        if (hasSuccesses) {
          result.data.responses.forEach((resp) => {
            console.log('  ✓ Updating UI for site:', resp.site_id);
            const siteElement = $(`[data-site-id="${resp.site_id}"]`);
            siteElement.find('.site-loader').hide();
            siteElement.find('.site-status').html('<span class="badge bg-success">✓ Success</span>');
            siteElement.addClass('site-success');
          });
        }
        
        // Reset form (nonce refresh happens in background)
        resetForm();
      } else if (result.success && isPartialSuccess) {
        console.log('⚠️  SCENARIO: PARTIAL SUCCESS');
        // Partial success - some succeeded, some failed
        $('.success-message').addClass('partial');
        $('.success-message span').html('- Partial Success!');  
        $('#post_event_status').text(`Processing complete: ${stats.succeeded} succeeded, ${stats.failed} failed`);
        
        // Update successful sites
        if (hasSuccesses) {
          result.data.responses.forEach((resp) => {
            console.log('  ✓ Site succeeded:', resp.site_id);
            const siteElement = $(`[data-site-id="${resp.site_id}"]`);
            siteElement.find('.site-loader').hide();
            siteElement.find('.site-status').html('<span class="badge bg-success">✓ Success</span>');
            siteElement.addClass('site-success');
          });
        }
        
        // Update failed sites with error messages
        if (hasErrors) {
          result.data.errors.forEach(err => {
            console.log('  ✗ Site error:', err);
            // Extract site name from error message (format: "SiteName: error details")
            const errorParts = err.split(':');
            const siteName = errorParts[0].trim();
            const errorMsg = errorParts.slice(1).join(':').trim();
            
            // Find matching site element
            const siteElement = $(`[data-site-name="${siteName}"]`);
            if (siteElement.length) {
              siteElement.find('.site-loader').hide();
              siteElement.find('.site-status').html(`<span class="badge bg-danger" title="${errorMsg}">✗ ${errorMsg.substring(0, 30)}...</span>`);
              siteElement.addClass('site-failed');
            }
          });
        }

        // Reset form (nonce refresh happens in background)
        resetForm();
      } else if (!result.success && hasErrors) {
        console.log('❌ SCENARIO: ALL SITES FAILED');
        // All sites failed or general error
        $('.success-message').addClass('failed');
        $('.success-message span').html('- Error!');  		  
        $('#post_event_status').text("Some errors occurred while posting:");
        
        // Update all sites to failed status
        if (hasErrors) {
          result.data.errors.forEach(err => {
            console.log('  ✗ Error details:', err);
            const errorParts = err.split(':');
            const siteName = errorParts[0].trim();
            const errorMsg = errorParts.slice(1).join(':').trim();
            
            const siteElement = $(`[data-site-name="${siteName}"]`);
            if (siteElement.length) {
              siteElement.find('.site-loader').hide();
              siteElement.find('.site-status').html(`<span class="badge bg-danger" title="${errorMsg}">✗ ${errorMsg.substring(0, 30)}...</span>`);
              siteElement.addClass('site-failed');
            }
          });
        }
      } else {
        console.log('⚠️  SCENARIO: UNEXPECTED RESPONSE FORMAT');
        // Fallback for unexpected response format
        $('.success-message').addClass('failed');
        $('.success-message span').html('- Error!');  
        $('#post_event_status').text("An error occurred during processing");
        modalBody.append('<div class="alert alert-danger mt-3">An unexpected error occurred during processing.</div>');
      }
      
      console.log('===== EVENT CONTROLLER: FORM SUBMISSION COMPLETED =====\n');
    }

    // Helper: Handle AJAX request error
    function handleSubmissionError(xhr, status, error) {
      console.log('❌ AJAX REQUEST ERROR HANDLER');
      console.log('Status:', status);
      console.log('Error:', error);
      console.log('HTTP Code:', xhr.status);
      console.log('Response Text:', xhr.responseText);
      
      const modalBody = $('#sendingData .modal-body');
      
      isSubmitting = false;
      $('.loader').hide(500);
      $('#submit_event').removeAttr('disabled');
      $('#post_event_status').text("Request failed");
      modalBody.append('<div class="alert alert-danger mt-3">Error: ' + error + '</div>');
      
      console.log('===== EVENT CONTROLLER: FORM SUBMISSION FAILED =====\n');
    }

    // Add a new objective field
    $('.add_objective').click(function(e) {
      e.preventDefault();
      $('#learning-objectives .objective-wrap').append(`
        <div class="row">
          <div class="col-md-10">
            <input type="text" name="objectives" class="form-control objective">
          </div>
          <div class="col-md-2 d-grid">
            <button type="button" class="btn btn-primary mb-3 remove_objective">Remove</button>
          </div>
        </div>`);
    });

    // Handle form submission
    $('#ec-form').on('submit', function(e) {
      e.preventDefault();
      
      // TODO: TEMPORARY DEBUG LOG - Remove before final release
      console.log('===== EVENT CONTROLLER: FORM SUBMISSION STARTED =====');
      console.log('Timestamp:', new Date().toISOString());

      // Prevent duplicate submissions
      if (isSubmitting) {
        console.log('⚠️ SUBMISSION ALREADY IN PROGRESS - Preventing duplicate');
        return;
      }
      console.log('✓ Submission lock acquired');
      isSubmitting = true;

      // Validate required fields
      const requiredFields = [
        'event_title',
        // 'eventlocation',
        'startdate',
        'enddate',
        'starttime',
        'endtime',
        'event_timezone',
        'event_location_type'
      ];
      console.log('📋 Validating required fields:', requiredFields);
      if (validateRequiredFields(requiredFields)) {
        console.log('❌ VALIDATION FAILED - Missing required fields');
        isSubmitting = false;
        return;
      }
      
      // Validate physical location fields if Physical is selected
      if (validatePhysicalLocationFields()) {
        console.log('❌ VALIDATION FAILED - Missing physical location fields');
        isSubmitting = false;
        return;
      }
      
      console.log('✓ All required fields validated');

      // Trigger TinyMCE save
      if (typeof tinyMCE !== 'undefined') tinyMCE.triggerSave();

      // Collect form data
      const objectives = collectObjectives();
      const starttime = $('select[name="starttime"]').val();
      const endtime = $('select[name="endtime"]').val();
      const data = collectEventData(objectives, starttime, endtime);
      const selectedSites = collectSelectedSites();
      const file = $('input[type=file]')[0].files[0];

      console.log('📝 Form data collected:');
      console.log('  - Event Title:', data.event_title);
      console.log('  - Start Date:', data.meta_input.event_start_date);
      console.log('  - End Date:', data.meta_input.event_end_date);
      console.log('  - Start Time:', starttime);
      console.log('  - End Time:', endtime);
      console.log('  - Objectives:', objectives);
      console.log('📍 Selected Sites:', selectedSites);
      console.log('🖼️  Featured Image:', file ? file.name : 'None');

      // Ensure at least one site is selected
      if (selectedSites.length === 0) {
        console.log('❌ ERROR: No sites selected');
        isSubmitting = false;
        $('#show-form-errors').append('<div class="alert alert-danger mt-3">Please select at least one website before posting the event.</div>');
        $('#show-form-errors')[0].scrollIntoView({ behavior: 'smooth' });
        return;
      }
      console.log('✓ Site selection validated -', selectedSites.length, 'site(s) selected');

      // Show loader and submit
      $('.loader').show();
      $('.success span').hide();
      
      // CRITICAL: Clear modal body completely before new submission
      $('#sendingData .modal-body').empty();
      $('#post_event_status').text('Posting Event');
      
      // Build site list with loading icons
      let sitesHTML = '<div class="sites-processing mt-4"><h5>Processing Sites:</h5><div class="sites-list">';
      selectedSites.forEach(siteId => {
        const siteName = $(`#${siteId}`).closest('.form-check').find('label').text().trim();
        sitesHTML += `
          <div class="site-item mb-2 p-2 border rounded" data-site-id="${siteId}" data-site-name="${siteName}">
            <div class="d-flex justify-content-between align-items-center">
              <span class="site-name"><strong>${siteName}</strong></span>
              <div class="site-status-container">
                <span class="site-loader">
                  <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                  <span class="badge bg-info">Processing...</span>
                </span>
                <span class="site-status"></span>
              </div>
            </div>
          </div>
        `;
      });
      sitesHTML += '</div></div>';
      
      $('#sendingData .modal-body').html(sitesHTML);
      $('#sendingData').modal('show');
      $('#submit_event').prop('disabled', true);

      // Prepare FormData for submission
      const formData = new FormData();
      formData.append('action', 'event_controller_submit_event');
      formData.append('nonce', eventControllerData.nonce);
      formData.append('selected_sites', JSON.stringify(selectedSites));
      formData.append('event_data', JSON.stringify(data));
      if (file) {
        formData.append('file', file);
      }

      console.log('📤 Preparing AJAX request...');
      console.log('  - Ajax URL:', eventControllerData.ajax_url);
      console.log('  - Action:', 'event_controller_submit_event');
      console.log('  - Nonce:', eventControllerData.nonce);
      console.log('  - Total sites to process:', selectedSites.length);
      console.log('  - File attached:', !!file);
      console.log('📡 Sending AJAX request...');

      $.ajax({
        url: eventControllerData.ajax_url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
          console.log('✓ AJAX REQUEST SUCCESSFUL - Response received');
          console.log('Response:', response);
          handleSubmissionSuccess(response);
        },
        error: function(xhr, status, error) {
          console.log('❌ AJAX REQUEST FAILED');
          console.log('  - Status:', status);
          console.log('  - Error:', error);
          console.log('  - HTTP Code:', xhr.status);
          console.log('  - Response:', xhr.responseText);
          handleSubmissionError(xhr, status, error);
        }
      });
    });

    // Remove an objective row
    $(document).on('click', '.remove_objective', function(e) {
      e.preventDefault();
      $(this).closest('.row').remove();
    });

    // Helper: Toggle visibility based on checkbox state
    function setupCheckboxToggle(checkboxSelector, targetSelector) {
      $(checkboxSelector).on('change', function() {
        $(checkboxSelector).prop('checked') ? $(targetSelector).show() : $(targetSelector).hide();
      });
    }

    // Checkbox-controlled visibility
    setupCheckboxToggle('#summit_staging', '.summit_staging');
    setupCheckboxToggle('#sbhc_multisite_controller', '.sbhc_multisite_controller');

    // Helper: Initialize TinyMCE editor
    function initTinyMCEEditor(selector, config = {}) {
      const defaultConfig = {
        selector: selector,
		  plugins: [
			  "advlist", "anchor", "autolink", "charmap", "code", "fullscreen",
			  "help", "image", "insertdatetime", "link", "lists", "media",
			  "preview", "searchreplace", "table", "visualblocks",
		  ],
		  toolbar: "undo redo | styles | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link",	  
        ai_request: (request, respondWith) => respondWith.string(() => Promise.reject("See docs to implement AI Assistant"))
      };
      tinymce.init(Object.assign({}, defaultConfig, config));
    }

    // Initialize TinyMCE editors
    initTinyMCEEditor('textarea#summary');
    initTinyMCEEditor('textarea#short_summary', { height: 250 });

  });
})(jQuery);
</script>
<?php
}