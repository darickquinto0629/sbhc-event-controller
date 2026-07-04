<?php

/**
 * Form submission handler
 *
 * @link       https://summitbhc.com/
 * @since      1.1.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes
 */

class Event_Controller_Form_Handler {

	/**
	 * Initialize the class and set up AJAX handler
	 */
	public function __construct() {
		add_action( 'wp_ajax_event_controller_submit_event', array( $this, 'handle_form_submission' ) );
		add_action( 'wp_ajax_nopriv_event_controller_submit_event', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Handle form submission from frontend
	 */
	public function handle_form_submission() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'event_controller_submit' ) ) {
			wp_send_json_error( array( 'message' => 'Security verification failed' ) );
		}

		// Parse data
		$selected_sites = isset( $_POST['selected_sites'] ) ? json_decode( stripslashes( $_POST['selected_sites'] ), true ) : array();
		$event_data = isset( $_POST['event_data'] ) ? json_decode( stripslashes( $_POST['event_data'] ), true ) : array();
		$file = isset( $_FILES['file'] ) ? $_FILES['file'] : null;

		if ( empty( $selected_sites ) ) {
			wp_send_json_error( array( 'message' => 'No sites selected' ) );
		}

		if ( empty( $event_data ) ) {
			wp_send_json_error( array( 'message' => 'No event data provided' ) );
		}

		$errors = array();

		// Process each selected site
		foreach ( $selected_sites as $site_id ) {
			$site_id = sanitize_text_field( $site_id );

			// Get site credentials from ACF
			$credentials = $this->get_site_credentials_by_id( $site_id );
			if ( ! $credentials ) {
				$errors[] = "Site '$site_id' not found in configuration";
				continue;
			}

			// Upload media if file exists
			$media_id = null;
			if ( $file && ! empty( $file['tmp_name'] ) ) {
				$media_id = $this->upload_media_to_remote( $credentials, $file );
				if ( is_wp_error( $media_id ) ) {
					$errors[] = "$site_id: " . $media_id->get_error_message();
					continue;
				}
			}

			// Add media ID to event data
			if ( $media_id ) {
				$event_data['featured_media'] = $media_id;
			}

			// Post event to remote site
			$result = $this->post_event_to_remote( $credentials, $event_data );
			if ( is_wp_error( $result ) ) {
				$errors[] = "$site_id: " . $result->get_error_message();
			}
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error( array( 'errors' => $errors ) );
		}

		wp_send_json_success( array( 'message' => 'All events posted successfully' ) );
	}

	/**
	 * Get site credentials by checkbox ID
	 */
	private function get_site_credentials_by_id( $site_id ) {
		if ( ! function_exists( 'have_rows' ) ) {
			return false;
		}

		if ( have_rows( 'site_details', 'option' ) ) {
			while ( have_rows( 'site_details', 'option' ) ) {
				the_row();
				$name = get_sub_field( 'site_name' );
				$normalized_id = strtolower( str_replace( ' ', '_', $name ) );

				if ( $normalized_id === $site_id ) {
					return array(
						'name'     => $name,
						'url'      => trim( get_sub_field( 'site_url' ) ),
						'username' => trim( get_sub_field( 'application_password_name' ) ),
						'password' => trim( get_sub_field( 'application_password' ) ),
					);
				}
			}
		}

		return false;
	}

	/**
	 * Upload media to remote site
	 */
	private function upload_media_to_remote( $credentials, $file ) {
		$upload_url = rtrim( $credentials['url'], '/' ) . '/wp-json/sbhc/v2/media_upload';

		// Validate URL
		if ( ! filter_var( $upload_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $upload_url, 'https://' ) ) {
			return new WP_Error( 'invalid_url', 'Invalid or unsecure URL' );
		}

		// Prepare multipart body
		$boundary = wp_generate_password( 24 );
		$body = $this->build_multipart_body( $file, $boundary );

		// Make request
		$response = wp_remote_post(
			$upload_url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['password'] ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'      => $body,
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'upload_failed', 'Failed to upload media: ' . $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status && 201 !== (int) $status ) {
			return new WP_Error( 'upload_error', "Server returned status $status" );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['data'] ) || ! isset( $data['success'] ) || ! $data['success'] ) {
			return new WP_Error( 'invalid_response', 'Invalid response from media upload' );
		}

		return $data['data'];
	}

	/**
	 * Post event to remote site
	 */
	private function post_event_to_remote( $credentials, $event_data ) {
		$post_url = rtrim( $credentials['url'], '/' ) . '/wp-json/sbhc/v2/postevent';

		// Validate URL
		if ( ! filter_var( $post_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $post_url, 'https://' ) ) {
			return new WP_Error( 'invalid_url', 'Invalid or unsecure URL' );
		}

		// Make request
		$response = wp_remote_post(
			$post_url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['password'] ),
					'Content-Type'  => 'application/json',
				),
				'body'      => json_encode( $event_data ),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'post_failed', 'Failed to post event: ' . $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status && 201 !== (int) $status ) {
			return new WP_Error( 'post_error', "Server returned status $status" );
		}

		return true;
	}

	/**
	 * Build multipart form body
	 */
	private function build_multipart_body( $file, $boundary ) {
		$body = '';

		if ( isset( $file['tmp_name'] ) && isset( $file['name'] ) ) {
			$file_content = file_get_contents( $file['tmp_name'] );
			$file_name    = basename( $file['name'] );

			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="async-upload"; filename="' . $file_name . "\"\r\n";
			$body .= 'Content-Type: ' . ( $file['type'] ?? 'application/octet-stream' ) . "\r\n\r\n";
			$body .= $file_content . "\r\n";
			$body .= '--' . $boundary . '--';
		}

		return $body;
	}
}

// Initialize on plugins_loaded
add_action( 'plugins_loaded', function() {
	new Event_Controller_Form_Handler();
} );
