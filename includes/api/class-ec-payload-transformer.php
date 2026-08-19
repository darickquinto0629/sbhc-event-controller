<?php

/**
 * Transforms the plugin's internal event-data shape into the Events Manager
 * REST API's payload schema. Moved verbatim from
 * Event_Controller_Form_Handler::transform_to_em_api_schema() (Phase 3) -
 * no behavior change, only relocated so both the legacy AJAX handler and the
 * new queued delivery worker can use identical transformation logic without
 * duplicating it going forward.
 *
 * @link       https://summitbhc.com/
 * @since      1.5.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/api
 */

class EC_Payload_Transformer {

	/**
	 * @param array $event_data Raw event form data (same shape submitted by the front-end).
	 * @return array Events Manager API payload.
	 */
	public static function transform( array $event_data ) {
		// Extract meta_input if it exists
		$meta = isset( $event_data['meta_input'] ) ? $event_data['meta_input'] : array();

		// Build location group field for ACF
		$location_group = array(
			'location_name' => $event_data['location_name'] ?? '',
			'country'       => 'United States',
			'address_1'     => $event_data['location_address_1'] ?? '',
			'address_2'     => $event_data['location_address_2'] ?? '',
			'city'          => $event_data['location_city'] ?? '',
			'state'         => $event_data['location_state'] ?? '',
			'zip_code'      => $event_data['location_postcode'] ?? '',
		);

		// Build EM API payload
		$em_payload = array(
			'event_name'           => $event_data['event_title'] ?? '',
			'content'              => '',
			'event_type'           => 'single',
			'post_status'          => 'publish',
			'event_start_date'     => $meta['event_start_date'] ?? '',
			'event_end_date'       => $meta['event_end_date'] ?? '',
			'event_start_time'     => $meta['event_start_time'] ?? '',
			'event_end_time'       => $meta['event_end_time'] ?? '',
			'event_timezone'       => $meta['event_time_zone'] ?? 'UTC',
			'event_all_day'        => false,
			'event_rsvp'           => 0,
			'event_rsvp_spaces'    => 0,
			'event_spaces'         => 0,
			'event_active_status'  => 1,
			'event_private'        => 0,
			'featured_image'       => $event_data['featured_media'] ?? 0,
			'location_id'          => 0,
			'location_region'      => '',
			'location_latitude'    => 0,
			'location_longitude'   => 0,
			'event_categories'     => array(),
			'event_tags'           => array(),
			'em_attributes'        => array(),
			'data_privacy_consent' => true,
			'data_comms_consent'   => true,

			// Custom ACF-mapped fields (will be picked up by em_api_event_save hook on remote site)
			'presenter'            => $event_data['presenter'] ?? '',
			'ticket_price'         => $event_data['ticket_price'] ?? '',
			'event_location_type'  => $meta['event_location_type'] ?? $event_data['event_type'] ?? '',
			'summary'              => $event_data['summary'] ?? '',
			'short_summary'        => $event_data['short_summary'] ?? '',
			'contact_name'         => $event_data['contact_name'] ?? '',
			'contact_number'       => $event_data['contact_number'] ?? '',
			'contact_address'      => $event_data['contact_address'] ?? '',
			'registration_link'    => $event_data['registration_link'] ?? '',
			'objectives'           => $event_data['objectives'] ?? array(),

			// Location as ACF group field
			'location'             => $location_group,
		);

		return $em_payload;
	}
}
