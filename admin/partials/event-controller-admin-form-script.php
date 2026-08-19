<?php
/**
 * Inline JS for the admin "Post Event" page. Required directly from
 * Event_Controller_Admin::print_post_event_script() on admin_footer (priority
 * 100, so Bootstrap/DateRangePicker/TinyMCE - enqueued for the footer at the
 * default priority - have already been printed by the time this runs, same
 * technique the original front-end form used with wp_footer).
 *
 * Validation/collection helpers are unchanged from the front-end version.
 * What changed: submission posts to the REST job-queue endpoint instead of
 * admin-ajax, and on success the browser navigates to the job detail page
 * instead of showing an inline processing modal.
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<script>
var ecAdminData = {
	restUrl: <?php echo wp_json_encode( esc_url_raw( rest_url( 'event-controller/v1' ) ) ); ?>,
	restNonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>,
	jobsPageUrl: <?php echo wp_json_encode( admin_url( 'admin.php?page=event-controller-jobs' ) ); ?>
};
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
      } else {
        $('#physical-location-section').slideUp();
        $('[data-required-if-physical="true"]').removeClass('is-invalid');
      }
    });

    // Auto-update end time when start time changes (+1 hour)
    $('select[name="starttime"]').on('change', function() {
      const selectedTime = $(this).val();
      if (!selectedTime) return;

      const [hours, minutes] = selectedTime.split(':');
      let endHour = parseInt(hours) + 1;
      const endMinute = minutes;

      if (endHour >= 24) {
        endHour = 0;
      }

      const endTime = String(endHour).padStart(2, '0') + ':' + endMinute + ':00';
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
        return false;
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

    // Remove an objective row
    $(document).on('click', '.remove_objective', function(e) {
      e.preventDefault();
      $(this).closest('.row').remove();
    });

    // Handle form submission - posts to the REST job queue, then navigates
    // to the job detail page (progress bar + per-site table) on success.
    $('#ec-form').on('submit', function(e) {
      e.preventDefault();

      if (isSubmitting) {
        return;
      }
      isSubmitting = true;

      const requiredFields = [
        'event_title',
        'startdate',
        'enddate',
        'starttime',
        'endtime',
        'event_timezone',
        'event_location_type'
      ];
      if (validateRequiredFields(requiredFields)) {
        isSubmitting = false;
        return;
      }

      if (validatePhysicalLocationFields()) {
        isSubmitting = false;
        return;
      }

      if (typeof tinyMCE !== 'undefined') tinyMCE.triggerSave();

      const objectives = collectObjectives();
      const starttime = $('select[name="starttime"]').val();
      const endtime = $('select[name="endtime"]').val();
      const data = collectEventData(objectives, starttime, endtime);
      const selectedSites = collectSelectedSites();
      const file = $('input[type=file]')[0].files[0];

      if (selectedSites.length === 0) {
        isSubmitting = false;
        $('#show-form-errors').html('<div class="alert alert-danger mt-3">Please select at least one website before posting the event.</div>');
        $('#show-form-errors')[0].scrollIntoView({ behavior: 'smooth' });
        return;
      }

      $('#submit_event').prop('disabled', true).text('Submitting...');
      $('#ec-form-errors').empty();

      const formData = new FormData();
      formData.append('selected_sites', JSON.stringify(selectedSites));
      formData.append('event_data', JSON.stringify(data));
      if (file) {
        formData.append('file', file);
      }

      $.ajax({
        url: ecAdminData.restUrl + '/jobs',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        timeout: 30000,
        headers: { 'X-WP-Nonce': ecAdminData.restNonce },
        success: function(response) {
          window.location.href = ecAdminData.jobsPageUrl + '&job_id=' + response.job_id;
        },
        error: function(xhr) {
          isSubmitting = false;
          $('#submit_event').prop('disabled', false).text('Post Event');
          const message = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Request failed';
          $('#ec-form-errors').html('<div class="alert alert-danger mt-3">Error: ' + message + '</div>');
          $('#ec-form-errors')[0].scrollIntoView({ behavior: 'smooth' });
        }
      });
    });

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

    initTinyMCEEditor('textarea#summary');
    initTinyMCEEditor('textarea#short_summary', { height: 250 });

  });
})(jQuery);
</script>
