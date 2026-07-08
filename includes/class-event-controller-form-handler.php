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
		error_log('EVENT_CONTROLLER: Form submission received');
		
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) ) {
			error_log('EVENT_CONTROLLER: No nonce in POST');
			wp_send_json_error( array( 'message' => 'No nonce provided' ) );
		}
		
		if ( ! wp_verify_nonce( $_POST['nonce'], 'event_controller_submit' ) ) {
			error_log('EVENT_CONTROLLER: Nonce verification failed. Nonce: ' . $_POST['nonce']);
			wp_send_json_error( array( 'message' => 'Nonce verification failed' ) );
		}

		error_log('EVENT_CONTROLLER: Nonce verified');

		// Parse data
		$selected_sites = isset( $_POST['selected_sites'] ) ? json_decode( stripslashes( $_POST['selected_sites'] ), true ) : array();
		$event_data = isset( $_POST['event_data'] ) ? json_decode( stripslashes( $_POST['event_data'] ), true ) : array();
		$file = isset( $_FILES['file'] ) ? $_FILES['file'] : null;

		error_log('EVENT_CONTROLLER: Selected sites: ' . json_encode($selected_sites));
		error_log('EVENT_CONTROLLER: Event data received: ' . json_encode($event_data));
		error_log('EVENT_CONTROLLER: File: ' . json_encode($file ? array('name' => $file['name'], 'size' => $file['size']) : 'none'));

		if ( empty( $selected_sites ) ) {
			error_log('EVENT_CONTROLLER: No sites selected');
			wp_send_json_error( array( 'message' => 'No sites selected' ) );
		}

		if ( empty( $event_data ) ) {
			error_log('EVENT_CONTROLLER: No event data');
			wp_send_json_error( array( 'message' => 'No event data provided' ) );
		}

		$errors = array();
		$all_responses = array();  // Collect responses with empty_fields data

		// Process each selected site
		foreach ( $selected_sites as $site_id ) {
			$site_id = sanitize_text_field( $site_id );
			error_log('EVENT_CONTROLLER: Processing site: ' . $site_id);

			// Get site credentials from ACF
			$credentials = $this->get_site_credentials_by_id( $site_id );
			if ( ! $credentials ) {
				error_log('EVENT_CONTROLLER: Credentials not found for site: ' . $site_id);
				$errors[] = "Site '$site_id' not found in configuration";
				continue;
			}

			error_log('EVENT_CONTROLLER: Credentials found for site: ' . $site_id);

			// Upload media if file exists
			$media_id = null;
			if ( $file && ! empty( $file['tmp_name'] ) ) {
				error_log('EVENT_CONTROLLER: Uploading media for site: ' . $site_id);
				$media_id = $this->upload_media_to_remote( $credentials, $file );
				if ( is_wp_error( $media_id ) ) {
					error_log('EVENT_CONTROLLER: Media upload failed for ' . $site_id . ': ' . $media_id->get_error_message());
					$errors[] = "$site_id: " . $media_id->get_error_message();
					continue;
				}
				error_log('EVENT_CONTROLLER: Media uploaded successfully for site: ' . $site_id . ', ID: ' . $media_id);
			}

			// Add media ID to event data
			if ( $media_id ) {
				$event_data['featured_media'] = $media_id;
			}

			// Post event to remote site
			error_log('EVENT_CONTROLLER: Posting event to site: ' . $site_id);
			$result = $this->post_event_to_remote( $credentials, $event_data );
			if ( is_wp_error( $result ) ) {
				error_log('EVENT_CONTROLLER: Event post failed for ' . $site_id . ': ' . $result->get_error_message());
				$errors[] = "$site_id: " . $result->get_error_message();
			} else {
				error_log('EVENT_CONTROLLER: Event posted successfully to site: ' . $site_id);
				$all_responses[] = $result;  // Store the response with empty_fields
			}
		}

		if ( ! empty( $errors ) ) {
			error_log('EVENT_CONTROLLER: Submission completed with errors: ' . json_encode($errors));
			wp_send_json_error( array( 'errors' => $errors ) );
		}

		error_log('EVENT_CONTROLLER: Submission completed successfully');
		// Pass through responses with empty_fields diagnostics from event-client
		wp_send_json_success( array( 
			'message' => 'All events posted successfully',
			'responses' => $all_responses
		) );
	}

	/**
	 * Get site credentials by checkbox ID
	 */
	private function get_site_credentials_by_id( $site_id ) {
		if ( ! function_exists( 'have_rows' ) ) {
			error_log('EVENT_CONTROLLER: ACF function have_rows not available');
			return false;
		}

		error_log('EVENT_CONTROLLER: Looking up credentials for site ID: ' . $site_id);

		if ( have_rows( 'site_details', 'option' ) ) {
			while ( have_rows( 'site_details', 'option' ) ) {
				the_row();
				$name = get_sub_field( 'site_name' );
				$normalized_id = strtolower( str_replace( ' ', '_', $name ) );

				error_log('EVENT_CONTROLLER: Checking site name: ' . $name . ', normalized ID: ' . $normalized_id);

				if ( $normalized_id === $site_id ) {
					$creds = array(
						'name'     => $name,
						'url'      => trim( get_sub_field( 'site_url' ) ),
						'username' => trim( get_sub_field( 'application_password_name' ) ),
						'password' => trim( get_sub_field( 'application_password' ) ),
					);
					error_log('EVENT_CONTROLLER: Credentials loaded for ' . $name);
					return $creds;
				}
			}
		} else {
			error_log('EVENT_CONTROLLER: No site_details repeater found in options');
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
			error_log('EVENT_CONTROLLER: Invalid upload URL: ' . $upload_url);
			return new WP_Error( 'invalid_url', 'Invalid or unsecure URL' );
		}

		// Prepare multipart body
		$boundary = wp_generate_password( 24 );
		$body = $this->build_multipart_body( $file, $boundary );

		error_log('EVENT_CONTROLLER: Uploading to: ' . $upload_url . ', file: ' . $file['name']);

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
			error_log('EVENT_CONTROLLER: wp_remote_post failed: ' . $response->get_error_message());
			
			// Map common connection errors to user-friendly messages
			// These are standard WordPress/cURL error codes returned when connection fails
			// before getting an HTTP response (connection-level errors, not HTTP status errors)
			// Error codes: 6=DNS resolution failed, 7=connection failed, 28=operation timeout, 60=SSL cert error
			$error_msg = $response->get_error_message();
			$connection_errors = array(
				'cURL error 7' => 'Failed to connect to remote site - site may be down or URL is incorrect',
				'cURL error 28' => 'Remote site is not responding - connection timed out',
				'cURL error 6' => 'Invalid site URL or DNS not resolving - check site URL configuration',
				'cURL error 60' => 'SSL certificate error on remote site - contact site administrator',
			);
			
			$user_message = 'Failed to upload media: Failed to connect to remote site';
			foreach ( $connection_errors as $key => $message ) {
				if ( strpos( $error_msg, $key ) !== false ) {
					$user_message = 'Failed to upload media: ' . $message;
					break;
				}
			}
			
			return new WP_Error( 'upload_failed', $user_message );
		}

		$status = wp_remote_retrieve_response_code( $response );
		error_log('EVENT_CONTROLLER: Upload response status: ' . $status);
		
		if ( ! in_array( (int) $status, array( 200, 201 ), true ) ) {
			$body_text = wp_remote_retrieve_body( $response );
			error_log('EVENT_CONTROLLER: Upload response body: ' . $body_text);

			// Map HTTP status codes to clear error messages
			$http_errors = array(
				'401' => 'Authentication failed - check application username and password',
				'403' => 'Access denied - user does not have permission to upload files',
				'404' => 'Remote media upload endpoint not found - check site URL configuration',
				'413' => 'File is too large - reduce file size',
				'500' => 'Server error on remote site - check event client error logs',
				'503' => 'Remote site service unavailable - try again later',
			);

			$error_message = isset( $http_errors[ (string) $status ] ) 
				? $http_errors[ (string) $status ] 
				: "Server returned HTTP status $status";

			// Build clean error message without verbose response body
			$error_details = "Media upload failed - $error_message";

			return new WP_Error( 'upload_error', $error_details );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['data'] ) || ! isset( $data['success'] ) || ! $data['success'] ) {
			error_log('EVENT_CONTROLLER: Invalid upload response: ' . $body);
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
			error_log('EVENT_CONTROLLER: Invalid post URL: ' . $post_url);
			return new WP_Error( 'invalid_url', 'Invalid or unsecure URL' );
		}

		error_log('EVENT_CONTROLLER: Posting to: ' . $post_url);

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
			error_log('EVENT_CONTROLLER: wp_remote_post failed: ' . $response->get_error_message());
			
			// Map common connection errors to user-friendly messages
			// These are standard WordPress/cURL error codes returned when connection fails
			// before getting an HTTP response (connection-level errors, not HTTP status errors)
			// Error codes: 6=DNS resolution failed, 7=connection failed, 28=operation timeout, 60=SSL cert error
			$error_msg = $response->get_error_message();
			$connection_errors = array(
				'cURL error 7' => 'Failed to connect to remote site - site may be down or URL is incorrect',
				'cURL error 28' => 'Remote site is not responding - connection timed out',
				'cURL error 6' => 'Invalid site URL or DNS not resolving - check site URL configuration',
				'cURL error 60' => 'SSL certificate error on remote site - contact site administrator',
			);
			
			$user_message = 'Failed to post event: Failed to connect to remote site';
			foreach ( $connection_errors as $key => $message ) {
				if ( strpos( $error_msg, $key ) !== false ) {
					$user_message = 'Failed to post event: ' . $message;
					break;
				}
			}
			
			return new WP_Error( 'post_failed', $user_message );
		}

		$status = wp_remote_retrieve_response_code( $response );
		error_log('EVENT_CONTROLLER: Post response status: ' . $status);
		
		if ( ! in_array( (int) $status, array( 200, 201 ), true ) ) {
			$body_text = wp_remote_retrieve_body( $response );
			error_log('EVENT_CONTROLLER: Post response body: ' . $body_text);

			// Map HTTP status codes to clear error messages
			$http_errors = array(
				'401' => 'Authentication failed - check application username and password',
				'403' => 'Access denied - user does not have permission to create events',
				'404' => 'Remote event creation endpoint not found - check site URL configuration',
				'500' => 'Server error on remote site - check event client error logs',
				'503' => 'Remote site service unavailable - try again later',
			);

			$error_message = isset( $http_errors[ (string) $status ] ) 
				? $http_errors[ (string) $status ] 
				: "Server returned HTTP status $status";

			// Build clean error message without verbose response body
			$error_details = "HTTP $status - $error_message";

			return new WP_Error( 'post_error', $error_details );
		}

		// Capture and return the full response from event-client (includes empty_fields diagnostics)
		$body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $body, true );
		error_log('EVENT_CONTROLLER: Post response data: ' . json_encode( $response_data ));
		
		return $response_data;
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
