<?php

/**
 * Renders the "Post Event" admin page - the event submission form, moved
 * here from the front-end [events-form] shortcode (Phase 5). Field markup
 * is unchanged from the original front-end form. What changed: submission
 * goes to the REST job-queue endpoint instead of admin-ajax, and on success
 * the browser navigates to the job detail page (progress bar + per-site
 * table) instead of showing an inline processing modal.
 *
 * @link       https://summitbhc.com/
 * @since      1.7.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div id="form-page" class="wrap">
	<h1>Post Event</h1>
	<p class="ec-form-description">Enter the event details below and select the client sites where you want the event to be posted.</p>

	<form id="ec-form" class="row g-3 mt-3">

		<div id="ec-form-errors"></div>

		<div class="col-md-12 mb-3">
		  <label for="featured_image" class="form-label">Set Featured Image</label>
		  <input class="form-control" type="file" id="featured_image" name="async-upload" required>
		</div>

		<div class="col-md-12 mb-3">
		  <label for="EventTitle" class="">Event Title</label>
		  <input type="text" class="form-control" value="" name="event_title" required>
		  <div class="invalid-feedback">Please enter a title for the event.</div>
		</div>

		<div class="col-md-12 mb-3">
		  <label for="TicketPrice" class="">Ticket Price</label>
		  <input type="text" name="ticketprice" class="form-control" required>
		</div>

		<div id="presenters" class="col-md-12 mb-3">
		   <label for="Presenters">Presenters:</label>

		   <div class="presenter-wrap">
			   <div class="row">
					<div class="col-md-10">
						<input type="text" name="presenter" class="form-control presenter">
					</div>
					<div class="col-md-2 d-grid">
						<button type="button" class="btn btn-primary mb-3 add_presenter">Add Presenter</button>
					</div>
			   </div>
		   </div>

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
		  <select name="starttime" class="form-control" required></select>
		  <div class="invalid-feedback">Please select a start time.</div>
		</div>

		<div class="col-md-6 mb-3">
		  <label for="endtime">End Time</label>
		  <select name="endtime" class="form-control" required></select>
		  <div class="invalid-feedback">Please select an end time.</div>
		</div>

		<div class="col-md-6 mb-3">
		  <label for="event_timezone">Timezone</label>
		  <select name="event_timezone" class="form-control" required>
		    <?php
		    $us_timezones = array(
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
		    );

		    foreach ( $us_timezones as $tz => $label ) {
		        $selected = ( 'America/New_York' === $tz ) ? 'selected' : '';
		        echo '<option value="' . esc_attr( $tz ) . '" ' . $selected . '>' . esc_html( $label ) . '</option>';
		    }
		    ?>
		  </select>
		  <div class="invalid-feedback">Please select a timezone.</div>
		</div>

		<div class="col-md-6 mb-3">
		  <label for="event_location_type">Event Location Type</label>
		  <select name="event_location_type" class="form-control" required>
		    <option value="Virtual" selected>Virtual</option>
		    <option value="Physical">Physical</option>
		  </select>
		</div>

		<!-- Physical Location Fields (shown only when Physical is selected) -->
		<div id="physical-location-section" class="col-md-12" style="display: none;">
		  <hr>
		  <h5 class="mb-3">Physical Location Details</h5>

		  <div class="row">
		    <div class="col-md-6 mb-3">
		      <label for="location_name">Location Name <span class="text-muted">(Optional)</span></label>
		      <input type="text" name="location_name" class="form-control">
		    </div>

		    <div class="col-md-6 mb-3">
		      <label for="location_address_1">Address Line 1 <span class="text-danger">*</span></label>
		      <input type="text" name="location_address_1" class="form-control" data-required-if-physical="true">
		      <div class="invalid-feedback">Address required</div>
		    </div>
		  </div>

		  <div class="row">
		    <div class="col-md-6 mb-3">
		      <label for="location_address_2">Address Line 2 <span class="text-muted">(Optional)</span></label>
		      <input type="text" name="location_address_2" class="form-control">
		    </div>

		    <div class="col-md-6 mb-3">
		      <label for="location_city">City <span class="text-danger">*</span></label>
		      <input type="text" name="location_city" class="form-control" data-required-if-physical="true">
		      <div class="invalid-feedback">City required</div>
		    </div>
		  </div>

		  <div class="row">
		    <div class="col-md-6 mb-3">
		      <label for="location_state">State <span class="text-danger">*</span></label>
		      <input type="text" name="location_state" class="form-control" data-required-if-physical="true">
		      <div class="invalid-feedback">State required</div>
		    </div>

		    <div class="col-md-6 mb-3">
		      <label for="location_postcode">Zip <span class="text-danger">*</span></label>
		      <input type="text" name="location_postcode" class="form-control" data-required-if-physical="true">
		      <div class="invalid-feedback">Zip required</div>
		    </div>
		  </div>
		  <hr>
		</div>

		<div class="col-md-12 mb-3">
		    <label for="summary" class="">Summary</label>
		    <textarea id="summary" name="summary"></textarea>
		</div>

		<div class="col-md-12 mb-3">
		    <label for="short_summary">Short Summary <span>(this is only used on summitbhc.com's homepage currently)</span></label>
		    <textarea id="short_summary" name="short_summary"></textarea>
		</div>

		<div id="learning-objectives" class="col-md-12 mb-3">
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

		<div class="col-md-12 mb-2">
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
		        	<div id="show-form-errors"></div>
		      </div>
				<div class="col-mb-12">
					<?php
						if ( have_rows( 'site_details', 'option' ) ) :
							$site_names = array();
							while ( have_rows( 'site_details', 'option' ) ) :
								the_row();
								$site_names[] = get_sub_field( 'site_name' );
							endwhile;
							sort( $site_names, SORT_NATURAL | SORT_FLAG_CASE );
							foreach ( $site_names as $name ) :
					?>
								<div class="form-check">
								  <input class="form-check-input" type="checkbox" value="" id="<?php echo esc_attr( strtolower( str_replace( ' ', '_', $name ) ) ); ?>">
								  <label class="form-check-label" for="<?php echo esc_attr( strtolower( str_replace( ' ', '_', $name ) ) ); ?>">
									<?php echo esc_html( $name ); ?>
								  </label>
								</div>
					<?php
							endforeach;
						else: 
							echo '<p class="text-danger">Please add the client sites where events should be sent.</p>';
						endif;
					?>
				</div>
			</div>
		</div>

		<div class="col-auto">
		  <button id="submit_event" type="submit" class="btn btn-primary mb-3">Post Event</button>
		</div>

	</form>
</div>
