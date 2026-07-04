<?php
add_action('wp_footer', 'event_submit_js', 100);
function event_submit_js() {
?>
<script>
(function($) {
  $(function() {

let uploadErrors = []; // Reset error array globally

    // Default start/end values
    let startdate = moment().format('YYYY-MM-DD');
    let enddate = moment().format('YYYY-MM-DD');

    // Helper: Initialize date picker with callback
    function initDatePicker(inputSelector, onDateSelected) {
      $(inputSelector).daterangepicker({
        minDate: moment(),
        timePicker: true,
        singleDatePicker: true,
        startDate: moment(),
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
      return {
        "event_title": $('input[name="event_title"]').val(),
        "presenter": $('input[name="presenter"]').val(),
        "ticket_price": $('input[name="ticketprice"]').val(),
        "event_location": $('input[name="eventlocation"]').val(),
        "event_type": $('select[name="event_location_type"]').val(),
        "summary": $('textarea#summary').val(),
        "short_summary": $('textarea#short_summary').val(),
        "contact_name": $('input[name="contact_name"]').val(),
        "contact_number": $('input[name="contact_number"]').val(),
        "contact_address": $('input[name="contact_address"]').val(),
        "registration_link": $('input[name="registration_link"]').val(),
        "objectives": objectives,
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
          "event_location_type": $('select[name="event_location_type"]').val(),
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
    }

    // Helper: Handle successful AJAX response
    function handleSubmissionSuccess(response) {
      const result = typeof response === 'string' ? JSON.parse(response) : response;
      const modalBody = $('#sendingData .modal-body');
      
      $('.loader').hide(500);
      $('#submit_event').removeAttr('disabled');

      if (result.success) {
        $('#post_event_status').text("All sites processed successfully.");
        modalBody.append('<div class="alert alert-success mt-3">All events posted successfully.</div>');
        resetForm();
      } else {
        $('#post_event_status').text("Some errors occurred while posting:");
        if (result.errors && result.errors.length > 0) {
          modalBody.append('<div class="alert alert-danger mt-3"><strong>Errors:</strong><ul>' +
            result.errors.map(err => `<li>${err}</li>`).join('') + '</ul></div>');
        } else {
          modalBody.append('<div class="alert alert-danger mt-3">An error occurred during processing.</div>');
        }
      }
    }

    // Helper: Handle AJAX request error
    function handleSubmissionError(xhr, status, error) {
      const modalBody = $('#sendingData .modal-body');
      
      $('.loader').hide(500);
      $('#submit_event').removeAttr('disabled');
      $('#post_event_status').text("Request failed");
      modalBody.append('<div class="alert alert-danger mt-3">Error: ' + error + '</div>');
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
    $('#submit_event').on('click', function(e) {
      e.preventDefault();
      e.stopImmediatePropagation();

      // Validate required fields
      const requiredFields = [
        'event_title',
        'eventlocation',
        'startdate',
        'enddate',
        'starttime',
        'endtime',
        'event_timezone',
        'event_location_type'
      ];
      if (validateRequiredFields(requiredFields)) return;

      // Trigger TinyMCE save
      if (typeof tinyMCE !== 'undefined') tinyMCE.triggerSave();

      // Prevent form submission on Enter key
      $(window).keydown(function(event) {
        if (event.keyCode === 13) {
          event.preventDefault();
          return false;
        }
      });

      // Collect form data
      const objectives = collectObjectives();
      const starttime = $('select[name="starttime"]').val();
      const endtime = $('select[name="endtime"]').val();
      const data = collectEventData(objectives, starttime, endtime);
      const selectedSites = collectSelectedSites();
      const file = $('input[type=file]')[0].files[0];

      // Ensure at least one site is selected
      if (selectedSites.length === 0) {
        $('#show-form-errors').append('<div class="alert alert-danger mt-3">No website selected to create event.</div>');
        window.scrollTo(0, 0);
        return;
      }

      // Prepare FormData for submission
      const formData = new FormData();
      formData.append('action', 'event_controller_submit_event');
      formData.append('nonce', eventControllerData.nonce);
      formData.append('selected_sites', JSON.stringify(selectedSites));
      formData.append('event_data', JSON.stringify(data));
      if (file) {
        formData.append('file', file);
      }

      // Show loader and submit
      $('.loader').show();
      $('.success span').hide();
      $('#sendingData').modal('show');
      $('#submit_event').prop('disabled', true);

      $.ajax({
        url: eventControllerData.ajax_url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: handleSubmissionSuccess,
        error: handleSubmissionError
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
