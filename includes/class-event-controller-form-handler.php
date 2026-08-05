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
		//add_action( 'wp_ajax_nopriv_event_controller_submit_event', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Refresh nonce for subsequent form submissions
	 * Called after form reset to ensure fresh nonce for next submission
	 */
	public function handle_refresh_nonce() {
		$nonce = wp_create_nonce( 'event_controller_submit' );
		wp_send_json_success( array( 'nonce' => $nonce ) );
	}

	/**
	 * Wait for processing result from original request
	 * Used by retry connections to get the final result instead of hanging
	 * 
	 * @param string $idempotency_key Unique key for this submission
	 * @param int $timeout Maximum seconds to wait (default 120)
	 * @return array|false Final result if available, false if timeout
	 */
	private function wait_for_result( $idempotency_key, $timeout = 120 ) {
		$result_key = 'event_controller_result_' . $idempotency_key;
		$start_time = time();
		$poll_interval = 1; // Check every 1 second
		
		error_log('EVENT_CONTROLLER: Retry waiting for result, max wait: ' . $timeout . 's');
		
		while ( time() - $start_time < $timeout ) {
			// Check cache first (faster than database)
			$result = wp_cache_get( $result_key );
			if ( ! $result ) {
				$result = get_option( $result_key );
			}
			if ( $result ) {
				error_log('EVENT_CONTROLLER: Retry retrieved result after ' . (time() - $start_time) . 's');
				return $result;
			}
			sleep( $poll_interval );
		}
		
		error_log('EVENT_CONTROLLER: Retry timeout - result not available after ' . $timeout . 's');
		return false;
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

		// Create idempotency key to prevent duplicate submissions
		// Uses selected sites + event data to uniquely identify this submission
		// Reason: WPMUDEV nginx proxy may retry requests if the backend processing takes longer
		// than the proxy timeout (~30-45 seconds), while backend is still processing (~60 seconds).
		// This causes the proxy to resend the same request, creating duplicate events if not prevented.
		// The idempotency key is generated from the payload (sites + data), so the same event
		// submission always produces the same key regardless of security tokens like nonces.
		$idempotency_key = hash(
			'sha256',
			wp_json_encode( [
				'sites' => $selected_sites,
				'data'  => $event_data,
			] )
		);
		
		// Attempt to acquire an atomic lock using add_option
		// add_option() only succeeds if the option doesn't exist (atomic operation)
		// Only the original request succeeds; retries wait for the result
		$lock_key = 'event_controller_lock_' . $idempotency_key;
		$lock_acquired = add_option( $lock_key, time(), '', 'no' );
		
		if ( $lock_acquired ) {
			// Lock acquired - this is the original request
			error_log('EVENT_CONTROLLER: Lock acquired for submission: ' . $idempotency_key);
		} else {
			// Lock exists - this is a retry, wait for the original request to complete
			error_log('EVENT_CONTROLLER: Proxy retry detected, waiting for original request to complete');
			$cached_result = $this->wait_for_result( $idempotency_key, 120 );
			if ( $cached_result ) {
				error_log('EVENT_CONTROLLER: Retry returning cached result');
				header( 'Content-Type: application/json' );
				echo wp_json_encode( $cached_result );
				exit;
			} else {
				error_log('EVENT_CONTROLLER: Retry timeout - no result available');
				wp_send_json_error( array( 'message' => 'Request processing timeout' ) );
			}
		}
		
		// Lock is now acquired, proceed with processing
		error_log('EVENT_CONTROLLER: Processing submission: ' . $idempotency_key);

		// Prepare response outside of try-finally to avoid releasing lock before response is sent
		$response_to_send = null;
		$http_status = null;

		try {
			if ( empty( $selected_sites ) ) {
				error_log('EVENT_CONTROLLER: No sites selected');
				$response_to_send = array( 'success' => false, 'data' => array( 'message' => 'No sites selected' ) );
				$http_status = 'error';
				return; // Jump to finally block
			}

			if ( empty( $event_data ) ) {
				error_log('EVENT_CONTROLLER: No event data');
				$response_to_send = array( 'success' => false, 'data' => array( 'message' => 'No event data provided' ) );
				$http_status = 'error';
				return; // Jump to finally block
			}

			$errors = array();
			$all_responses = array();  // Collect responses with empty_fields data
			$media_ids = array();  // Track media ID PER SITE

			// Build credentials for all sites
			$site_credentials = array();
			foreach ( $selected_sites as $site_id ) {
				$site_id = sanitize_text_field( $site_id );
				$credentials = $this->get_site_credentials_by_id( $site_id );
				if ( ! $credentials ) {
					error_log('EVENT_CONTROLLER: Credentials not found for site: ' . $site_id);
					$errors[] = "Site configuration not found for: " . ucwords( str_replace( '_', ' ', $site_id ) );
					continue;
				}
				$site_credentials[ $site_id ] = $credentials;
			}

			// PHASE 1: Parallel media uploads
			// NOTE: Keep featured images under 1MB (recommended 300-500KB) to avoid proxy timeouts.
			// Large images can cause backend processing to exceed the nginx proxy timeout (~30-45 seconds),
			// which triggers proxy retries and creates duplicate events.
			if ( $file && ! empty( $file['tmp_name'] ) ) {
				error_log('EVENT_CONTROLLER: Starting parallel media uploads for ' . count( $site_credentials ) . ' sites');
				$media_results = $this->upload_media_parallel( $site_credentials, $file );
				
				// Check for upload failures and track media IDs per site
				foreach ( $media_results as $site_id => $result ) {
					if ( is_wp_error( $result ) ) {
						error_log('EVENT_CONTROLLER: Media upload failed for ' . $site_id . ': ' . $result->get_error_message());
						$errors[] = $site_credentials[ $site_id ]['name'] . ": " . $result->get_error_message();
						unset( $site_credentials[ $site_id ] );
					} else {
						error_log('EVENT_CONTROLLER: Media uploaded for ' . $site_id . ', ID: ' . $result);
						$media_ids[ $site_id ] = $result;  // Store per-site media ID
					}
				}
			}

			// PHASE 2: Parallel event posts (only for sites that passed media upload)
			if ( ! empty( $site_credentials ) ) {
				error_log('EVENT_CONTROLLER: Starting parallel event posts for ' . count( $site_credentials ) . ' sites');
				$post_results = $this->post_event_parallel( $site_credentials, $event_data, $media_ids );
				
				// Process post results
				foreach ( $post_results as $site_id => $result ) {
					if ( is_wp_error( $result ) ) {
						error_log('EVENT_CONTROLLER: Event post failed for ' . $site_id . ': ' . $result->get_error_message());
						$errors[] = $site_credentials[ $site_id ]['name'] . ": " . $result->get_error_message();
					} else {
						error_log('EVENT_CONTROLLER: Event posted successfully to site: ' . $site_id);
						$all_responses[] = array(
							'site_id' => $site_id,
							'site_name' => $site_credentials[ $site_id ]['name'],
							'response' => $result
						);
					}
				}
			}

			// Calculate success/failure counts
			$total_sites = count( $selected_sites );
			$succeeded_count = count( $all_responses );
			$failed_count = count( $errors );

			if ( $failed_count === $total_sites ) {
				// All sites failed - prepare error response
				error_log('EVENT_CONTROLLER: Submission completed with all sites failed: ' . json_encode($errors));
				$response_to_send = array( 'success' => false, 'data' => array( 'errors' => $errors ) );
				$http_status = 'error';
			} else {
				// Partial success or all success - prepare success response
				$is_partial = $failed_count > 0 && $succeeded_count > 0;
				$message = $succeeded_count === $total_sites 
					? 'All events posted successfully'
					: "Partial success: $succeeded_count of $total_sites sites succeeded";

				error_log('EVENT_CONTROLLER: Submission completed - ' . $message);
				$response_to_send = array(
					'success' => true,
					'data' => array(
						'message' => $message,
						'responses' => $all_responses,
						'errors' => $errors,
						'partial_success' => $is_partial,
						'stats' => array(
							'total' => $total_sites,
							'succeeded' => $succeeded_count,
							'failed' => $failed_count
						)
					)
				);
				$http_status = 'success';
			}
		} finally {
			// Release lock AFTER response is prepared but BEFORE it's sent
			// This ensures retry requests can immediately find the cached result
			// while original request still owns the lock until truly complete
			error_log('EVENT_CONTROLLER: Entering finally block for: ' . $idempotency_key);
			
			if ( $response_to_send ) {
				error_log('EVENT_CONTROLLER: Response to send exists, caching now');
				$result_key = 'event_controller_result_' . $idempotency_key;
				
				error_log('EVENT_CONTROLLER: Before update_option, key: ' . $result_key);
				$update_result = update_option( $result_key, $response_to_send );
				error_log('EVENT_CONTROLLER: After update_option, result: ' . ($update_result ? 'true' : 'false'));
				
				// Verify it was saved
				$verify = get_option( $result_key );
				error_log('EVENT_CONTROLLER: Verified saved option (exists: ' . ($verify ? 'yes' : 'no') . ')');
				
				wp_cache_set( $result_key, $response_to_send, '', 300 );
				error_log('EVENT_CONTROLLER: wp_cache_set executed');
			} else {
				error_log('EVENT_CONTROLLER: Response to send is NULL/empty, skipping cache');
			}
			
			error_log('EVENT_CONTROLLER: Before release_lock');
			$this->release_lock( $idempotency_key );
			error_log('EVENT_CONTROLLER: After release_lock, about to exit finally');
		}

		// Cache response and send after lock is released
		error_log('EVENT_CONTROLLER: Exited finally, response_to_send is ' . ($response_to_send ? 'set' : 'null/empty'));
		
		if ( $response_to_send ) {
			error_log('EVENT_CONTROLLER: Before wp_send_json call');
			
			if ( $http_status === 'error' ) {
				error_log('EVENT_CONTROLLER: Calling wp_send_json_error');
				wp_send_json_error( $response_to_send['data'] );
			} else {
				error_log('EVENT_CONTROLLER: Calling wp_send_json_success');
				wp_send_json_success( $response_to_send['data'] );
			}
		} else {
			error_log('EVENT_CONTROLLER: Response is null, not sending anything');
		}
	}

	/**
	 * Release the processing lock
	 * 
	 * @param string $idempotency_key Unique key for this submission
	 */
	private function release_lock( $idempotency_key ) {
		$lock_key = 'event_controller_lock_' . $idempotency_key;
		delete_option( $lock_key );
		error_log('EVENT_CONTROLLER: Lock released: ' . $lock_key);
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
	 * Upload media to multiple remote sites in parallel
	 * 
	 * @param array $site_credentials Keyed by site_id with 'url', 'username', 'password', 'name'
	 * @param array $file The $_FILES array entry
	 * @return array Keyed by site_id with media ID on success or WP_Error on failure
	 */
	private function upload_media_parallel( $site_credentials, $file ) {
		$results = array();
		
		// Check if cURL multi is available
		if ( ! function_exists( 'curl_multi_init' ) ) {
			error_log('EVENT_CONTROLLER: cURL multi not available, falling back to sequential uploads');
			foreach ( $site_credentials as $site_id => $creds ) {
				$results[ $site_id ] = $this->upload_media_to_remote( $creds, $file );
			}
			return $results;
		}

		$multi_handle = curl_multi_init();
		$handles = array();
		$file_content = file_get_contents( $file['tmp_name'] );

		error_log('EVENT_CONTROLLER: Setting up parallel media uploads for ' . count( $site_credentials ) . ' sites');

		// Create upload requests for all sites
		foreach ( $site_credentials as $site_id => $credentials ) {
			$upload_url = rtrim( $credentials['url'], '/' ) . '/wp-json/events-manager/v1/media';

			// Validate URL
			if ( ! filter_var( $upload_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $upload_url, 'https://' ) ) {
				error_log('EVENT_CONTROLLER: Invalid upload URL: ' . $upload_url);
				$results[ $site_id ] = new WP_Error( 'invalid_url', 'Invalid or unsecure URL' );
				continue;
			}

			// Build multipart body
			$boundary = wp_generate_password( 24 );
			$body = $this->build_multipart_body( $file, $boundary );

			// Create cURL handle
			$handle = curl_init();
			curl_setopt_array( $handle, array(
				CURLOPT_URL            => $upload_url,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => array(
					'Authorization: Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['password'] ),
					'Content-Type: multipart/form-data; boundary=' . $boundary,
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_SSL_VERIFYPEER => true,
			) );

			curl_multi_add_handle( $multi_handle, $handle );
			$handles[ $site_id ] = $handle;
			error_log('EVENT_CONTROLLER: Added upload request for site: ' . $site_id);
		}

		// Execute all requests in parallel with timeout protection
		$running = null;
		$start_time = time();
		$operation_timeout = 120; // Max 120 seconds for all parallel operations
		do {
			curl_multi_exec( $multi_handle, $running );
			// Use 1-second timeout to prevent blocking indefinitely
			curl_multi_select( $multi_handle, 1 );
			// Safety check: if operation takes longer than timeout, break out
			if ( time() - $start_time > $operation_timeout ) {
				error_log('EVENT_CONTROLLER: Parallel media upload timeout after ' . $operation_timeout . 's');
				break;
			}
		} while ( $running > 0 );

		error_log('EVENT_CONTROLLER: All parallel media uploads completed');

		// Process responses
		foreach ( $handles as $site_id => $handle ) {
			$response_code = curl_getinfo( $handle, CURLINFO_HTTP_CODE );
			$response_body = curl_multi_getcontent( $handle );
			$curl_error = curl_error( $handle );

			error_log('EVENT_CONTROLLER: Site ' . $site_id . ' upload response code: ' . $response_code);

			if ( $curl_error ) {
				error_log('EVENT_CONTROLLER: cURL error for ' . $site_id . ': ' . $curl_error);
				
				// Map common cURL errors
				$connection_errors = array(
					'7'  => 'Failed to connect to remote site - site may be down or URL is incorrect',
					'28' => 'Remote site is not responding - connection timed out',
					'6'  => 'Invalid site URL or DNS not resolving - check site URL configuration',
					'60' => 'SSL certificate error on remote site - contact site administrator',
				);
				
				$user_message = 'Failed to upload media: Failed to connect to remote site';
				foreach ( $connection_errors as $code => $message ) {
					if ( strpos( $curl_error, $code ) !== false ) {
						$user_message = 'Failed to upload media: ' . $message;
						break;
					}
				}
				
				$results[ $site_id ] = new WP_Error( 'upload_failed', $user_message );
				curl_multi_remove_handle( $multi_handle, $handle );
				curl_close( $handle );
				continue;
			}

			if ( ! in_array( (int) $response_code, array( 200, 201 ), true ) ) {
				error_log('EVENT_CONTROLLER: Upload failed for ' . $site_id . ' with status ' . $response_code . ': ' . $response_body);

				$http_errors = array(
					'401' => 'Authentication failed - check application username and password',
					'403' => 'Access denied - user does not have permission to upload files',
					'404' => 'Remote media upload endpoint not found - check if Events Manager plugin is installed on remote site',
					'413' => 'File is too large - reduce file size',
					'500' => 'Server error on remote site - check event client error logs',
					'503' => 'Remote site service unavailable - try again later',
				);

				$error_message = isset( $http_errors[ (string) $response_code ] ) 
					? $http_errors[ (string) $response_code ] 
					: "Server returned HTTP status $response_code";

				$results[ $site_id ] = new WP_Error( 'upload_error', "Media upload failed - $error_message" );
				curl_multi_remove_handle( $multi_handle, $handle );
				curl_close( $handle );
				continue;
			}

			// Parse successful response
			$data = json_decode( $response_body, true );
			if ( ! isset( $data['id'] ) ) {
				error_log('EVENT_CONTROLLER: Invalid upload response for ' . $site_id . ' - missing id: ' . $response_body);
				$results[ $site_id ] = new WP_Error( 'invalid_response', 'Invalid response from media upload' );
			} else {
				error_log('EVENT_CONTROLLER: Media uploaded successfully for ' . $site_id . ', ID: ' . $data['id']);
				$results[ $site_id ] = $data['id'];
			}

			curl_multi_remove_handle( $multi_handle, $handle );
			curl_close( $handle );
		}

		curl_multi_close( $multi_handle );
		return $results;
	}

	/**
	 * Post events to multiple remote sites in parallel
	 * 
	 * @param array $site_credentials Keyed by site_id with 'url', 'username', 'password', 'name'
	 * @param array $event_data The event data to post
	 * @param array $media_ids Keyed by site_id with the media ID for each site
	 * @return array Keyed by site_id with response data on success or WP_Error on failure
	 */
	private function post_event_parallel( $site_credentials, $event_data, $media_ids = array() ) {
		$results = array();
		
		// Check if cURL multi is available
		if ( ! function_exists( 'curl_multi_init' ) ) {
			error_log('EVENT_CONTROLLER: cURL multi not available, falling back to sequential posts');
			foreach ( $site_credentials as $site_id => $creds ) {
				// Use site-specific media ID if available
				$site_event_data = $event_data;
				if ( isset( $media_ids[ $site_id ] ) ) {
					$site_event_data['featured_media'] = $media_ids[ $site_id ];
				}
				$results[ $site_id ] = $this->post_event_to_remote( $creds, $site_event_data );
			}
			return $results;
		}

		$multi_handle = curl_multi_init();
		$handles = array();

		error_log('EVENT_CONTROLLER: Setting up parallel event posts for ' . count( $site_credentials ) . ' sites');

		// Create post requests for all sites
		foreach ( $site_credentials as $site_id => $credentials ) {
			$post_url = rtrim( $credentials['url'], '/' ) . '/wp-json/events-manager/v1/events';

			// Validate URL
			if ( ! filter_var( $post_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $post_url, 'https://' ) ) {
				error_log('EVENT_CONTROLLER: Invalid post URL: ' . $post_url);
				$results[ $site_id ] = new WP_Error( 'invalid_url', 'Invalid or unsecure URL' );
				continue;
			}

			// Use site-specific media ID if available
			$site_event_data = $event_data;
			if ( isset( $media_ids[ $site_id ] ) ) {
				$site_event_data['featured_media'] = $media_ids[ $site_id ];
				error_log('EVENT_CONTROLLER: Using media ID ' . $media_ids[ $site_id ] . ' for site ' . $site_id);
			}

			$em_payload = $this->transform_to_em_api_schema( $site_event_data );

			// Create cURL handle
			$handle = curl_init();
			curl_setopt_array( $handle, array(
				CURLOPT_URL            => $post_url,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => json_encode( $em_payload ),
				CURLOPT_HTTPHEADER     => array(
					'Authorization: Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['password'] ),
					'Content-Type: application/json',
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_SSL_VERIFYPEER => true,
			) );

			curl_multi_add_handle( $multi_handle, $handle );
			$handles[ $site_id ] = $handle;
			error_log('EVENT_CONTROLLER: Added post request for site: ' . $site_id);
		}

		// Execute all requests in parallel with timeout protection
		$running = null;
		$start_time = time();
		$operation_timeout = 120; // Max 120 seconds for all parallel operations
		do {
			curl_multi_exec( $multi_handle, $running );
			// Use 1-second timeout to prevent blocking indefinitely
			curl_multi_select( $multi_handle, 1 );
			// Safety check: if operation takes longer than timeout, break out
			if ( time() - $start_time > $operation_timeout ) {
				error_log('EVENT_CONTROLLER: Parallel event post timeout after ' . $operation_timeout . 's');
				break;
			}
		} while ( $running > 0 );

		error_log('EVENT_CONTROLLER: All parallel event posts completed');

		// Process responses
		foreach ( $handles as $site_id => $handle ) {
			$response_code = curl_getinfo( $handle, CURLINFO_HTTP_CODE );
			$response_body = curl_multi_getcontent( $handle );
			$curl_error = curl_error( $handle );

			error_log('EVENT_CONTROLLER: Site ' . $site_id . ' post response code: ' . $response_code);

			if ( $curl_error ) {
				error_log('EVENT_CONTROLLER: cURL error for ' . $site_id . ': ' . $curl_error);
				
				// Map common cURL errors
				$connection_errors = array(
					'7'  => 'Failed to connect to remote site - site may be down or URL is incorrect',
					'28' => 'Remote site is not responding - connection timed out',
					'6'  => 'Invalid site URL or DNS not resolving - check site URL configuration',
					'60' => 'SSL certificate error on remote site - contact site administrator',
				);
				
				$user_message = 'Failed to post event: Failed to connect to remote site';
				foreach ( $connection_errors as $code => $message ) {
					if ( strpos( $curl_error, $code ) !== false ) {
						$user_message = 'Failed to post event: ' . $message;
						break;
					}
				}
				
				$results[ $site_id ] = new WP_Error( 'post_failed', $user_message );
				curl_multi_remove_handle( $multi_handle, $handle );
				curl_close( $handle );
				continue;
			}

			if ( ! in_array( (int) $response_code, array( 200, 201 ), true ) ) {
				error_log('EVENT_CONTROLLER: Post failed for ' . $site_id . ' with status ' . $response_code . ': ' . $response_body);

				$http_errors = array(
					'401' => 'Authentication failed - check application username and password',
					'403' => 'Access denied - user does not have permission to create events',
					'404' => 'Remote event creation endpoint not found - check if Events Manager plugin is installed on remote site',
					'500' => 'Server error on remote site - check event client error logs',
					'503' => 'Remote site service unavailable - try again later',
				);

				$error_message = isset( $http_errors[ (string) $response_code ] ) 
					? $http_errors[ (string) $response_code ] 
					: "Server returned HTTP status $response_code";

				$results[ $site_id ] = new WP_Error( 'post_error', "HTTP $response_code - $error_message" );
				curl_multi_remove_handle( $multi_handle, $handle );
				curl_close( $handle );
				continue;
			}

			// Parse successful response
			$response_data = json_decode( $response_body, true );
			error_log('EVENT_CONTROLLER: Event posted successfully for ' . $site_id);
			$results[ $site_id ] = $response_data;

			curl_multi_remove_handle( $multi_handle, $handle );
			curl_close( $handle );
		}

		curl_multi_close( $multi_handle );
		return $results;
	}

	/**
	 * Upload media to remote site via Events Manager API
	 */
	private function upload_media_to_remote( $credentials, $file ) {
		$upload_url = rtrim( $credentials['url'], '/' ) . '/wp-json/events-manager/v1/media';

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
				'404' => 'Remote media upload endpoint not found - check if Events Manager plugin is installed on remote site',
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

		// Events Manager API returns attachment object directly with 'id', 'url', 'mime_type', etc.
		// No 'success' wrapper, just the attachment data
		if ( ! isset( $data['id'] ) ) {
			error_log('EVENT_CONTROLLER: Invalid upload response - missing id: ' . $body);
			return new WP_Error( 'invalid_response', 'Invalid response from media upload' );
		}

		error_log('EVENT_CONTROLLER: Media uploaded successfully, attachment ID: ' . $data['id']);
		return $data['id'];
	}

	/**
	 * Transform form data to Events Manager API schema
	 */
	private function transform_to_em_api_schema( $event_data ) {
		// Extract meta_input if it exists
		$meta = isset( $event_data['meta_input'] ) ? $event_data['meta_input'] : array();
		
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
			'location_name'        => $event_data['event_location'] ?? '',
			'location_address'     => '',
			'location_town'        => '',
			'location_state'       => '',
			'location_postcode'    => '',
			'location_region'      => '',
			'location_country'     => 'US',
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
		);
		
		error_log('EVENT_CONTROLLER: Building EM API payload with featured_image: ' . json_encode($em_payload['featured_image']));
		error_log('EVENT_CONTROLLER: Transformed payload: ' . json_encode( $em_payload ));
		return $em_payload;
	}

	/**
	 * Post event to remote site via Events Manager API
	 */
	private function post_event_to_remote( $credentials, $event_data ) {
		$post_url = rtrim( $credentials['url'], '/' ) . '/wp-json/events-manager/v1/events';

		// Validate URL
		if ( ! filter_var( $post_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $post_url, 'https://' ) ) {
			error_log('EVENT_CONTROLLER: Invalid post URL: ' . $post_url);
			return new WP_Error( 'invalid_url', 'Invalid or unsecure URL' );
		}

		error_log('EVENT_CONTROLLER: Posting to: ' . $post_url);

		// Transform form data to EM API schema
		$em_payload = $this->transform_to_em_api_schema( $event_data );
		
		error_log('EVENT_CONTROLLER: Sending payload with featured_image: ' . $em_payload['featured_image']);
		error_log('EVENT_CONTROLLER: Full payload JSON: ' . json_encode( $em_payload ));

		// Make request
		$response = wp_remote_post(
			$post_url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['password'] ),
					'Content-Type'  => 'application/json',
				),
				'body'      => json_encode( $em_payload ),
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
				'404' => 'Remote event creation endpoint not found - check if Events Manager plugin is installed on remote site',
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
			$body .= 'Content-Disposition: form-data; name="file"; filename="' . $file_name . "\"\r\n";
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
