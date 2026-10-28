<?php

/**
 * Claims and processes one ec_site_jobs row per Action Scheduler action.
 * Runs the media-then-event phase machine via EC_Events_API_Client and
 * EC_Payload_Transformer (Phase 3), plus error classification, backoff
 * retry, a per-site circuit breaker, and ambiguous-outcome reconciliation
 * for the event phase (Phase 4).
 *
 * @link       https://summitbhc.com/
 * @since      1.4.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/jobs
 */

class EC_Delivery_Worker {

	const ACTION_HOOK = 'ec_deliver_site';

	/** Reconcile-only re-check hook - never re-attempts the actual API call, see handle_reconcile(). */
	const RECONCILE_HOOK = 'ec_reconcile_site';

	/** Give up waiting on an inconclusive reconciliation after this long and flag for manual review. */
	const RECONCILE_GIVE_UP_HOURS = 24;

	/** Stop claiming new work once this fraction of max_execution_time has elapsed. */
	const TIME_BUDGET_RATIO = 0.5;

	/**
	 * Upper bound (seconds) on the time budget, used as-is when max_execution_time
	 * is 0/unlimited (common on PHP-FPM hosts that rely on the reverse proxy for
	 * timeouts) so the worker still yields periodically instead of never stopping.
	 */
	const MAX_TIME_BUDGET_SECONDS = 20;

	/** Stop claiming new work once this fraction of the memory limit is in use. */
	const MEMORY_BUDGET_RATIO = 0.75;

	/**
	 * Wall-clock start of this worker process (shared across every site job
	 * this PHP process handles in one Action Scheduler runner pass).
	 *
	 * @var float|null
	 */
	private static $started_at = null;

	public function __construct() {
		add_action( self::ACTION_HOOK, array( $this, 'handle' ) );
		add_action( self::RECONCILE_HOOK, array( $this, 'handle_reconcile' ) );
	}

	/**
	 * Action Scheduler callback for a single site job.
	 *
	 * @param int $site_job_id
	 */
	public function handle( $site_job_id ) {
		if ( $this->budget_exceeded() ) {
			// Don't claim - the job stays queued/retrying exactly as it was.
			// Re-schedule a fresh action so it isn't lost once this action
			// completes (Action Scheduler marks *this* action done either way).
			EC_Logger::log( 'debug', 'Execution budget exceeded before claiming, rescheduling', array(), null, $site_job_id );
			as_schedule_single_action( time() + 5, self::ACTION_HOOK, array( $site_job_id ), 'event-controller' );
			return;
		}

		$repository = new EC_Site_Job_Repository();
		$pre_check  = $repository->get( $site_job_id );

		if ( $pre_check && EC_Circuit_Breaker::is_open( $pre_check['site_key'] ) ) {
			// Don't consume an attempt - this isn't the site job's fault.
			EC_Logger::log( 'warn', 'Circuit open for site ' . $pre_check['site_key'] . ', deferring', array(), $pre_check['job_id'], $site_job_id );
			$repository->mark_status( $site_job_id, 'blocked', array() );
			as_schedule_single_action( time() + 300, self::ACTION_HOOK, array( $site_job_id ), 'event-controller' );
			return;
		}

		if ( ! $repository->claim( $site_job_id ) ) {
			EC_Logger::log( 'debug', 'Could not claim site_job (already claimed or terminal)', array(), $pre_check ? $pre_check['job_id'] : null, $site_job_id );
			return;
		}

		EC_Logger::log( 'info', 'Claimed site_job', array(), $pre_check ? $pre_check['job_id'] : null, $site_job_id );

		$this->deliver( $repository, $site_job_id );
	}

	/**
	 * Media-then-event phase machine. remote_media_id is persisted the moment
	 * the upload succeeds, so if the event-post step fails and this site job
	 * is retried, delivery resumes at the event phase instead of re-uploading
	 * the image and orphaning a duplicate attachment.
	 *
	 * @param EC_Site_Job_Repository $repository
	 * @param int                    $site_job_id
	 */
	private function deliver( EC_Site_Job_Repository $repository, $site_job_id ) {
		$site_job = $repository->get( $site_job_id );
		if ( ! $site_job ) {
			EC_Logger::log( 'error', 'site_job vanished before delivery', array(), null, $site_job_id );
			return;
		}

		$job_manager = new EC_Job_Manager();
		$job         = $job_manager->get_raw_job( $site_job['job_id'] );

		if ( ! $job ) {
			$this->handle_failure( $job_manager, $repository, $site_job, new WP_Error( 'parent_job_missing', 'Parent job record no longer exists' ), 'event' );
			return;
		}

		$credentials = EC_Site_Registry::get( $site_job['site_key'] );
		if ( ! $credentials ) {
			$this->handle_failure( $job_manager, $repository, $site_job, new WP_Error( 'site_not_configured', 'Site configuration not found for: ' . $site_job['site_key'] ), 'event' );
			return;
		}

		$event_data = json_decode( $job['event_payload'], true );
		if ( ! is_array( $event_data ) ) {
			$event_data = array();
		}

		$api_client = new EC_Events_API_Client();

		// MEDIA phase - skipped if there's no file, or resumed cleanly if a
		// previous attempt already uploaded it (remote_media_id persisted).
		// An ambiguous outcome here is treated as retryable rather than requiring
		// full reconciliation: a duplicate media attachment is a harmless cleanup
		// issue, not the duplicate-EVENT risk this system exists to prevent.
		if ( $job['media_path'] && empty( $site_job['remote_media_id'] ) ) {
			$repository->mark_status( $site_job_id, 'processing', array( 'phase' => 'media' ) );

			$media_result = $api_client->upload_media(
				$credentials,
				$job['media_path'],
				isset( $event_data['featured_image_alt'] ) ? (string) $event_data['featured_image_alt'] : ''
			);

			if ( is_wp_error( $media_result ) ) {
				$this->handle_failure( $job_manager, $repository, $site_job, $media_result, 'media' );
				return;
			}

			$remote_media_id              = isset( $media_result['id'] ) ? (int) $media_result['id'] : 0;
			$event_data['featured_media'] = $remote_media_id;

			EC_Logger::log( 'info', 'Media uploaded, remote_media_id=' . $remote_media_id, array(), $site_job['job_id'], $site_job_id, 'media' );

			$repository->mark_status(
				$site_job_id,
				'processing',
				array(
					'phase'           => 'event',
					'remote_media_id' => $remote_media_id,
				)
			);
		} elseif ( ! empty( $site_job['remote_media_id'] ) ) {
			$event_data['featured_media'] = (int) $site_job['remote_media_id'];
			$repository->mark_status( $site_job_id, 'processing', array( 'phase' => 'event' ) );
		} else {
			$repository->mark_status( $site_job_id, 'processing', array( 'phase' => 'event' ) );
		}

		// EVENT phase - the critical one. An ambiguous outcome here must go
		// through EC_Reconciler before any retry is allowed (see handle_failure()).
		$em_payload   = EC_Payload_Transformer::transform( $event_data );
		$event_result = $api_client->post_event( $credentials, $em_payload );

		if ( is_wp_error( $event_result ) ) {
			$this->handle_failure( $job_manager, $repository, $site_job, $event_result, 'event' );
			return;
		}

		$remote_event_id = isset( $event_result['id'] ) ? (int) $event_result['id'] : 0;

		EC_Logger::log( 'info', 'Event posted, remote_event_id=' . $remote_event_id, array(), $site_job['job_id'], $site_job_id, 'event' );

		$repository->mark_status(
			$site_job_id,
			'complete',
			array(
				'phase'           => 'done',
				'remote_event_id' => $remote_event_id,
				'completed_at'    => current_time( 'mysql', true ),
			)
		);

		EC_Circuit_Breaker::record_success( $site_job['site_key'] );
		$job_manager->rollup_job_counters( $site_job['job_id'] );
	}

	/**
	 * Classifies the failure and decides what happens next: fail permanently,
	 * schedule a backed-off retry, or (event-phase ambiguous outcomes only)
	 * hand off to reconciliation instead of retrying blindly.
	 *
	 * @param EC_Job_Manager         $job_manager
	 * @param EC_Site_Job_Repository $repository
	 * @param array                  $site_job
	 * @param WP_Error               $error
	 * @param string                 $phase 'media' or 'event'.
	 */
	private function handle_failure( EC_Job_Manager $job_manager, EC_Site_Job_Repository $repository, array $site_job, WP_Error $error, $phase ) {
		$classification = EC_Error_Classifier::classify( $error );
		$data           = $error->get_error_data();

		$common_fields = array(
			'last_error_code'    => $error->get_error_code(),
			'last_error_message' => $error->get_error_message(),
			'last_http_status'   => isset( $data['http_status'] ) ? (int) $data['http_status'] : null,
		);

		EC_Circuit_Breaker::record_failure( $site_job['site_key'] );

		if ( EC_Error_Classifier::AMBIGUOUS === $classification && 'event' === $phase ) {
			EC_Logger::log( 'warn', 'Ambiguous event-phase outcome, deferring to reconciliation', $common_fields, $site_job['job_id'], $site_job['id'], $phase );
			$repository->mark_status( $site_job['id'], 'ambiguous_pending', $common_fields );
			as_schedule_single_action( time() + 30, self::RECONCILE_HOOK, array( (int) $site_job['id'] ), 'event-controller' );
			return;
		}

		if ( EC_Error_Classifier::PERMANENT !== $classification && ! EC_Retry_Policy::max_attempts_reached( $site_job['attempts'] ) ) {
			$delay = EC_Retry_Policy::next_delay_seconds( $site_job['attempts'] );

			EC_Logger::log( 'warn', 'Delivery failed, scheduling retry in ' . $delay . 's', $common_fields, $site_job['job_id'], $site_job['id'], $phase );
			$repository->mark_status(
				$site_job['id'],
				'retrying',
				array_merge( $common_fields, array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ) ) )
			);
			as_schedule_single_action( time() + $delay, self::ACTION_HOOK, array( (int) $site_job['id'] ), 'event-controller' );
			return;
		}

		// Permanent, or retryable but out of attempts - terminal.
		EC_Logger::log( 'error', 'Delivery failed permanently', $common_fields, $site_job['job_id'], $site_job['id'], $phase );
		$repository->mark_status(
			$site_job['id'],
			'failed',
			array_merge( $common_fields, array( 'completed_at' => current_time( 'mysql', true ) ) )
		);
		$job_manager->rollup_job_counters( $site_job['job_id'] );
	}

	/**
	 * Reconcile-only re-check for a site job left 'ambiguous_pending' by the
	 * event phase. Never re-posts the event - only queries the remote for one
	 * that may already exist. See EC_Reconciler for why every inconclusive
	 * result here defers instead of guessing.
	 *
	 * @param int $site_job_id
	 */
	public function handle_reconcile( $site_job_id ) {
		$repository = new EC_Site_Job_Repository();
		$site_job   = $repository->get( $site_job_id );

		if ( ! $site_job || 'ambiguous_pending' !== $site_job['status'] ) {
			return; // Already resolved through another path.
		}

		$job_manager = new EC_Job_Manager();
		$job         = $job_manager->get_raw_job( $site_job['job_id'] );
		$credentials = EC_Site_Registry::get( $site_job['site_key'] );

		if ( ! $job || ! $credentials ) {
			$repository->mark_status(
				$site_job_id,
				'failed',
				array(
					'last_error_code'    => 'reconcile_context_missing',
					'last_error_message' => 'Could not reconcile - parent job or site credentials no longer available',
					'completed_at'       => current_time( 'mysql', true ),
				)
			);
			$job_manager->rollup_job_counters( $site_job['job_id'] );
			return;
		}

		$event_data = json_decode( $job['event_payload'], true );
		if ( ! is_array( $event_data ) ) {
			$event_data = array();
		}
		if ( ! empty( $site_job['remote_media_id'] ) ) {
			$event_data['featured_media'] = (int) $site_job['remote_media_id'];
		}

		$em_payload    = EC_Payload_Transformer::transform( $event_data );
		$window_start  = $site_job['last_attempt_at'] ? strtotime( $site_job['last_attempt_at'] ) : time();
		list( $outcome, $remote_event_id ) = EC_Reconciler::reconcile( $credentials, $em_payload, $window_start );

		EC_Logger::log( 'info', 'Reconciliation outcome: ' . $outcome, array(), $site_job['job_id'], $site_job_id, 'event' );

		if ( EC_Reconciler::FOUND === $outcome ) {
			$repository->mark_status(
				$site_job_id,
				'complete',
				array(
					'phase'           => 'done',
					'remote_event_id' => $remote_event_id,
					'completed_at'    => current_time( 'mysql', true ),
				)
			);
			EC_Circuit_Breaker::record_success( $site_job['site_key'] );
			$job_manager->rollup_job_counters( $site_job['job_id'] );
			return;
		}

		if ( EC_Reconciler::NOT_FOUND === $outcome ) {
			if ( EC_Retry_Policy::max_attempts_reached( $site_job['attempts'] ) ) {
				$repository->mark_status(
					$site_job_id,
					'failed',
					array(
						'last_error_code'    => 'max_attempts_reached',
						'last_error_message' => 'Reconciliation found no remote event, and max retry attempts reached',
						'completed_at'       => current_time( 'mysql', true ),
					)
				);
				$job_manager->rollup_job_counters( $site_job['job_id'] );
				return;
			}

			// Confirmed safe to retry the actual event post now.
			$delay = EC_Retry_Policy::next_delay_seconds( $site_job['attempts'] );
			$repository->mark_status(
				$site_job_id,
				'retrying',
				array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ) )
			);
			as_schedule_single_action( time() + $delay, self::ACTION_HOOK, array( $site_job_id ), 'event-controller' );
			return;
		}

		// Inconclusive. Never guess - either give up after the timeout (surfaced
		// for manual review once the admin UI exists) or check again later.
		$hours_waiting = $site_job['last_attempt_at'] ? ( time() - strtotime( $site_job['last_attempt_at'] ) ) / HOUR_IN_SECONDS : 0;

		if ( $hours_waiting > self::RECONCILE_GIVE_UP_HOURS ) {
			$repository->mark_status(
				$site_job_id,
				'needs_review',
				array(
					'last_error_code'    => 'reconciliation_inconclusive',
					'last_error_message' => 'Could not reliably determine whether this event was created after ' . self::RECONCILE_GIVE_UP_HOURS . ' hours - manual review required',
				)
			);
			$job_manager->rollup_job_counters( $site_job['job_id'] );
			return;
		}

		as_schedule_single_action( time() + 60, self::RECONCILE_HOOK, array( $site_job_id ), 'event-controller' );
	}

	/**
	 * @return bool True if this worker process should stop claiming new work.
	 */
	private function budget_exceeded() {
		if ( null === self::$started_at ) {
			self::$started_at = microtime( true );
		}

		$max_execution_time = (int) ini_get( 'max_execution_time' );

		// max_execution_time = 0 means unlimited (common on PHP-FPM hosts that rely
		// on a reverse proxy timeout instead) - fall back to a fixed cap so the
		// worker still yields periodically rather than never stopping.
		$time_budget = $max_execution_time > 0
			? min( self::MAX_TIME_BUDGET_SECONDS, $max_execution_time * self::TIME_BUDGET_RATIO )
			: self::MAX_TIME_BUDGET_SECONDS;

		/** Filter to override the worker's time budget in seconds (e.g. for testing). */
		$time_budget = (float) apply_filters( 'ec_delivery_worker_time_budget_seconds', $time_budget );

		if ( ( microtime( true ) - self::$started_at ) > $time_budget ) {
			return true;
		}

		$memory_limit = $this->memory_limit_bytes();
		if ( $memory_limit > 0 && memory_get_usage( true ) > ( $memory_limit * self::MEMORY_BUDGET_RATIO ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @return int Memory limit in bytes, or 0 if unlimited/unknown.
	 */
	private function memory_limit_bytes() {
		$limit = ini_get( 'memory_limit' );

		if ( ! $limit || '-1' === $limit ) {
			return 0;
		}

		$unit  = strtolower( substr( $limit, -1 ) );
		$value = (int) $limit;

		switch ( $unit ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return $value;
	}
}
