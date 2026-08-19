<?php

/**
 * Attempts to determine whether a remote site already created the event
 * that caused an ambiguous outcome (the request timed out, or the remote
 * returned a 5xx, but may have actually processed it before failing to
 * respond). This is the ONLY safeguard against duplicate events for this
 * scenario - the remote "event-client" companion plugin cannot be modified
 * to add its own idempotency check (confirmed decision, see
 * EVENT-CONTROLLER-ARCHITECTURE-REVIEW.md Section 8).
 *
 * !!! IMPORTANT - UNVERIFIED REMOTE CAPABILITY !!!
 * The query parameters this relies on (?search=&after=&per_page=) follow
 * the most common WP REST convention, but the actual remote endpoint is
 * implemented by a separate "event-client" plugin whose source was not
 * available to inspect during development. It is NOT confirmed that the
 * remote endpoint supports server-side search/filtering at all.
 *
 * Because of this uncertainty, every code path in this class is written to
 * fail toward "wait and re-check later" rather than ever guessing. A
 * request error, a non-200 response, an unexpected response shape, or more
 * than one plausible match are ALL treated as inconclusive - never as
 * grounds to mark a job complete or to safely retry. This MUST be validated
 * against a real remote site before being relied on in production.
 *
 * @link       https://summitbhc.com/
 * @since      1.6.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/retry
 */

class EC_Reconciler {

	const FOUND        = 'found';
	const NOT_FOUND    = 'not_found';
	const INCONCLUSIVE = 'inconclusive';

	/**
	 * @param array $credentials           [ 'url', 'username', 'password' ]
	 * @param array $em_payload            The payload that was (maybe) posted - see EC_Payload_Transformer.
	 * @param int   $attempt_window_start  Unix timestamp; search from slightly before this to tolerate clock skew.
	 * @return array [ string $outcome, int|null $remote_event_id ]
	 */
	public static function reconcile( array $credentials, array $em_payload, $attempt_window_start ) {
		$search_url = rtrim( $credentials['url'], '/' ) . '/wp-json/events-manager/v1/events';
		$search_url = add_query_arg(
			array(
				'search'   => rawurlencode( $em_payload['event_name'] ),
				'after'    => gmdate( 'c', $attempt_window_start - 60 ),
				'per_page' => 5,
			),
			$search_url
		);

		$response = wp_remote_get(
			$search_url,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $credentials['username'] . ':' . $credentials['password'] ),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'EC_RECONCILER: request error, treating as inconclusive: ' . $response->get_error_message() );
			return array( self::INCONCLUSIVE, null );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			// Could mean search/filtering isn't actually supported by the remote
			// endpoint (unverified capability) or a transient failure - either
			// way, this result cannot be trusted.
			error_log( 'EC_RECONCILER: unexpected HTTP ' . $status . ', treating as inconclusive' );
			return array( self::INCONCLUSIVE, null );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			error_log( 'EC_RECONCILER: unparseable response body, treating as inconclusive' );
			return array( self::INCONCLUSIVE, null );
		}

		$matches = array_values(
			array_filter(
				$data,
				function ( $event ) use ( $em_payload ) {
					return isset( $event['event_name'], $event['event_start_date'] )
						&& $event['event_name'] === $em_payload['event_name']
						&& $event['event_start_date'] === $em_payload['event_start_date'];
				}
			)
		);

		$count = count( $matches );

		if ( 1 === $count ) {
			return array( self::FOUND, isset( $matches[0]['id'] ) ? (int) $matches[0]['id'] : 0 );
		}

		if ( 0 === $count ) {
			return array( self::NOT_FOUND, null );
		}

		// More than one plausible match - cannot safely determine which one
		// (if any) is ours. Never guess.
		error_log( 'EC_RECONCILER: ' . $count . ' plausible matches found, treating as inconclusive (needs manual review)' );
		return array( self::INCONCLUSIVE, null );
	}
}
