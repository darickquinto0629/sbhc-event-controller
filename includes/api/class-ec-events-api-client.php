<?php

/**
 * Single-site Events Manager API client for the queued delivery pipeline.
 *
 * Unlike the legacy handler's curl_multi parallel functions (which fan out
 * to every selected site in one request), this client handles exactly one
 * site per call - each site is already its own independent Action Scheduler
 * job, so no parallel cURL is needed here.
 *
 * Error classification (retryable vs. permanent vs. ambiguous) is Phase 4's
 * job (EC_Error_Classifier). For now, every WP_Error returned here carries
 * an 'http_status' and/or 'wp_error_code' in its error data so Phase 4 can
 * classify it without this class needing to change.
 *
 * @link       https://summitbhc.com/
 * @since      1.5.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/api
 */

class EC_Events_API_Client {

	/**
	 * Upload a persisted file to a remote site's Events Manager media endpoint.
	 *
	 * @param array  $credentials [ 'url', 'username', 'password' ]
	 * @param string $file_path   Absolute path to the file (see EC_Job_Manager::persist_media()).
	 * @return array|WP_Error Decoded response (contains 'id') on success.
	 */
	public function upload_media( array $credentials, $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'media_file_missing', 'Persisted media file not found: ' . $file_path );
		}

		$upload_url = rtrim( $credentials['url'], '/' ) . '/wp-json/events-manager/v1/media';

		if ( ! filter_var( $upload_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $upload_url, 'https://' ) ) {
			return new WP_Error( 'invalid_url', 'Invalid or unsecure URL' );
		}

		$boundary = wp_generate_password( 24 );
		$body     = $this->build_multipart_body( $file_path, $boundary );

		$response = wp_remote_post(
			$upload_url,
			array(
				'method'    => 'POST',
				'headers'   => array(
					'Authorization' => 'Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['password'] ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'      => $body,
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		return $this->handle_response( $response, 'media' );
	}

	/**
	 * Post an already-transformed Events Manager payload to a remote site.
	 *
	 * @param array $credentials [ 'url', 'username', 'password' ]
	 * @param array $em_payload  See EC_Payload_Transformer::transform().
	 * @return array|WP_Error Decoded response (contains 'id') on success.
	 */
	public function post_event( array $credentials, array $em_payload ) {
		$post_url = rtrim( $credentials['url'], '/' ) . '/wp-json/events-manager/v1/events';

		if ( ! filter_var( $post_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $post_url, 'https://' ) ) {
			return new WP_Error( 'invalid_url', 'Invalid or unsecure URL' );
		}

		$response = wp_remote_post(
			$post_url,
			array(
				'method'    => 'POST',
				'headers'   => array(
					'Authorization' => 'Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['password'] ),
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode( $em_payload ),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		return $this->handle_response( $response, 'event' );
	}

	/**
	 * @param string $file_path
	 * @param string $boundary
	 * @return string
	 */
	private function build_multipart_body( $file_path, $boundary ) {
		$file_content = file_get_contents( $file_path );
		$file_name    = basename( $file_path );
		$filetype     = wp_check_filetype( $file_name );
		$mime_type    = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';

		$body  = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $file_name . "\"\r\n";
		$body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
		$body .= $file_content . "\r\n";
		$body .= '--' . $boundary . '--';

		return $body;
	}

	/**
	 * Normalize a wp_remote_post() result into either the decoded response
	 * body (success) or a WP_Error carrying enough data for Phase 4's error
	 * classifier to work without needing changes here.
	 *
	 * @param array|WP_Error $response
	 * @param string         $context 'media' or 'event'.
	 * @return array|WP_Error
	 */
	private function handle_response( $response, $context ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				$context . '_connection_error',
				$response->get_error_message(),
				array( 'wp_error_code' => $response->get_error_code() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( ! in_array( $status, array( 200, 201 ), true ) ) {
			return new WP_Error(
				$context . '_http_error',
				'Remote site returned HTTP ' . $status,
				array(
					'http_status' => $status,
					'body'        => $body,
				)
			);
		}

		$data = json_decode( $body, true );

		if ( 'media' === $context && ! isset( $data['id'] ) ) {
			return new WP_Error( 'media_invalid_response', 'Invalid response from media upload', array( 'http_status' => $status ) );
		}

		return $data;
	}
}
