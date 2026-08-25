<?php

/**
 * REST API surface for the queued event-delivery pipeline.
 *
 * All routes require the manage_options capability. WordPress' built-in
 * cookie + X-WP-Nonce ('wp_rest') authentication applies automatically to
 * REST requests, which is why these routes are not vulnerable to the
 * capability gap described in the architecture review (C1) for the legacy
 * admin-ajax endpoint.
 *
 * @link       https://summitbhc.com/
 * @since      1.3.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/rest
 */

class EC_REST_Controller {

	const NAMESPACE_NAME = 'event-controller/v1';

	/**
	 * Register the /jobs and /jobs/{id} routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_NAME,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_job' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_jobs' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			'/jobs/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			'/jobs/(?P<id>\d+)/retry',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'retry_job' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			'/jobs/(?P<id>\d+)/cancel',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel_job' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * POST /event-controller/v1/jobs
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( WP_REST_Request $request ) {
		$selected_sites = $request->get_param( 'selected_sites' );
		$event_data     = $request->get_param( 'event_data' );
		$files          = $request->get_file_params();

		if ( is_string( $selected_sites ) ) {
			// WP_REST_Server already unslashes body params before this callback runs.
			$selected_sites = json_decode( $selected_sites, true );
		}

		if ( is_string( $event_data ) ) {
			$event_data = json_decode( $event_data, true );
		}

		if ( empty( $selected_sites ) || ! is_array( $selected_sites ) ) {
			return new WP_Error( 'ec_no_sites', 'No sites selected', array( 'status' => 400 ) );
		}

		if ( empty( $event_data ) || ! is_array( $event_data ) ) {
			return new WP_Error( 'ec_no_event_data', 'No event data provided', array( 'status' => 400 ) );
		}

		$file = isset( $files['file'] ) ? $files['file'] : null;

		$job_manager = new EC_Job_Manager();
		$result      = $job_manager->create( $selected_sites, $event_data, $file, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = ! empty( $result['is_duplicate'] ) ? 200 : 202;

		return new WP_REST_Response( $result, $status );
	}

	/**
	 * GET /event-controller/v1/jobs/{id}
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		$job_id = (int) $request->get_param( 'id' );

		$job_manager = new EC_Job_Manager();
		$job         = $job_manager->get_job_status( $job_id );

		if ( ! $job ) {
			return new WP_Error( 'ec_job_not_found', 'Job not found', array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $job, 200 );
	}

	/**
	 * GET /event-controller/v1/jobs
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function list_jobs( WP_REST_Request $request ) {
		$job_manager = new EC_Job_Manager();
		$limit       = (int) $request->get_param( 'limit' ) ?: 20;

		return new WP_REST_Response( $job_manager->list_jobs( $limit ), 200 );
	}

	/**
	 * POST /event-controller/v1/jobs/{id}/retry
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function retry_job( WP_REST_Request $request ) {
		$job_id = (int) $request->get_param( 'id' );

		$job_manager = new EC_Job_Manager();
		if ( ! $job_manager->get_raw_job( $job_id ) ) {
			return new WP_Error( 'ec_job_not_found', 'Job not found', array( 'status' => 404 ) );
		}

		$job_manager->retry_failed_sites( $job_id );

		return new WP_REST_Response( $job_manager->get_job_status( $job_id ), 200 );
	}

	/**
	 * POST /event-controller/v1/jobs/{id}/cancel
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( WP_REST_Request $request ) {
		$job_id = (int) $request->get_param( 'id' );

		$job_manager = new EC_Job_Manager();
		if ( ! $job_manager->get_raw_job( $job_id ) ) {
			return new WP_Error( 'ec_job_not_found', 'Job not found', array( 'status' => 404 ) );
		}

		$job_manager->cancel_job( $job_id );

		return new WP_REST_Response( $job_manager->get_job_status( $job_id ), 200 );
	}
}
