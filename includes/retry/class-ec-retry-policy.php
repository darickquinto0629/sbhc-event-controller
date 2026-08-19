<?php

/**
 * Exponential backoff with jitter for retryable site-job failures.
 *
 * @link       https://summitbhc.com/
 * @since      1.6.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/retry
 */

class EC_Retry_Policy {

	const MAX_ATTEMPTS = 5;
	const BASE_DELAY_SECONDS = 60;
	const MAX_DELAY_SECONDS = 21600; // 6 hours

	/**
	 * @param int $attempts Attempts already made (1 after the first claim).
	 * @return bool
	 */
	public static function max_attempts_reached( $attempts ) {
		return (int) $attempts >= self::MAX_ATTEMPTS;
	}

	/**
	 * Roughly 1m, 4m, 16m, 64m, 6h (capped), each +/-50% jitter so many site
	 * jobs failing at once don't all retry in lockstep.
	 *
	 * @param int $attempts Attempts already made.
	 * @return int Seconds to wait before the next attempt.
	 */
	public static function next_delay_seconds( $attempts ) {
		$exponent = max( 0, (int) $attempts - 1 );
		$delay    = min( self::BASE_DELAY_SECONDS * ( 4 ** $exponent ), self::MAX_DELAY_SECONDS );
		$jitter   = wp_rand( 50, 150 ) / 100;

		return (int) round( $delay * $jitter );
	}
}
