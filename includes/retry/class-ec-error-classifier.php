<?php

/**
 * Classifies a delivery failure so EC_Delivery_Worker knows what to do next.
 *
 * - PERMANENT: retrying will not help (bad credentials, bad payload, missing
 *   remote endpoint). Fail immediately.
 * - RETRYABLE: a transient condition. Schedule a backed-off retry.
 * - AMBIGUOUS: the remote site may have actually processed the request even
 *   though we didn't get a clean success response (timeout, 5xx, connection
 *   reset). MUST NOT be retried blindly during the event phase - see
 *   EC_Reconciler and EVENT-CONTROLLER-ARCHITECTURE-REVIEW.md Section 7/8.
 *
 * @link       https://summitbhc.com/
 * @since      1.6.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/retry
 */

class EC_Error_Classifier {

	const PERMANENT = 'permanent';
	const RETRYABLE = 'retryable';
	const AMBIGUOUS = 'ambiguous';

	/** Error codes EC_Events_API_Client/EC_Job_Manager/EC_Delivery_Worker raise for config/local problems - never worth retrying. */
	private static $permanent_error_codes = array(
		'invalid_url',
		'media_file_missing',
		'media_invalid_response',
		'site_not_configured',
		'parent_job_missing',
		'reconcile_context_missing',
	);

	/** HTTP status codes where the remote site may have already processed the request. */
	private static $ambiguous_http = array( 500, 502, 503, 504 );

	/** HTTP status codes worth retrying (rate limiting, request timeout). */
	private static $retryable_http = array( 408, 429 );

	/** HTTP status codes that indicate a problem retrying will not fix. */
	private static $permanent_http = array( 400, 401, 403, 404, 413, 422 );

	/** cURL error numbers where the remote may have already processed the request (timeout, dropped connection). */
	private static $ambiguous_curl_errno = array( 28, 52, 56 );

	/** cURL error numbers that are safe to retry (never reached the remote server). */
	private static $retryable_curl_errno = array( 6, 7, 35, 51, 60 );

	/**
	 * @param WP_Error $error
	 * @return string One of PERMANENT, RETRYABLE, AMBIGUOUS.
	 */
	public static function classify( WP_Error $error ) {
		if ( in_array( $error->get_error_code(), self::$permanent_error_codes, true ) ) {
			return self::PERMANENT;
		}

		$data = $error->get_error_data();

		if ( isset( $data['http_status'] ) ) {
			$status = (int) $data['http_status'];

			if ( in_array( $status, self::$ambiguous_http, true ) ) {
				return self::AMBIGUOUS;
			}
			if ( in_array( $status, self::$retryable_http, true ) ) {
				return self::RETRYABLE;
			}
			if ( in_array( $status, self::$permanent_http, true ) ) {
				return self::PERMANENT;
			}

			// Unrecognized status code: don't guess in either direction.
			return self::AMBIGUOUS;
		}

		// Connection-level failure (no HTTP response at all). WordPress's HTTP API
		// formats these messages as "cURL error N: ..." reliably - unlike raw
		// curl_error() text, this substring is safe to parse (see H6 in Phase 1).
		$message = $error->get_error_message();
		if ( preg_match( '/cURL error (\d+)/', $message, $matches ) ) {
			$errno = (int) $matches[1];

			if ( in_array( $errno, self::$ambiguous_curl_errno, true ) ) {
				return self::AMBIGUOUS;
			}
			if ( in_array( $errno, self::$retryable_curl_errno, true ) ) {
				return self::RETRYABLE;
			}
		}

		// Unknown connection error: a connection that never reached the remote
		// almost never means "the remote processed it" - default to retryable.
		return self::RETRYABLE;
	}
}
