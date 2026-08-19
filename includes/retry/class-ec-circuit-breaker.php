<?php

/**
 * Per-site circuit breaker: stops hammering a site that is failing
 * repeatedly instead of letting every queued job burn a full retry cycle
 * against it individually.
 *
 * State is stored in one wp_options row keyed by site_key. Deliberately not
 * a database table - this is small, low-write-frequency data, and autoload
 * is explicitly disabled (see Phase 1, H4) since it's only read inside the
 * delivery worker, never on every page load.
 *
 * CLOSED -> (N consecutive failures) -> OPEN (cooldown)
 *        -> cooldown elapsed -> HALF_OPEN (one probe allowed through)
 *        -> probe succeeds -> CLOSED | probe fails -> OPEN (longer cooldown)
 *
 * @link       https://summitbhc.com/
 * @since      1.6.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/retry
 */

class EC_Circuit_Breaker {

	const OPTION_KEY = 'ec_site_health';
	const FAILURE_THRESHOLD = 5;
	const BASE_COOLDOWN_SECONDS = 900; // 15 minutes
	const MAX_COOLDOWN_SECONDS = 14400; // 4 hours

	/**
	 * @param string $site_key
	 * @return bool True if this site should not be attempted right now.
	 */
	public static function is_open( $site_key ) {
		$health = self::get_health( $site_key );

		if ( 'open' !== $health['state'] ) {
			return false;
		}

		if ( time() >= $health['open_until'] ) {
			// Cooldown elapsed - allow exactly one probe through.
			$health['state'] = 'half_open';
			self::save_health( $site_key, $health );
			return false;
		}

		return true;
	}

	/**
	 * @param string $site_key
	 */
	public static function record_success( $site_key ) {
		self::save_health(
			$site_key,
			array(
				'state'                => 'closed',
				'consecutive_failures' => 0,
				'opened_at'            => null,
				'open_until'           => null,
				'open_count'           => 0,
			)
		);
	}

	/**
	 * @param string $site_key
	 */
	public static function record_failure( $site_key ) {
		$health = self::get_health( $site_key );
		$health['consecutive_failures']++;

		if ( 'half_open' === $health['state'] ) {
			// The probe failed - reopen with a longer cooldown.
			$health['open_count'] = (int) $health['open_count'] + 1;
			$health['state']      = 'open';
			$health['opened_at']  = time();
			$health['open_until'] = time() + min( self::BASE_COOLDOWN_SECONDS * ( 2 ** $health['open_count'] ), self::MAX_COOLDOWN_SECONDS );
		} elseif ( $health['consecutive_failures'] >= self::FAILURE_THRESHOLD ) {
			$health['state']      = 'open';
			$health['opened_at']  = time();
			$health['open_count'] = 1;
			$health['open_until'] = time() + self::BASE_COOLDOWN_SECONDS;
		}

		self::save_health( $site_key, $health );
	}

	/**
	 * @param string $site_key
	 * @return array
	 */
	private static function get_health( $site_key ) {
		$all = get_option( self::OPTION_KEY, array() );

		return isset( $all[ $site_key ] ) ? $all[ $site_key ] : array(
			'state'                => 'closed',
			'consecutive_failures' => 0,
			'opened_at'            => null,
			'open_until'           => null,
			'open_count'           => 0,
		);
	}

	/**
	 * @param string $site_key
	 * @param array  $health
	 */
	private static function save_health( $site_key, $health ) {
		$all               = get_option( self::OPTION_KEY, array() );
		$all[ $site_key ]  = $health;
		update_option( self::OPTION_KEY, $all, false );
	}
}
