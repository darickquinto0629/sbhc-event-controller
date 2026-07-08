<?php

/**
 * Event Controller Form Handler
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/public
 */

class Event_Controller_Form {

	/**
	 * Initialize the form handler and register hooks
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ) );
		add_shortcode( 'events-form', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Register all scripts and styles
	 * This runs once on wp_enqueue_scripts action
	 *
	 * @since 1.0.0
	 */
	public function register_scripts() {
		wp_register_style( 'bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' );
		wp_register_style( 'daterange-picker', 'https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css' );

		wp_register_script( 'bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', array(), '5.3.3', true );
		wp_register_script( 'drp-moment', 'https://cdn.jsdelivr.net/momentjs/latest/moment.min.js', array(), '', true );
		wp_register_script( 'daterange-picker', 'https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js', array( 'drp-moment' ), '', true );
        // This is the tiny mce api key from darickquinto@gmail.com. this should have to be replaced before production.
        //wp_register_script('wysiwyg', 'https://cdn.tiny.cloud/1/2gsxlj7p8c0istuycljhrr5v0vkef00u9iigmksvo3uiqfm5/tinymce/7/tinymce.min.js', array(), '', true);
		wp_register_script( 'wysiwyg', 'https://cdn.tiny.cloud/1/exwkwqhfq45kt6ljbutjnd6hvf3vpgbq574lh980b83d7n3a/tinymce/7/tinymce.min.js', array(), '7', true );
	}

	/**
	 * Enqueue scripts and styles for the shortcode
	 * This only runs when the shortcode is rendered
	 *
	 * @since 1.0.0
	 */
	private function enqueue_scripts() {
		wp_enqueue_style( 'bootstrap' );
		wp_enqueue_style( 'daterange-picker' );
		wp_enqueue_script( 'bootstrap' );
		wp_enqueue_script( 'drp-moment' );
		wp_enqueue_script( 'daterange-picker' );
		wp_enqueue_script( 'wysiwyg' );
		wp_enqueue_script( 'event-controller' );

		// Localize script with data
		$ajax_data = array(
			'nonce' => wp_create_nonce( 'event_controller_nonce' ),
		);
		wp_localize_script( 'event-controller', 'data_for_ajaxsubmit', $ajax_data );
	}

	/**
	 * Render the events form shortcode
	 * Only displays if current user can manage options
	 *
	 * @since 1.0.0
	 * @return string The form HTML or error message
	 */
	public function render_shortcode() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p class="center">You need to be logged in as an Admin to use the Event Controller.</p>';
		}

		// Enqueue scripts only when shortcode is used
		$this->enqueue_scripts();

		ob_start();
		?>

		<div id="show-form-errors"></div>
		<form id="ec-form" class="row g-3 mt-3">

		<!-- Modal -->
			
		<div class="modal fade" id="sendingData" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="sendingData" aria-hidden="true">
		  <div class="modal-dialog modal-lg modal-dialog-centered">
		    <div class="modal-content">
		      <div class="modal-header">
		        <h3 class="modal-title fs-5" id="post_event_status">Posting Event</h3>
		        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
		      </div>
		      <div class="modal-body">
				    <div class="text-center mb-3 loader"></div>
		      </div>
		    </div>
		  </div>
		</div>


	<div class="col-md-12 mb-3">
	  <label for="featuredImage" class="form-label">Set Featured Image</label>
	  <input class="form-control" type="file" id="featured_image" name="async-upload">
	</div>		
	
  <div class="col-md-12 mb-3">
    <label for="EventTitle" class="">Event Title</label>
    <input type="text" class="form-control" value="" name="event_title" required>
    <div class="invalid-feedback">Please enter a title for the event.</div>
  </div>
		
  <div class="col-md-6 mb-3">
    <label for="Presenter">Presenter</label>
    <input type="text" name="presenter" class="form-control">
  </div>
	
  <div class="col-md-6 mb-3">
    <label for="TicketPrice" class="">Ticket Price</label>
    <input type="text" name="ticketprice" class="form-control">
  </div>	
	
  <div class="col-md-12 mb-3">
    <label for="EventLocation">Event Location</label>
    <input type="text" name="eventlocation" class="form-control" required>
    <div class="invalid-feedback">Please provide a location for the event.</div>
  </div>	
	
  <div class="col-md-6 mb-3" style="display: none">
    <label for="EventType" class="">Event Type</label>
    <input type="text" name="eventtype" class="form-control">
  </div>		
	
  <div class="col-md-6 mb-3">
    <label for="StartDate">Start Date</label>
    <input type="text" name="startdate" class="form-control" required>
    <div class="invalid-feedback">Please select a start date.</div>
  </div>
	
  <div class="col-md-6 mb-3">
    <label for="enddate">End Date</label>
    <input type="text" class="form-control" name="enddate" required>
    <div class="invalid-feedback">Please select an end date.</div>
  </div>

  <div class="col-md-6 mb-3">
    <label for="starttime">Start Time</label>
    <select name="starttime" class="form-control" required>
      <option value="00:00:00">12:00 AM</option>
      <option value="00:15:00">12:15 AM</option>
      <option value="00:30:00">12:30 AM</option>
      <option value="00:45:00">12:45 AM</option>
      <option value="01:00:00">1:00 AM</option>
      <option value="01:15:00">1:15 AM</option>
      <option value="01:30:00">1:30 AM</option>
      <option value="01:45:00">1:45 AM</option>
      <option value="02:00:00">2:00 AM</option>
      <option value="02:15:00">2:15 AM</option>
      <option value="02:30:00">2:30 AM</option>
      <option value="02:45:00">2:45 AM</option>
      <option value="03:00:00">3:00 AM</option>
      <option value="03:15:00">3:15 AM</option>
      <option value="03:30:00">3:30 AM</option>
      <option value="03:45:00">3:45 AM</option>
      <option value="04:00:00">4:00 AM</option>
      <option value="04:15:00">4:15 AM</option>
      <option value="04:30:00">4:30 AM</option>
      <option value="04:45:00">4:45 AM</option>
      <option value="05:00:00">5:00 AM</option>
      <option value="05:15:00">5:15 AM</option>
      <option value="05:30:00">5:30 AM</option>
      <option value="05:45:00">5:45 AM</option>
      <option value="06:00:00">6:00 AM</option>
      <option value="06:15:00">6:15 AM</option>
      <option value="06:30:00">6:30 AM</option>
      <option value="06:45:00">6:45 AM</option>
      <option value="07:00:00">7:00 AM</option>
      <option value="07:15:00">7:15 AM</option>
      <option value="07:30:00">7:30 AM</option>
      <option value="07:45:00">7:45 AM</option>
      <option value="08:00:00">8:00 AM</option>
      <option value="08:15:00">8:15 AM</option>
      <option value="08:30:00">8:30 AM</option>
      <option value="08:45:00">8:45 AM</option>
      <option value="09:00:00">9:00 AM</option>
      <option value="09:15:00">9:15 AM</option>
      <option value="09:30:00">9:30 AM</option>
      <option value="09:45:00">9:45 AM</option>
      <option value="10:00:00">10:00 AM</option>
      <option value="10:15:00">10:15 AM</option>
      <option value="10:30:00">10:30 AM</option>
      <option value="10:45:00">10:45 AM</option>
      <option value="11:00:00">11:00 AM</option>
      <option value="11:15:00">11:15 AM</option>
      <option value="11:30:00">11:30 AM</option>
      <option value="11:45:00">11:45 AM</option>
      <option value="12:00:00">12:00 PM</option>
      <option value="12:15:00">12:15 PM</option>
      <option value="12:30:00">12:30 PM</option>
      <option value="12:45:00">12:45 PM</option>
      <option value="13:00:00">1:00 PM</option>
      <option value="13:15:00">1:15 PM</option>
      <option value="13:30:00">1:30 PM</option>
      <option value="13:45:00">1:45 PM</option>
      <option value="14:00:00">2:00 PM</option>
      <option value="14:15:00">2:15 PM</option>
      <option value="14:30:00">2:30 PM</option>
      <option value="14:45:00">2:45 PM</option>
      <option value="15:00:00">3:00 PM</option>
      <option value="15:15:00">3:15 PM</option>
      <option value="15:30:00">3:30 PM</option>
      <option value="15:45:00">3:45 PM</option>
      <option value="16:00:00">4:00 PM</option>
      <option value="16:15:00">4:15 PM</option>
      <option value="16:30:00">4:30 PM</option>
      <option value="16:45:00">4:45 PM</option>
      <option value="17:00:00">5:00 PM</option>
      <option value="17:15:00">5:15 PM</option>
      <option value="17:30:00">5:30 PM</option>
      <option value="17:45:00">5:45 PM</option>
      <option value="18:00:00">6:00 PM</option>
      <option value="18:15:00">6:15 PM</option>
      <option value="18:30:00">6:30 PM</option>
      <option value="18:45:00">6:45 PM</option>
      <option value="19:00:00">7:00 PM</option>
      <option value="19:15:00">7:15 PM</option>
      <option value="19:30:00">7:30 PM</option>
      <option value="19:45:00">7:45 PM</option>
      <option value="20:00:00">8:00 PM</option>
      <option value="20:15:00">8:15 PM</option>
      <option value="20:30:00">8:30 PM</option>
      <option value="20:45:00">8:45 PM</option>
      <option value="21:00:00">9:00 PM</option>
      <option value="21:15:00">9:15 PM</option>
      <option value="21:30:00">9:30 PM</option>
      <option value="21:45:00">9:45 PM</option>
      <option value="22:00:00">10:00 PM</option>
      <option value="22:15:00">10:15 PM</option>
      <option value="22:30:00">10:30 PM</option>
      <option value="22:45:00">10:45 PM</option>
      <option value="23:00:00">11:00 PM</option>
      <option value="23:15:00">11:15 PM</option>
      <option value="23:30:00">11:30 PM</option>
      <option value="23:45:00">11:45 PM</option>
    </select>
    <div class="invalid-feedback">Please select a start time.</div>
  </div>

  <div class="col-md-6 mb-3">
    <label for="endtime">End Time</label>
    <select name="endtime" class="form-control" required>
      <option value="00:00:00">12:00 AM</option>
      <option value="00:15:00">12:15 AM</option>
      <option value="00:30:00">12:30 AM</option>
      <option value="00:45:00">12:45 AM</option>
      <option value="01:00:00">1:00 AM</option>
      <option value="01:15:00">1:15 AM</option>
      <option value="01:30:00">1:30 AM</option>
      <option value="01:45:00">1:45 AM</option>
      <option value="02:00:00">2:00 AM</option>
      <option value="02:15:00">2:15 AM</option>
      <option value="02:30:00">2:30 AM</option>
      <option value="02:45:00">2:45 AM</option>
      <option value="03:00:00">3:00 AM</option>
      <option value="03:15:00">3:15 AM</option>
      <option value="03:30:00">3:30 AM</option>
      <option value="03:45:00">3:45 AM</option>
      <option value="04:00:00">4:00 AM</option>
      <option value="04:15:00">4:15 AM</option>
      <option value="04:30:00">4:30 AM</option>
      <option value="04:45:00">4:45 AM</option>
      <option value="05:00:00">5:00 AM</option>
      <option value="05:15:00">5:15 AM</option>
      <option value="05:30:00">5:30 AM</option>
      <option value="05:45:00">5:45 AM</option>
      <option value="06:00:00">6:00 AM</option>
      <option value="06:15:00">6:15 AM</option>
      <option value="06:30:00">6:30 AM</option>
      <option value="06:45:00">6:45 AM</option>
      <option value="07:00:00">7:00 AM</option>
      <option value="07:15:00">7:15 AM</option>
      <option value="07:30:00">7:30 AM</option>
      <option value="07:45:00">7:45 AM</option>
      <option value="08:00:00">8:00 AM</option>
      <option value="08:15:00">8:15 AM</option>
      <option value="08:30:00">8:30 AM</option>
      <option value="08:45:00">8:45 AM</option>
      <option value="09:00:00">9:00 AM</option>
      <option value="09:15:00">9:15 AM</option>
      <option value="09:30:00">9:30 AM</option>
      <option value="09:45:00">9:45 AM</option>
      <option value="10:00:00">10:00 AM</option>
      <option value="10:15:00">10:15 AM</option>
      <option value="10:30:00">10:30 AM</option>
      <option value="10:45:00">10:45 AM</option>
      <option value="11:00:00">11:00 AM</option>
      <option value="11:15:00">11:15 AM</option>
      <option value="11:30:00">11:30 AM</option>
      <option value="11:45:00">11:45 AM</option>
      <option value="12:00:00">12:00 PM</option>
      <option value="12:15:00">12:15 PM</option>
      <option value="12:30:00">12:30 PM</option>
      <option value="12:45:00">12:45 PM</option>
      <option value="13:00:00">1:00 PM</option>
      <option value="13:15:00">1:15 PM</option>
      <option value="13:30:00">1:30 PM</option>
      <option value="13:45:00">1:45 PM</option>
      <option value="14:00:00">2:00 PM</option>
      <option value="14:15:00">2:15 PM</option>
      <option value="14:30:00">2:30 PM</option>
      <option value="14:45:00">2:45 PM</option>
      <option value="15:00:00">3:00 PM</option>
      <option value="15:15:00">3:15 PM</option>
      <option value="15:30:00">3:30 PM</option>
      <option value="15:45:00">3:45 PM</option>
      <option value="16:00:00">4:00 PM</option>
      <option value="16:15:00">4:15 PM</option>
      <option value="16:30:00">4:30 PM</option>
      <option value="16:45:00">4:45 PM</option>
      <option value="17:00:00">5:00 PM</option>
      <option value="17:15:00">5:15 PM</option>
      <option value="17:30:00">5:30 PM</option>
      <option value="17:45:00">5:45 PM</option>
      <option value="18:00:00">6:00 PM</option>
      <option value="18:15:00">6:15 PM</option>
      <option value="18:30:00">6:30 PM</option>
      <option value="18:45:00">6:45 PM</option>
      <option value="19:00:00">7:00 PM</option>
      <option value="19:15:00">7:15 PM</option>
      <option value="19:30:00">7:30 PM</option>
      <option value="19:45:00">7:45 PM</option>
      <option value="20:00:00">8:00 PM</option>
      <option value="20:15:00">8:15 PM</option>
      <option value="20:30:00">8:30 PM</option>
      <option value="20:45:00">8:45 PM</option>
      <option value="21:00:00">9:00 PM</option>
      <option value="21:15:00">9:15 PM</option>
      <option value="21:30:00">9:30 PM</option>
      <option value="21:45:00">9:45 PM</option>
      <option value="22:00:00">10:00 PM</option>
      <option value="22:15:00">10:15 PM</option>
      <option value="22:30:00">10:30 PM</option>
      <option value="22:45:00">10:45 PM</option>
      <option value="23:00:00">11:00 PM</option>
      <option value="23:15:00">11:15 PM</option>
      <option value="23:30:00">11:30 PM</option>
      <option value="23:45:00">11:45 PM</option>
    </select>
    <div class="invalid-feedback">Please select an end time.</div>
  </div>

  <div class="col-md-6 mb-3">
    <label for="event_timezone">Timezone</label>
    <select name="event_timezone" class="form-control" required>
      <?php
      $us_timezones = [
          'America/New_York'    => 'Eastern Time (EST/EDT)',
          'America/Detroit'     => 'Eastern Time - Detroit',
          'America/Kentucky/Louisville' => 'Eastern Time - Louisville, KY',
          'America/Kentucky/Monticello' => 'Eastern Time - Monticello, KY',
          'America/Indiana/Indianapolis' => 'Eastern Time - Indianapolis, IN',
          'America/Indiana/Marengo'     => 'Eastern Time - Marengo, IN',
          'America/Indiana/Vevay'       => 'Eastern Time - Vevay, IN',
          'America/Chicago'    => 'Central Time (CST/CDT)',
          'America/Indiana/Knox'       => 'Central Time - Knox, IN',
          'America/Indiana/Tell_City'  => 'Central Time - Tell City, IN',
          'America/Menominee' => 'Central Time - Menominee, MI',
          'America/North_Dakota/Center' => 'Central Time - Center, ND',
          'America/North_Dakota/New_Salem' => 'Central Time - New Salem, ND',
          'America/North_Dakota/Beulah'   => 'Central Time - Beulah, ND',
          'America/Denver'     => 'Mountain Time (MST/MDT)',
          'America/Boise'      => 'Mountain Time - Boise, ID',
          'America/Phoenix'    => 'Mountain Standard Time (no DST)',
          'America/Los_Angeles'=> 'Pacific Time (PST/PDT)',
          'America/Anchorage'  => 'Alaska Time (AKST/AKDT)',
          'America/Juneau'     => 'Alaska Time - Juneau, AK',
          'America/Sitka'      => 'Alaska Time - Sitka, AK',
          'America/Metlakatla' => 'Alaska Time - Metlakatla, AK',
          'America/Yakutat'    => 'Alaska Time - Yakutat, AK',
          'America/Nome'       => 'Alaska Time - Nome, AK',
          'America/Adak'       => 'Hawaii-Aleutian Time (HAST/HADT)',
          'Pacific/Honolulu'   => 'Hawaii Standard Time (no DST)',
          'Pacific/Guam'       => 'Guam (ChST)',
          'Pacific/Saipan'     => 'Northern Mariana Islands (ChST)',
          'Pacific/Pago_Pago'  => 'American Samoa (SST)',
          'Pacific/Midway'     => 'Midway Islands (SST)',
          'Pacific/Wake'       => 'Wake Island (WAKT)',
      ];

      foreach ($us_timezones as $tz => $label) {
          $selected = ($tz === 'America/Chicago') ? 'selected' : '';
          echo '<option value="' . esc_attr($tz) . '" ' . $selected . '>' . esc_html($label) . '</option>';
      }
      ?>
    </select>
    <div class="invalid-feedback">Please select a timezone.</div>
  </div>

  <div class="col-md-6 mb-3">
    <label for="event_location_type">Event Location Type</label>
    <select name="event_location_type" class="form-control" required>
      <option value="Virtual" selected>Online (URL)</option>
      <option value="Physical">Physical</option>
      <option value="">None</option>
    </select>
  </div>
	
   <div class="col-md-12 mb-5"> 
	    <label for="summary" class="">Summary</label>
        <textarea id="summary" name="summary"></textarea>
	</div>
	
   <div class="col-md-12 mb-5"> 
	    <label for="summary" class="">Short Summary</label>
        <textarea id="short_summary" name="short_summary"></textarea>
	</div>	
	
   <div id="learning-objectives" class="col-md-12 mb-5"> 
	   <h3>Learning Objectives:</h3>
	   
	   <div class="objective-wrap">
		   <div class="row">
				<div class="col-md-10">
					<input type="text" name="objectives" class="form-control objective">
				</div>
				<div class="col-md-2 d-grid">
					<button type="button" class="btn btn-primary mb-3 add_objective">Add Objective</button>
				</div>				   
		   </div>		   
	   </div>

	</div>	
	
	<div class="col-md-12 mb-5">
		<div class="row">
			<h3>Event Contact Information:</h3>
			<div class="col-md-6 mb-3">
				<label for="contactName" class="">Contact Name</label>
				<input type="text" name="contact_name" class="form-control">
			</div>

			<div class="col-md-6 mb-3">
				<label for="contactNumber" class="">Contact Number</label>
				<input type="text" name="contact_number" class="form-control">
			</div>
			<div class="col-md-6 mb-3">
				<label for="contactAddress" class="">Contact Email Address</label>
				<input type="text" name="contact_address" class="form-control">
			</div>

			<div class="col-md-6 mb-3">
				<label for="regLink" class="">Registration Link</label>
				<input type="url" name="registration_link" class="form-control">
			</div>	
		</div>
	</div>
			
	<div class="col-md-12 mb-5">
		<div class="row">
			<div class="col-mb-12">
				<?php
					if ( have_rows( 'site_details', 'option' ) ) :
						while ( have_rows( 'site_details', 'option' ) ) :
							the_row();
							$name = get_sub_field( 'site_name' );
				?>
							<div class="form-check">
							  <input class="form-check-input" type="checkbox" value="" id="<?php echo esc_attr( strtolower( str_replace( ' ', '_', $name ) ) ); ?>">
							  <label class="form-check-label" for="<?php echo esc_attr( strtolower( str_replace( ' ', '_', $name ) ) ); ?>">
								<?php echo esc_html( $name ); ?>
							  </label>
							</div>
				<?php
						endwhile;
					endif;
				?>					
			</div>	
		</div>	
	</div>		
	
  <div class="col-auto">
    <button id="submit_event" type="submit" class="btn btn-primary mb-3">Post Event</button>
  </div>

		</form>

		<?php
		return ob_get_clean();
	}
}

// Instantiate the form handler
new Event_Controller_Form();
