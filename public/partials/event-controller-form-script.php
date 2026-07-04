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

    // Initialize start date picker
    $('input[name="startdate"]').daterangepicker({
      minDate: moment(),
      timePicker: true,
      singleDatePicker: true,
      startDate: moment(),
    }, function(start) {
      startdate = start.format('YYYY-MM-DD');
    });

    // When selecting a start date, auto-fill the end date
    $('input[name="startdate"]').on('apply.daterangepicker', function() {
      $('input[name="enddate"]').val($(this).val());
      enddate = startdate;
    });

    // Initialize end date picker
    $('input[name="enddate"]').daterangepicker({
      minDate: moment(),
      timePicker: true,
      singleDatePicker: true,
      startDate: moment(),
    }, function(end) {
      enddate = end.format('YYYY-MM-DD');
    });

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

      // Remove previous validation styles
      $('.is-invalid').removeClass('is-invalid');

      // Required field validation
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
      let hasErrors = false;

      requiredFields.forEach(field => {
        const input = $(`[name="${field}"]`);
        if (!input.val().trim()) {
          input.addClass('is-invalid');
          hasErrors = true;
					window.scrollTo(0, 0);
        }
      });

      // Abort if there are validation errors
      if (hasErrors) return;

      // Trigger TinyMCE save
      if (typeof tinyMCE !== 'undefined') tinyMCE.triggerSave();

      // Prevent form submission on Enter key
      $(window).keydown(function(event) {
        if (event.keyCode === 13) {
          event.preventDefault();
          return false;
        }
      });

      // Collect objectives
      const objectives = [];
      $('input[name="objectives"]').each(function() {
        const val = $(this).val().trim();
        if (val !== '') objectives.push(val);
      });

      // Prepare image upload
      const asyncUpload = new FormData();
      asyncUpload.append("async-upload", $('input[type=file]')[0].files[0]);

      // Collect start/end times from select
      const starttime = $('select[name="starttime"]').val();
      const endtime = $('select[name="endtime"]').val();

      // Build data payload
      const data = {
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

      // Ensure at least one site is selected
      if ($('.form-check input[type="checkbox"]:checked').length > 0) {
        // Collect selected sites
        const selectedSites = [];
        $('.form-check input[type="checkbox"]:checked').each(function() {
          selectedSites.push($(this).attr('id'));
        });

        // Get file
        const file = $('input[type=file]')[0].files[0];

        // Prepare FormData for submission
        const formData = new FormData();
        formData.append('action', 'event_controller_submit_event');
        formData.append('nonce', eventControllerData.nonce);
        formData.append('selected_sites', JSON.stringify(selectedSites));
        formData.append('event_data', JSON.stringify(data));
        if (file) {
          formData.append('file', file);
        }

        // Show loader
        $('.loader').show();
        $('.success span').hide();
        $('#sendingData').modal('show');
        $('#submit_event').prop('disabled', true);

        // Submit to server
        $.ajax({
          url: eventControllerData.ajax_url,
          type: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          success: function(response) {
            const result = typeof response === 'string' ? JSON.parse(response) : response;
            
            $('.loader').hide(500);
            $('#submit_event').removeAttr('disabled');

            if (result.success) {
              $('#post_event_status').text("All sites processed successfully.");
              $('#sendingData .modal-body').append('<div class="alert alert-success mt-3">All events posted successfully.</div>');
              
              // Reset form
              $('#ec-form')[0].reset();
              $('.objective-wrap .row:not(:first)').remove();
              tinymce.get('summary')?.setContent('');
              tinymce.get('short_summary')?.setContent('');
              $('.is-invalid').removeClass('is-invalid');
            } else {
              $('#post_event_status').text("Some errors occurred while posting:");
              const modalBody = $('#sendingData .modal-body');
              if (result.errors && result.errors.length > 0) {
                modalBody.append('<div class="alert alert-danger mt-3"><strong>Errors:</strong><ul>' +
                  result.errors.map(err => `<li>${err}</li>`).join('') + '</ul></div>');
              } else {
                modalBody.append('<div class="alert alert-danger mt-3">An error occurred during processing.</div>');
              }
            }
          },
          error: function(xhr, status, error) {
            $('.loader').hide(500);
            $('#submit_event').removeAttr('disabled');
            $('#post_event_status').text("Request failed");
            $('#sendingData .modal-body').append('<div class="alert alert-danger mt-3">Error: ' + error + '</div>');
          }
        });
      } else {
				$('#show-form-errors').append('<div class="alert alert-danger mt-3">No website selected to create event.</div>');
				window.scrollTo(0, 0);
      }
    });

    // Remove an objective row
    $(document).on('click', '.remove_objective', function(e) {
      e.preventDefault();
      $(this).closest('.row').remove();
    });

    // Checkbox-controlled visibility
    $('#summit_staging').on('change', () => {
      $('#summit_staging').prop('checked') ? $('.summit_staging').show() : $('.summit_staging').hide();
    });
    $('#sbhc_multisite_controller').on('change', () => {
      $('#sbhc_multisite_controller').prop('checked') ? $('.sbhc_multisite_controller').show() : $('.sbhc_multisite_controller').hide();
    });

    // Initialize TinyMCE
    tinymce.init({
      selector: 'textarea#summary',
      ai_request: (request, respondWith) => respondWith.string(() => Promise.reject("See docs to implement AI Assistant"))
    });
    
    tinymce.init({
      selector: 'textarea#short_summary',
      height: 250,
      ai_request: (request, respondWith) => respondWith.string(() => Promise.reject("See docs to implement AI Assistant"))
    });

  });
})(jQuery);
</script>
<?php
}
