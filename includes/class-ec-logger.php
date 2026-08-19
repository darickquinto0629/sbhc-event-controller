<?php

/**
 * Structured, queryable logging for the queued delivery pipeline, replacing
 * the plain error_log() calls used in Phases 2-4. Writes to the ec_job_log
 * table instead so entries can be filtered/traced per job or per site job
 * (Phase 6).
 *
 * Correlation ID format: EC-{job_id}-S{site_job_id}-{PHASE}
 * e.g. EC-1024-S17-EVENT
 *
 * @link       https://summitbhc.com/
 * @since      1.8.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes
 */

class EC_Logger {

	const LOG_LEVEL_OPTION = 'ec_log_level';
	const DEFAULT_LOG_LEVEL = 'warn';
	const RETENTION_DAYS = 30;

	/** Ordered so a numeric comparison decides whether a message meets the configured threshold. */
	private static $levels = array(
		'debug' => 10,
		'info'  => 20,
		'warn'  => 30,
		'error' => 40,
	);

	/**
	 * @param string   $level       'debug' | 'info' | 'warn' | 'error'.
	 * @param string   $message
	 * @param array    $context     Freeform data; 'Authorization'/'authorization' keys are always redacted.
	 * @param int|null $job_id
	 * @param int|null $site_job_id
	 * @param string|null $phase    'media' | 'event' | etc. Used only to build the correlation ID.
	 */
	public static function log( $level, $message, array $context = array(), $job_id = null, $site_job_id = null, $phase = null ) {
		if ( ! self::meets_threshold( $level ) ) {
			return;
		}

		global $wpdb;

		$context = self::redact( $context );

		// "Never log full payloads at info level" - truncate any long string
		// values rather than omitting them outright, so context is still useful.
		if ( 'info' === $level ) {
			$context = self::truncate_long_values( $context );
		}

		$wpdb->insert(
			$wpdb->prefix . 'ec_job_log',
			array(
				'job_id'         => $job_id,
				'site_job_id'    => $site_job_id,
				'correlation_id' => self::correlation_id( $job_id, $site_job_id, $phase ),
				'level'          => $level,
				'phase'          => $phase,
				'message'        => $message,
				'context'        => empty( $context ) ? null : wp_json_encode( $context ),
				'created_at'     => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * @param int|null    $job_id
	 * @param int|null    $site_job_id
	 * @param string|null $phase
	 * @return string|null
	 */
	public static function correlation_id( $job_id, $site_job_id, $phase = null ) {
		if ( ! $job_id ) {
			return null;
		}

		$id = 'EC-' . $job_id;

		if ( $site_job_id ) {
			$id .= '-S' . $site_job_id;
		}

		if ( $phase ) {
			$id .= '-' . strtoupper( $phase );
		}

		return $id;
	}

	/**
	 * Delete log rows older than RETENTION_DAYS. Hooked to a daily cron event.
	 */
	public static function purge_old_logs() {
		global $wpdb;

		$table  = $wpdb->prefix . 'ec_job_log';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * @param string $level
	 * @return bool
	 */
	private static function meets_threshold( $level ) {
		$configured = get_option( self::LOG_LEVEL_OPTION, self::DEFAULT_LOG_LEVEL );

		if ( ! isset( self::$levels[ $level ] ) || ! isset( self::$levels[ $configured ] ) ) {
			return true; // Unknown level name - log it rather than silently drop it.
		}

		return self::$levels[ $level ] >= self::$levels[ $configured ];
	}

	/**
	 * @param array $context
	 * @return array
	 */
	private static function redact( array $context ) {
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && 'authorization' === strtolower( $key ) ) {
				$context[ $key ] = '[REDACTED]';
				continue;
			}

			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
			}
		}

		return $context;
	}

	/**
	 * @param array $context
	 * @return array
	 */
	private static function truncate_long_values( array $context ) {
		$max_length = 500;

		foreach ( $context as $key => $value ) {
			if ( is_string( $value ) && strlen( $value ) > $max_length ) {
				$context[ $key ] = substr( $value, 0, $max_length ) . '... (truncated)';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::truncate_long_values( $value );
			}
		}

		return $context;
	}
}
